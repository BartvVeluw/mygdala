<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Writes the business details and the public base URL this installation is
 * CURRENTLY using into `site_settings`, so that App\Service\SiteSettings and
 * App\Service\AppUrl can drop their Van Veluw-specific code defaults without
 * any existing site losing its e-mail address, its city, its KVK number, its
 * footer blurb, its invoice prefix or its canonical domain.
 *
 * Exactly the same shape and the same reasoning as
 * 20260909210000_pin_branding_paths_before_generic_defaults.php, one step
 * further: that one covered the four branding keys, this one covers the rest
 * of the identity. A value that used to be a CODE default becomes a real
 * ROW, and the code default becomes generic — so "generic default" can only
 * ever mean "this install has not filled it in yet", which is what the Setup
 * Wizard asks about (SETUP.md).
 *
 * THE BASE URL is the one genuinely new row here. Until now
 * App\Service\AppUrl fell back to this site's own domain in code, and
 * APP_URL is not set in this project's .env — so a brand-new installation
 * published canonical tags, og:url values and a sitemap pointing at Van
 * Veluw Laserdesign. AppUrl's chain is now: APP_URL in .env, then this row,
 * then an obviously-local placeholder. This migration puts the current
 * answer in the middle step, so nothing about an existing site's SEO output
 * changes; APP_URL still wins wherever it is set. See SEO.md.
 *
 * NOT RUN ON A FRESH INSTALL, unlike its predecessor which did not need the
 * guard when it was written. A from-zero database no longer receives these
 * rows from the historical seeds either (20260904190000, 20260906030000,
 * 20260907200000), and pinning them here would put back precisely what those
 * guards keep out.
 *
 * Forward-only and non-destructive: INSERT IGNORE against the unique index
 * on setting_key, so a key that already has a row keeps whatever it holds,
 * including a deliberately emptied one. Idempotent.
 */
final class PinBusinessDetailsBeforeGenericDefaults extends AbstractMigration
{
    /**
     * The values App\Service\SiteSettings and App\Service\AppUrl used to
     * fall back to. Spelled out here rather than read from those classes,
     * because the whole point is that they stop holding them.
     */
    private const PREVIOUS_CODE_DEFAULTS = [
        'email' => 'info@vanveluwlaserdesign.nl',
        'city_nl' => 'Nijmegen, Nederland',
        'city_en' => 'Nijmegen, the Netherlands',
        'kvk_number' => '97749540',
        'footer_description_nl' => 'Persoonlijke lasergravure op hout, metaal, acryl en glas — ontworpen en gemaakt in Nijmegen.',
        'footer_description_en' => 'Personal laser engraving on wood, metal, acrylic and glass — designed and made in Nijmegen.',
        'company_city' => 'Nijmegen',
        'company_website' => 'www.vanveluwlaserdesign.nl',
        'invoice_number_prefix' => 'VLD-F',

        // App\Service\AppUrl::DEFAULT_BASE_URL as it was, and the value this
        // site's canonical tags, og:url and sitemap.xml have always used.
        'canonical_base_url' => 'https://www.vanveluwlaserdesign.nl',
    ];

    public function up(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        if (InstallState::isFreshInstall($this)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

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
