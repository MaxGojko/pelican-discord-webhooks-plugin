<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free conversion of an embed colour into a CSS colour that is safe to place
 * in a style attribute.
 */
final class EmbedColor
{
    /**
     * Accepts a Discord integer colour (0..16777215) or a `#rgb` / `#rrggbb` string and
     * returns a `#...` colour, or null for anything else.
     */
    public static function toCss(mixed $color): ?string
    {
        if (is_int($color)) {
            return $color >= 0 && $color <= 0xFFFFFF ? '#' . str_pad(dechex($color), 6, '0', STR_PAD_LEFT) : null;
        }

        if (is_string($color) && preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/D', $color) === 1) {
            return $color;
        }

        return null;
    }
}
