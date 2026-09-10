<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use PDO;

/**
 * Permanently removes a catalog product from the CMS/shop, together with every
 * row and uploaded file that belongs exclusively to it — while leaving every
 * historical order completely intact.
 *
 * This mirrors App\Service\PageService::delete(): the rules live here rather
 * than in the admin endpoint, so they hold no matter which UI (or forged
 * request) triggers a deletion.
 *
 * ## Why historical orders survive
 *
 * `order_items` carries its own authoritative snapshot of each purchased line —
 * `product_name`/`product_name_en`, `variant_label`, `unit_price` and
 * `quantity` — so nothing an order displays is read from `products` at render
 * time (see App\Repository\OrderRepository::findItems()). Since
 * db/migrations/20260908120000_relax_order_item_product_foreign_keys.php the
 * `product_id`/`variant_id` foreign keys are ON DELETE SET NULL, so deleting a
 * product detaches those order lines instead of blocking or cascading. Order
 * history, totals, invoices and confirmation emails are therefore untouched by
 * everything below. order_items rows are never deleted here — deliberately, and
 * that is the single most important property of this class.
 *
 * ## Why variants are deleted explicitly, before the product
 *
 * `products` cascades to `product_options` -> `product_option_values`, and to
 * `product_variants` -> `product_variant_values`. But
 * `product_variant_values.product_option_value_id` is ON DELETE RESTRICT (it
 * guards the admin's "delete this option value" flow). A single
 * `DELETE FROM products` therefore hits MySQL error 1451 for any product that
 * has variants, because the cascade tries to remove an option value while a
 * variant value still points at it — which is why deleting a variant product
 * failed even when it had never been ordered. Removing `product_variants`
 * first (which cascades away `product_variant_values` and `variant_images`)
 * frees the option values, so the subsequent product delete cascades cleanly.
 *
 * ## Media
 *
 * File cleanup runs only after the transaction commits — an unlinked file
 * cannot be rolled back, so the database is made authoritative first. Deletion
 * is delegated to ProductImageUploader::delete(), which by design only ever
 * touches `assets/images/products/` (the admin upload folder, where every file
 * has a unique random name) and is a no-op for anything else. Shared site
 * assets (`assets/images/*.webp` used by the seeded catalog and the rest of the
 * site), invoice PDFs under `storage/` and every other order-related file are
 * consequently unreachable from here. See the known limitation in MAIN.MD:
 * there is no global reference counter, so a file deliberately reused across
 * two products would be removed with the first one — the project's uploader
 * never produces that situation, and leaving an unused file behind is the
 * chosen failure mode everywhere else.
 */
class ProductDeletionService
{
    private PDO $db;
    private ProductRepository $products;
    private ProductImageRepository $images;
    private ProductVariantRepository $variants;
    private ProductImageUploader $uploader;

    public function __construct(
        ?PDO $db = null,
        ?ProductRepository $products = null,
        ?ProductImageRepository $images = null,
        ?ProductVariantRepository $variants = null,
        ?ProductImageUploader $uploader = null
    ) {
        $this->db = $db ?? Database::connection();
        $this->products = $products ?? new ProductRepository($this->db);
        $this->images = $images ?? new ProductImageRepository($this->db);
        $this->variants = $variants ?? new ProductVariantRepository($this->db);
        $this->uploader = $uploader ?? new ProductImageUploader();
    }

    /**
     * Deletes the product with this id and everything it owns.
     *
     * @return bool false when no such product exists (already deleted, or a
     *              bogus id) — deliberately not an error, so a double-submitted
     *              delete form is harmless.
     * @throws \Throwable if the database work fails; the whole deletion is
     *                    rolled back first and no file is touched.
     */
    public function delete(int $productId): bool
    {
        if ($productId < 1) {
            return false;
        }

        $product = $this->products->findByIdForAdmin($productId);

        if ($product === null) {
            return false;
        }

        // Collected before the delete: once the rows are gone their file paths
        // are unrecoverable. Nothing is unlinked until the commit succeeds.
        $filePaths = $this->ownedFilePaths($productId, $product);

        $this->db->beginTransaction();

        try {
            // See class docblock: variants must go before the product itself,
            // or the products cascade collides with the RESTRICT on
            // product_variant_values.product_option_value_id.
            $this->deleteVariants($productId);

            // Cascades to product_images, product_options ->
            // product_option_values; sets order_items.product_id to NULL.
            $deleted = $this->products->delete($productId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($deleted) {
            foreach ($filePaths as $path) {
                $this->uploader->delete($path);
            }
        }

        return $deleted;
    }

    /**
     * Removes the product's variants up front so their `product_variant_values`
     * rows (and `variant_images`) are gone before the product cascade reaches
     * the option values those rows reference.
     */
    private function deleteVariants(int $productId): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_variants WHERE product_id = :product_id');
        $stmt->execute(['product_id' => $productId]);
    }

    /**
     * Every image path owned exclusively by this product: its gallery images,
     * each variant's images, plus the legacy `products.image_path` column
     * (kept in sync with the primary gallery image by
     * api/admin/_product_image_helpers.php, so normally a duplicate — included
     * for the case where it is not).
     *
     * The product's PERSONALIZATION PREVIEW image
     * (product_personalization_settings.preview_image_path) is deliberately
     * NOT in this list, even though its configuration row cascades away with
     * the product. Historical orders reconstruct their personalization
     * preview from that exact image (see admin/_order_personalization.php),
     * so deleting it would quietly degrade order evidence for a product that
     * has already been sold. The cost is one orphaned file per deleted
     * personalized product — consistent with this project's chosen failure
     * mode elsewhere: leaving an unused file behind beats removing one that
     * is still needed.
     *
     * @param array<string, mixed> $product
     * @return array<int, string>
     */
    private function ownedFilePaths(int $productId, array $product): array
    {
        $paths = [];

        foreach ($this->images->findByProductId($productId) as $image) {
            if (!empty($image['image_path'])) {
                $paths[] = (string) $image['image_path'];
            }
        }

        foreach ($this->variants->findByProductId($productId) as $variant) {
            foreach ($variant['images'] ?? [] as $variantImage) {
                if (!empty($variantImage['image_path'])) {
                    $paths[] = (string) $variantImage['image_path'];
                }
            }
        }

        if (!empty($product['image_path'])) {
            $paths[] = (string) $product['image_path'];
        }

        return array_values(array_unique($paths));
    }
}
