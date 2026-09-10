<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Generic key/value store for globally-repeated site/business content
 * (company name, logo, favicon, contact details, KVK number, footer blurb,
 * ...). Deliberately a key/value shape rather than fixed columns so a new
 * setting never needs a schema migration, and so the same table shape can be
 * reused as-is by future, separate website installations — each install just
 * seeds its own rows.
 *
 * App\Service\SiteSettings is the only thing that should read/write this
 * table; it also owns the fallback defaults used when a key is missing.
 */
final class CreateSiteSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('site_settings', ['id' => true]);
        $table
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // A database with no history has no header, footer or contact
            // page rendering Van Veluw Laserdesign's details, so there is
            // nothing to preserve — and seeding them would hand a brand-new
            // site another company's name, e-mail address and Chamber of
            // Commerce number. The table is left EMPTY on purpose: a missing
            // row means App\Service\SiteSettings' own (generic) default, and
            // the Setup Wizard is where an owner fills these in (SETUP.md).
            // See src/Install/InstallState.php.
            return;
        }

        // Seed the values that are currently hardcoded/repeated across the
        // public site (header/footer/contact) so the site keeps rendering
        // identical output the moment this migration runs.
        $now = date('Y-m-d H:i:s');
        $defaults = [
            'site_name' => 'Van Veluw Laserdesign',
            'logo_path' => 'assets/images/vanveluwlaserdesignlogo.svg',
            'favicon_path' => 'assets/images/favicon-v.png',
            'email' => 'info@vanveluwlaserdesign.nl',
            'city_nl' => 'Nijmegen, Nederland',
            'city_en' => 'Nijmegen, the Netherlands',
            'kvk_number' => '97749540',
            'footer_description_nl' => 'Persoonlijke lasergravure op hout, metaal, acryl en glas — ontworpen en gemaakt in Nijmegen.',
            'footer_description_en' => 'Personal laser engraving on wood, metal, acrylic and glass — designed and made in Nijmegen.',
        ];

        $rows = [];
        foreach ($defaults as $key => $value) {
            $rows[] = [
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('site_settings')->drop()->save();
    }
}
