<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free mapping between the edit form state and the payload stored on a
 * webhook configuration. DiscordSchema delegates to this so the round trip can be
 * tested without a panel install.
 *
 * Form state and payload deliberately differ in a few places:
 *  - the embed colour is a `#rrggbb` string in the form and an integer in the payload;
 *  - the flag and allowed mention checkboxes are lists in the form and a bit field and
 *    an `allowed_mentions.parse` list in the payload;
 *  - payload keys the form has no field for (`tts`, `applied_tags`, mention ids, an
 *    embed `timestamp`) travel through the form in a hidden `_extra` entry, at the top
 *    level and inside each embed, so editing a payload that was created through the API
 *    does not silently delete them. Only keys on the Discord allowlist are carried, and
 *    a key the form does model always wins over its `_extra` copy.
 */
final class DiscordPayload
{
    /** Form state key holding the payload data the form has no field for. */
    public const EXTRA = '_extra';

    /** Top level payload keys that have a form field. */
    private const MODELLED_KEYS = [
        'username',
        'avatar_url',
        'content',
        'embeds',
        'thread_name',
        'flags',
        'allowed_mentions',
    ];

    /** Embed keys that have a form field. */
    private const MODELLED_EMBED_KEYS = [
        'author',
        'title',
        'description',
        'color',
        'url',
        'image',
        'thumbnail',
        'footer',
        'fields',
    ];

    /** Top level form keys that live inside the stored payload. */
    public const FIELDS = [
        'username',
        'avatar_url',
        'content',
        'embeds',
        'thread_name',
        'flags',
        'allowed_mentions',
        self::EXTRA,
    ];

    /**
     * Whether the data holds any of the form fields. The panel always submits all of
     * them, while the API sends a ready made `payload` or only unrelated attributes,
     * such as a PATCH renaming the webhook. Only the first case may be collapsed,
     * rebuilding a payload from fields that were never sent would empty it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function hasFormFields(array $data): bool
    {
        return array_intersect_key($data, array_flip(self::FIELDS)) !== [];
    }

    /**
     * Collapse form state into the stored payload.
     *
     * `allowed_mentions.parse` is always present, an empty list when nothing is ticked,
     * because leaving it out makes Discord ping everything the message mentions.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        $payload = PayloadArray::withoutBlank([
            'username' => self::text($data['username'] ?? null),
            'avatar_url' => self::text($data['avatar_url'] ?? null),
            'content' => self::text($data['content'] ?? null),
            'embeds' => self::embeds($data['embeds'] ?? null, fn (mixed $color): ?int => Color::fromHex($color), false),
            'thread_name' => self::text($data['thread_name'] ?? null),
            'flags' => self::flagsFromForm($data['flags'] ?? null) ?: null,
        ]);

        $extra = is_array($data[self::EXTRA] ?? null) ? $data[self::EXTRA] : [];

        $payload['allowed_mentions'] = ['parse' => self::mentions($data['allowed_mentions'] ?? null)]
            + self::keep($extra['allowed_mentions'] ?? null, Discord::MENTION_EXTRA_KEYS);

        return $payload + self::keep($extra, array_diff(Discord::PAYLOAD_KEYS, self::MODELLED_KEYS));
    }

    /**
     * Expand a stored payload into form state, the inverse of fromForm().
     *
     * The flags are returned as strings, which is how the CheckboxList holds them in
     * its state: its state cast turns option keys into strings when the form is filled
     * and back into integers only when the state is read. Matching that avoids the
     * form seeing a value it has to convert.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function toForm(array $payload): array
    {
        // A stored bit field is the same as a list holding one combined flag
        $flags = self::flagsFromForm([$payload['flags'] ?? null]);
        $allowedMentions = $payload['allowed_mentions'] ?? null;

        return PayloadArray::withoutBlank([
            'username' => self::text($payload['username'] ?? null),
            'avatar_url' => self::text($payload['avatar_url'] ?? null),
            'content' => self::text($payload['content'] ?? null),
            'embeds' => self::embeds($payload['embeds'] ?? null, fn (mixed $color): ?string => Color::toHex($color), true),
            'thread_name' => self::text($payload['thread_name'] ?? null),
            self::EXTRA => self::extraFromPayload($payload),
        ]) + [
            'flags' => array_map(
                'strval',
                array_values(array_filter(
                    [Discord::FLAG_SUPPRESS_EMBEDS, Discord::FLAG_SUPPRESS_NOTIFICATIONS],
                    fn (int $flag): bool => ($flags & $flag) !== 0,
                )),
            ),
            'allowed_mentions' => self::mentions(is_array($allowedMentions) ? ($allowedMentions['parse'] ?? null) : null),
        ];
    }

    /**
     * OR together the ticked flags. The CheckboxList posts strings but its state is read
     * back as integers, so both are accepted. Bits other than the two the form offers
     * are ignored.
     */
    public static function flagsFromForm(mixed $selected): int
    {
        if (!is_array($selected)) {
            return 0;
        }

        $flags = 0;

        foreach ($selected as $bit) {
            if (is_int($bit) || (is_string($bit) && ctype_digit($bit))) {
                $flags |= (int) $bit & Discord::FLAGS_ALLOWED;
            }
        }

        return $flags;
    }

