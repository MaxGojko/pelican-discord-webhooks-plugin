<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Remove the stylesheet that plugin version 1.0.0 copied into the panel's public directory.
     *
     * Version 1.0.0 copied `css/discord-preview.css` to
     * `public/plugins/discord-webhooks/css/discord-preview.css` at runtime. Since 1.1.0 the
     * stylesheet is served inline, so that copy is dead weight that would otherwise go stale.
     * The panel runs plugin migrations on install and again on every update, so upgrading
     * from 1.0.0 lands here.
     *
     * Best effort only: a missing file, a read-only public directory or any filesystem error
     * must never fail the migration (and with it the whole plugin update), so everything is
     * wrapped and ignored. Directories are only removed when they are empty.
     */
    public function up(): void
    {
        try {
            $base = public_path('plugins/discord-webhooks');
            $file = $base . '/css/discord-preview.css';

            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }

            foreach ([$base . '/css', $base] as $directory) {
                if (is_dir($directory) && !is_link($directory) && $this->isEmpty($directory)) {
                    @rmdir($directory);
                }
            }
        } catch (Throwable) {
            // Leaving the stale file behind is harmless.
        }
    }

    /**
     * Deliberately a no-op: the 1.0.0 stylesheet is not restored on rollback.
     */
    public function down(): void
    {
        //
    }

    private function isEmpty(string $directory): bool
    {
        $entries = @scandir($directory);

        return is_array($entries) && count(array_diff($entries, ['.', '..'])) === 0;
    }
};
