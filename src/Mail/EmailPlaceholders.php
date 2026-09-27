<?php

namespace App\Mail;

/**
 * Small, deliberately limited placeholder substitution for CMS-editable
 * email text (App\Service\SiteSettings `order_email_*` keys) — see MAIN.MD
 * "CMS-editable email template". Only a fixed, known set of tokens is ever
 * substituted; anything else matching `{{...}}` is left as literal text
 * rather than removed or executed, so a typo'd/unknown placeholder can
 * never break rendering. There is no way to reach arbitrary PHP/executable
 * syntax through this — it is pure string substitution, never `eval`/
 * variable-variables/callable strings.
 */
class EmailPlaceholders
{
    /** The order confirmation's placeholders (App\Mail\OrderConfirmationBuilder). */
    public const KNOWN = ['customer_name', 'order_number', 'order_date', 'order_total', 'site_name'];

    /**
     * The back-in-stock mail's placeholders (App\Mail\StockNotificationBuilder,
     * Shop Product & Ordering 2.0): only what that mail really knows. There
     * is no customer name — nobody gives one to ask for a notification.
     */
    public const STOCK = ['product_name', 'variant', 'product_url', 'site_name'];

    /**
     * Substitutes `{{key}}` tokens in $template using $values (only keys
     * listed in KNOWN are recognised; anything else in $values is ignored).
     *
     * When $escapeHtml is true, the *template* text is HTML-escaped first
     * (so any stray "<"/">" an admin typed can never open a tag), then each
     * substituted value is escaped too — substitution never re-opens
     * already-escaped markup because the inserted value itself contains no
     * unescaped "<"/">"/"&"/quotes.
     *
     * $known is the closed list this mail replaces (KNOWN or STOCK); a token
     * from another mail's list stays literal text here.
     *
     * @param array<string, string> $values
     * @param list<string> $known
     */
    public static function render(string $template, array $values, bool $escapeHtml, array $known = self::KNOWN): string
    {
        $text = $escapeHtml ? htmlspecialchars($template, ENT_QUOTES, 'UTF-8') : $template;

        return preg_replace_callback(
            '/\{\{\s*(' . implode('|', array_map('preg_quote', $known)) . ')\s*\}\}/',
            static function (array $matches) use ($values, $escapeHtml): string {
                $value = $values[$matches[1]] ?? '';
                return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            },
            $text
        ) ?? $text;
    }
}
