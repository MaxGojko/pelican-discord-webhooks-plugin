<?php

use JoanFo\DiscordWebhooks\Support\PayloadRenderer;

function payloadNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));
}

/** @param array<string, mixed> $template @param array<string, mixed> $event */
function prepared(array $template, array $event = ['event' => 'test:event']): array
{
    return PayloadRenderer::prepare($template, $event, payloadNow());
}

class PayloadRendererStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}

test('render keeps quotes, newlines and backslashes literal', function () {
    $value = "My \"Cool\" Server\nline two C:\\games\\srv";
    $out = PayloadRenderer::render(['content' => 'Server: {{ name }}'], ['name' => $value]);

    assertSame('Server: ' . $value, $out['content']);
    assertTrue(json_encode($out) !== false);
});

test('render does not allow JSON injection', function () {
    $evil = 'x","content":"@everyone hi';
    $out = PayloadRenderer::render(['content' => '{{ name }}', 'username' => 'bot'], ['name' => $evil]);

    assertSame(['content' => $evil, 'username' => 'bot'], $out);
});

test('render resolves nested dot paths and list indexes', function () {
    $event = ['user' => ['name' => 'Ann', 'tags' => ['a', 'b']], 'server' => (object) ['uuid' => 'abc']];

    $out = PayloadRenderer::render(['embeds' => [['title' => '{{user.name}} / {{ user.tags.1 }} / {{ server.uuid }}']]], $event);

    assertSame('Ann / b / abc', $out['embeds'][0]['title']);
});

test('render returns the trimmed path for unknown placeholders like core', function () {
    $out = PayloadRenderer::render(['content' => 'a {{  nope.deep }} b {{ user.missing }}'], ['user' => ['name' => 'x']]);

    assertSame('a nope.deep b user.missing', $out['content']);
});

test('render stringifies non-string values', function () {
    $event = [
        'int' => 5,
        'float' => 1.5,
        'yes' => true,
        'no' => false,
        'nil' => null,
        'date' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
        'str' => new PayloadRendererStringable(),
        'list' => ['a' => 1, 'u' => 'é/x'],
    ];

    $out = PayloadRenderer::render(['content' => '{{int}}|{{float}}|{{yes}}|{{no}}|[{{nil}}]|{{date}}|{{str}}|{{list}}'], $event);

    assertSame('5|1.5|true|false|[]|2026-01-02T03:04:05+00:00|stringable|{"a":1,"u":"é/x"}', $out['content']);
});

test('render does not touch keys or non-string scalars and does not re-expand values', function () {
    $out = PayloadRenderer::render(['{{ k }}' => '{{ v }}', 'color' => 0, 'flag' => true, 'n' => null], ['k' => 'KEY', 'v' => '{{ k }}']);

    assertSame(['{{ k }}' => '{{ k }}', 'color' => 0, 'flag' => true, 'n' => null], $out);
});

test('prepare with a null template yields default content with the event name', function () {
    assertSame(
        ['allowed_mentions' => ['parse' => []], 'content' => 'Pelican webhook event: eloquent.created: Server'],
        prepared([], ['event' => 'eloquent.created: Server'])
    );
    assertSame('Pelican webhook event: unknown', PayloadRenderer::prepare(null, [], payloadNow())['content']);
    assertSame('Pelican webhook event: unknown', PayloadRenderer::prepare(null, ['event' => ['x']], payloadNow())['content']);
});

test('prepare applies the default content when everything was blank', function () {
    $out = prepared(['content' => '  ', 'embeds' => [['title' => '', 'fields' => [['name' => 'a', 'value' => '']]]]]);

    assertSame('Pelican webhook event: test:event', $out['content']);
    assertFalse(isset($out['embeds']));
});

test('sanitize truncates to Discord limits', function () {
    $out = prepared([
        'content' => str_repeat('a', 2500),
        'username' => str_repeat('u', 100),
        'thread_name' => str_repeat('t', 150),
        'embeds' => [[
            'title' => str_repeat('t', 300),
            'description' => str_repeat('d', 5000),
            'author' => ['name' => str_repeat('n', 300)],
            'footer' => ['text' => str_repeat('f', 3000)],
            'fields' => [['name' => str_repeat('x', 300), 'value' => str_repeat('y', 1200)]],
        ]],
    ]);

    assertSame(2000, mb_strlen($out['content']));
    assertSame(80, mb_strlen($out['username']));
    assertSame(100, mb_strlen($out['thread_name']));
    $embed = $out['embeds'][0];
    assertSame(256, mb_strlen($embed['title']));
    assertSame(256, mb_strlen($embed['author']['name']));
    assertSame(256, mb_strlen($embed['fields'][0]['name']));
    assertSame(1024, mb_strlen($embed['fields'][0]['value']));
    assertTrue(str_ends_with($out['content'], '…'));
    // 256 + 4096 (capped) + 256 + 2048 + 256 + 1024 exceeds 6000, so the description was shortened
    assertTrue(mb_strlen($embed['description']) < 4096);
});

