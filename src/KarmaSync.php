<?php
namespace Funseco\Karma;

use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Funseco\Core\Models\Map;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Http\AsyncHttpClient;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * mania-karma.com API, as XAseco's plugin.mania_karma uses it: Auth once (gives an authcode
 * and the API url), Get per map (global votes + the players' votes), Vote when the next map
 * loads (the votes cast on this server). Failures are logged once per map and never retried in a
 * loop: after a failed Auth (or a refused Get/Vote, e.g. an expired authcode) the next call
 * after retry_minutes authenticates again (XAseco retrytime).
 */
class KarmaSync
{
    private ?string $authcode = null;
    private ?string $apiUrl = null;
    private ?string $failedFor = null;
    private int $retryAt = 0;
    /** Auth under way: callers share it instead of sending a second one */
    private ?PromiseInterface $authenticating = null;

    public function __construct(private AsyncHttpClient $http)
    {
    }

    /** The login/zone/nation/auth URL changed: the cached authcode and API URL belong to the old values. The next request authenticates again. */
    public function forgetAuth(): void
    {
        $this->authcode = null;
        $this->apiUrl = null;
        $this->retryAt = 0;
    }

    public function enabled(): bool
    {
        return (bool) config('karma.sync.enabled') && (string) config('karma.sync.login') !== '';
    }

    public function ready(): bool
    {
        return $this->enabled() && $this->authcode !== null;
    }

    /** Resolves once authenticated: right away, or after a new Auth when the retry wait is over. */
    public function whenReady(): PromiseInterface
    {
        if (!$this->enabled()) {
            return reject(new \RuntimeException('karma sync disabled'));
        }
        if ($this->authcode !== null) {
            return resolve(null);
        }
        if (now()->timestamp < $this->retryAt) {
            return reject(new \RuntimeException('waiting to retry mania-karma.com'));
        }

        if ($this->authenticating) {
            return $this->authenticating;
        }
        $auth = $this->authenticating = $this->authenticate();
        // cleared once settled (right away if it already is)
        $auth->then(fn () => null, fn () => null)->finally(function () use ($auth) {
            if ($this->authenticating === $auth) {
                $this->authenticating = null;
            }
        });

        return $auth;
    }

    public function authenticate(): PromiseInterface
    {
        if (!$this->enabled()) {
            return reject(new \RuntimeException('karma sync disabled'));
        }

        $url = config('karma.sync.auth_url').'?'.http_build_query([
            'Action' => 'Auth',
            'login'  => config('karma.sync.login'),
            'name'   => base64_encode((string) (ServerInfo::get()['server_name'] ?? '')),
            'game'   => 'TmForever',
            'zone'   => config('karma.sync.zone'),
            'nation' => config('karma.sync.nation'),
        ]);

        return $this->request($url, 'auth')->then(function (\SimpleXMLElement $xml) {
            $this->authcode = (string) $xml->authcode;
            $this->apiUrl = (string) $xml->api_url;
            Log::info('[Karma] Authenticated with mania-karma.com');
        }, function (\Throwable $e) {
            $this->retryLater();
            throw $e;
        });
    }

    /**
     * @param  string[] $logins
     * @return PromiseInterface<array{tally: KarmaTally, players: array<string, int>}>
     */
    public function fetch(Map $map, array $logins): PromiseInterface
    {
        if (!$this->ready()) {
            return reject(new \RuntimeException('not authenticated'));
        }

        $url = $this->apiUrl.'?'.http_build_query(['Action' => 'Get'] + $this->identity() + [
            'uid'    => $map->uid,
            'map'    => base64_encode((string) $map->name),
            'author' => $map->author,
            'env'    => $map->environment,
        ]).'&player='.implode('|', array_map('urlencode', $logins));

        return $this->request($url, "get:{$map->uid}")->then(function (\SimpleXMLElement $xml) {
            $counts = [];
            foreach (config('karma.levels') as $score => $name) {
                $counts[$score] = (int) ($xml->votes->{$name}['count'] ?? 0);
            }
            $players = [];
            foreach ($xml->players->player ?? [] as $player) {
                $players[(string) $player['login']] = (int) $player['vote'];
            }

            return ['tally' => KarmaTally::fromService($counts, (int) $xml->votes->karma), 'players' => $players];
        });
    }

    /** @param array<string, int> $votes login => score */
    public function store(Map $map, array $votes): PromiseInterface
    {
        if (!$this->ready() || !$votes) {
            return reject(new \RuntimeException('nothing to send'));
        }

        $pairs = implode('|', array_map(fn ($login, $vote) => urlencode($login).'='.$vote, array_keys($votes), $votes));
        $url = $this->apiUrl.'?'.http_build_query(['Action' => 'Vote'] + $this->identity() + [
            'uid'      => $map->uid,
            'map'      => base64_encode((string) $map->name),
            'author'   => $map->author,
            'atime'    => (int) $map->author_time,
            'ascore'   => 0,
            'nblaps'   => (int) $map->lap_count,
            'nbchecks' => (int) $map->cp_count,
            'mood'     => $map->mood,
            'env'      => $map->environment,
            'votes'    => $pairs,
            'tmx'      => '',
        ]);

        return $this->request($url, "vote:{$map->uid}");
    }

    private function identity(): array
    {
        return ['login' => config('karma.sync.login'), 'authcode' => $this->authcode];
    }

    private function request(string $url, string $what): PromiseInterface
    {
        return $this->http->get($url)->then(function (ResponseInterface $response) {
            $xml = @simplexml_load_string((string) $response->getBody());
            if (!$xml || (int) $xml->status !== 200) {
                // The service refused us (e.g. expired authcode): authenticate again later.
                if ($this->authcode !== null) {
                    $this->authcode = null;
                    $this->retryLater();
                }
                throw new \RuntimeException('mania-karma.com answered status '.($xml ? (int) $xml->status : 'unreadable'));
            }

            return $xml;
        })->catch(function (\Throwable $e) use ($what) {
            $this->warnOnce($what, $e);
            throw $e;
        });
    }

    private function retryLater(): void
    {
        $this->retryAt = now()->timestamp + 60 * (int) config('karma.sync.retry_minutes', 10);
    }

    private function warnOnce(string $what, \Throwable $e): void
    {
        $key = explode(':', $what)[1] ?? $what;
        if ($this->failedFor !== $key) {
            $this->failedFor = $key;
            Log::warning("[Karma] mania-karma.com $what failed: ".$e->getMessage());
        }
    }
}
