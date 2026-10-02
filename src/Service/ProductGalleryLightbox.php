<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether the big picture of a product gallery opens the site's one
 * lightbox (assets/js/lightbox.js, partials/lightbox.php): the one place
 * that decides it (MODULES.md, "Shop", Productgalerij; Product Gallery 2.1).
 *
 * ONE SHOP-WIDE SWITCH, OFF BY DEFAULT. site_settings.shop_gallery_lightbox,
 * on Shop-instellingen → Productpagina. '1' is on; anything else — the
 * default '0', a missing row, an unknown value — is off, so an installation
 * that never saw this setting keeps exactly the gallery it had: no extra
 * script, no overlay, no new attribute and nothing to click.
 *
 * When it is on, product.php and the Uitgelicht product block put
 * data-gallery-lightbox on their gallery, ask for assets/js/lightbox.js and
 * print the shared overlay once per page
 * (App\Service\ItemGalleryContent::claimLightboxOverlay()). The gallery
 * script then opens the lightbox with the pictures it shows right now.
 */
final class ProductGalleryLightbox
{
    public const ON = '1';
    public const OFF = '0';

    /** The Shop-wide switch in site_settings (App\Service\SiteSettings). */
    public const SETTING_KEY = 'shop_gallery_lightbox';

    /** '1' or '0', or null for anything else (a request may only send these two). */
    public static function normalise(mixed $value): ?string
    {
        return is_string($value) && in_array($value, [self::ON, self::OFF], true) ? $value : null;
    }

    /** Whether a stored value means on: only exactly '1'. */
    public static function isOn(mixed $stored): bool
    {
        return self::normalise($stored) === self::ON;
    }

    /**
     * Whether the product galleries of this site open the lightbox. A
     * database that cannot answer means off: this is presentation and must
     * never be why a product page fails.
     */
    public static function enabled(): bool
    {
        try {
            return self::isOn(SiteSettings::get(self::SETTING_KEY));
        } catch (\Throwable $e) {
            error_log('[ProductGalleryLightbox] ' . $e->getMessage());

            return false;
        }
    }
}
