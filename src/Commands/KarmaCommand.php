<?php
namespace Funseco\Karma\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Karma\KarmaBoard;
use Funseco\Karma\Messages;

class KarmaCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'karma';
    protected string $description = 'Shows karma for the current Map (see: /karma help)';
    protected string $usage = '[help|details]';

    public function handle(Player $player, array $args): void
    {
        $sub = strtolower($args[0] ?? '');
        if ($sub === 'help') {
            $lines = array_map(fn ($line) => [format_colors($line)], explode('{br}', config('karma.messages.help')));
            Window::show($player, 'ManiaKarma help', $lines, [1.1], ['Icons64x64_1', 'TrackInfo', -0.01]);
            return;
        }

        if ($message = app(KarmaBoard::class)->message($player, force: $sub === 'details')) {
            Messages::tell($player, $message);
        }
    }
}
