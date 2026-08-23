<?php

namespace JoanFo\DiscordWebhooks\Providers;

use App\Facades\WebhookTypes;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use JoanFo\DiscordWebhooks\Schemas\DiscordSchema;

class DiscordWebhooksProvider extends ServiceProvider
{
    private const CSS_SOURCE = __DIR__ . '/../../css/discord-preview.css';

    private const CSS_PATH = 'plugins/discord-webhooks/css/discord-preview.css';

    public function register(): void
    {
        $this->ensureCssPublished();
    }

    /**
     * Registering happens in boot() so the panel's WebhookTypeService singleton
     * is already bound by the time the schema is added to it.
     */
    public function boot(): void
    {
        $this->publishes([
            self::CSS_SOURCE => public_path(self::CSS_PATH),
        ], 'discord-webhooks-assets');

        FilamentColor::register([
            'blurple' => Color::hex('#5865F2'),
        ]);

        WebhookTypes::register(new DiscordSchema());
    }

    /**
     * The preview stylesheet is served straight from public/ instead of going
     * through Vite, so copy it over whenever it is missing or out of date.
     */
    private function ensureCssPublished(): void
    {
        $destination = public_path(self::CSS_PATH);

        if (!File::exists(self::CSS_SOURCE)) {
            return;
        }

        if (File::exists($destination) && File::lastModified($destination) >= File::lastModified(self::CSS_SOURCE)) {
            return;
        }

        File::ensureDirectoryExists(dirname($destination), 0755, true);
        File::copy(self::CSS_SOURCE, $destination);
    }
}
