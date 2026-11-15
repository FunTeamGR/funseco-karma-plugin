<?php
namespace Funseco\Karma;

use Illuminate\Support\Facades\Log;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\SharedKernel\FinishHistory;

/**
 * Karma of the current map: local votes from the database, replaced by mania-karma.com's
 * global figures when the service answers. Handles votes (XAseco mk_handlePlayerVote).
 */
class KarmaBoard
{
    private ?int $mapId = null;
    private KarmaTally $tally;
    /** @var array<string, int> login => vote */
    private array $votes = [];
    /** @var array<string, int> votes cast here this map (score screen included), sent when the next map loads */
    private array $pending = [];

    /** The map that just ended while the podium shows: CurrentMap already points at the next one by then. */
    private ?Map $podiumMap = null;

    public function __construct(private KarmaSync $sync)
    {
        $this->tally = KarmaTally::fromScores([]);
    }

    /** Podium: votes and finish checks are about the map that was just raced, not the next one. */
    public function enterPodium(Map $map): void
    {
        $this->podiumMap = $map;
    }

    /** The map a vote counts for right now. */
    private function votingMap(): ?Map
    {
        return $this->podiumMap ?? CurrentMap::get();
    }

    public function load(Map $map): void
    {
        $this->podiumMap = null;
        // XAseco mk_onNewChallenge: send the previous map's votes, reminder votes included.
        $this->storePending();
        $this->mapId = $map->id;
        $this->votes = KarmaVote::query()->join('players', 'players.id', '=', 'karma_votes.player_id')
            ->where('karma_votes.map_id', $map->id)->pluck('karma_votes.score', 'players.login')
            ->map(fn ($s) => (int) $s)->all();
        $this->tally = KarmaTally::fromScores(array_values($this->votes));

        $this->sync->whenReady()
            ->then(fn () => $this->sync->fetch($map, CurrentPlayers::all()->keys()->all()))
            ->then(function (array $global) use ($map) {
            if ($this->mapId !== $map->id) {
                return;   // the server moved on
            }
            $this->tally = $global['tally'];
            foreach ($global['players'] as $login => $vote) {
                if ($vote === 0) {
                    continue;
                }
                if (!isset($this->votes[$login]) && config('karma.sync.copy_global_votes') && ($player = CurrentPlayers::get($login))) {
                    KarmaVote::updateOrCreate(['map_id' => $map->id, 'player_id' => $player->id], ['score' => $vote]);
                }
                $this->votes[$login] = $vote;
            }
            app(KarmaWidget::class)->showToAll();
        }, fn () => null)   // service down: local karma stays
            ->catch(fn (\Throwable $e) => Log::error('[Karma] applying global votes failed: '.$e->getMessage()));
    }

    public function tally(): KarmaTally
    {
        return $this->tally;
    }

    public function karma(): int
    {
        return $this->tally->karma();
    }

    public function voteOf(string $login): ?int
    {
        return $this->votes[$login] ?? null;
    }

    public function vote(Player $player, int $score): void
    {
        $map = $this->votingMap();
        if (!$map) {
            return;
        }
        if ($this->mapId !== $map->id) {
            $this->load($map);
        }

        $required = (int) config('karma.voting.require_finish', 0);
        if ($required > 0 && app(FinishHistory::class)->finishCount($player, $map) < $required) {
            Messages::tell($player, Messages::get('require_finish', $required, $required === 1 ? '' : 's'));
            return;
        }

        // Vote pay sees every vote that got past the finish requirement (repeats included, as in XAseco);
        // refused ones are not counted, so a player cannot farm it without finishing.
        event(new \Funseco\Core\SharedKernel\Events\KarmaVoteCast($player, (string) $map->uid, $score));

        $previous = $this->voteOf($player->login);
        if ($previous === $score) {
            Messages::tell($player, Messages::get('voted'));
            return;
        }

        KarmaVote::updateOrCreate(['map_id' => $map->id, 'player_id' => $player->id], ['score' => $score]);
        $this->votes[$player->login] = $score;
        $this->tally = $this->tally->moved($previous, $score);
        $this->pending[$player->login] = $score;

        $name = Messages::stripColors($map->name);
        Messages::tell($player, Messages::get($previous === null ? 'done' : 'change', $name));
        if ($message = $this->message($player)) {
            Messages::tell($player, $message);
        }
        app(KarmaWidget::class)->showToAll();

        if (config('karma.voting.show_opinions')) {
            $this->tellOpinion($player, $score, $map);
        }
    }

    /** The /karma line (XAseco mk_createKarmaMessage). */
    public function message(Player $player, bool $force = false): ?string
    {
        $map = $this->votingMap();
        if (!$map) {
            return null;
        }

        $message = '';
        if ($force || config('karma.display.show_karma')) {
            $message .= Messages::raw('message', Messages::stripColors($map->name), $this->karma());
        }
        if ($force || config('karma.display.show_votes')) {
            $vote = $this->voteOf($player->login);
            $message .= $vote === null
                ? Messages::raw('not_voted')
                : Messages::raw('your_vote', config("karma.levels.$vote"), '/'.str_repeat($vote > 0 ? '+' : '-', abs($vote)));
        }
        if ($force || config('karma.display.show_details')) {
            $values = [$this->karma()];
            foreach (array_keys(config('karma.levels')) as $level) {
                array_push($values, $this->tally->percent($level), $this->tally->count($level));
            }
            $message .= "\n".Messages::raw('details', ...$values);
        }

        return $message === '' ? null : format_colors($message);
    }

    public function storePending(): void
    {
        $map = $this->mapId ? Map::find($this->mapId) : null;
        if ($map && $this->pending) {
            $votes = $this->pending;
            $this->sync->whenReady()->then(fn () => $this->sync->store($map, $votes))->catch(fn () => null);
        }
        $this->pending = [];
    }

    /** Players who could vote but haven't hear someone's opinion (XAseco show_player_vote_public). */
    private function tellOpinion(Player $voter, int $score, Map $map): void
    {
        $message = Messages::get('opinion', Messages::stripColors($voter->nickname), config("karma.levels.$score"));
        $required = (int) config('karma.voting.require_finish', 0);
        foreach (CurrentPlayers::all() as $player) {
            if ($player->login === $voter->login || $this->voteOf($player->login) !== null || CurrentSpectators::has($player->login)) {
                continue;
            }
            if ($required > 0 && app(FinishHistory::class)->finishCount($player, $map) < $required) {
                continue;
            }
            Messages::tell($player, $message);
        }
    }
}
