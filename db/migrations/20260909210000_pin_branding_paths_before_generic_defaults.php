<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Writes the branding paths this installation is CURRENTLY rendering into
 * `site_settings`, so that App\Service\SiteSettings can drop its Van
 * Veluw-specific code defaults without any existing site losing its logo,
 * favicon, site name or social image.
 *
 * Why it is needed. Those four keys used to have a Van Veluw value as their
 * CODE default, and a site only stored a row once somebody saved the
 * settings form. That is fine for one site and wrong for a CMS meant to be
 * installed twice: a fresh install would inherit another company's name and
 * a photograph of its workshop as the default preview image for every share.
 * The defaults therefore become generic (empty, and a neutral site name),
 * and this migration makes sure "generic default" can only ever mean "this
 * install has not chosen one yet".
 *
 * Forward-only and non-destructive: it only INSERTS a row where none exists.
 * A key that already has a row — which is the case for every key on the
 * live site — is left exactly as it is, including a deliberately emptied
 * one.
 */
final class PinBrandingPathsBeforeGenericDefaults extends AbstractMigration
{
    /**
     * The values App\Service\SiteSettings used to fall back to. Spelled out
     * here rather than read from that class, because the whole point is that
     * the class stops holding them.
     */
    private const PREVIOUS_CODE_DEFAULTS = [
        'site_name' => 'Van Veluw Laserdesign',
        'logo_path' => 'assets/images/vanveluwlaserdesignlogo.svg',
        'favicon_path' => 'assets/images/favicon-v.png',
        'og_image_path' => 'assets/images/hero-collage-a.webp',
    ];

    public function up(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        if (InstallState::isFreshInstall($this)) {
            // There is nothing to pin. When this migration was written, a
            // fresh install still received these four values from the seed
            // in 20260904190000, so INSERT IGNORE found rows and did
            // nothing; now that the seed stays out of a from-zero database,
            // the same INSERT would put them back — the one outcome this
            // migration exists to prevent. The generic code defaults are
            // already the right answer for an install that has not chosen
            // (App\Service\SiteSettings::DEFAULTS), and leaving the rows
            // absent is also what keeps 20260909270000 from adopting this
            // site's logo and favicon into a brand-new Media Library.
            // See src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        // INSERT IGNORE against the unique index on setting_key: a key that
        // already has a row is skipped, whatever it currently holds. That is
        // what makes this both idempotent and impossible to run backwards
        // over somebody's chosen value.
        foreach (self::PREVIOUS_CODE_DEFAULTS as $key => $value) {
            $this->execute(
                'INSERT IGNORE INTO site_settings (setting_key, setting_value, created_at, updated_at)
                 VALUES (?, ?, ?, ?)',
                [$key, $value, $now, $now]
            );
        }
    }

    /**
     * Nothing to undo: the rows this wrote are ordinary settings rows, and
     * deleting them would take a value the owner may since have changed.
     */
    public function down(): void
    {
    }
}
