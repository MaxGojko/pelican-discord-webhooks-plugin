<?php

use JoanFo\DiscordWebhooks\Support\DiscordPayload;

/** The shape Filament hands to mutateFormDataBeforeSave() for an empty form. */
function discordEmptyForm(): array
{
    return [
        'username' => null,
        'avatar_url' => null,
        'content' => null,
        'thread_name' => null,
        'flags' => [],
        'allowed_mentions' => [],
        'embeds' => [],
    ];
}

test('an empty form stores only an explicit empty allowed_mentions list', function () {
    $payload = DiscordPayload::fromForm(discordEmptyForm());

    assertSame(['allowed_mentions' => ['parse' => []]], $payload);
    assertSame('{"allowed_mentions":{"parse":[]}}', json_encode($payload));
});

test('allowed mentions keep only known types in a stable order', function () {
    $payload = DiscordPayload::fromForm(['allowed_mentions' => ['everyone', 'users', 'bogus', 'users']] + discordEmptyForm());

    assertSame(['parse' => ['users', 'everyone']], $payload['allowed_mentions']);
});

test('allowed mentions are restored from payload.allowed_mentions.parse', function () {
    $form = DiscordPayload::toForm(['allowed_mentions' => ['parse' => ['roles', 'everyone']]]);
    assertSame(['roles', 'everyone'], $form['allowed_mentions']);

    assertSame([], DiscordPayload::toForm([])['allowed_mentions']);
    assertSame([], DiscordPayload::toForm(['allowed_mentions' => ['parse' => []]])['allowed_mentions']);
    assertSame([], DiscordPayload::toForm(['allowed_mentions' => []])['allowed_mentions']);
});

test('flags are ORed from ints or strings and limited to the two offered bits', function () {
    assertSame(4, DiscordPayload::flagsFromForm([4]));
    assertSame(4, DiscordPayload::flagsFromForm(['4']));
    assertSame(4100, DiscordPayload::flagsFromForm(['4', '4096']));
    assertSame(4100, DiscordPayload::flagsFromForm([4, 4096]));
    assertSame(4, DiscordPayload::flagsFromForm([4, 64, 'x', '', null]));
    assertSame(0, DiscordPayload::flagsFromForm([]));
    assertSame(0, DiscordPayload::flagsFromForm(null));
});

test('flags are omitted from the payload when nothing is ticked', function () {
    assertFalse(array_key_exists('flags', DiscordPayload::fromForm(['flags' => []] + discordEmptyForm())));
    assertSame(4096, DiscordPayload::fromForm(['flags' => ['4096']] + discordEmptyForm())['flags']);
});

test('flags are filled back as strings, the way the CheckboxList holds them', function () {
    assertSame(['4', '4096'], DiscordPayload::toForm(['flags' => 4100])['flags']);
    assertSame(['4096'], DiscordPayload::toForm(['flags' => 4096])['flags']);
    assertSame([], DiscordPayload::toForm(['flags' => 0])['flags']);
    assertSame([], DiscordPayload::toForm([])['flags']);
});

test('embed colour is stored as an int, keeps black and is omitted when unset', function () {
    $embeds = fn (array $colors) => array_map(fn ($c) => ['title' => 'T', 'color' => $c], $colors);
    $saved = DiscordPayload::fromForm(['embeds' => $embeds(['#00ff00', '#000000', '', null, 'nope'])] + discordEmptyForm())['embeds'];

    assertSame(0x00FF00, $saved[0]['color']);
    assertSame(0, $saved[1]['color']);
    foreach ([2, 3, 4] as $i) {
        assertFalse(array_key_exists('color', $saved[$i]), "embed {$i} must have no colour");
    }
});

test('embed colour is filled as padded hex and left out when there is none', function () {
    $form = DiscordPayload::toForm(['embeds' => [
        ['title' => 'green', 'color' => 0x00FF00],
        ['title' => 'blue', 'color' => 255],
        ['title' => 'black', 'color' => 0],
        ['title' => 'none'],
    ]])['embeds'];

    assertSame('#00ff00', $form[0]['color']);
    assertSame('#0000ff', $form[1]['color']);
    assertSame('#000000', $form[2]['color']);
    assertFalse(array_key_exists('color', $form[3]));
});

