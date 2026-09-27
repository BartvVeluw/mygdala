<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\LanguageFallback;
use App\Service\Language\LocalizedSettings;
use App\Service\Language\SiteText;

/**
 * The Shop's settings that are WEBSITE TEXT in a language (Shop Product &
 * Ordering 2.0): the subject and the text of the "back in stock" mail, which
 * the owner edits per website language under Shop-instellingen → E-mails.
 *
 *   stock_notification_subject   the subject line
 *   stock_notification_body      the text, plain paragraphs
 *
 * A CLOSED CATALOGUE, held by the Shop itself, in the one physical
 * localized-settings table (`site_setting_translations`) through
 * App\Service\Language\LocalizedSettings — the pattern of
 * App\Service\Blog\BlogLocalizedSettings. The storage is shared, the
 * catalogue is not: Core never names these keys, and a request can never
 * reach a key outside this list.
 *
 * THE FALLBACK is the store's (the asked-for language, then the default
 * language), and after it the code's own standard text, per language, so a
 * mail is never empty: an owner who changed nothing sends DEFAULTS in the
 * visitor's language. {{placeholders}} are replaced by
 * App\Mail\StockNotificationBuilder from App\Mail\EmailPlaceholders::STOCK;
 * the text is never HTML.
 */
final class ShopLocalizedSettings
{
    public const STOCK_SUBJECT = 'stock_notification_subject';
    public const STOCK_BODY = 'stock_notification_body';

    public const SUBJECT_MAX_LENGTH = 255;
    public const BODY_MAX_LENGTH = 3000;

    /** @var array<string, int> key => maximum length in characters */
    public const KEYS = [
        self::STOCK_SUBJECT => self::SUBJECT_MAX_LENGTH,
        self::STOCK_BODY => self::BODY_MAX_LENGTH,
    ];

    /**
     * The standard text, per language: what a shop that changed nothing
     * sends, and what "Herstel standaardtekst" puts back.
     *
     * @var array<string, array<string, string>>
     */
    public const DEFAULTS = [
        self::STOCK_SUBJECT => [
            'nl' => '{{product_name}} is weer op voorraad',
            'en' => '{{product_name}} is back in stock',
        ],
        self::STOCK_BODY => [
            'nl' => "Goed nieuws: {{product_name}} {{variant}} is weer te bestellen.\n\nJe vroeg ons je te laten weten wanneer het weer beschikbaar is. Bekijk het hier:\n{{product_url}}\n\nOp is op: we houden niets voor je apart.\n\nMet vriendelijke groet,\n{{site_name}}",
            'en' => "Good news: {{product_name}} {{variant}} can be ordered again.\n\nYou asked us to let you know when it was available again. Have a look here:\n{{product_url}}\n\nOnce it's gone, it's gone: we do not hold one for you.\n\nKind regards,\n{{site_name}}",
        ],
    ];

    private static ?LocalizedSettings $store = null;

    public static function store(): LocalizedSettings
    {
        return self::$store ??= new LocalizedSettings(self::KEYS);
    }

    /** The text of one key in one language: stored (with the fallback), else the standard text. */
    public static function value(string $key, string $languageCode): string
    {
        $stored = self::store()->value($key, $languageCode);

        return $stored !== '' ? $stored : self::standard($key, $languageCode);
    }

    /** The standard text of one key in one language (the default language's for a third one). */
    public static function standard(string $key, string $languageCode): string
    {
        if (!isset(self::DEFAULTS[$key])) {
            throw new \InvalidArgumentException('Unknown Shop setting: ' . $key);
        }

        return SiteText::pick(self::DEFAULTS[$key], $languageCode);
    }

    /** The stored words in one language, no fallback and no standard text (what an editor shows). */
    public static function raw(string $key, string $languageCode): string
    {
        return self::store()->raw($key, $languageCode);
    }

    /**
     * @param array<string, string|null> $values
     * @return array<string, string> key => 'too_long'
     */
    public static function problems(array $values): array
    {
        return self::store()->problems($values);
    }

    /**
     * Store one language's words. Every other language stays as it is, and an
     * empty value removes that language's row (the standard text applies).
     *
     * @param array<string, string|null> $values
     */
    public static function save(string $languageCode, array $values): void
    {
        self::store()->save($languageCode, $values);
    }

    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::store()->clearCache();
    }
}
