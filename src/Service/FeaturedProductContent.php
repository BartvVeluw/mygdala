<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FeaturedProductRepository;
use App\Repository\ProductRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\SiteText;
use App\Service\OrderFields\OrderFields;
use App\Service\Routing\RequestLanguage;

/**
 * Read model of the Uitgelicht product block
 * (App\Service\Blocks\FeaturedProductBlock): ONE product of the Shop on an
 * ordinary page, with its pictures, price, text, variants and — when this
 * block and the product both allow it — the way to put it in the cart
 * (CONTENT-BLOCKS.md, "Uitgelicht product").
 *
 * THE PRODUCT IS LIVE, NEVER COPIED. A row holds which product and how this
 * block shows it; everything about the product itself is read at every render
 * from the Shop's own services, the same ones the product page uses:
 *
 *   App\Service\ProductDetail         visibility, words, pictures, variants,
 *                                     stock and — only for a product sold
 *                                     directly — prices: the one payload
 *                                     assets/js/shop/shop.js draws from
 *   App\Service\ProductPurchasePath   whether it goes in the cart, is "op
 *                                     aanvraag", has a configurator, or cannot
 *                                     be ordered right now
 *   App\Service\OrderFields\OrderFields, App\Service\ProductSpecifications,
 *   App\Service\ProductGalleryTransition, App\Service\ProductSeo
 *
 * So a new main picture, another price or a variant that sells out shows in
 * the block at once, and the block can never make a product orderable that
 * its own page does not sell.
 *
 * THE BLOCK CAN ONLY OFFER LESS. `ordering` 'view' takes the cart away; the
 * switches hide the name, the price, the description or the specifications.
 * None of them can show a price the product hides ("op aanvraag") or add a
 * cart the product does not have. With the price hidden and no cart on
 * offer, the price is not even in the page (ProductDetail::withoutPrices()).
 *
 * THE THREE STATES of every block (CONTENT-BLOCKS.md): no row or a failed
 * lookup is STATE_FALLBACK, a row switched off is STATE_HIDDEN, and both
 * render nothing. So does an active row whose product is not chosen yet, was
 * deleted, or is not visible to a visitor (inactive): 'product' is then null.
 * A block may be placed first and set up later.
 *
 * EVERY CHOICE IS A WORD FROM A CLOSED LIST (CONTENT-BLOCKS.md, "Een
 * weergavekeuze is een woord uit een gesloten lijst"); a stored value this
 * class does not know reads as the default.
 */
final class FeaturedProductContent
{
    public const STATE_FALLBACK = 'fallback';

    public const STATE_ACTIVE = 'active';

    public const STATE_HIDDEN = 'hidden';

    /** The product's pictures: the gallery with its thumbnails, or only the main picture. */
    public const IMAGE_MODES = ['gallery', 'main'];

    public const DEFAULT_IMAGE_MODE = 'gallery';

    /** Where the pictures stand on a wide screen; on a phone they always come first. */
    public const IMAGE_POSITIONS = ['left', 'right'];

    public const DEFAULT_IMAGE_POSITION = 'left';

    /** How much of the width the pictures take. The shares are in featured-product.css. */
    public const IMAGE_SIZES = ['small', 'medium', 'large'];

    public const DEFAULT_IMAGE_SIZE = 'medium';

    /** How the text column is aligned. */
    public const ALIGNMENTS = ['left', 'center', 'right'];

    public const DEFAULT_ALIGNMENT = 'left';

    /** Whether THIS block offers the cart: 'direct', or 'view' for the product without buying. */
    public const ORDERINGS = ['direct', 'view'];

    public const DEFAULT_ORDERING = 'direct';

    /** The on/off switches, with the value a new block starts with. */
    public const SWITCHES = [
        'show_name' => true,
        'show_price' => true,
        'show_description' => true,
        'show_specifications' => false,
        'show_product_link' => true,
    ];

    /** The block's own words (block_translations): an optional intro and the button's own label. */
    public const INTRO = 'intro';

    public const LINK_LABEL = 'link_label';

    public const TABLE = 'featured_products';

    /** What the admin editor says about the chosen product. */
    public const PRODUCT_NONE = 'none';

    public const PRODUCT_AVAILABLE = 'available';

