<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * New `site_settings` keys for the footer's "Brand / Company block" (see
 * App\Service\SiteSettings::DEFAULTS, which this must stay in sync with,
 * and App\Service\FooterService). No schema change — site_settings is
 * key/value on purpose — this is purely seeded rows, same convention as
 * 20260907200000_add_invoicing_and_email_settings.php.
 *
 * Company identity itself (site_name/logo_path/email/kvk_number/
 * company_phone/footer_description_nl/en) already exists and is reused
 * as-is — these new keys only ever decide *whether* the footer shows each
 * one, never store a second copy of the value (see MAIN.MD, "Global
 * Navigation + Footer").
 *
 * Defaults reproduce the current hardcoded footer's output as closely as
 * the new Brand-block layout allows: logo/email/KVK were always shown, so
 * default on; company name and phone were never shown as their own line,
 * so default off (an admin can turn them on later, nothing new appears
 * until they do). footer_copyright_template supports exactly two small,
 * safe placeholders — {{year}} and {{site_name}} — resolved by
 * FooterService::renderCopyright(); the KVK suffix is appended separately,
 * controlled by footer_show_kvk, not part of the free-text template.
 */
final class AddFooterSettings extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            'footer_show_logo' => '1',
            'footer_show_company_name' => '0',
            'footer_show_email' => '1',
            'footer_show_phone' => '0',
            'footer_show_kvk' => '1',
            'footer_copyright_template' => '© {{year}} {{site_name}}',
        ];

        $data = [];
        foreach ($rows as $key => $value) {
            $data[] = [
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table('site_settings')->insert($data)->saveData();
    }

    public function down(): void
    {
        $keys = [
            'footer_show_logo', 'footer_show_company_name', 'footer_show_email',
            'footer_show_phone', 'footer_show_kvk', 'footer_copyright_template',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $this->execute("DELETE FROM site_settings WHERE setting_key IN ($placeholders)", $keys);
    }
}
