<?php

namespace App\Service\Shipping;

use App\Service\Language\SiteText;

/**
 * The three supported shipping profiles (products.shipping_profile,
 * shipping_rates.shipping_profile) and the deterministic priority rule for
 * mixed carts — see MAIN.MD "Mixed shipping profiles":
 *
 *   parcel > letterbox > letter
 *
 * A parcel can always carry what a letter/letterbox can, never the other way
 * around, so a cart mixing profiles always ships as a whole under the
 * highest one present.
 *
 * A profile's NAME is a closed code catalogue keyed by language code
 * (App\Service\Language\SiteText::pick()). The caller says which language:
 * the checkout's quote the request's, the CMS its interface language
 * (App\Service\Language\AdminLocale), the Dutch-only confirmation mail
 * Dutch.
 */
final class ShippingProfile
{
    public const LETTER = 'letter';
    public const LETTERBOX = 'letterbox';
    public const PARCEL = 'parcel';

    public const ALL = [self::LETTER, self::LETTERBOX, self::PARCEL];

    private const PRIORITY = [
        self::LETTER => 0,
        self::LETTERBOX => 1,
        self::PARCEL => 2,
    ];

    /** @var array<string, array<string, string>> */
    private const LABELS = [
        self::LETTER => ['nl' => 'Briefpost', 'en' => 'Letter post'],
        self::LETTERBOX => ['nl' => 'Brievenbuspakket', 'en' => 'Letterbox parcel'],
        self::PARCEL => ['nl' => 'Pakket', 'en' => 'Parcel'],
    ];

    private function __construct()
    {
    }

    public static function isValid(string $profile): bool
    {
        return in_array($profile, self::ALL, true);
    }

    /** The profile's name in $languageCode (the request's language when null), or the key itself. */
    public static function label(string $profile, ?string $languageCode = null): string
    {
        return isset(self::LABELS[$profile]) ? SiteText::pick(self::LABELS[$profile], $languageCode) : $profile;
    }

    /**
     * Given every shipping profile present across a cart's line items,
     * returns the one profile the whole order must ship under.
     *
     * @param array<int, string> $profiles
     */
    public static function highestOf(array $profiles): string
    {
        $highest = self::LETTER;

        foreach ($profiles as $profile) {
            if (self::rank($profile) > self::rank($highest)) {
                $highest = $profile;
            }
        }

        return $highest;
    }

    private static function rank(string $profile): int
    {
        return self::PRIORITY[$profile] ?? 0;
    }
}
