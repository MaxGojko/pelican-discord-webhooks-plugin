<?php

use JoanFo\DiscordWebhooks\Support\Color;

test('Color::fromHex parses #rrggbb and #rgb in any case, with or without #', function () {
    assertSame(0x00FF00, Color::fromHex('#00ff00'));
    assertSame(0x00FF00, Color::fromHex('00FF00'));
    assertSame(0x5865F2, Color::fromHex('#5865F2'));
    assertSame(0xFFAA00, Color::fromHex('#fa0'));
    assertSame(0xFFAA00, Color::fromHex('FA0'));
    assertSame(0x0000FF, Color::fromHex(' #0000ff '));
});

test('Color::fromHex keeps black as 0 and not as null', function () {
    assertSame(0, Color::fromHex('#000000'));
    assertSame(0, Color::fromHex('#000'));
    assertSame(0xFFFFFF, Color::fromHex('#fff'));
});

test('Color::fromHex rejects invalid and blank input', function () {
    foreach ([null, '', ' ', '#', '#12', '#12345', '#1234567', '#gggggg', 'red', 'rgb(1,2,3)', '##fff', '#ff ff', 255, true, []] as $bad) {
        assertSame(null, Color::fromHex($bad), 'should reject ' . var_export($bad, true));
    }
});

test('Color::toHex pads to six lowercase digits', function () {
    assertSame('#000000', Color::toHex(0));
    assertSame('#0000ff', Color::toHex(255));
    assertSame('#00ff00', Color::toHex(0x00FF00));
    assertSame('#5865f2', Color::toHex(0x5865F2));
    assertSame('#ffffff', Color::toHex(0xFFFFFF));
});

test('Color::toHex returns null for missing or out of range values', function () {
    foreach ([null, '', -1, 0xFFFFFF + 1, 1.5, 'red', true, [], '123456789', '-5'] as $bad) {
        assertSame(null, Color::toHex($bad), 'should reject ' . var_export($bad, true));
    }
});

test('Color::toHex accepts digit strings and hex strings from hand written payloads', function () {
    assertSame('#00ff00', Color::toHex('65280'));
    assertSame('#ff0000', Color::toHex('#FF0000'));
    assertSame(null, Color::toHex('#nothex'));
});

test('Color round trips every colour through both directions', function () {
    foreach ([0, 1, 255, 0x00FF00, 0x123456, 0x5865F2, 0xFFFFFF] as $color) {
        assertSame($color, Color::fromHex(Color::toHex($color)));
    }
});
