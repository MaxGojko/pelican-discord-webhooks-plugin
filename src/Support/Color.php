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
     * The one parser for every colour that is not typed into the picker: a stored or
     * rendered value. Accepts the integer Discord uses (or an integral float), a digit
     * string as that same decimal number, and anything fromHex() accepts. A string made
     * only of digits is therefore decimal, never hex; the picker always adds the `#`.
     * Out of range and unusable values are null.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        if (is_string($value) && preg_match('/\A\s*\d{1,8}\s*\z/', $value) === 1) {
            $value = (int) trim($value);
        }

        if (is_string($value)) {
            return self::fromHex($value);
        }

        return is_int($value) && $value >= 0 && $value <= self::MAX ? $value : null;
    }

    /**
     * Format a stored colour as a lowercase, zero padded `#rrggbb`. Accepts the integer
     * Discord uses, or the same number as a digit string. A string starting with `#` is
     * treated as hex so a hand written payload still opens in the picker. Missing or
     * out of range values are null.
     */
    public static function toHex(mixed $value): ?string
    {
        $color = self::parse($value);

        return $color === null ? null : '#' . str_pad(dechex($color), 6, '0', STR_PAD_LEFT);
    }
}
