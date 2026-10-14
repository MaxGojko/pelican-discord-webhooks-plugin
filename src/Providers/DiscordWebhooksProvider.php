<?php

namespace JoanFo\DiscordWebhooks\Providers;

use App\Facades\WebhookTypes;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\ServiceProvider;
use JoanFo\DiscordWebhooks\Schemas\DiscordSchema;

class DiscordWebhooksProvider extends ServiceProvider
{
    /**
     * Registering happens in boot() so the panel's WebhookTypeService singleton
     * is already bound by the time the schema is added to it.
     *
     * The preview stylesheet is not published to public/; DiscordPreview delivers it
     * inline through the view.
     */
    public function boot(): void
    {
        FilamentColor::register([
            'blurple' => Color::hex('#5865F2'),
        ]);

        WebhookTypes::register(new DiscordSchema());
    }
}
