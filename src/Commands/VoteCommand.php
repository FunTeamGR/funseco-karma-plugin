<?php
namespace Funseco\Karma\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Karma\KarmaBoard;

/** /+++ ... /---: one instance per level. */
class VoteCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name;
    protected string $description;

    public function __construct(private int $score)
    {
        $this->name = str_repeat($score > 0 ? '+' : '-', abs($score));
        $this->description = 'Set "'.ucfirst(config("karma.levels.$score")).'" karma for the current Map';
    }

    public function handle(Player $player, array $args): void
    {
        app(KarmaBoard::class)->vote($player, $this->score);
    }
}