test('sanitize truncates multibyte text without breaking characters', function () {
    $out = prepared(['content' => str_repeat('日本語🙂', 600)]);

    assertSame(2000, mb_strlen($out['content']));
    assertTrue(str_ends_with($out['content'], '…'));
    assertSame($out['content'], mb_scrub($out['content']));
    assertTrue(json_encode($out) !== false);
});

test('sanitize keeps text exactly at the limit untouched', function () {
    $text = str_repeat('é', 2000);

    assertSame($text, prepared(['content' => $text])['content']);
});

test('sanitize caps embeds at 10 and fields at 25', function () {
    $embeds = [];
    for ($i = 0; $i < 12; $i++) {
        $embeds[] = ['title' => "e{$i}"];
    }

    $fields = [];
    for ($i = 0; $i < 30; $i++) {
        $fields[] = ['name' => "n{$i}", 'value' => "v{$i}"];
    }

    $out = prepared(['embeds' => $embeds]);
    assertSame(10, count($out['embeds']));
    assertSame('e9', $out['embeds'][9]['title']);

    $out = prepared(['embeds' => [['title' => 't', 'fields' => $fields]]]);
    assertSame(25, count($out['embeds'][0]['fields']));
});

test('sanitize enforces the 6000 total by shortening descriptions from the last embed', function () {
    $out = prepared(['embeds' => [
        ['title' => 'first', 'description' => str_repeat('a', 4000)],
        ['title' => 'second', 'description' => str_repeat('b', 4000)],
    ]]);

    assertSame(4000, mb_strlen($out['embeds'][0]['description']));
    assertSame(5 + 6 + 4000 + mb_strlen($out['embeds'][1]['description']), 6000);
    assertTrue(str_ends_with($out['embeds'][1]['description'], '…'));
});

test('sanitize drops trailing fields when descriptions are not enough', function () {
    $fields = [];
    for ($i = 0; $i < 10; $i++) {
        $fields[] = ['name' => 'n', 'value' => str_repeat('v', 1024)];
    }

    $out = prepared(['embeds' => [['title' => 't', 'fields' => $fields]]]);

    $total = 1;
    foreach ($out['embeds'][0]['fields'] as $field) {
        $total += mb_strlen($field['name']) + mb_strlen($field['value']);
    }

    assertTrue($total <= 6000);
    assertSame(5, count($out['embeds'][0]['fields']));
});

test('sanitize drops usernames containing discord or clyde', function () {
    assertFalse(isset(prepared(['content' => 'x', 'username' => 'My DiScOrD bot'])['username']));
    assertFalse(isset(prepared(['content' => 'x', 'username' => 'xCLYDEx'])['username']));
    assertSame('Pelican', prepared(['content' => 'x', 'username' => 'Pelican'])['username']);
});

test('sanitize removes invalid URLs and the containers they empty', function () {
    $out = prepared([
        'content' => 'x',
        'avatar_url' => 'javascript:alert(1)',
        'embeds' => [[
            'title' => 't',
            'url' => 'not a url',
            'author' => ['name' => 'a', 'url' => 'ftp://x', 'icon_url' => 'https://ok.example/i.png'],
            'footer' => ['text' => 'f', 'icon_url' => '/relative.png'],
            'image' => ['url' => 'data:image/png;base64,AA'],
            'thumbnail' => ['url' => 'https://ok.example/t.png'],
        ]],
    ]);

    assertFalse(isset($out['avatar_url']));
    $embed = $out['embeds'][0];
    assertFalse(isset($embed['url']));
    assertSame(['name' => 'a', 'icon_url' => 'https://ok.example/i.png'], $embed['author']);
    assertSame(['text' => 'f'], $embed['footer']);
    assertFalse(isset($embed['image']));
    assertSame(['url' => 'https://ok.example/t.png'], $embed['thumbnail']);
});

test('sanitize drops author without name and footer without text', function () {
    $out = prepared(['embeds' => [[
        'title' => 't',
        'author' => ['url' => 'https://a.example', 'icon_url' => 'https://a.example/i.png'],
        'footer' => ['icon_url' => 'https://a.example/i.png'],
    ]]]);

    assertFalse(isset($out['embeds'][0]['author']));
    assertFalse(isset($out['embeds'][0]['footer']));
});

test('sanitize validates URLs after rendering, so placeholders that resolve badly are removed', function () {
    $out = prepared(['content' => 'x', 'avatar_url' => '{{ avatar }}'], ['avatar' => 'nope']);
    assertFalse(isset($out['avatar_url']));

    $rendered = PayloadRenderer::render(['content' => 'x', 'avatar_url' => '{{ avatar }}'], ['avatar' => 'https://cdn.example/a.png']);
    assertSame('https://cdn.example/a.png', PayloadRenderer::sanitize($rendered, [], payloadNow())['avatar_url']);
});

