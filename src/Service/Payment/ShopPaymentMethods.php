<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Language\SiteText;
use App\Service\SiteSettings;

/**
 * Which payment methods the checkout offers: the owner's choice on
 * Shop → Betalingen, stored here, never asked of Mollie on a page view.
 *
 * TWO LISTS, KEPT APART
 *
 *   available  what Mollie has switched on for the account, asked live by
 *              the Betalingen screen (PaymentProvider::availableMethods()).
 *              The only source of truth for what CAN be offered: nothing in
 *              this file decides that
 *   enabled    what the owner chose to offer, stored as method ids in
 *              `site_settings.shop_payment_methods`, together with the names
 *              Mollie gave them in every website language at the moment of
 *              saving (`shop_payment_method_names`), so the checkout can name
 *              a method without a request to Mollie
 *
 * THE DEFAULT IS WHAT THE CHECKOUT ALWAYS OFFERED: iDEAL and credit card,
 * with the words it always had. An installation from before this screen
 * therefore offers exactly those two after an update, and not every method
 * Mollie happens to have switched on; the owner widens the list on purpose.
 * Those two are also the only ids with words of their own below, and only
 * as a fallback for an installation that never saved a choice.
 *
 * `kaart` is what the checkout's radio used to send for credit card; a page
 * or script from before the update may still send it, so it is read as
 * `creditcard`.
 */
final class ShopPaymentMethods
{
    public const SETTING_KEY = 'shop_payment_methods';
    public const NAMES_SETTING_KEY = 'shop_payment_method_names';

    /** What the checkout offered before the owner could choose. */
    public const DEFAULT = ['ideal', 'creditcard'];

    /** The checkout's old value for credit card. */
    private const ALIASES = ['kaart' => 'creditcard'];

    /**
     * The words the checkout always had for the two default methods, used
     * only while no name from Mollie is stored.
     *
     * @var array<string, array{name: array<string, string>, note: array<string, string>}>
     */
    private const DEFAULT_WORDS = [
        'ideal' => [
            'name' => ['nl' => 'iDEAL', 'en' => 'iDEAL'],
            'note' => ['nl' => 'Direct betalen via je eigen bank', 'en' => 'Pay directly through your own bank'],
        ],
        'creditcard' => [
            'name' => ['nl' => 'Creditcard', 'en' => 'Credit card'],
            'note' => ['nl' => 'Visa, Mastercard', 'en' => 'Visa, Mastercard'],
        ],
    ];

    /** Whether $id has the shape of a Mollie method id ("ideal", "creditcard", "paybybank"). */
    public static function isMethodId(string $id): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) === 1;
    }

    /**
     * The method ids the checkout offers, in the owner's order.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        $ids = [];
        foreach (explode(',', SiteSettings::get(self::SETTING_KEY)) as $id) {
            $id = trim($id);
            if (self::isMethodId($id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids !== [] ? $ids : self::DEFAULT;
    }

    /**
     * The stored names: method id => language code => name.
     *
     * @return array<string, array<string, string>>
     */
    public static function storedNames(): array
    {
        $decoded = json_decode(SiteSettings::get(self::NAMES_SETTING_KEY), true);
        if (!is_array($decoded)) {
            return [];
        }

        $names = [];
        foreach ($decoded as $id => $byLanguage) {
            if (!is_string($id) || !self::isMethodId($id) || !is_array($byLanguage)) {
                continue;
            }
            foreach ($byLanguage as $language => $name) {
                if (is_string($language) && is_string($name) && trim($name) !== '') {
                    $names[$id][$language] = trim($name);
                }
            }
        }

        return $names;
    }

    /**
     * Whether the owner ever saved a choice on Shop → Betalingen. Until then
     * the checkout offers DEFAULT, and the screen says so.
     */
    public static function isChosen(): bool
    {
        return SiteSettings::get(self::NAMES_SETTING_KEY) !== '';
    }

    /**
     * A method's name in $language when nothing better is at hand: the name
     * stored for it (in that language, else any), the checkout's own word for
     * a default method, or its id.
     */
    public static function name(string $id, string $language): string
    {
        $stored = self::storedNames()[$id] ?? [];
        if ($stored !== []) {
            return $stored[$language] ?? (string) reset($stored);
        }

        if (isset(self::DEFAULT_WORDS[$id])) {
            return self::DEFAULT_WORDS[$id]['name'][$language] ?? self::DEFAULT_WORDS[$id]['name']['en'];
        }

        return $id;
    }

    /**
     * What the checkout shows, in the language of the request: every enabled
     * method with its name and, for the two default methods, the line under
     * it the checkout always had. Plain text; the template escapes it.
     *
     * @return list<array{id: string, name: string, note: string}>
     */
    public static function forCheckout(): array
    {
        $names = self::storedNames();
        $methods = [];

        foreach (self::enabled() as $id) {
            $byLanguage = $names[$id] ?? (self::DEFAULT_WORDS[$id]['name'] ?? ['en' => $id]);
            $methods[] = [
                'id' => $id,
                'name' => SiteText::pick($byLanguage),
                'note' => isset(self::DEFAULT_WORDS[$id]) ? SiteText::pick(self::DEFAULT_WORDS[$id]['note']) : '',
            ];
        }

        return $methods;
    }

    /**
     * The method a checkout request asked for, when the owner offers it; null
     * for anything else, so a request can never start a payment with a
     * method the shop does not offer.
     */
    public static function resolveChoice(mixed $submitted): ?string
    {
        if (!is_string($submitted)) {
            return null;
        }

        $id = self::ALIASES[$submitted] ?? $submitted;

        return in_array($id, self::enabled(), true) ? $id : null;
    }

    /**
     * The value stored for a list of ids, in their order.
     *
     * @param list<string> $ids
     */
    public static function serialise(array $ids): string
    {
        return implode(',', $ids);
    }
}
