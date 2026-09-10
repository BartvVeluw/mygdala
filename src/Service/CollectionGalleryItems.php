<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;

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
        $nameNl = (string) $product['name'];
        $nameEn = self::valueOrDefault($product['name_en'] ?? null, $nameNl);

        $subtitleNl = Seo::excerpt($product['description'] ?? null, 70);
        $subtitleEn = Seo::excerpt($product['description_en'] ?? null, 70);

        return [
            'image_path' => (string) ($product['image_path'] ?? ''),
            'alt_nl' => $nameNl,
            'alt_en' => $nameEn,
            'title_nl' => $nameNl,
            'title_en' => $nameEn,
            'subtitle_nl' => $subtitleNl,
            'subtitle_en' => $subtitleEn === '' ? $subtitleNl : $subtitleEn,
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
