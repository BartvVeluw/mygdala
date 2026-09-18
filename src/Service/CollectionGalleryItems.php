<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\Language\LocalizedValue;

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
 * WORDS. A card's words leave here as one LocalizedValue each, the shape the
 * partial reads since Multilingual 2.0 phase 5 wave A. Until wave C moves the
 * Shop's own storage, those values are still built from the products' fixed
 * Dutch/English columns — the pair, not the partial, is what changes then.
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
        $name = LocalizedValue::ofDutchEnglish(
            (string) $product['name'],
            self::valueOrDefault($product['name_en'] ?? null, (string) $product['name'])
        );

        $subtitleNl = Seo::excerpt($product['description'] ?? null, 70);
        $subtitleEn = Seo::excerpt($product['description_en'] ?? null, 70);

        return [
            'image_path' => (string) ($product['image_path'] ?? ''),
            // A product has no alt text of its own; its name is what the
            // photo is of, in whatever language the visitor reads.
            'alt' => $name,
            'title' => $name,
            'subtitle' => LocalizedValue::ofDutchEnglish(
                $subtitleNl,
                $subtitleEn === '' ? $subtitleNl : $subtitleEn
            ),
            'categories' => '',
            'url' => ProductSeo::publicPath((int) $product['id']),
            'is_detail_link' => true,
        ];
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }
}
