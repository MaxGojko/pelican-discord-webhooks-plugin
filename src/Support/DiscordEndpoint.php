<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free check for "is this URL a Discord webhook endpoint".
 *
 * A substring match on the whole URL also accepts lookalike hosts and URLs that only
 * mention Discord in their path or query, so the URL is parsed and the host and path
 * are compared strictly instead.
 */
final class DiscordEndpoint
{
    private const HOSTS = [
        'discord.com',
        'discordapp.com',
        'canary.discord.com',
        'canary.discordapp.com',
        'ptb.discord.com',
        'ptb.discordapp.com',
    ];

    /** `/api/webhooks/<id>` or `/api/v<digits>/webhooks/<id>`, optionally followed by more path. */
    private const PATH_PATTERN = '#^/api/(?:v\d+/)?webhooks/\d+(?:/|$)#';

    public static function matches(mixed $url): bool
    {
        if (!is_string($url)) {
            return false;
        }

        $url = trim($url);

        // Whitespace, control characters and backslashes are parsed differently by PHP and
        // by HTTP clients, which is what userinfo/host confusion tricks rely on.
        if ($url === '' || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        // Discord webhook URLs never carry credentials.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) {
            return false;
        }

        $host = rtrim(strtolower($parts['host'] ?? ''), '.');
        if (!in_array($host, self::HOSTS, true)) {
            return false;
        }

        return preg_match(self::PATH_PATTERN, $parts['path'] ?? '') === 1;
    }
}
