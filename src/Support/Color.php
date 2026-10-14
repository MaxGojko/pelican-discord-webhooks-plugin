<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free conversion between the hex strings the colour picker works with
 * and the integer Discord stores in an embed's `color`.
 */
final class Color
{
    /** Highest value Discord accepts for an embed colour (#ffffff). */
    public const MAX = 0xFFFFFF;

    /**
     * Parse `#rgb` or `#rrggbb`, with or without the leading `#` and in any case.
     * Anything else, including blank input, is null so that black (0) stays distinct
     * from "no colour".
     */
    public static function fromHex(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $hex = trim($value);

        if (str_starts_with($hex, '#')) {
            $hex = substr($hex, 1);
        }

        if (preg_match('/\A(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i', $hex) !== 1) {
            return null;
        }

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return (int) hexdec($hex);
    }

    /**
     * Format a stored colour as a lowercase, zero padded `#rrggbb`. Accepts the integer
     * Discord uses, or the same number as a digit string. A string starting with `#` is
     * treated as hex so a hand written payload still opens in the picker. Missing or
     * out of range values are null.
     */
    public static function toHex(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if (str_starts_with($trimmed, '#')) {
                $value = self::fromHex($trimmed);
            } elseif (preg_match('/\A\d{1,8}\z/', $trimmed) === 1) {
                $value = (int) $trimmed;
            } else {
                return null;
            }
        }

        if (!is_int($value) || $value < 0 || $value > self::MAX) {
            return null;
        }

        return '#' . str_pad(dechex($value), 6, '0', STR_PAD_LEFT);
    }
}
