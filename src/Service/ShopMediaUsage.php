<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Module\ShopModule;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * How the Shop answers the Media Library's "do you use any of these images,
 * and where?" — the module's own contribution through
 * App\Module\ShopModule::mediaUsageProviders(), the same shape as the Blog's
 * App\Service\Blog\BlogPostMediaUsage (MEDIA.md, "Waar wordt dit gebruikt?").
 *
 * WHAT COUNTS: a product's picture (product_images.media_id) and a
 * collection's picture (collections.media_id). A variant never holds a
 * picture of its own — it links to one of its product's
 * (product_variant_images) — so its use is part of the product's, and the
 * label says how many variants show it. Removing a variant therefore never
 * frees a picture its product still has, and a picture is only deletable
 * once no product and no collection has it.
 *
 * ONE QUERY PER TABLE for the whole batch, whatever its size: the library's
 * overview asks about a page of tiles at once.
 *
 * WHO READS THE NAME: the permission of the editor each place is changed
 * on — products.manage for admin/product-form.php, collections.manage for
 * admin/collection.php.
 *
 * A DISABLED SHOP REPORTS NOTHING, because the registry only asks enabled
 * modules — the general rule (MODULES.md). The rows stay; switching the Shop
 * back on protects its pictures again, and the RESTRICT foreign keys keep the
 * rows from ever pointing at nothing in between.
 */
final class ShopMediaUsage extends MediaUsageProvider
{
    public function key(): string
    {
        return 'shop';
    }

    public function label(): string
    {
        return 'Shop';
    }

    public function usagesFor(array $mediaIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $mediaIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $placeholders = $this->placeholders(count($ids));
        $db = Database::connection();

        $products = $db->prepare(
            'SELECT pi.media_id, pi.product_id, COUNT(pvi.id) AS variant_count
             FROM product_images pi
             LEFT JOIN product_variant_images pvi ON pvi.product_image_id = pi.id
             WHERE pi.media_id IN (' . $placeholders . ')
             GROUP BY pi.id, pi.media_id, pi.product_id
             ORDER BY pi.product_id ASC'
        );
        $products->execute($ids);
        $productRows = $products->fetchAll();

        $collections = $db->prepare(
            'SELECT id, media_id FROM collections WHERE media_id IN (' . $placeholders . ') ORDER BY id ASC'
        );
        $collections->execute($ids);
        $collectionRows = $collections->fetchAll();

        // The share images (Media Library 2.0): a separate use of possibly a
        // separate item, reported apart, like a blog post's.
        $productShares = $db->prepare('SELECT id, og_media_id FROM products WHERE og_media_id IN (' . $placeholders . ') ORDER BY id ASC');
        $productShares->execute($ids);
        $productShareRows = $productShares->fetchAll();

        $collectionShares = $db->prepare('SELECT id, og_media_id FROM collections WHERE og_media_id IN (' . $placeholders . ') ORDER BY id ASC');
        $collectionShares->execute($ids);
        $collectionShareRows = $collectionShares->fetchAll();

        ShopLocalization::preloadProducts(array_merge(
            array_map(static fn (array $row): int => (int) $row['product_id'], $productRows),
            array_map(static fn (array $row): int => (int) $row['id'], $productShareRows)
        ));
        ShopLocalization::preloadCollections(array_merge(
            array_map(static fn (array $row): int => (int) $row['id'], $collectionRows),
            array_map(static fn (array $row): int => (int) $row['id'], $collectionShareRows)
        ));

        $usages = [];

        foreach ($productRows as $row) {
            $productId = (int) $row['product_id'];
            $variants = (int) $row['variant_count'];
            $label = 'Product: ' . ShopLocalization::productName($productId);
            if ($variants > 0) {
                $label .= $variants === 1 ? ' (ook bij 1 variant)' : ' (ook bij ' . $variants . ' varianten)';
            }

            $usages[(int) $row['media_id']][] = new MediaUsage(
                source: $this->key(),
                label: $label,
                permission: ShopModule::PRODUCTS_MANAGE,
                editUrl: '/admin/product-form.php?id=' . $productId,
            );
        }

        foreach ($collectionRows as $row) {
            $collectionId = (int) $row['id'];

            $usages[(int) $row['media_id']][] = new MediaUsage(
                source: $this->key(),
                label: 'Collectie: ' . ShopLocalization::collectionName($collectionId),
                permission: ShopModule::COLLECTIONS_MANAGE,
                editUrl: '/admin/collection.php?id=' . $collectionId,
            );
        }

        foreach ($productShareRows as $row) {
            $productId = (int) $row['id'];

            $usages[(int) $row['og_media_id']][] = new MediaUsage(
                source: $this->key(),
                label: 'Deel-afbeelding van product: ' . ShopLocalization::productName($productId),
                permission: ShopModule::PRODUCTS_MANAGE,
                editUrl: '/admin/product-form.php?id=' . $productId,
            );
        }

        foreach ($collectionShareRows as $row) {
            $collectionId = (int) $row['id'];

            $usages[(int) $row['og_media_id']][] = new MediaUsage(
                source: $this->key(),
                label: 'Deel-afbeelding van collectie: ' . ShopLocalization::collectionName($collectionId),
                permission: ShopModule::COLLECTIONS_MANAGE,
                editUrl: '/admin/collection.php?id=' . $collectionId,
            );
        }

        return $usages;
    }
}