test('unticked checkboxes leave no false behind but ticked ones are kept', function () {
    $payload = DiscordPayload::fromForm(['embeds' => [[
        'title' => 'T',
        'has_timestamp' => false,
        'fields' => [
            ['name' => 'a', 'value' => '1', 'inline' => false],
            ['name' => 'b', 'value' => '0', 'inline' => true],
        ],
    ]]] + discordEmptyForm());

    $embed = $payload['embeds'][0];
    assertFalse(array_key_exists('has_timestamp', $embed));
    assertSame(['name' => 'a', 'value' => '1'], $embed['fields'][0]);
    assertSame(['name' => 'b', 'value' => '0', 'inline' => true], $embed['fields'][1], '"0" is a real value');

    $ticked = DiscordPayload::fromForm(['embeds' => [['title' => 'T', 'has_timestamp' => true]]] + discordEmptyForm());
    assertSame(true, $ticked['embeds'][0]['has_timestamp']);
});

test('nested nulls and blank groups are dropped and a zero description survives', function () {
    $payload = DiscordPayload::fromForm(['embeds' => [[
        'title' => '',
        'description' => '0',
        'footer' => ['text' => 'x', 'icon_url' => null],
        'author' => ['name' => null, 'url' => null, 'icon_url' => null],
        'image' => ['url' => ''],
        'fields' => [],
    ]]] + discordEmptyForm());

    assertSame([['description' => '0', 'footer' => ['text' => 'x']]], $payload['embeds']);
});

test('embeds and fields are re-indexed lists even when the form keys them by uuid', function () {
    $payload = DiscordPayload::fromForm(['embeds' => [
        'a1b2' => ['title' => 'one', 'fields' => ['f1' => ['name' => 'n', 'value' => 'v']]],
        'c3d4' => ['title' => 'two'],
    ]] + discordEmptyForm());

    assertTrue(array_is_list($payload['embeds']));
    assertTrue(array_is_list($payload['embeds'][0]['fields']));
});

test('top level image and thumbnail are not part of the payload', function () {
    assertFalse(in_array('image', DiscordPayload::FIELDS, true));
    assertFalse(in_array('thumbnail', DiscordPayload::FIELDS, true));

    $payload = DiscordPayload::fromForm(['image' => ['url' => 'https://a.b/c.png'], 'thumbnail' => ['url' => 'https://a.b/d.png']] + discordEmptyForm());
    assertFalse(array_key_exists('image', $payload));
    assertFalse(array_key_exists('thumbnail', $payload));
});

test('save then fill then save is stable', function () {
    $form = [
        'username' => 'Pelican',
        'avatar_url' => 'https://example.com/{{server.name}}.png',
        'content' => "line one\nline two",
        'thread_name' => '0',
        'flags' => ['4', '4096'],
        'allowed_mentions' => ['users', 'roles'],
        'embeds' => [
            [
                'author' => ['name' => 'Me', 'url' => null, 'icon_url' => 'https://example.com/a.png'],
                'title' => 'Black',
                'description' => 'text',
                'color' => '#000000',
                'url' => 'https://example.com',
                'image' => ['url' => 'https://example.com/i.png'],
                'thumbnail' => ['url' => null],
                'footer' => ['text' => 'foot', 'icon_url' => null],
                'has_timestamp' => true,
                'fields' => [
                    ['name' => 'a', 'value' => 'b', 'inline' => true],
                    ['name' => 'c', 'value' => 'd', 'inline' => false],
                ],
            ],
            ['title' => 'Green', 'color' => '#00FF00'],
            ['title' => 'No colour', 'color' => null],
        ],
    ];

    $stored = DiscordPayload::fromForm($form);
    $filled = DiscordPayload::toForm($stored);

    assertSame('#000000', $filled['embeds'][0]['color']);
    assertSame('#00ff00', $filled['embeds'][1]['color']);
    assertFalse(array_key_exists('color', $filled['embeds'][2]));
    assertSame(['4', '4096'], $filled['flags']);
    assertSame(['roles', 'users'], $filled['allowed_mentions']);
    assertSame('0', $filled['thread_name']);

    $again = DiscordPayload::fromForm($filled);
    assertSame($stored, $again, 'a second save must not change what was stored');
    assertSame(json_encode($stored), json_encode($again));

    // The stored form of the first embed
    assertSame(0, $stored['embeds'][0]['color']);
    assertSame(4100, $stored['flags']);
    assertSame(['roles', 'users'], $stored['allowed_mentions']['parse']);
});

test('a payload saved before this change still opens in the form', function () {
    $form = DiscordPayload::toForm(['content' => 'hello', 'embeds' => [['title' => 'x', 'color' => 0]]]);

    assertSame('hello', $form['content']);
    assertSame('#000000', $form['embeds'][0]['color']);
    assertSame([], $form['flags']);
    assertSame([], $form['allowed_mentions']);
    assertFalse(array_key_exists('username', $form));
});
