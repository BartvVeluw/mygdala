<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;

/**
 * What a product was CALLED at the moment it was bought, in the languages an
 * order line kept (Multilingual 2.0 phase 5 wave C,
 * docs/multilingual/ARCHITECTURE.md).
 *
 * A CLASS OF ITS OWN, on purpose, because a snapshot is not a translation:
 *
 *   - App\Service\ShopLocalization says what a product is called NOW, and
 *     changes the moment an editor changes it;
 *   - this says what it was called THEN, and must never change again — not
 *     when the product is renamed, not when it is deleted, and not when the
 *     site's default language moves.
 *
 * THE TWO HALVES OF A SNAPSHOT.
 *
 *   `order_items.product_name`      one language-free name, the one the
 *                                   invoice, the confirmation e-mail and the
 *                                   CMS order screen print. Written once by
 *                                   checkout and never read through this
 *                                   class.
 *   `order_item_translations`       the OTHER language versions the
 *                                   order-status page offers a visitor.
 *
 * THE READING RULE IS NOT LanguageFallback's, and that is deliberate. It is
 * "the row for this language, else the neutral snapshot" — the neutral name is
 * the fallback by construction rather than by being some language's. So a
 * document can never break: deleting a website language, adding one, or moving
 * the default cannot change what a placed order says, because none of that
 * touches `order_items.product_name` and nothing here consults the registry to
 * decide what to fall back to.
 *
 * It is also exactly what the browser did before phase 7: shop.js rendered an
 * order line as `item.name_en || item.name`, so "no English name" always
 * meant "show the neutral one". This class states that rule on the server,
 * and api/order-status.php sends the one name it picks.
 *
 * WHAT CHECKOUT WRITES. The neutral name is the product's name in the DEFAULT
 * language (api/checkout.php), and one row per other active website language
 * that has words of its own — `raw()`, not `value()`, so a language without
 * its own name gets no row rather than a copy of the default one. On a
 * Dutch-default site with Dutch and English that is byte-for-byte what the two
 * columns held before this wave.
 */
final class OrderItemNameSnapshot
{
    public const PRODUCT_NAME = 'product_name';
    public const PRODUCT_NAME_MAX_LENGTH = 255;

    private static ?EntityTranslations $names = null;

    /** The store, for the few callers that need more than the methods below. */
    public static function names(): EntityTranslations
    {
        return self::$names ??= new EntityTranslations(
            new TranslationTable('order_item_translations', 'order_item_id', [
                self::PRODUCT_NAME => self::PRODUCT_NAME_MAX_LENGTH,
            ])
        );
    }

    /**
     * What this order line was called in one language: its own snapshot when
     * it kept one, else the neutral snapshot on the line itself.
     *
     * $neutralName is `order_items.product_name`, which the caller has in hand
     * already — passing it in rather than re-reading it keeps this class out
     * of the order tables entirely.
     */
    public static function name(int $orderItemId, string $languageCode, string $neutralName): string
    {
        $own = trim(self::names()->raw($orderItemId, self::PRODUCT_NAME, $languageCode));

        return $own !== '' ? $own : $neutralName;
    }

    /** @param list<int> $orderItemIds */
    public static function preload(array $orderItemIds): void
    {
        self::names()->preload($orderItemIds);
    }

    /**
     * Records what one order line's product was called in one language, at
     * purchase time. Called only by checkout, once per line, inside the
     * transaction that creates the order.
     *
     * There is no update path and there never should be: a snapshot that can
     * be rewritten is not a snapshot.
     */
    public static function record(int $orderItemId, string $languageCode, string $name): void
    {
        self::names()->save($orderItemId, $languageCode, [self::PRODUCT_NAME => $name]);
    }

    /**
     * The languages a new order line should keep a name in, beside the neutral
     * one: every active website language except the default, because the
     * default language's name IS the neutral snapshot.
     *
     * @return list<string>
     */
    public static function extraLanguages(): array
    {
        $default = LanguageFallback::defaultLanguage();
        $codes = [];

        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            if ($code !== $default) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function clearCache(): void
    {
        self::names()->clearCache();
    }
}
