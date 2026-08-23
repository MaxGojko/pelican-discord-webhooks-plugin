<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::table('webhook_configurations')
            ->where(function ($query) {
                $query->where('type', 'regular')->orWhereNull('type');
            })
            ->where(function ($query) {
                $query->where('endpoint', 'like', '%discord.com%')
                    ->orWhere('endpoint', 'like', '%discordapp.com%');
            })
            ->update(['type' => 'discord']);
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
