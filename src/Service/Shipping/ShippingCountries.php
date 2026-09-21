<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Service\Language\SiteText;

/**
 * The names of the countries this shop ships to, as a customer reads them.
 *
 * A CLOSED CODE CATALOGUE keyed by ISO country code and then by website
 * language code (App\Service\Language\SiteText::pick()): the checkout's own
 * country selects and /api/shipping-zones.php both name a country from here,
 * in the language of the request, so the two can never disagree. A country
 * code this catalogue does not know is shown as its code — a shipping zone
 * may name one before anybody wrote its name down, and the code is still
 * what the order stores.
 */
final class ShippingCountries
{
    /** @var array<string, array<string, string>> */
    private const NAMES = [
        'NL' => ['nl' => 'Nederland', 'en' => 'Netherlands'],
        'BE' => ['nl' => 'België', 'en' => 'Belgium'],
    ];

    private function __construct()
    {
    }

    /** The country's name in $languageCode (the request's language when null), or its code. */
    public static function name(string $countryCode, ?string $languageCode = null): string
    {
        $code = strtoupper(trim($countryCode));

        return isset(self::NAMES[$code]) ? SiteText::pick(self::NAMES[$code], $languageCode) : $code;
    }
}
