<?php
namespace Funseco\Karma;

use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\KarmaStats;

/** Local karma figures for other plugins (/list karma filters, scoreboard top tracks / voters). */
class DatabaseKarmaStats implements KarmaStats
{
    public function karmaByMap(): array
    {
        return KarmaVote::groupBy('map_id')->selectRaw('map_id, SUM(score) as karma')
            ->pluck('karma', 'map_id')->map(fn ($k) => (int) $k)->all();
    }

    public function mapKarma(Map $map): ?int
    {
        $sum = KarmaVote::where('map_id', $map->id)->sum('score');

        return KarmaVote::where('map_id', $map->id)->exists() ? (int) $sum : null;
    }

    public function votesByMap(): array
    {
        $votes = [];
        foreach (KarmaVote::groupBy('map_id', 'score')->selectRaw('map_id, score, COUNT(*) as votes')->get() as $row) {
            $votes[(int) $row->map_id][(int) $row->score] = (int) $row->votes;
        }

        return $votes;
    }

    public function votedMapIds(Player $player): array
    {
        return KarmaVote::where('player_id', $player->id)->pluck('map_id')->map(fn ($id) => (int) $id)->all();
    }

    public function voteCounts(int $limit): array
    {
        return KarmaVote::groupBy('player_id')->selectRaw('player_id, COUNT(*) as votes')
            ->orderByDesc('votes')->orderBy('player_id')->limit($limit)
            ->pluck('votes', 'player_id')->map(fn ($n) => (int) $n)->all();
    }
}
