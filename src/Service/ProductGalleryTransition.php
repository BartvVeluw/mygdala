<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;

/**
 * How the product page's big picture changes to another one: the one place
 * that decides it, so no template or script ever works out
 * "product override ?? shop default ?? fallback" on its own (MODULES.md,
 * "Shop", Productgalerij).
 *
 * A CLOSED LIST of three words. It reaches the page as
 * data-gallery-transition on the gallery (product.php), and
 * assets/js/shop/product-gallery.js checks it against the same three again,
 * so a stored value can only ever hit or miss one of them; it is never a
 * class name or a piece of CSS.
 *
 *   none   the next picture is simply there
 *   fade   the pictures cross-fade (the default: the gallery faded before
 *          these settings existed, so an existing shop looks the same)
 *   slide  the next picture slides in from the side it comes from
 *
 * TWO LEVELS. The Shop has one default (site_settings.shop_gallery_transition,
 * on Shop-instellingen), and a product may name its own
 * (products.gallery_transition). NULL on the product means "follow the Shop",
 * which is why the Shop's value is never copied into a product: changing the
 * default changes every product that follows it.
 *
 * Swiping is not here. It is a way of asking for the next picture, not a way
 * of showing it, and works whatever this says.
 */
final class ProductGalleryTransition
{
    public const NONE = 'none';
    public const FADE = 'fade';
    public const SLIDE = 'slide';

    /** @var list<string> in the order the CMS offers them */
    public const ALL = [self::NONE, self::FADE, self::SLIDE];

    /** When nothing valid is stored anywhere. */
    public const DEFAULT = self::FADE;

    /** The Shop-wide default in site_settings (App\Service\SiteSettings). */
    public const SETTING_KEY = 'shop_gallery_transition';

    /** The product's own choice; NULL follows the Shop. */
    public const COLUMN = 'gallery_transition';

    /** One of ALL, or null for anything else (empty, unknown, not a string). */
    public static function normalise(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::ALL, true) ? $value : null;
    }

    /** The Shop's default: what is stored when it is one of ALL, DEFAULT otherwise. */
    public static function shopDefault(): string
    {
        return self::normalise(SiteSettings::get(self::SETTING_KEY)) ?? self::DEFAULT;
    }

    /**
     * The transition a product shows: its own when that is valid, else the
     * Shop's default when that is valid, else DEFAULT. Both arguments are
     * read as stored, so a value no longer on the list falls through.
     */
    public static function resolve(mixed $productOverride, mixed $shopDefault): string
    {
        return self::normalise($productOverride)
            ?? self::normalise($shopDefault)
            ?? self::DEFAULT;
    }

    /**
     * The transition for the product page of $productId. A product that is
     * not there, or a database that cannot answer, follows the Shop: this is
     * presentation and must never be why a product page fails.
     */
    public static function forProduct(int $productId): string
    {
        $override = null;

        if ($productId > 0) {
            try {
                $override = (new ProductRepository())->findGalleryTransition($productId);
            } catch (\Throwable $e) {
                error_log('[ProductGalleryTransition] ' . $e->getMessage());
            }
        }

        return self::resolve($override, SiteSettings::get(self::SETTING_KEY));
    }

    /**
     * What the product editor posted: [submitted, value, valid]. Not
     * submitted leaves the stored value alone; '' is "follow the Shop"
     * (null); anything but '' or one of ALL is refused.
     *
     * @param array<string, mixed> $input
     * @return array{0: bool, 1: ?string, 2: bool}
     */
    public static function fromProductInput(array $input): array
    {
        if (!array_key_exists(self::COLUMN, $input)) {
            return [false, null, true];
        }

        $raw = $input[self::COLUMN];

        if ($raw === '') {
            return [true, null, true];
        }

        $value = self::normalise($raw);

        return [true, $value, $value !== null];
    }
}
