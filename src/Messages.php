<?php
namespace Funseco\Karma;

use Funseco\Core\Models\Player;

final class Messages
{
    /** Message with {n} filled in, colour tokens left for the caller. */
    public static function raw(string $key, string|int ...$values): string
    {
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr((string) config("karma.messages.$key", ''), $map);
    }

    public static function get(string $key, string|int ...$values): string
    {
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr(format_colors((string) config("karma.messages.$key", '')), $map);   // values must not expand {#..}
    }

    public static function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }

    public static function stripColors(?string $text): string
    {
        $text = str_replace('$$', "\0", (string) $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }
}
