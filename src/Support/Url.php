<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free URL checks shared by payload rendering and the preview.
 */
final class Url
{
    /**
     * Whether the value is an absolute http(s) URL with a host, which is the only
     * kind of URL Discord accepts in avatar, embed, author, footer and image fields.
     */
    public static function isHttp(mixed $value): bool
    {
        if (!is_string($value) || $value === '' || preg_match('/\s/', $value)) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
