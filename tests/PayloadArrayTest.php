<?php

use JoanFo\DiscordWebhooks\Support\PayloadArray;

test('PayloadArray::withoutBlank drops null, empty strings and empty arrays recursively', function () {
    $input = [
        'title' => 'Hi',
        'url' => null,
        'footer' => ['text' => '', 'icon_url' => null],
        'author' => ['name' => 'A', 'url' => null],
        'blank' => '   ',
    ];

    assertSame(['title' => 'Hi', 'author' => ['name' => 'A']], PayloadArray::withoutBlank($input));
});

test('PayloadArray::withoutBlank keeps 0, "0" and false', function () {
    assertSame(
        ['a' => 0, 'b' => '0', 'c' => false, 'd' => ['e' => 0]],
        PayloadArray::withoutBlank(['a' => 0, 'b' => '0', 'c' => false, 'd' => ['e' => 0], 'f' => null]),
    );
});

test('PayloadArray::withoutBlank removes nested nulls that the old filter kept', function () {
    $embed = ['title' => 'T', 'footer' => ['text' => 'x', 'icon_url' => null]];

    assertSame(['title' => 'T', 'footer' => ['text' => 'x']], PayloadArray::withoutBlank($embed));
});

test('PayloadArray::withoutBlank reindexes lists so they stay JSON arrays', function () {
    $result = PayloadArray::withoutBlank(['fields' => [['name' => null], ['name' => 'a'], [], ['name' => 'b']]]);

    assertSame([['name' => 'a'], ['name' => 'b']], $result['fields']);
    assertSame('{"fields":[{"name":"a"},{"name":"b"}]}', json_encode($result));
});

test('PayloadArray::withoutBlank keeps non-list keys', function () {
    assertSame([3 => 'x'], PayloadArray::withoutBlank([1 => null, 3 => 'x']));
});
