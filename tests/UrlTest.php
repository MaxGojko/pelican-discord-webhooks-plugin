<?php

use JoanFo\DiscordWebhooks\Support\Url;

test('Url::isHttp accepts http and https URLs', function () {
    assertTrue(Url::isHttp('https://cdn.example.com/a.png'));
    assertTrue(Url::isHttp('HTTP://example.com'));
});

test('Url::isHttp rejects other schemes, relative and blank values', function () {
    foreach (['javascript:alert(1)', 'data:image/png;base64,AA', '/relative.png', 'example.com/x', '', ' https://a.b', null, 42, 'https://'] as $bad) {
        assertFalse(Url::isHttp($bad), 'should reject ' . var_export($bad, true));
    }
});
