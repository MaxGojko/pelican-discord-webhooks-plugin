<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free rules of the Discord webhook API, in one place so the form, the API
 * validation rules, the payload mapping and the renderer cannot drift apart.
 */
final class Discord
{
    /** Message flag: SUPPRESS_EMBEDS */
    public const FLAG_SUPPRESS_EMBEDS = 1 << 2;

    /** Message flag: SUPPRESS_NOTIFICATIONS */
    public const FLAG_SUPPRESS_NOTIFICATIONS = 1 << 12;

    /** Every flag a webhook message may carry; other bits are never delivered. */
    public const FLAGS_ALLOWED = self::FLAG_SUPPRESS_EMBEDS | self::FLAG_SUPPRESS_NOTIFICATIONS;

    /** Mention types `allowed_mentions.parse` can hold, in the order the form lists them. */
    public const MENTION_TYPES = ['roles', 'users', 'everyone'];

    /** Keys of `allowed_mentions` that hold ids or switches instead of parse types. */
    public const MENTION_EXTRA_KEYS = ['users', 'roles', 'replied_user'];

    /** Keys Discord accepts at the top level of a payload and this plugin delivers. */
    public const PAYLOAD_KEYS = [
        'content',
        'username',
        'avatar_url',
        'tts',
        'embeds',
        'allowed_mentions',
        'flags',
        'thread_name',
        'applied_tags',
    ];

    /** Keys of an embed this plugin delivers. */
    public const EMBED_KEYS = [
        'title',
        'description',
        'url',
        'timestamp',
        'color',
        'footer',
        'image',
        'thumbnail',
        'author',
        'fields',
    ];

    /** Words Discord refuses in a webhook username, compared case insensitively. */
    public const FORBIDDEN_USERNAME_WORDS = ['discord', 'clyde'];

    public const LIMIT_CONTENT = 2000;

    public const LIMIT_USERNAME = 80;

    public const LIMIT_THREAD_NAME = 100;

    public const LIMIT_EMBED_TITLE = 256;

    public const LIMIT_EMBED_DESCRIPTION = 4096;

    public const LIMIT_AUTHOR_NAME = 256;

    public const LIMIT_FOOTER_TEXT = 2048;

    public const LIMIT_FIELD_NAME = 256;

    public const LIMIT_FIELD_VALUE = 1024;

    public const MAX_EMBEDS = 10;

    public const MAX_FIELDS = 25;

    /** Combined size of all embed text Discord accepts in one message. */
    public const MAX_EMBED_TOTAL = 6000;

    public const MAX_MENTION_IDS = 100;

    public const MAX_APPLIED_TAGS = 5;

    /** Whether Discord would accept the name as a webhook username. */
    public static function isAllowedUsername(string $username): bool
    {
        foreach (self::FORBIDDEN_USERNAME_WORDS as $word) {
            if (mb_stripos($username, $word) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Same check for a template that may hold `{{variable}}` placeholders. Only the
     * literal text is checked, since what a placeholder expands to is not known until
     * an event fires; delivery checks the rendered name again.
     */
    public static function isAllowedUsernameTemplate(string $template): bool
    {
        return self::isAllowedUsername(preg_replace('/{{.*?}}/', '', $template) ?? $template);
    }
}
