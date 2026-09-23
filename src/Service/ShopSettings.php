<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\EmailIdentity;
use App\Mail\EmailPlaceholders;
use App\Mail\OrderConfirmationBuilder;
use App\Service\Language\AdminTranslator;

/**
 * The webshop's own settings screen (admin/shop-settings.php): what it may
 * submit, how it is grouped, and what a submission needs before
 * api/admin/update-shop-settings.php stores it.
 *
 * WHY A SCREEN OF ITS OWN. Invoices, order numbers and the order confirmation
 * e-mail only exist while the Shop runs. They used to be two tabs of
 * Instellingen, a Core screen that looks the same on a site without a
 * shop, so a CMS-only site offered settings for invoices it can never issue.
 * The values did not move: they are the same keys in the same site_settings
 * table (App\Service\SiteSettings), which is why switching the Shop off and
 * on again changes nothing that is stored.
 *
 * Same shape as App\Service\SiteSettingsValidator: FIELDS is the closed list
 * the screen's forms carry, with the longest value each accepts; the screen
 * writes the same numbers into its maxlength attributes; nothing outside the
 * list is ever written here. Nothing here is required, because every reader
 * copes with an empty value: an empty invoice prefix is INV, an empty
 * order-number prefix is ORD, an empty e-mail text is the standard text, and
 * the invoice leaves an empty row out.
 *
 * Deliberately NOT here: the address, the KVK number, the e-mail address and
 * the phone number an invoice also prints. Those are the site's, the footer
 * and the e-mail footer line read them too, and they are edited once, on
 * Instellingen.
 */
final class ShopSettings
{
    /** @var array<string, int> key => the longest value it accepts */
    public const FIELDS = [
        'company_name' => 150,
        'company_vat_id' => 30,
        'company_website' => 150,
        'invoice_number_prefix' => 20,
        'invoice_tax_note' => 500,
        'invoice_payment_note' => 500,
        'invoice_footer_text' => 500,
        'order_number_prefix' => 10,
        'order_email_subject' => 255,
        'order_email_heading' => 150,
        'order_email_intro' => 1000,
        'order_email_before_items' => 500,
        'order_email_after_items' => 500,
        'order_email_closing' => 500,
        'order_email_signature' => 500,
    ];

    /**
     * The settings that are a choice from a list rather than text: stored in
     * the same site_settings table, validated one by one in validate().
     *
     * @var list<string>
     */
    public const CHOICES = [
        ShopOverview::SETTING_KEY,
    ];

    /**
     * The screen's tabs and the fields on each: one <form> per tab, and the
     * `section` a save returns to. A closed list, so a request can only ever
     * name one of these.
     *
     * @var array<string, list<string>>
     */
    public const TABS = [
        'bedrijf' => ['company_name', 'company_vat_id', 'company_website'],
        'facturen' => ['invoice_number_prefix', 'invoice_tax_note', 'invoice_payment_note', 'invoice_footer_text'],
        'bestellingen' => ['order_number_prefix'],
        'emails' => OrderConfirmationBuilder::CUSTOMER_COPY_KEYS,
        'overzicht' => [ShopOverview::SETTING_KEY],
    ];

    /**
     * @param array<string, mixed>  $post    the request body
     * @param array<string, string> $current App\Service\SiteSettings::all()
     *
     * @return array{values: array<string, string>, errors: list<string>}
     *         `values` holds only the listed keys the request carried, trimmed:
     *         exactly what may be written
     */
    public static function validate(array $post, array $current): array
    {
        $values = [];

        foreach (self::FIELDS as $key => $maxLength) {
            if (array_key_exists($key, $post) && is_scalar($post[$key])) {
                $values[$key] = trim((string) $post[$key]);
            }
        }

        $errors = [];

        // On changed values only, for the reason SiteSettingsValidator gives:
        // an older stored value must never be why an unrelated save fails.
        foreach ($values as $key => $value) {
            if ($value !== ($current[$key] ?? '') && mb_strlen($value) > self::FIELDS[$key]) {
                $errors[] = AdminTranslator::trans('validation.a_field_is_too_long');
                break;
            }
        }

        /*
         * The order-number prefix (App\Repository\OrderRepository::formatOrderNumber()).
         * Letters and digits only: it ends up in customer e-mails, in the
         * Mollie description on a bank statement and in a CSV cell, and the
         * formatter owns the separators. Refused rather than cleaned, so what
         * an owner sees after saving is what they typed. Empty means the
         * generic default.
         */
        if (
            ($values['order_number_prefix'] ?? '') !== ''
            && preg_match('/^[A-Za-z0-9]{1,10}$/', $values['order_number_prefix']) !== 1
        ) {
            $errors[] = AdminTranslator::trans('validation.bestelnummerprefix_ongeldig');
        }

        /*
         * Which page is the product overview (App\Service\ShopOverview): no
         * page, one of the pages it offers, or the automatic listing while it
         * is already the stored value. A choice from a list, not text, so it
         * is not one of FIELDS; anything but those choices is refused, so a
         * request can never point the storefront at a page it was not offered.
         */
        if (array_key_exists(ShopOverview::SETTING_KEY, $post)) {
            $overview = ShopOverview::normalise($post[ShopOverview::SETTING_KEY], (string) ($current[ShopOverview::SETTING_KEY] ?? ''));

            if ($overview === null) {
                $errors[] = AdminTranslator::trans('validation.shop_overview_invalid');
            } else {
                $values[ShopOverview::SETTING_KEY] = $overview;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** The tab a request names, if it is one of TABS; the first tab otherwise. */
    public static function section(mixed $requested): string
    {
        return is_string($requested) && array_key_exists($requested, self::TABS)
            ? $requested
            : (string) array_key_first(self::TABS);
    }

    /**
     * The explanation behind the "?" beside Invulvelden: every placeholder
     * the e-mail really replaces (App\Mail\EmailPlaceholders::KNOWN), each
     * with what it becomes. Built from that list rather than written out, so
     * a placeholder added there cannot go unexplained here, and one that does
     * not exist cannot be explained at all.
     */
    public static function placeholderHelp(): string
    {
        $paragraphs = [AdminTranslator::trans('help.shop_settings.placeholders')];

        foreach (EmailPlaceholders::KNOWN as $placeholder) {
            $paragraphs[] = '<strong>{{' . $placeholder . '}}</strong>' . "\n"
                . AdminTranslator::trans('help.shop_settings.placeholder.' . $placeholder);
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Why order confirmation e-mails cannot go out right now, as catalog
     * keys; empty when nothing stands in the way.
     *
     * The two things App\Service\OrderConfirmationService needs before it
     * sends anything: a sender (App\Mail\EmailIdentity) and an address for
     * the shop's own copy (SHOP_NOTIFICATION_EMAIL). Without either it sends
     * neither e-mail and only writes a line in the server log. This is how
     * the owner hears it too.
     *
     * @return list<string>
     */
    public static function confirmationMailProblems(): array
    {
        $problems = [];

        if (!EmailIdentity::isConfigured()) {
            $problems[] = 'shop_settings.warning_no_sender';
        }

        if (trim((string) ($_ENV['SHOP_NOTIFICATION_EMAIL'] ?? '')) === '') {
            $problems[] = 'shop_settings.warning_no_notification_address';
        }

        return $problems;
    }
}
