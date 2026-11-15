<?php
namespace Funseco\Karma;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\TrackExchange;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * Karma widget (XAseco ManiaKarma race widget, simplified): karma value, vote count and the
 * six vote buttons; the player's own vote is highlighted. Buttons run the vote commands.
 */
class KarmaWidget
{
    public const NAME = 'karma';

    private const PODIUM = 'karma.podium';
    private const COLOURS = [3 => '0C0F', 2 => '6C0F', 1 => 'AC0F', -1 => 'FA0F', -2 => 'F60F', -3 => 'F00F'];
    /** The column starts at x 49.2 and the screen ends at 64: keep the content within 14.8. */
    private const BAR = 14.0;
    private const BUTTON = 2.2;
    private const PITCH = 2.3;
    private const BUTTONS = [3 => '+++', 2 => '++', 1 => '+', -1 => '-', -2 => '--', -3 => '---'];

    public static function register(WidgetRegistry $registry): void
    {
        // Pinned per gamemode in config/manialinks.php (karma.gamemode.*), above local records.
        $registry->regionWidget(self::NAME, \Funseco\Core\SharedKernel\UI\Region::MidRight, 0, width: 15.5, height: 14.0);
    }

    /** Vote buttons: action name => command. */
    public static function actions(): array
    {
        $actions = [];
        foreach (self::BUTTONS as $score => $command) {
            $actions["karma_vote_$score"] = $command;
        }

        return $actions;
    }

    /** The podium (score screen) shows the vote reminder instead: hide the widget until the next map. */
    public function enterPodium(): void
    {
        state()->forever(self::PODIUM, true);
        $this->hideFromAll();
    }

    public function leavePodium(): void
    {
        state()->forget(self::PODIUM);
    }

    public function showToAll(): void
    {
        foreach (CurrentPlayers::all() as $player) {
            $this->show($player);
        }
    }

    public function show(Player $player): void
    {
        if (state()->get(self::PODIUM, false)) {
            return;
        }
        if ($xml = $this->xml($player)) {
            ManiaLink::displayLogin($player, $xml);
        }
    }

    public function hideFromAll(): void
    {
        ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction(self::NAME).'"></manialink>');
    }

    public function xml(Player $player): ?string
    {
        $placement = app(WidgetRegistry::class)->placement(self::NAME);
        if (!$placement) {
            return null;
        }
        $board = app(KarmaBoard::class);
        $tally = $board->tally();
        $mine = $board->voteOf($player->login);

        $tmx = app(TrackExchange::class)->current();
        $xml = '<manialink id="'.ManiaLink::getAction(self::NAME).'"><frame posn="'.$placement->x.' '.$placement->y.' 0">'
            .'<quad posn="0 0 0.001" sizen="15.5 14" style="Bgs1InRace" substyle="NavButton"/>'
            .'<quad posn="0.4 -0.36 0.002" sizen="14.7 2" style="BgsPlayerCard" substyle="BgRacePlayerName"/>'
            .'<quad posn="0.6 0 0.004" sizen="2.5 2.5" style="Icons64x64_1" substyle="ToolLeague1"/>'
            .'<label posn="3.2 -0.55 0.004" sizen="9.5 0" textsize="1" text="$FFFManiaKarma"/>'
            .($tmx && $tmx->pageUrl !== ''
                ? '<quad posn="12.7 -0.15 0.005" sizen="2.3 1.7" style="Icons128x128_1" substyle="Challenge" url="'.htmlspecialchars($tmx->pageUrl, ENT_QUOTES).'"/>'
                : '')
            .'<label posn="7.75 -3 0.004" sizen="14 0" halign="center" textsize="3" text="$o$FFF'.$tally->karma().'"/>'
            .'<label posn="7.75 -5.4 0.004" sizen="14 0" halign="center" textsize="1" scale="0.8" text="$FFF'.$tally->total().' '.($tally->total() === 1 ? 'vote' : 'votes').'"/>';

        // Vote distribution: one bar, a segment per level (+++ first), with the counts below.
        $total = $tally->total();
        $x = 0.4;
        $xml .= '<quad posn="0.4 -7.2 0.002" sizen="'.self::BAR.' 1.1" bgcolor="0008"/>';
        foreach (self::BUTTONS as $score => $label) {
            $w = $total ? self::BAR * $tally->count($score) / $total : 0;
            if ($w > 0) {
                $xml .= '<quad posn="'.round($x, 3).' -7.2 0.003" sizen="'.round($w, 3).' 1.1" bgcolor="'.self::COLOURS[$score].'"/>';
            }
            $x += $w;
        }
        $x = 0.5;
        foreach (self::BUTTONS as $score => $label) {
            $xml .= '<label posn="'.($x + self::BUTTON / 2).' -8.5 0.004" sizen="'.self::BUTTON.' 0" halign="center" textsize="1" scale="0.7" text="$FFF'.$tally->count($score).'"/>';
            $x += self::PITCH;
        }

        // Pale green/red buttons like XAseco's; the player's own vote is drawn stronger.
        $x = 0.5;
        foreach (self::BUTTONS as $score => $label) {
            $colour = $score > 0 ? '$070' : '$700';
            $fill = $score > 0 ? ($mine === $score ? '8E8F' : 'CFCC') : ($mine === $score ? 'F88F' : 'FCCC');
            $xml .= '<quad posn="'.$x.' -10.7 0.003" sizen="'.self::BUTTON.' '.self::BUTTON.'" bgcolor="'.$fill.'" action="'.ManiaLink::getAction("karma_vote_$score").'"/>'
                .'<label posn="'.($x + self::BUTTON / 2).' -11.1 0.004" sizen="'.self::BUTTON.' 0" halign="center" textsize="1" text="$o'.$colour.$label.'"/>';
            $x += self::PITCH;
        }

        return $xml.'</frame></manialink>';
    }

    /** End-of-race reminder for players who haven't voted (XAseco reminder window at score). */
    public function remind(Player $player): void
    {
        $pos = config('manialinks.karma.reminder.pos_x').' '.config('manialinks.karma.reminder.pos_y');
        $xml = '<manialink id="'.ManiaLink::getAction('karma_reminder').'"><frame posn="'.$pos.' 0">'
            .'<quad sizen="82 4.4" style="Bgs1InRace" substyle="NavButton"/>'
            .'<label posn="2 -1.2 0.01" sizen="26 0" textsize="2" text="$FFF'.htmlspecialchars(config('karma.messages.reminder')).'"/>';
        $x = 30;
        foreach (self::BUTTONS as $score => $label) {
            $xml .= '<quad posn="'.$x.' -0.7 0.01" sizen="8 3" style="Bgs1InRace" substyle="BgCard" action="'.ManiaLink::getAction("karma_vote_$score").'"/>'
                .'<label posn="'.($x + 4).' -1.2 0.02" sizen="8 0" halign="center" textsize="1" text="$o$000'.ucfirst(config("karma.levels.$score")).'"/>';
            $x += 8.5;
        }
        ManiaLink::displayLogin($player, $xml.'</frame></manialink>');
    }

    public function closeReminders(): void
    {
        ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction('karma_reminder').'"></manialink>');
    }
}
