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
     * Core's `2025_04_09_015500_add_webhook_configurations_type_column` migration also sets
     * `type = 'discord'` itself, but only for rows whose `type` is still NULL (i.e. when it
     * adds the `type` column), and it decides by host alone: any host equal to, or a
     * subdomain of, discord.com or discordapp.com, whatever the path. It leaves every other
     * row, including rows that already had a type, as is. This migration covers what that
     * one does not: rows that are still `regular` (or NULL) but whose endpoint is a Discord
     * webhook URL according to DiscordEndpoint, such as webhooks created before the plugin
     * was installed. Only built-in type rows are considered, so a webhook already claimed
     * by another plugin's type is never overwritten, and rows core already marked `discord`
     * are not touched, even if DiscordEndpoint's stricter check would not match them.
     *
     * Because uninstalling rolls this migration back and reinstalling runs it again, a
     * webhook a user deliberately switched back to `regular` is claimed again on reinstall
     * if its endpoint still looks like a Discord webhook. That is accepted behaviour.
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
