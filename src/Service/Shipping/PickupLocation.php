<?php

namespace App\Service\Shipping;

use App\Service\SiteSettings;

/**
 * What the checkout's "collect it yourself" option is called.
 *
 * The label used to be the sentence "Afhalen in Nijmegen", written into
 * checkout.php. That is one shop's town compiled into a generic checkout
 * form: any other installation of this CMS offered its customers pickup in a
 * city it has nothing to do with, and no screen anywhere could correct it.
 *
 * The town now comes from where an owner already types it — Instellingen ->
 * the company address, `site_settings.company_city`, the same structured
 * postal city the invoice prints. Nothing new is stored and nothing is seeded:
 * an installation that has not filled it in offers plain "Afhalen", which is
 * accurate rather than wrong, and the existing site keeps the exact sentence
 * it had because its own row already says Nijmegen.
 *
 * Deliberately NOT the localized `city` (App\Service\LocalizedSiteSettings,
 * formerly `city_nl`/`city_en`): that is site copy per language and
 * hold prose like "Nijmegen, Nederland", which reads badly inside a sentence.
 * A pickup point is a place, and a place has one name.
 */
final class PickupLocation
{
    /** The town orders can be collected from, or '' when nobody has said. */
    public static function city(): string
    {
        return trim(SiteSettings::get('company_city'));
    }

    /** Dutch label for the pickup option, with the town when there is one. */
    public static function labelNl(): string
    {
        $city = self::city();

        return $city === '' ? 'Afhalen' : 'Afhalen in ' . $city;
    }

    /** English label for the pickup option, same rule. */
    public static function labelEn(): string
    {
        $city = self::city();

        return $city === '' ? 'Pickup' : 'Pick up in ' . $city;
    }
}
