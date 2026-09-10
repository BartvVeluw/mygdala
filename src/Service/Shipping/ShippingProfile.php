<?php

namespace App\Service\Shipping;

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

    private const LABELS_NL = [
        self::LETTER => 'Briefpost',
        self::LETTERBOX => 'Brievenbuspakket',
        self::PARCEL => 'Pakket',
    ];

    private function __construct()
    {
    }

    public static function isValid(string $profile): bool
    {
        return in_array($profile, self::ALL, true);
    }

    public static function label(string $profile): string
    {
        return self::LABELS_NL[$profile] ?? $profile;
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