    /**
     * @return list<string>
     */
    private static function mentions(mixed $selected): array
    {
        if (!is_array($selected)) {
            return [];
        }

        return array_values(array_filter(
            Discord::MENTION_TYPES,
            fn (string $type): bool => in_array($type, $selected, true),
        ));
    }

    /**
     * Shared by both directions, which differ in how the colour is converted and in what
     * happens to the keys the form does not model: they move into `_extra` on the way to
     * the form and back out of it on the way to the payload.
     *
     * @param  \Closure(mixed): mixed  $color
     * @return list<array<string, mixed>>
     */
    private static function embeds(mixed $embeds, \Closure $color, bool $toForm): array
    {
        if (!is_array($embeds)) {
            return [];
        }

        $result = [];
        $extraKeys = array_diff(Discord::EMBED_KEYS, self::MODELLED_EMBED_KEYS);

        foreach ($embeds as $embed) {
            if (!is_array($embed)) {
                continue;
            }

            $extra = $toForm
                ? [self::EXTRA => self::keep($embed, $extraKeys)]
                : self::keep($embed[self::EXTRA] ?? null, $extraKeys);

            $embed = PayloadArray::withoutBlank([
                'author' => self::pick($embed['author'] ?? null, ['name', 'url', 'icon_url']),
                'title' => self::text($embed['title'] ?? null),
                'description' => self::text($embed['description'] ?? null),
                'color' => $color($embed['color'] ?? null),
                'url' => self::text($embed['url'] ?? null),
                'image' => self::pick($embed['image'] ?? null, ['url']),
                'thumbnail' => self::pick($embed['thumbnail'] ?? null, ['url']),
                'footer' => self::pick($embed['footer'] ?? null, ['text', 'icon_url']),
                'has_timestamp' => self::onlyTrue($embed['has_timestamp'] ?? null),
                'fields' => self::fields($embed['fields'] ?? null),
            ] + $extra);

            if ($embed !== []) {
                $result[] = $embed;
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $result = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $field = PayloadArray::withoutBlank([
                'name' => self::text($field['name'] ?? null),
                'value' => self::text($field['value'] ?? null),
                'inline' => self::onlyTrue($field['inline'] ?? null),
            ]);

            if ($field !== []) {
                $result[] = $field;
            }
        }

        return $result;
    }

    /**
     * Checkbox values are stored only when ticked, so an unticked box leaves no `false`
     * behind. The panel posts booleans, hand written payloads may use 1 or "1".
     */
    private static function onlyTrue(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true ? true : null;
    }

    /**
     * Keep only the listed string keys of a nested group such as `author` or `footer`.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function pick(mixed $group, array $keys): array
    {
        if (!is_array($group)) {
            return [];
        }

        $result = [];

        foreach ($keys as $key) {
            $value = self::text($group[$key] ?? null);

            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * The payload data the form has no field for, for the hidden `_extra` state.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function extraFromPayload(array $payload): array
    {
        $extra = self::keep($payload, array_diff(Discord::PAYLOAD_KEYS, self::MODELLED_KEYS));
        $mentions = self::keep($payload['allowed_mentions'] ?? null, Discord::MENTION_EXTRA_KEYS);

        return $mentions === [] ? $extra : $extra + ['allowed_mentions' => $mentions];
    }

    /**
     * Entries of an array under the listed keys, minus blank ones. Anything that is not
     * an array yields nothing. The form state is client supplied, so this is also what
     * stops `_extra` from smuggling in keys outside the allowlist.
     *
     * @param  iterable<array-key, string>  $keys
     * @return array<string, mixed>
     */
    private static function keep(mixed $source, iterable $keys): array
    {
        if (!is_array($source)) {
            return [];
        }

        return PayloadArray::withoutBlank(array_intersect_key($source, array_flip([...$keys])));
    }

    /**
     * Strings pass through and numbers are stringified. Anything else, and blank
     * strings, is null so it is dropped.
     */
    private static function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
