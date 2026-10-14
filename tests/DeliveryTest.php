<?php

use JoanFo\DiscordWebhooks\Support\DiscordPayload;
use JoanFo\DiscordWebhooks\Support\PayloadRenderer;

/**
 * Form state to delivered message, the path a webhook saved in the panel takes. The unit
 * tests of each half passed while the two together dropped an embed titled "0".
 */
function deliveredFromForm(array $form, array $event = ['event' => 'updated: Server']): array
{
    return PayloadRenderer::prepare(DiscordPayload::fromForm($form), $event, new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC')));
}

test('an embed whose only text is "0" survives saving and delivery', function () {
    $out = deliveredFromForm(['embeds' => ['uuid' => ['title' => '0', 'description' => null]]]);

    assertSame([['title' => '0']], $out['embeds']);
    assertFalse(isset($out['content']));
});

test('a form saved in the panel is delivered as configured', function () {
    $out = deliveredFromForm([
        'username' => 'Pelican',
        'content' => 'Server {{ name }}',
        'flags' => ['4', '4096'],
        'allowed_mentions' => ['users'],
        'embeds' => [[
            'title' => '{{ name }}',
            'color' => '#5865F2',
            'has_timestamp' => true,
            'footer' => ['text' => 'foot', 'icon_url' => 'not a url'],
            'fields' => [['name' => 'a', 'value' => '0', 'inline' => true]],
            DiscordPayload::EXTRA => ['timestamp' => '2020-01-01T00:00:00+00:00'],
        ]],
    ], ['name' => 'Survival', 'event' => 'e']);

    assertSame('Server Survival', $out['content']);
    assertSame(4096, $out['flags'], 'SUPPRESS_EMBEDS is cleared because embeds are delivered');
    assertSame(['parse' => ['users']], $out['allowed_mentions']);
    assertSame('Survival', $out['embeds'][0]['title']);
    assertSame(0x5865F2, $out['embeds'][0]['color']);
    assertSame('2026-01-02T03:04:05+00:00', $out['embeds'][0]['timestamp'], 'the ticked box wins over a stored timestamp');
    assertSame(['text' => 'foot'], $out['embeds'][0]['footer']);
    assertSame([['name' => 'a', 'value' => '0', 'inline' => true]], $out['embeds'][0]['fields']);
});

test('an empty form is delivered as the default message', function () {
    $out = deliveredFromForm(['embeds' => [], 'content' => null]);

    assertSame('Pelican webhook event: updated: Server', $out['content']);
    assertSame(['parse' => []], $out['allowed_mentions']);
});

test('the preview inputs deliver what a saved webhook would', function () {
    // The preview only receives these four keys from the live form
    $live = ['content' => 'hi', 'username' => 'discordish', 'avatar_url' => null, 'embeds' => [['title' => '', 'description' => '', 'color' => 'ff0000']]];
    $out = deliveredFromForm($live);

    assertFalse(isset($out['username']), 'a username Discord rejects is not shown');
    assertSame('hi', $out['content']);
    assertFalse(isset($out['embeds']), 'an empty embed is dropped like on delivery');
    assertSame('{"content":"hi","allowed_mentions":{"parse":[]}}', json_encode($out));
});

test('a stored API payload with extras is delivered with them', function () {
    $stored = ['content' => 'x', 'tts' => true, 'applied_tags' => ['1'], 'allowed_mentions' => ['users' => ['5'], 'replied_user' => false]];
    $out = PayloadRenderer::prepare(DiscordPayload::fromForm(DiscordPayload::toForm($stored)), ['event' => 'e'], new DateTimeImmutable('2026-01-02'));

    assertSame(true, $out['tts']);
    assertSame(['1'], $out['applied_tags']);
    assertSame(['parse' => [], 'users' => ['5'], 'replied_user' => false], $out['allowed_mentions']);
});
