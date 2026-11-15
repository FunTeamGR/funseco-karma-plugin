<?php
namespace Funseco\Karma;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Chat\Facades\Chat;

/** Widget / reminder vote buttons run the vote command. */
class VoteAction
{
    public function handle(Player $player, string $action): void
    {
        if ($command = KarmaWidget::actions()[$action] ?? null) {
            Chat::run($player, $command);
        }
    }
}
