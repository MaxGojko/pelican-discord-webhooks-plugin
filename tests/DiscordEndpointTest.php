<?php

use JoanFo\DiscordWebhooks\Support\DiscordEndpoint;

test('DiscordEndpoint accepts real-looking Discord webhook URLs', function () {
    $good = [
        'https://discord.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        'https://discord.com/api/webhooks/1234567890123456789/AbC-dEf_123?wait=true',
        'https://discord.com/api/webhooks/1234567890123456789/AbC-dEf_123/github',
        'https://discord.com/api/v10/webhooks/1234567890123456789/AbC-dEf_123',
        'https://discordapp.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        'https://canary.discord.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        'https://ptb.discord.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        'https://ptb.discordapp.com/api/v9/webhooks/1234567890123456789/AbC-dEf_123',
        'https://DISCORD.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        'HTTPS://discord.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        'https://discord.com./api/webhooks/1234567890123456789/AbC-dEf_123',
        'https://discord.com:443/api/webhooks/1234567890123456789/AbC-dEf_123',
        'http://discord.com/api/webhooks/1234567890123456789/AbC-dEf_123',
        '  https://discord.com/api/webhooks/1234567890123456789/AbC-dEf_123  ',
        'https://discord.com/api/webhooks/1234567890123456789',
    ];

    foreach ($good as $url) {
        assertTrue(DiscordEndpoint::matches($url), 'should match ' . $url);
    }
});

test('DiscordEndpoint rejects lookalike hosts', function () {
    $bad = [
        'https://notdiscord.com/api/webhooks/1/x',
        'https://discord.com.evil.example/api/webhooks/1/x',
        'https://evil-discord.com/api/webhooks/1/x',
        'https://discordapp.com.evil.example/api/webhooks/1/x',
        'https://xcanary.discord.com/api/webhooks/1/x',
        'https://a.b.discord.com/api/webhooks/1/x',
        'https://cdn.discordapp.com/api/webhooks/1/x',
        'https://discord.co/api/webhooks/1/x',
        'https://discord.com.cn/api/webhooks/1/x',
        'https://relay.example/discord.com-bridge',
        'https://relay.example/discord.com/api/webhooks/1/x',
        'https://relay.example/api/webhooks/1/x',
        'https://127.0.0.1/api/webhooks/1/x',
    ];

    foreach ($bad as $url) {
        assertFalse(DiscordEndpoint::matches($url), 'should reject ' . $url);
    }
});

test('DiscordEndpoint rejects userinfo, port and parsing tricks', function () {
    $bad = [
        'https://discord.com@evil.com/api/webhooks/1/x',
        'https://discord.com:pw@evil.com/api/webhooks/1/x',
        'https://user@discord.com/api/webhooks/1/x',
        'https://user:pw@discord.com/api/webhooks/1/x',
        'https://evil.com\\@discord.com/api/webhooks/1/x',
        'https://evil.com#@discord.com/api/webhooks/1/x',
        'https://evil.com/?u=https://discord.com/api/webhooks/1/x',
        'https://evil.com/path?host=discord.com',
        'https://evil.com/#https://discord.com/api/webhooks/1/x',
        'https://discord.com:8443/api/webhooks/1/x',
        'https://discord.com:abc/api/webhooks/1/x',
        "https://discord.com/api/webhooks/1/x\n@evil.com",
        "https://discord.com/api/webhooks/1/x y",
    ];

    foreach ($bad as $url) {
        assertFalse(DiscordEndpoint::matches($url), 'should reject ' . var_export($url, true));
    }
});

test('DiscordEndpoint rejects non-webhook Discord URLs and other schemes', function () {
    $bad = [
        'https://discord.com/',
        'https://discord.com',
        'https://discord.com/invite/abc',
        'https://discord.com/channels/1/2',
        'https://discord.com/api/users/@me',
        'https://discord.com/api/webhooks/',
        'https://discord.com/api/webhooks',
        'https://discord.com/api/webhooks/abc/token',
        'https://discord.com/api/webhooks/123abc/token',
        'https://discord.com/api/v/webhooks/1/x',
        'https://discord.com/api/vx10/webhooks/1/x',
        'https://discord.com/api/v10/other/webhooks/1/x',
        'https://discord.com/x/api/webhooks/1/x',
        'https://discord.com//api/webhooks/1/x',
        'https://discord.com/API/webhooks/1/x',
        'https://discord.com/api/webhooks/../users/1',
        'ftp://discord.com/api/webhooks/1/x',
        'javascript://discord.com/api/webhooks/1/x',
        'file://discord.com/api/webhooks/1/x',
        '//discord.com/api/webhooks/1/x',
        'discord.com/api/webhooks/1/x',
        'discord.com',
        'discordapp.com',
    ];

    foreach ($bad as $url) {
        assertFalse(DiscordEndpoint::matches($url), 'should reject ' . $url);
    }
});

test('DiscordEndpoint rejects empty and non-string input', function () {
    foreach (['', '   ', null, false, true, 0, 12345, [], ['https://discord.com/api/webhooks/1/x'], new stdClass()] as $bad) {
        assertFalse(DiscordEndpoint::matches($bad), 'should reject ' . var_export($bad, true));
    }
});
