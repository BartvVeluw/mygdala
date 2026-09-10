<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Adds one new key to the existing `site_settings` key/value table — the
 * default social-sharing image (`og:image` fallback used on every public
 * page that doesn't set its own). No schema change: `site_settings` is
 * deliberately key/value (see 20260904190000_create_site_settings_table.php)
 * so a new setting is just a seeded row, exactly like this one.
 *
 * Seeded with the homepage's current hardcoded og:image path so the site
 * keeps rendering identical output the moment this migration runs.
 */
final class AddOgImagePathToSiteSettings extends AbstractMigration
{
    public function up(): void
    {
        if (InstallState::isFreshInstall($this)) {
            // There is no homepage og:image to keep rendering here, and the
            // file this points at is a photograph of Van Veluw Laserdesign's
            // workshop — the default preview for every share on a site that
            // has nothing to do with it. It is also what made the Media
            // Library adopt that file on a brand-new install
            // (20260909270000). Left unset: App\Service\Branding renders no
            // og:image until an owner chooses one. See
            // src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->table('site_settings')->insert([
            [
                'setting_key' => 'og_image_path',
                'setting_value' => 'assets/images/hero-collage-a.webp',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM site_settings WHERE setting_key = 'og_image_path'");
    }
}