test('has_timestamp sets an ISO-8601 UTC timestamp and is always removed', function () {
    $now = new DateTimeImmutable('2026-01-02 05:04:05', new DateTimeZone('+02:00'));
    $out = PayloadRenderer::prepare(['embeds' => [
        ['title' => 'a', 'has_timestamp' => true],
        ['title' => 'b', 'has_timestamp' => false],
        ['title' => 'c', 'has_timestamp' => '1'],
    ]], [], $now);

    assertSame('2026-01-02T03:04:05+00:00', $out['embeds'][0]['timestamp']);
    assertFalse(isset($out['embeds'][1]['timestamp']));
    assertSame('2026-01-02T03:04:05+00:00', $out['embeds'][2]['timestamp']);
    foreach ($out['embeds'] as $embed) {
        assertFalse(array_key_exists('has_timestamp', $embed));
    }
});

test('flags keep only known bits and SUPPRESS_EMBEDS is cleared when embeds remain', function () {
    $both = 4 | 4096 | 64;

    assertSame(4 | 4096, prepared(['content' => 'x', 'flags' => $both])['flags']);
    assertSame(4096, prepared(['embeds' => [['title' => 't']], 'flags' => $both])['flags']);
    assertSame(4096, prepared(['content' => 'x', 'flags' => '4096'])['flags']);
    assertFalse(isset(prepared(['embeds' => [['title' => 't']], 'flags' => 4])['flags']));
    assertFalse(isset(prepared(['content' => 'x', 'flags' => 64])['flags']));
    assertFalse(isset(prepared(['content' => 'x', 'flags' => 'abc'])['flags']));
});

test('allowed_mentions defaults to parsing nothing', function () {
    assertSame(['parse' => []], prepared(['content' => 'x'])['allowed_mentions']);
    assertSame(['parse' => []], prepared(['content' => 'x', 'allowed_mentions' => []])['allowed_mentions']);
    assertSame(['parse' => []], prepared(['content' => 'x', 'allowed_mentions' => 'everyone'])['allowed_mentions']);
});

test('allowed_mentions parse is filtered and deduplicated', function () {
    $out = prepared(['content' => 'x', 'allowed_mentions' => ['parse' => ['roles', 'bogus', 'everyone', 'roles', 5]]]);

    assertSame(['parse' => ['roles', 'everyone']], $out['allowed_mentions']);
});

test('allowed_mentions keeps valid id lists and replied_user', function () {
    $out = prepared(['content' => 'x', 'allowed_mentions' => [
        'parse' => ['users', 'everyone'],
        'roles' => ['123', 456, 'abc'],
        'replied_user' => true,
    ]]);

    assertSame(['parse' => ['users', 'everyone'], 'roles' => ['123', '456'], 'replied_user' => true], $out['allowed_mentions']);

    // parse and an id list of the same type cannot be combined
    $out = prepared(['content' => 'x', 'allowed_mentions' => ['parse' => ['users', 'roles'], 'users' => ['1']]]);
    assertSame(['parse' => ['roles'], 'users' => ['1']], $out['allowed_mentions']);
});

test('empty embeds and blank fields are dropped', function () {
    $out = prepared(['content' => 'x', 'embeds' => [
        ['color' => 5, 'url' => 'https://a.example'],
        ['title' => '  '],
        ['title' => 'keep', 'fields' => [
            ['name' => '', 'value' => 'v'],
            ['name' => 'n', 'value' => ' '],
            ['name' => 'ok', 'value' => '0', 'inline' => false],
            'junk',
        ]],
        [],
    ]]);

    assertSame(1, count($out['embeds']));
    assertSame([['name' => 'ok', 'value' => '0', 'inline' => false]], $out['embeds'][0]['fields']);
});

test('an embed holding only fields still counts as content', function () {
    $out = prepared(['embeds' => [['fields' => [['name' => 'a', 'value' => 'b']]]]]);

    assertFalse(isset($out['content']));
    assertSame(1, count($out['embeds']));
});

test('embed color 0 is kept and other forms are converted', function () {
    $colors = fn (mixed ...$values) => array_map(
        fn (mixed $color) => prepared(['embeds' => [['title' => 't', 'color' => $color]]])['embeds'][0]['color'] ?? null,
        $values
    );

    assertSame([0, 0, 16777215, 255, 1193046, 5, 255], $colors(0, '0', 16777215, '#0000ff', '#123456', '5', '#00F'));
    assertSame([null, null, null, null, null], $colors(-1, 16777216, 'red', true, ['x']));
});

test('a placeholder resolving to a colour string works end to end', function () {
    $out = prepared(['embeds' => [['title' => 't', 'color' => '{{ c }}']]], ['c' => '#ff0000']);

    assertSame(16711680, $out['embeds'][0]['color']);
});

test('an unusual but valid event payload round trips through JSON', function () {
    $out = prepared([
        'content' => 'Path {{ path }} by {{ who }}',
        'embeds' => [['title' => '{{ who }}', 'description' => "line1\nline2 \\ \"q\"", 'has_timestamp' => true]],
    ], ['path' => 'C:\\Users\\x', 'who' => "O'Brien \"Bob\"\t"]);

    $decoded = json_decode(json_encode($out), true);

    assertSame($out, $decoded);
    assertSame("Path C:\\Users\\x by O'Brien \"Bob\"\t", $decoded['content']);
});
