<?php

use JoanFo\DiscordWebhooks\Support\Discord;

test('usernames containing discord or clyde are not allowed in any case', function () {
    foreach (['discord', 'My DiScOrD bot', 'xCLYDEx', 'Clyde'] as $bad) {
        assertFalse(Discord::isAllowedUsername($bad), $bad);
    }

    foreach (['Pelican', 'Disc ord', 'Clide', '0'] as $good) {
        assertTrue(Discord::isAllowedUsername($good), $good);
    }
});

test('a username template is checked on its literal text only', function () {
    assertTrue(Discord::isAllowedUsernameTemplate('{{ discord_user }} bot'));
    assertTrue(Discord::isAllowedUsernameTemplate('{{server.name}}'));
    assertFalse(Discord::isAllowedUsernameTemplate('{{server.name}} discord'));
    assertFalse(Discord::isAllowedUsernameTemplate('Clyde {{server.name}}'));
});

test('the flag mask covers exactly the two flags the form offers', function () {
    assertSame(4100, Discord::FLAGS_ALLOWED);
    assertSame(Discord::FLAG_SUPPRESS_EMBEDS | Discord::FLAG_SUPPRESS_NOTIFICATIONS, Discord::FLAGS_ALLOWED);
});
