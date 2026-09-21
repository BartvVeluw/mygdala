<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\Language\LanguageFallback;
use App\Service\Routing\RequestLanguage;

/**
 * One shop collection as gallery cards — the Shop's side of the
 * `item_gallery` block's `collection` source (App\Module\ShopModule,
 * App\Service\ItemGallerySources).
 *
 * It used to be two private methods on App\Service\ItemGalleryContent, which
 * made a Core content class import ProductRepository and CollectionRepository
 * and know what a collection is. The block still knows nothing about
 * products: it asks its chosen source for items and gets back the one
 * normalised shape every source returns.
 *
 * Read through the existing repositories, so there is no second definition
 * anywhere of "what is in a collection". An unpicked, unknown or unpublished
 * collection yields nothing rather than an error — the same fallback rule
 * every *Content class in this project follows.
 *
 * WORDS. A card's words leave here as one string each, in the language of the
 * request: the shape the partial reads. They come from
 * App\Service\ShopLocalization — the products' own per-language storage —
 * with the one fallback rule of App\Service\Language\LanguageFallback
 * applied, never a second "English else Dutch" branch of this class's own.
 */
final class CollectionGalleryItems
{
    /**
     * The active products of one collection, in the order the CMS put them in.
     *
     * @return list<array<string, mixed>>
     */
    public static function forCollection(mixed $collectionId): array
    {
        $collectionId = is_numeric($collectionId) ? (int) $collectionId : 0;

        if ($collectionId <= 0) {
            return [];
        }

        try {
            $collection = (new CollectionRepository())->findById($collectionId);
        } catch (\Throwable $e) {
            error_log('[CollectionGalleryItems] collection lookup failed for #' . $collectionId . ': ' . $e->getMessage());

            return [];
        }

        if ($collection === null || !(bool) $collection['is_active']) {
            return [];
        }

        try {
            $products = (new ProductRepository())->findAllActive($collectionId);
        } catch (\Throwable $e) {
            error_log('[CollectionGalleryItems] product lookup failed for collection #' . $collectionId . ': ' . $e->getMessage());

            return [];
        }

        // One query for every card's words instead of one per card.
        ShopLocalization::preloadProducts(array_map(
            static fn (array $product): int => (int) $product['id'],
            $products
        ));

        return array_map([self::class, 'mapProduct'], $products);
    }

    /**
     * A product as a gallery card: its own photo, its name, a short
     * plain-text line from its description, and a link to its one canonical
     * product page (never a URL nested under the collection).
     *
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private static function mapProduct(array $product): array
    {
        $productId = (int) $product['id'];
        $language = RequestLanguage::current();
        $name = ShopLocalization::product($productId, ShopLocalization::NAME, $language);

        return [
            'image_path' => (string) ($product['image_path'] ?? ''),
            // A product has no alt text of its own; its name is what the
            // photo is of, in whatever language the visitor reads.
            'alt' => $name,
            'title' => $name,
            'subtitle' => self::subtitle($productId, $language),
            'categories' => '',
            'url' => ProductSeo::publicPath($productId),
            'is_detail_link' => true,
        ];
    }

    /**
     * The short plain-text line under a card's title: the first words of the
     * product's description, in one language.
     *
     * The excerpt is taken from each language's OWN description and the
     * fallback runs afterwards, so a product described in the default
     * language only shows that line rather than an empty subtitle.
     * LanguageFallback does the falling back — this class never decides it.
     */
    private static function subtitle(int $productId, string $language): string
    {
        $lines = [];

        foreach (array_unique([$language, LanguageFallback::defaultLanguage()]) as $code) {
            $line = Seo::excerpt(
                ShopLocalization::rawProduct($productId, ShopLocalization::DESCRIPTION, $code),
                70
            );

            if ($line !== '') {
                $lines[$code] = $line;
            }
        }

        return LanguageFallback::resolve($lines, $language);
    }
}
