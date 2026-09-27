<?php

declare(strict_types=1);

namespace App\Mail;

use App\Service\Language\SiteText;
use App\Service\ShopLocalizedSettings;

/**
 * Builds the "back in stock" mail (Shop Product & Ordering 2.0, MODULES.md
 * "Terug op voorraad"): subject, HTML and plain text, in the language the
 * visitor asked in. Pure content — no sending, no database access, no
 * decision about who gets it (App\Service\Inventory\StockNotifications).
 *
 * The subject and the text are the owner's, per website language
 * (App\Service\ShopLocalizedSettings, Shop-instellingen → E-mails), with the
 * standard text when nothing was typed. Only EmailPlaceholders::STOCK is
 * replaced, and never as code: the text is escaped and each paragraph gets
 * its own <p>, the way the order confirmation treats its copy. One thing the
 * owner cannot remove: the button to the product, so the mail always does
 * what it promised.
 *
 * A placeholder that is empty ({{variant}} for a product without variants)
 * leaves no double space and no space before a full stop.
 */
final class StockNotificationBuilder
{
    /**
     * @param array{product_name: string, variant: string, product_url: string} $facts
     * @return array{subject: string, html: string, text: string}
     */
    public static function build(array $facts, string $languageCode): array
    {
        $values = $facts + ['site_name' => EmailIdentity::name()];

        $render = static fn (string $key): string => self::tidy(EmailPlaceholders::render(
            ShopLocalizedSettings::value($key, $languageCode),
            $values,
            false,
            EmailPlaceholders::STOCK
        ));

        $subject = str_replace(["\r", "\n"], ' ', $render(ShopLocalizedSettings::STOCK_SUBJECT));
        $body = $render(ShopLocalizedSettings::STOCK_BODY);
        $button = SiteText::pick(['nl' => 'Bekijk het product', 'en' => 'View the product'], $languageCode);

        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split("/\n\s*\n/", str_replace("\r\n", "\n", $body)) ?: []),
            static fn (string $paragraph): bool => $paragraph !== ''
        ));

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . nl2br(self::esc($paragraph)) . '</p>';
        }
        $html .= '<p style="margin-top:24px;"><a href="' . self::esc($facts['product_url']) . '" style="display:inline-block;padding:10px 18px;border-radius:6px;background:#222;color:#fff;text-decoration:none;">'
            . self::esc($button) . '</a></p>';

        return [
            'subject' => $subject,
            'html' => '<!doctype html><html lang="' . self::esc($languageCode) . '"><head><meta charset="utf-8"></head>'
                . '<body style="margin:0;padding:24px;background:#f5f3f0;font-family:Arial,Helvetica,sans-serif;color:#222;">'
                . '<table role="presentation" style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;">'
                . '<tr><td>'
                . '<h1 style="font-size:20px;margin:0 0 16px;">' . self::esc($subject) . '</h1>'
                . $html
                . '<p style="margin-top:32px;font-size:12px;color:#999;">' . EmailIdentity::footerLine() . '</p>'
                . '</td></tr></table>'
                . '</body></html>',
            'text' => implode("\n\n", $paragraphs) . "\n\n" . $button . ': ' . $facts['product_url'] . "\n",
        ];
    }

    /** No double spaces and no space before punctuation where a placeholder was empty. */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

        return (string) preg_replace('/[ \t]+([.,!?:;])/', '$1', $text);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
