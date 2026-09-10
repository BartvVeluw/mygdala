<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * New `site_settings` keys for the invoice/company details and the
 * CMS-editable parts of the order-confirmation email (see MAIN.MD
 * "Transactional e-mail + factuur" and App\Service\SiteSettings::DEFAULTS,
 * which this must stay in sync with). No schema change — site_settings is
 * key/value on purpose (20260904190000) — this is purely seeded rows,
 * exactly like 20260906030000_add_og_image_path_to_site_settings.php.
 *
 * Company identity (name/logo/KVK/e-mail) already exists
 * (site_name/logo_path/kvk_number/email) and is reused as-is on the
 * invoice — only the missing structured postal address + optional
 * tax/contact fields are added here. VAT/KOR wording is left blank on
 * purpose: MAIN.MD "BTW/KOR" explicitly says this is still an undecided,
 * out-of-scope business decision — the invoice must stay configurable, not
 * presumptive, so no legal text is invented.
 *
 * Email-copy defaults are seeded to the exact current hardcoded text in
 * App\Mail\OrderConfirmationBuilder, so this migration changes nothing
 * about what a customer receives until an admin actually edits a field in
 * Settings.
 */
final class AddInvoicingAndEmailSettings extends AbstractMigration
{
    public function up(): void
    {
        if (InstallState::isFreshInstall($this)) {
            // Nijmegen, www.vanveluwlaserdesign.nl, the invoice prefix
            // "VLD-F" and an order e-mail signed with this company's name
            // are Van Veluw Laserdesign's business details, not a starting
            // point for someone else's shop. A database with no history has
            // no invoice or order mail to keep identical, so nothing is
            // seeded and App\Service\SiteSettings' generic defaults apply
            // until the owner fills them in. See src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $rows = [
            // Company postal address for the invoice (structured, distinct
            // from the free-text "city_nl"/"city_en" used for site copy).
            'company_street' => '',
            'company_house_number' => '',
            'company_postal_code' => '',
            'company_city' => 'Nijmegen',
            'company_country' => 'NL',
            'company_phone' => '',
            'company_website' => 'www.vanveluwlaserdesign.nl',
            'company_vat_id' => '',

            // Invoice-specific settings.
            'invoice_number_prefix' => 'VLD-F',
            'invoice_footer_text' => '',
            'invoice_tax_note' => '',
            'invoice_payment_note' => '',

            // CMS-editable order-confirmation email copy (customer email
            // only — the shop/internal notification email is unaffected).
            // {{customer_name}}, {{order_number}}, {{order_date}} and
            // {{order_total}} are the only supported placeholders (see
            // App\Mail\EmailPlaceholders) — unknown tokens are left as
            // literal text, never executed.
            'order_email_subject' => 'Bevestiging van je bestelling {{order_number}} — Van Veluw Laserdesign',
            'order_email_heading' => 'Bedankt voor je bestelling!',
            'order_email_intro' => "Beste {{customer_name}},\n\nJe betaling voor bestelling {{order_number}} is gelukt. We gaan zo snel mogelijk voor je aan de slag.",
            'order_email_before_items' => '',
            'order_email_after_items' => '',
            'order_email_closing' => 'Heb je vragen over je bestelling? Antwoord gerust op deze e-mail.',
            'order_email_signature' => '',
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
            'company_street', 'company_house_number', 'company_postal_code', 'company_city',
            'company_country', 'company_phone', 'company_website', 'company_vat_id',
            'invoice_number_prefix', 'invoice_footer_text', 'invoice_tax_note', 'invoice_payment_note',
            'order_email_subject', 'order_email_heading', 'order_email_intro',
            'order_email_before_items', 'order_email_after_items', 'order_email_closing', 'order_email_signature',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $this->execute("DELETE FROM site_settings WHERE setting_key IN ($placeholders)", $keys);
    }
}
