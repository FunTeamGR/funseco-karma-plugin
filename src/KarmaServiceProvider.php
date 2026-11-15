<?php

namespace Funseco\Karma;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\DatabaseWipeRequested;
use Funseco\Core\SharedKernel\Events\KarmaClearRequested;
use Funseco\Core\SharedKernel\Events\MapDataPurgeRequested;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerChatted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDataPurgeRequested;
use Funseco\Core\SharedKernel\Events\SettingChanged;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\FinishHistory;
use Funseco\Core\SharedKernel\KarmaStats;
use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\Karma\Commands\KarmaCommand;
use Funseco\Karma\Commands\VoteCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class KarmaServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    private const PUBLIC_VOTES = ['+++' => 3, '++' => 2, '+' => 1, '-' => -1, '--' => -2, '---' => -3];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/karma.php', 'karma');
        $this->mergeConfigDeep(__DIR__.'/../config/manialinks.php', 'manialinks.karma');
        $this->publishPluginConfig(__DIR__.'/..');
        $this->app->singleton(KarmaSync::class);
        $this->app->singleton(KarmaBoard::class);
        $this->app->singleton(KarmaWidget::class);
        $this->app->singleton(KarmaStats::class, DatabaseKarmaStats::class);

        // All read lazily through config(): live. (sync.* is re-read on every request/authenticate.)
        $this->app->make(SettingsRegistry::class)->declare('karma', 'karma', [
            Setting::bool('voting.public', 'Public voting'),
            Setting::int('voting.require_finish', 'Finishes needed to vote', min: 0),
            Setting::bool('sync.enabled', 'mania-karma.com sync'),
            Setting::url('sync.auth_url', 'mania-karma.com API URL'),
            Setting::string('sync.login', 'mania-karma.com login'),
            Setting::string('sync.nation', 'Server nation (3 letters)'),
            Setting::string('sync.zone', 'Server zone'),
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        KarmaWidget::register(app(WidgetRegistry::class));

        $maniaLinks = app(ManiaLinkService::class);
        $maniaLinks->addActions([KarmaWidget::NAME, 'karma_reminder']);
        $maniaLinks->addActions(array_keys(KarmaWidget::actions()), VoteAction::class);

        \Chat::register(KarmaCommand::class);
        foreach (self::PUBLIC_VOTES as $score) {
            \Chat::register(new VoteCommand($score));
        }

        $board = fn () => app(KarmaBoard::class);
        $widget = fn () => app(KarmaWidget::class);

        Event::listen(StartupEvent::class, function () use ($board, $widget) {
            $load = function () use ($board, $widget) {
                if ($map = CurrentMap::get()) {
                    $board()->load($map);
                    $widget()->showToAll();
                }
            };
            app(KarmaSync::class)->authenticate()->then($load, $load);
        });

        Event::listen(SettingChanged::class, function (SettingChanged $e) {
            if (str_starts_with($e->setting->key(), 'karma.sync.')) {
                app(KarmaSync::class)->forgetAuth();
            }
        });

        Event::listen(MapStarted::class, function (MapStarted $event) use ($board, $widget) {
            $widget()->closeReminders();
            $widget()->leavePodium();
            $board()->load($event->map);
            $widget()->showToAll();
            if (config('karma.display.show_at_start')) {
                foreach (CurrentPlayers::all() as $player) {
                    if ($message = $board()->message($player)) {
                        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
                    }
                }
            }
        });

        Event::listen(PlayerConnected::class, fn (PlayerConnected $e) => $widget()->show($e->player));

        // /dbtools
        Event::listen(KarmaClearRequested::class, [KarmaMaintenance::class, 'clear']);
        Event::listen(PlayerDataPurgeRequested::class, [KarmaMaintenance::class, 'purgePlayerData']);
        Event::listen(MapDataPurgeRequested::class, [KarmaMaintenance::class, 'purgeMapData']);
        Event::listen(DatabaseWipeRequested::class, [KarmaMaintenance::class, 'wipe']);

        Event::listen(MapEnded::class, function (MapEnded $event) use ($board, $widget) {
            $board()->enterPodium($event->map);
            $widget()->enterPodium();
            // Votes are sent when the next map loads, so score-screen votes go too.
            if (config('karma.display.remind_at_score')) {
                $required = (int) config('karma.voting.require_finish', 0);
                $map = CurrentMap::get();
                foreach (CurrentPlayers::all() as $player) {
                    $mayVote = $required === 0 || ($map && app(FinishHistory::class)->finishCount($player, $map) >= $required);
                    if ($mayVote && $board()->voteOf($player->login) === null) {
                        $widget()->remind($player);
                    }
                }
            }
        });

        Event::listen(PlayerChatted::class, function (PlayerChatted $event) use ($board) {
            $text = trim($event->text);
            if (! isset(self::PUBLIC_VOTES[$text])) {
                return;
            }
            if (config('karma.voting.public')) {
                $board()->vote($event->player, self::PUBLIC_VOTES[$text]);
            } else {
                Messages::tell($event->player, Messages::get('no_public', '/'.$text));
            }
        });
    }
}
