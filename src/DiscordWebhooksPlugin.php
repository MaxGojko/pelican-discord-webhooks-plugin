<?php

namespace JoanFo\DiscordWebhooks;

use Filament\Contracts\Plugin;
use Filament\Panel;

class DiscordWebhooksPlugin implements Plugin
{
    public function getId(): string
    {
        return 'discord-webhooks';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
