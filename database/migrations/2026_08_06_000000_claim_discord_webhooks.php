<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use JoanFo\DiscordWebhooks\Support\DiscordEndpoint;

// PluginService registers the plugin's src/ autoload prefix for migrations, but a plugin that is
// imported and installed within a single request may have been missed by it, so fall back to the file.
if (!class_exists(DiscordEndpoint::class)) {
    require_once __DIR__ . '/../../src/Support/DiscordEndpoint.php';
}

return new class extends Migration
{
    /**
     * Claim webhooks that point at Discord.
     *
     * The panel used to do this itself when it added the `type` column. Now that Discord
     * is a plugin, the panel leaves every webhook as `regular` and this migration takes
     * ownership on install. Only webhooks still on the built-in type are considered, so a
     * webhook already claimed by another plugin's type is never overwritten.
     */
    public function up(): void
    {
        // The LIKE only narrows the table down cheaply; whether a row really is a Discord
        // webhook endpoint is decided by DiscordEndpoint, so hosts such as notdiscord.com or
        // URLs that merely mention discord.com keep their regular payload format.
        DB::table('webhook_configurations')
            ->where(function ($query) {
                $query->where('type', 'regular')->orWhereNull('type');
            })
            ->whereRaw('LOWER(endpoint) LIKE ?', ['%discord%'])
            ->select(['id', 'endpoint'])
            ->chunkById(200, function ($webhooks) {
                $ids = $webhooks
                    ->filter(fn ($webhook) => DiscordEndpoint::matches($webhook->endpoint))
                    ->pluck('id')
                    ->all();

                if ($ids !== []) {
                    DB::table('webhook_configurations')->whereIn('id', $ids)->update(['type' => 'discord']);
                }
            });
    }

    /**
     * Deliberately a no-op.
     *
     * Uninstalling the plugin rolls its migrations back, and resetting these webhooks to
     * `regular` would silently send Discord embed payloads as plain JSON. The panel already
     * degrades gracefully on an unknown type, so leaving them as `discord` means
     * reinstalling the plugin restores them exactly as they were.
     */
    public function down(): void
    {
        //
    }
};
