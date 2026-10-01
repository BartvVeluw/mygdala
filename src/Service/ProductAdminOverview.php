<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantImageRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Inventory\Inventory;
use App\Service\Inventory\StockSummary;
use PDO;

/**
 * The rows of Shop → Producten (admin/products.php): every product with its
 * thumbnail and its stock in one line, in a FIXED number of queries whatever
 * the number of products — no query per card.
 *
 * THE THUMBNAIL mirrors the public shop card: the default variant's (first
 * active by sort_order) first chosen picture, else the product's own primary
 * picture, else the legacy `products.image_path`. The Media Library's small
 * copy (`thumbnail_path`) where there is one, since the overview never shows
 * a picture larger than a card.
 *
 * THE STOCK comes from App\Service\Inventory\Inventory::forProducts(), the one
 * resolver, summarised by App\Service\Inventory\StockSummary.
 */
final class ProductAdminOverview
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    /**
     * @return list<array<string, mixed>> the products of findAllForAdmin() plus `thumbnail` (?string) and `stock` (StockSummary)
     */
    public function rows(): array
    {
        $db = $this->db ?? \App\Database::connection();
        $products = (new ProductRepository($db))->findAllForAdmin();
        if ($products === []) {
            return [];
        }

        $ids = array_map(static fn (array $product): int => (int) $product['id'], $products);

        $defaultVariants = (new ProductVariantRepository($db))->defaultVariantIds($ids);
        $variantImages = (new ProductVariantImageRepository($db))->findByVariantIds(array_values($defaultVariants));
        $primary = (new ProductImageRepository($db))->primaryForProducts($ids);
        $stock = (new Inventory($db))->forProducts($ids);

        foreach ($products as &$product) {
            $id = (int) $product['id'];
            // The default variant's first general picture, as the shop card
            // takes it: one meant for variants only never stands for the product.
            $variantPicture = isset($defaultVariants[$id])
                ? (ProductVariantImageRepository::generalOnly($variantImages[$defaultVariants[$id]] ?? [])[0] ?? null)
                : null;
            $picture = $variantPicture ?? $primary[$id] ?? null;

            $path = $picture !== null ? (string) ($picture['thumbnail_path'] ?? $picture['image_path'] ?? '') : '';
            if ($path === '') {
                $path = (string) ($product['image_path'] ?? '');
            }

            $product['thumbnail'] = $path !== '' ? $path : null;
            $product['stock'] = StockSummary::for($stock[$id], PurchaseMode::isInquiry($product['purchase_mode'] ?? null));
        }
        unset($product);

        return $products;
    }
}
