<?php
namespace Funseco\Karma;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Events\DatabaseWipeRequested;
use Funseco\Core\SharedKernel\Events\KarmaClearRequested;
use Funseco\Core\SharedKernel\Events\MapDataPurgeRequested;
use Funseco\Core\SharedKernel\Events\PlayerDataPurgeRequested;

/** /dbtools: delete karma votes (all, one map, one player) and refresh the live board. */
class KarmaMaintenance
{
    public function clear(KarmaClearRequested $event): void
    {
        if (!$event->all && !$event->map && !$event->player) {
            return;   // clearing everything must be asked for explicitly
        }
        $query = KarmaVote::query();
        $event->map && $query->where('map_id', $event->map->id);
        $event->player && $query->where('player_id', $event->player->id);
        $query->delete();
        $this->reload();
    }

    public function purgePlayerData(PlayerDataPurgeRequested $event): void
    {
        KarmaVote::where('player_id', $event->player->id)->delete();
        $this->reload();
    }

    public function purgeMapData(MapDataPurgeRequested $event): void
    {
        KarmaVote::where('map_id', $event->map->id)->delete();
        if (CurrentMap::get()?->id === $event->map->id) {
            $this->reload();
        }
    }

    public function wipe(DatabaseWipeRequested $event): void
    {
        KarmaVote::query()->delete();
        $this->reload();
    }

    private function reload(): void
    {
        if ($map = CurrentMap::get()) {
            app(KarmaBoard::class)->load($map);
            app(KarmaWidget::class)->showToAll();
        }
    }
}