    public const PRODUCT_UNAVAILABLE = 'unavailable';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state', the settings (settings()), 'intro'
     *         and 'link_label' in the request's language, and 'product': null
     *         for nothing to show, else productView(). Templates must check
     *         'state' !== STATE_HIDDEN first.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new FeaturedProductRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[FeaturedProductContent] lookup failed for "' . $pageSlug . ':' . $sectionKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_FALLBACK);
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_HIDDEN);
        }

        $settings = self::settings($row);
        $id = (int) $row['id'];
        $storedLabel = BlockLocalization::text(self::TABLE, $id, self::LINK_LABEL);

        try {
            $product = self::productView(isset($row['product_id']) ? (int) $row['product_id'] : 0, $settings);
        } catch (\Throwable $e) {
            // The product failing to load must never take the page down: the
            // block is simply not there, like a block without a product.
            error_log('[FeaturedProductContent] product lookup failed for "' . $pageSlug . ':' . $sectionKey . '": ' . $e->getMessage());
            $product = null;
        }

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE] + $settings + [
            'intro' => BlockLocalization::text(self::TABLE, $id, self::INTRO),
            'link_label' => $storedLabel !== '' ? $storedLabel : self::defaultLinkLabel(),
            'product' => $product,
        ];
    }

    /**
     * The same keys with nothing to show: what STATE_FALLBACK and
     * STATE_HIDDEN hand a template, never a stand-in text.
     *
     * @return array<string, mixed>
     */
    public static function emptyContent(string $state = self::STATE_FALLBACK): array
    {
        return ['state' => $state] + self::settings([]) + ['intro' => '', 'link_label' => '', 'product' => null];
    }

    /**
     * The block's own choices of a stored row, each checked against its list:
     * for the page and for the editor alike. An empty row is every default.
     *
     * @param array<string, mixed> $row
     * @return array{image_mode: string, image_position: string, image_size: string, content_align: string, ordering: string, show_name: bool, show_price: bool, show_description: bool, show_specifications: bool, show_product_link: bool}
     */
    public static function settings(array $row): array
    {
        $settings = [
            'image_mode' => self::choice(self::IMAGE_MODES, $row['image_mode'] ?? null),
            'image_position' => self::choice(self::IMAGE_POSITIONS, $row['image_position'] ?? null),
            'image_size' => self::choice(self::IMAGE_SIZES, $row['image_size'] ?? null, self::DEFAULT_IMAGE_SIZE),
            'content_align' => self::choice(self::ALIGNMENTS, $row['content_align'] ?? null),
            'ordering' => self::choice(self::ORDERINGS, $row['ordering'] ?? null),
        ];

        foreach (self::SWITCHES as $switch => $default) {
            $settings[$switch] = array_key_exists($switch, $row) && $row[$switch] !== null ? (bool) $row[$switch] : $default;
        }

        return $settings;
    }

    /**
     * A stored word, or the list's default — the first entry unless another is
     * named — for anything the list does not know.
     *
     * @param list<string> $list
     */
    public static function choice(array $list, mixed $stored, ?string $default = null): string
    {
        return is_string($stored) && in_array($stored, $list, true) ? $stored : ($default ?? $list[0]);
    }

    /**
     * Everything the partial needs of the product on show, or null when there
     * is nothing a visitor may see: no product chosen, a product that is gone,
     * or one that is not active. Whatever the product page would not show,
     * this does not return.
     *
     * @param array<string, mixed> $settings settings()
     * @return array<string, mixed>|null
     */
    public static function productView(int $productId, array $settings): ?array
    {
        if ($productId < 1) {
            return null;
        }

        $language = RequestLanguage::current();
        $payload = ProductDetail::forPublic($productId, $language);
        if ($payload === null) {
            return null;
        }

        $purchase = ProductPurchasePath::forProduct($productId);
        $path = $purchase['path'];
        $offersCart = $settings['ordering'] === 'direct' && $path === ProductPurchasePath::CART;

        // The price leaves the server only where this block uses it: shown,
        // or needed for the cart line. A price the product hides ("op
        // aanvraag") is not in the payload at all (ProductDetail).
        if (!$settings['show_price'] && !$offersCart) {
            $payload = ProductDetail::withoutPrices($payload);
        }

        $personalization = $purchase['personalization'];

        return [
            'id' => $productId,
            'name' => (string) ($payload['name'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'inquiry' => (bool) ($payload['inquiry'] ?? false),
            'purchase_path' => $path,
            'personalization_required' => $personalization !== null && ($personalization['is_required'] ?? false) === true,
            'offers_cart' => $offersCart,
            'order_questions' => $offersCart ? (new OrderFields())->questions($productId, $language) : [],
            'specifications' => $settings['show_specifications'] ? (new ProductSpecifications())->forProduct($productId, $language) : [],
            'gallery_transition' => ProductGalleryTransition::forProduct($productId),
            'url' => ProductSeo::publicPath($productId),
            'payload' => $payload,
        ];
    }

    /**
     * What the editor says about a chosen product: none chosen, one a visitor
     * can see, or one that is not (inactive). A deleted product leaves no id
     * behind (ON DELETE SET NULL), so it reads as none chosen.
     */
    public static function productStatus(?int $productId): string
    {
        if ($productId === null || $productId < 1) {
            return self::PRODUCT_NONE;
        }

        $products = new ProductRepository();
        if ($products->findActiveById($productId) !== null) {
            return self::PRODUCT_AVAILABLE;
        }

        return $products->findByIdForAdmin($productId) === null ? self::PRODUCT_NONE : self::PRODUCT_UNAVAILABLE;
    }

    /**
     * The button's words when the owner typed none: "Bekijk product", in the
     * language of the page — or in $languageCode, for the editor's placeholder.
     */
    public static function defaultLinkLabel(?string $languageCode = null): string
    {
        return SiteText::pick(['nl' => 'Bekijk product', 'en' => 'View product'], $languageCode);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
