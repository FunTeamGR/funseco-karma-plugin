<?php
namespace Funseco\Karma\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use React\Http\Message\Response;
use Funseco\Core\Testing\FakesServer;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Events\PlayerChatted;
use Funseco\Core\SharedKernel\FinishHistory;
use Funseco\Core\SharedKernel\KarmaStats;
use Funseco\Karma\KarmaBoard;
use Funseco\Karma\KarmaSync;
use Funseco\Karma\KarmaTally;
use Funseco\Karma\KarmaVote;
use function React\Promise\reject;
use function React\Promise\resolve;

class KarmaTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $alice;
    private Player $bob;
    private Map $map;
    private array $finishes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => '$f00Alice']);
        $this->bob = Player::factory()->create(['login' => 'bob', 'nickname' => 'Bob']);
        CurrentPlayers::add($this->alice);
        CurrentPlayers::add($this->bob);
        $this->map = Map::factory()->create(['name' => '$fffSpeed']);
        CurrentMap::set($this->map);
        $this->finishes = ['alice' => 1, 'bob' => 0];
        $finishes = &$this->finishes;
        $this->app->instance(FinishHistory::class, new class($finishes) implements FinishHistory {
            public function __construct(private array &$counts) {}
            public function finishedMapIds(Player $player): array { return []; }
            public function bestScores(Player $player): array { return []; }
            public function finishCount(Player $player, Map $map): int { return $this->counts[$player->login] ?? 0; }
            public function averageScore(Player $player, Map $map, int $last): ?int { return null; }
        });
        app(KarmaBoard::class)->load($this->map);
    }

    private function toLogin(string $login): array
    {
        return array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === $login)));
    }

    public function test_tally_uses_the_default_calculation()
    {
        $tally = KarmaTally::fromScores([3, 3, 2, -1, -3]);

        // (2×100 + 80 + 40 + 0) / 5 = 64
        $this->assertSame(64, $tally->karma());
        $this->assertSame(5, $tally->total());
        $this->assertSame('40.00', $tally->percent(3));
        $this->assertSame(0, KarmaTally::fromScores([])->karma());
    }

    public function test_vote_is_stored_and_confirmed()
    {
        Chat::resolve('/++', 'alice');

        $this->assertSame(2, KarmaVote::where('player_id', $this->alice->id)->value('score'));
        $this->assertSame([
            format_colors('{#server}> {#karma}Vote successful for Track {#highlite}Speed{#karma}!'),
            format_colors('{#server}> {#karma}Karma of {#highlite}Speed{#karma} is {#highlite}80{#karma}, you think this Track is {#highlite}beautiful{#karma}. Command {#highlite}/++'),
        ], $this->toLogin('alice'));
    }

    public function test_changing_and_repeating_a_vote_keeps_one_row()   // [RF 5]
    {
        Chat::resolve('/+++', 'alice');
        Chat::resolve('/---', 'alice');
        Chat::resolve('/---', 'alice');

        $this->assertSame(1, KarmaVote::count());
        $this->assertSame(-3, KarmaVote::first()->score);
        $messages = $this->toLogin('alice');
        $this->assertSame(format_colors('{#server}> {#karma}Vote changed for Track {#highlite}Speed{#karma}!'), $messages[2]);
        $this->assertSame(format_colors('{#server}> {#karma}You have already voted for this Track'), end($messages));
        $this->assertSame(1, app(KarmaBoard::class)->tally()->total());
    }

    public function test_finish_is_required_before_voting()
    {
        Chat::resolve('/+', 'bob');

        $this->assertSame(0, KarmaVote::count());
        $this->assertSame([format_colors('{#server}> {#error}You need to finish this Track at least {#highlite}$I 1{#error} time before being able to vote!')], $this->toLogin('bob'));
    }

    public function test_vote_cast_event_only_for_votes_past_the_checks()
    {
        $seen = [];
        \Illuminate\Support\Facades\Event::listen(\Funseco\Core\SharedKernel\Events\KarmaVoteCast::class, function ($e) use (&$seen) {
            $seen[] = [$e->player->login, $e->value];
        });

        Chat::resolve('/+', 'bob');   // must finish first: refused
        $this->assertSame([], $seen);

        Chat::resolve('/++', 'alice');
        Chat::resolve('/++', 'alice');   // repeat still counts (XAseco)
        $this->assertSame([['alice', 2], ['alice', 2]], $seen);

        config(['karma.voting.public' => false]);
        event(new PlayerChatted($this->alice, '+++'));   // public votes refused: no event
        $this->assertCount(2, $seen);
    }

    public function test_public_chat_votes()
    {
        event(new PlayerChatted($this->alice, '+++'));
        event(new PlayerChatted($this->alice, 'hello +++'));

        $this->assertSame(3, KarmaVote::first()->score);
        $this->assertSame(1, KarmaVote::count());
    }

    public function test_karma_command_and_public_opinion()
    {
        $this->finishes['bob'] = 1;
        Chat::resolve('/karma', 'bob');
        $this->assertSame([format_colors('{#server}> {#karma}Karma of {#highlite}Speed{#karma} is {#highlite}0{#karma}, you have {#highlite}not yet voted!')], $this->toLogin('bob'));

        Chat::resolve('/-', 'alice');
        // bob may vote and hasn't: he gets alice's opinion
        $this->assertContains(format_colors('{#server}>> {#highlite}[Alice]{#server} thinks this Track is {#highlite}bad{#server}! What do you think?'), $this->toLogin('bob'));
    }

    public function test_widget_shows_karma_and_vote_buttons()
    {
        Chat::resolve('/+++', 'alice');

        $xml = app(\Funseco\Karma\KarmaWidget::class)->xml($this->alice);
        $this->assertStringContainsString('49.2 32.86', $xml);
        $this->assertStringContainsString('100', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::dispatchAnswer($this->alice, \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction('karma_vote_-2'));
        $this->assertSame(-2, KarmaVote::first()->score, 'buttons run the vote commands');
    }

    public function test_vote_bar_and_buttons_stay_inside_the_visible_width()
    {
        Chat::resolve('/+++', 'alice');
        Chat::resolve('/-', 'bob');
        $xml = simplexml_load_string(app(\Funseco\Karma\KarmaWidget::class)->xml($this->alice));

        // The column starts at x 49.2 and the screen ends at 64: 14.8 are visible.
        $checked = 0;
        foreach ($xml->frame->quad as $quad) {
            if (isset($quad['bgcolor']) || isset($quad['action'])) {
                [$x] = explode(' ', (string) $quad['posn']);
                [$w] = explode(' ', (string) $quad['sizen']);
                $this->assertLessThanOrEqual(14.8, (float) $x + (float) $w, (string) $quad['posn']);
                $checked++;
            }
        }
        $this->assertGreaterThan(6, $checked);
    }

    public function test_full_widget_shows_distribution_and_tmx_link_snapshot()
    {
        Chat::resolve('/+++', 'alice');
        Chat::resolve('/-', 'bob');
        $xml = app(\Funseco\Karma\KarmaWidget::class)->xml($this->alice);
        $this->assertStringNotContainsString('url=', $xml, 'no TMX link while the track is unknown');

        $tmx = new \Funseco\Core\SharedKernel\Values\TmxTrack(7, 'uid', 'Speed', 'nadeo', null, null, 'Race', 'Race', 'Stadium', 'Day', 'Easy', 'Single', '1:00', 0, '', 'TMNF', '', 0,
            'http://tmnf.exchange/trackshow/7', 'http://img', 'http://dl', null);
        $this->app->instance(\Funseco\Core\SharedKernel\TrackExchange::class, new class($tmx) implements \Funseco\Core\SharedKernel\TrackExchange {
            public function __construct(private $t) {}
            public function current(): ?\Funseco\Core\SharedKernel\Values\TmxTrack { return $this->t; }
            public function forUid(string $uid, ?string $section = null): \React\Promise\PromiseInterface { return \React\Promise\resolve(null); }
            public function forId(int $id, ?string $section = null): \React\Promise\PromiseInterface { return \React\Promise\resolve(null); }
        });
        $xml = app(\Funseco\Karma\KarmaWidget::class)->xml($this->alice);
        $this->assertStringContainsString('url="http://tmnf.exchange/trackshow/7"', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        $normalized = preg_replace('/id="\d+"|action="\d+"/', 'ID', $xml);
        $file = __DIR__.'/snapshots/karma_full.xml';
        if (getenv('UPDATE_SNAPSHOTS')) {
            file_put_contents($file, $normalized);
        }
        $this->assertSame(file_get_contents($file), $normalized);
    }

    public function test_karma_stats_contract()
    {
        $other = Map::factory()->create();
        KarmaVote::create(['map_id' => $this->map->id, 'player_id' => $this->alice->id, 'score' => 3]);
        KarmaVote::create(['map_id' => $this->map->id, 'player_id' => $this->bob->id, 'score' => -1]);
        KarmaVote::create(['map_id' => $other->id, 'player_id' => $this->alice->id, 'score' => -2]);

        $stats = app(KarmaStats::class);

        $this->assertSame([$this->map->id => 2, $other->id => -2], $stats->karmaByMap());
        $this->assertSame(2, $stats->mapKarma($this->map));
        $this->assertEqualsCanonicalizing([$this->map->id, $other->id], $stats->votedMapIds($this->alice));
    }

    // --- mania-karma.com sync ---------------------------------------------

    private function syncWith($http): KarmaSync
    {
        config(['karma.sync.enabled' => true, 'karma.sync.login' => 'srvlogin']);
        $this->app->instance(AsyncHttpClient::class, $http);
        $this->app->forgetInstance(KarmaSync::class);
        $this->app->forgetInstance(KarmaBoard::class);   // the board holds the sync client

        return app(KarmaSync::class);
    }

    public function test_sync_authenticates_fetches_and_stores_votes()
    {
        $http = Mockery::mock(AsyncHttpClient::class);
        $urls = [];
        $http->shouldReceive('get')->andReturnUsing(function ($url) use (&$urls) {
            $urls[] = $url;
            return resolve(new Response(200, [], match (true) {
                str_contains($url, 'Action=Auth') => '<result><status>200</status><authcode>AC</authcode><api_url>http://api.test/k.php</api_url><import_done>true</import_done></result>',
                str_contains($url, 'Action=Get')  => '<result><status>200</status><votes><karma>70</karma><fantastic percent="50" count="1"/><beautiful percent="0" count="0"/><good percent="0" count="0"/><bad percent="50" count="1"/><poor percent="0" count="0"/><waste percent="0" count="0"/></votes><players><player login="bob" vote="-1" previous="0"/></players></result>',
                default => '<result><status>200</status></result>',
            }));
        });
        $sync = $this->syncWith($http);

        $sync->authenticate();
        app(KarmaBoard::class)->load($this->map);
        $this->assertSame(70, app(KarmaBoard::class)->karma(), 'global karma shown');
        $this->assertSame(-1, KarmaVote::where('player_id', $this->bob->id)->value('score'), 'global vote copied locally');

        Chat::resolve('/+++', 'alice');   // e.g. from the score-screen reminder
        app(KarmaBoard::class)->load(Map::factory()->create());   // next map: the previous map's votes go out

        $this->assertStringContainsString('Action=Auth&login=srvlogin', $urls[0]);
        $this->assertStringContainsString('http://api.test/k.php?Action=Get&login=srvlogin&authcode=AC&uid='.urlencode($this->map->uid), $urls[1]);
        $vote = array_values(array_filter($urls, fn ($u) => str_contains($u, 'Action=Vote')));
        $this->assertCount(1, $vote);
        $this->assertStringContainsString('uid='.urlencode($this->map->uid), $vote[0]);
        $this->assertStringContainsString('votes=alice%3D3', $vote[0]);
    }

    public function test_service_down_keeps_local_votes_and_logs_once()   // [RF 3]
    {
        Log::spy();
        $http = Mockery::mock(AsyncHttpClient::class);
        $http->shouldReceive('get')->andReturn(reject(new \RuntimeException('Connection refused')));
        $sync = $this->syncWith($http);

        $sync->authenticate()->catch(fn () => null);
        app(KarmaBoard::class)->load($this->map);
        Chat::resolve('/+', 'alice');

        $this->assertSame(1, KarmaVote::first()->score);
        $this->assertSame(60, app(KarmaBoard::class)->karma(), 'local karma shown');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_auth_is_retried_after_the_wait()   // [RF 3]
    {
        $this->travelTo(now());
        $calls = 0;
        $up = false;
        $http = Mockery::mock(AsyncHttpClient::class);
        $http->shouldReceive('get')->andReturnUsing(function ($url) use (&$calls, &$up) {
            $calls++;
            if (!$up) {
                return reject(new \RuntimeException('Connection refused'));
            }
            return resolve(new Response(200, [], str_contains($url, 'Action=Auth')
                ? '<result><status>200</status><authcode>AC</authcode><api_url>http://api.test/k.php</api_url></result>'
                : '<result><status>200</status><votes><karma>55</karma></votes><players/></result>'));
        });
        $sync = $this->syncWith($http);
        $sync->authenticate()->catch(fn () => null);

        app(KarmaBoard::class)->load($this->map);
        $this->assertSame(1, $calls, 'no retry before the wait is over');

        $up = true;
        $this->travel(11)->minutes();
        Chat::resolve('/+', 'alice');   // a pending vote: load() sends it and fetches, one Auth for both
        $calls = 0;
        app(KarmaBoard::class)->load($this->map);
        $this->assertSame(3, $calls, 'Auth, Vote, Get');
        $this->assertTrue($sync->ready());
        $this->assertSame(55, app(KarmaBoard::class)->karma());
    }

    public function test_widget_is_hidden_on_the_podium_and_back_on_the_next_map()
    {
        $widget = app(\Funseco\Karma\KarmaWidget::class);
        $id = \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction('karma');

        $widget->enterPodium();
        $this->assertStringContainsString('<manialink id="'.$id.'"></manialink>', implode('', array_column($this->sent('SendDisplayManialinkPage'), 0)));

        $this->calls = [];
        $widget->show($this->alice);
        $widget->showToAll();
        $this->assertSame([], $this->sent('SendDisplayManialinkPageToLogin'), 'joiners at the podium do not get it');

        $widget->leavePodium();
        $widget->show($this->alice);
        $this->assertNotSame([], $this->sent('SendDisplayManialinkPageToLogin'));
    }
}
