<?php

namespace JoanFo\DiscordWebhooks\Support;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Stringable;

/**
 * Framework-free rendering and sanitising of the stored Discord payload template.
 *
 * Placeholders are substituted inside each string value, never in the JSON text, so a
 * value containing quotes, newlines or backslashes cannot break or inject into the
 * message. The rendered result is then trimmed down to what Discord accepts.
 */
final class PayloadRenderer
{
    private const ELLIPSIS = '…';

    /**
     * Render, then sanitise. A missing template is treated as an empty one, which ends up
     * as the default content message.
     *
     * @param  array<mixed, mixed>|null  $template
     * @param  array<mixed, mixed>  $eventData
     * @return array<string, mixed>
     */
    public static function prepare(?array $template, array $eventData, DateTimeInterface $now): array
    {
        return self::sanitize(self::render($template ?? [], $eventData), $eventData, $now);
    }

    /**
     * Replace `{{ path }}` placeholders in every string value of the template. Array keys
     * are left alone and non-string scalars pass through unchanged.
     *
     * @param  array<mixed, mixed>  $template
     * @param  array<mixed, mixed>  $eventData
     * @return array<mixed, mixed>
     */
    public static function render(array $template, array $eventData): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::render($value, $eventData);
            } elseif (is_string($value)) {
                $result[$key] = self::renderString($value, $eventData);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Make a rendered payload acceptable to Discord.
     *
     * @param  array<mixed, mixed>  $payload
     * @param  array<mixed, mixed>  $eventData
     * @return array<string, mixed>
     */
    public static function sanitize(array $payload, array $eventData, DateTimeInterface $now): array
    {
        $payload = PayloadArray::withoutBlank($payload);
        $out = array_intersect_key($payload, array_flip(Discord::PAYLOAD_KEYS));

        foreach (['content' => Discord::LIMIT_CONTENT, 'username' => Discord::LIMIT_USERNAME, 'thread_name' => Discord::LIMIT_THREAD_NAME] as $key => $limit) {
            self::assign($out, $key, self::text($payload[$key] ?? null, $limit));
        }

        // Discord refuses usernames containing these, so fall back to the webhook's own name
        if (isset($out['username']) && !Discord::isAllowedUsername($out['username'])) {
            unset($out['username']);
        }

        self::assign($out, 'avatar_url', self::url($payload['avatar_url'] ?? null));
        self::assign($out, 'tts', self::bool($payload['tts'] ?? null));
        self::assign($out, 'applied_tags', self::appliedTags($payload['applied_tags'] ?? null));

        $embeds = self::embeds($payload['embeds'] ?? null, $now);
        if ($embeds !== []) {
            $out['embeds'] = $embeds;
        } else {
            unset($out['embeds']);
        }

        if (array_key_exists('flags', $payload)) {
            $flags = self::flags($payload['flags'], $embeds !== []);
            self::assign($out, 'flags', $flags === 0 ? null : $flags);
        }

        $out['allowed_mentions'] = self::allowedMentions($payload['allowed_mentions'] ?? null);

        if (!isset($out['content']) && $embeds === []) {
            $event = $eventData['event'] ?? null;
            $event = (is_string($event) || is_int($event) || $event instanceof Stringable) && trim((string) $event) !== '' ? (string) $event : 'unknown';

            $out['content'] = self::truncate('Pelican webhook event: ' . $event, Discord::LIMIT_CONTENT);
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $eventData
     */
    private static function renderString(string $subject, array $eventData): string
    {
        $rendered = preg_replace_callback(
            '/{{(.*?)}}/',
            function (array $matches) use ($eventData): string {
                $path = trim($matches[1]);
                $value = self::lookup($eventData, $path, $found);

                return $found ? self::stringify($value) : $path;
            },
            $subject
        ) ?? $subject;

        // Event data is not always valid UTF-8 and json_encode() refuses the whole payload
        // over a single bad byte
        return mb_scrub($rendered, 'UTF-8');
    }

    /**
     * Dot notation lookup into nested arrays and objects.
     */
    private static function lookup(mixed $target, string $path, ?bool &$found): mixed
    {
        $found = true;

        foreach (explode('.', $path) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif ($target instanceof ArrayAccess && $target->offsetExists($segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                $found = false;

                return null;
            }
        }

        return $target;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Stringable, is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '',
        };
    }

    /** @param  array<string, mixed>  $target */
    private static function assign(array &$target, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($target[$key]);
        } else {
            $target[$key] = $value;
        }
    }

    /**
     * A scalar as a non-blank, valid UTF-8 string within the limit, otherwise null.
     */
    private static function text(mixed $value, int $limit): ?string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = mb_scrub($value, 'UTF-8');

        return trim($value) === '' ? null : self::truncate($value, $limit);
    }

    private static function url(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return Url::isHttp($value) ? $value : null;
    }

    /**
     * Multibyte safe; the ellipsis counts towards the limit.
     */
    private static function truncate(string $value, int $limit): string
    {
        if ($limit < 1) {
            return '';
        }

        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1, 'UTF-8') . self::ELLIPSIS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function embeds(mixed $embeds, DateTimeInterface $now): array
    {
        if (!is_array($embeds)) {
            return [];
        }

        $result = [];
        foreach ($embeds as $embed) {
            $embed = is_array($embed) ? self::embed($embed, $now) : null;
            if ($embed === null) {
                continue;
            }

            $result[] = $embed;
            if (count($result) >= Discord::MAX_EMBEDS) {
                break;
            }
        }

        $result = self::fitEmbedTotal($result);

        return array_values(array_filter($result, self::hasContent(...)));
    }

    /**
     * @param  array<mixed, mixed>  $embed
     * @return array<string, mixed>|null
     */
    private static function embed(array $embed, DateTimeInterface $now): ?array
    {
        $out = array_intersect_key($embed, array_flip(Discord::EMBED_KEYS));

        self::assign($out, 'title', self::text($embed['title'] ?? null, Discord::LIMIT_EMBED_TITLE));
        self::assign($out, 'description', self::text($embed['description'] ?? null, Discord::LIMIT_EMBED_DESCRIPTION));
        self::assign($out, 'url', self::url($embed['url'] ?? null));
        self::assign($out, 'color', Color::parse($embed['color'] ?? null));
        self::assign($out, 'timestamp', self::timestamp($embed['timestamp'] ?? null));

        if (filter_var($embed['has_timestamp'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $out['timestamp'] = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        }

        $author = is_array($embed['author'] ?? null) ? $embed['author'] : [];
        $name = self::text($author['name'] ?? null, Discord::LIMIT_AUTHOR_NAME);
        unset($out['author']);
        if ($name !== null) {
            $out['author'] = array_filter([
                'name' => $name,
                'url' => self::url($author['url'] ?? null),
                'icon_url' => self::url($author['icon_url'] ?? null),
            ], fn (mixed $value) => $value !== null);
        }

        $footer = is_array($embed['footer'] ?? null) ? $embed['footer'] : [];
        $text = self::text($footer['text'] ?? null, Discord::LIMIT_FOOTER_TEXT);
        unset($out['footer']);
        if ($text !== null) {
            $out['footer'] = array_filter([
                'text' => $text,
                'icon_url' => self::url($footer['icon_url'] ?? null),
            ], fn (mixed $value) => $value !== null);
        }

        foreach (['image', 'thumbnail'] as $key) {
            $url = is_array($embed[$key] ?? null) ? self::url($embed[$key]['url'] ?? null) : null;
            unset($out[$key]);
            if ($url !== null) {
                $out[$key] = ['url' => $url];
            }
        }

        $fields = [];
        foreach (is_array($embed['fields'] ?? null) ? $embed['fields'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldName = self::text($field['name'] ?? null, Discord::LIMIT_FIELD_NAME);
            $fieldValue = self::text($field['value'] ?? null, Discord::LIMIT_FIELD_VALUE);
            if ($fieldName === null || $fieldValue === null) {
                continue;
            }

            $entry = ['name' => $fieldName, 'value' => $fieldValue];
            $inline = array_key_exists('inline', $field) ? filter_var($field['inline'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if ($inline !== null) {
                $entry['inline'] = $inline;
            }

            $fields[] = $entry;
            if (count($fields) >= Discord::MAX_FIELDS) {
                break;
            }
        }

        unset($out['fields']);
        if ($fields !== []) {
            $out['fields'] = $fields;
        }

        return self::hasContent($out) ? $out : null;
    }

    /**
     * @param  array<string, mixed>  $embed
     */
    private static function hasContent(array $embed): bool
    {
        foreach (['title', 'description', 'fields', 'author', 'image', 'thumbnail', 'footer'] as $key) {
            // isset() on purpose: a title of "0" is content, and keys are unset when blank
            if (isset($embed[$key])) {
                return true;
            }
        }

        return false;
    }

    private static function bool(mixed $value): ?bool
    {
        return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    }

    /**
     * Forum tag ids: unique digit strings, at most as many as Discord allows.
     *
     * @return list<string>|null
     */
    private static function appliedTags(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            if ((is_string($id) || is_int($id)) && preg_match('/\A\d{1,20}\z/', (string) $id) === 1 && !in_array((string) $id, $ids, true)) {
                $ids[] = (string) $id;
            }
        }

        return $ids === [] ? null : array_slice($ids, 0, Discord::MAX_APPLIED_TAGS);
    }

    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private static function flags(mixed $value, bool $hasEmbeds): int
    {
        $flags = is_numeric($value) || is_bool($value) ? (int) $value : 0;
        $flags &= Discord::FLAGS_ALLOWED;

        // Embeds were requested, so never let SUPPRESS_EMBEDS hide them
        if ($hasEmbeds) {
            $flags &= ~Discord::FLAG_SUPPRESS_EMBEDS;
        }

        return $flags;
    }

    /**
     * Without an explicit allowed_mentions Discord parses @everyone, @here, roles and users
     * out of the text, so default to parsing nothing.
     *
     * @return array<string, mixed>
     */
    private static function allowedMentions(mixed $value): array
    {
        if (!is_array($value)) {
            return ['parse' => []];
        }

        $parse = [];
        foreach (is_array($value['parse'] ?? null) ? $value['parse'] : [] as $type) {
            if (is_string($type) && in_array($type, Discord::MENTION_TYPES, true) && !in_array($type, $parse, true)) {
                $parse[] = $type;
            }
        }

        $result = [];

        // Discord rejects parsing a type and listing ids of that same type together
        foreach (['users', 'roles'] as $key) {
            $ids = [];
            foreach (is_array($value[$key] ?? null) ? $value[$key] : [] as $id) {
                if ((is_string($id) || is_int($id)) && preg_match('/^\d{1,20}$/', (string) $id) === 1 && !in_array((string) $id, $ids, true)) {
                    $ids[] = (string) $id;
                }
            }

            if ($ids !== []) {
                $result[$key] = array_slice($ids, 0, Discord::MAX_MENTION_IDS);
                $parse = array_values(array_diff($parse, [$key]));
            }
        }

        if (array_key_exists('replied_user', $value)) {
            $replied = filter_var($value['replied_user'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($replied !== null) {
                $result['replied_user'] = $replied;
            }
        }

        return ['parse' => $parse] + $result;
    }

    /**
     * Discord rejects a message whose embeds hold more than 6000 characters of text in total.
     * Shorten descriptions starting from the last embed, then drop trailing fields, and as a
     * last resort trailing embeds.
     *
     * @param  list<array<string, mixed>>  $embeds
     * @return list<array<string, mixed>>
     */
    private static function fitEmbedTotal(array $embeds): array
    {
        $total = self::totalLength($embeds);

        for ($i = count($embeds) - 1; $i >= 0 && $total > Discord::MAX_EMBED_TOTAL; $i--) {
            if (!isset($embeds[$i]['description'])) {
                continue;
            }

            $length = mb_strlen($embeds[$i]['description'], 'UTF-8');
            $shortened = self::truncate($embeds[$i]['description'], max(0, $length - ($total - Discord::MAX_EMBED_TOTAL)));

            if ($shortened === '') {
                unset($embeds[$i]['description']);
            } else {
                $embeds[$i]['description'] = $shortened;
            }

            $total = self::totalLength($embeds);
        }

        for ($i = count($embeds) - 1; $i >= 0 && $total > Discord::MAX_EMBED_TOTAL; $i--) {
            while (($embeds[$i]['fields'] ?? []) !== [] && $total > Discord::MAX_EMBED_TOTAL) {
                array_pop($embeds[$i]['fields']);
                $total = self::totalLength($embeds);
            }

            if (($embeds[$i]['fields'] ?? []) === []) {
                unset($embeds[$i]['fields']);
            }
        }

        while (count($embeds) > 1 && $total > Discord::MAX_EMBED_TOTAL) {
            array_pop($embeds);
            $total = self::totalLength($embeds);
        }

        return $embeds;
    }

    /**
     * @param  list<array<string, mixed>>  $embeds
     */
    private static function totalLength(array $embeds): int
    {
        $total = 0;

        foreach ($embeds as $embed) {
            $parts = [$embed['title'] ?? '', $embed['description'] ?? '', $embed['footer']['text'] ?? '', $embed['author']['name'] ?? ''];
            foreach ($embed['fields'] ?? [] as $field) {
                $parts[] = $field['name'];
                $parts[] = $field['value'];
            }

            foreach ($parts as $part) {
                $total += mb_strlen((string) $part, 'UTF-8');
            }
        }

        return $total;
    }
}
