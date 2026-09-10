<?php

declare(strict_types=1);

/**
 * Shared helpers for the admin product-image endpoints
 * (add/delete/set-primary/move-product-image.php).
 */

use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;

/**
 * Normalizes a multi-file <input type="file" name="images[]" multiple>
 * entry from $_FILES (PHP groups each property into a parallel array
 * instead of one array per file) into a list of single-file arrays, each
 * shaped like a normal $_FILES entry. Slots where no file was chosen are
 * skipped.
 *
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function normalizeMultiFileInput(?array $filesEntry): array
{
    if ($filesEntry === null || !isset($filesEntry['name']) || !is_array($filesEntry['name'])) {
        return [];
    }

    $files = [];

    foreach ($filesEntry['name'] as $index => $name) {
        if ($name === '' || $name === null) {
            continue;
        }

        $files[] = [
            'name' => $name,
            'type' => $filesEntry['type'][$index] ?? '',
            'tmp_name' => $filesEntry['tmp_name'][$index] ?? '',
            'error' => $filesEntry['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesEntry['size'][$index] ?? 0,
        ];
    }

    return $files;
}

/**
 * Keeps products.image_path in sync with the current primary product_images
 * row, so every existing read of that column (shop cards, cart, order
 * queries/confirmation emails) keeps working unchanged without needing to
 * know product_images exists.
 */
function syncPrimaryImagePath(
    ProductRepository $productRepository,
    ProductImageRepository $imageRepository,
    int $productId
): void {
    $primary = $imageRepository->findPrimary($productId);
    $productRepository->updateImagePath($productId, $primary['image_path'] ?? null);
}
