<?php

use JoanFo\DiscordWebhooks\Support\EmbedColor;

test('EmbedColor converts integer colours to zero-padded hex', function () {
    assertSame('#000000', EmbedColor::toCss(0));
    assertSame('#0000ff', EmbedColor::toCss(255));
    assertSame('#5865f2', EmbedColor::toCss(5793266));
    assertSame('#ffffff', EmbedColor::toCss(16777215));
});

test('EmbedColor rejects out-of-range integers', function () {
    assertSame(null, EmbedColor::toCss(-1));
    assertSame(null, EmbedColor::toCss(16777216));
});

test('EmbedColor accepts only #rgb and #rrggbb strings', function () {
    assertSame('#fff', EmbedColor::toCss('#fff'));
    assertSame('#5865F2', EmbedColor::toCss('#5865F2'));

    foreach (['red', 'red; background:url(//evil.example/x)', '#ff', '#ffff', '#fffff', '#fffffff', '#ggg', "#fff\n", '#fff;', 'fff', '', ' #fff', 'rgb(0,0,0)', 'url(x)', '5793266'] as $bad) {
        assertSame(null, EmbedColor::toCss($bad), 'should reject ' . var_export($bad, true));
    }
});

test('EmbedColor rejects non-string, non-int values', function () {
    foreach ([null, true, false, 1.5, [], ['#fff']] as $bad) {
        assertSame(null, EmbedColor::toCss($bad), 'should reject ' . var_export($bad, true));
    }
});
