<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantImageRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Media\MediaService;
use PDO;

/**
 * Stores what the product editor's pictures section sent: the product's own
 * pool of pictures in their order, and for each variant on the screen the
 * subset of that pool it shows, in its own order (MODULES.md, "Shop").
 *
 * ONE POOL. A picture belongs to the product (product_images). A variant only
 * links to pictures of its own product (product_variant_images), so assigning
 * a picture to a variant never takes it away from the product, one picture
 * can serve several variants, and removing a variant removes its links and
 * nothing else. The first picture of the pool is the product's primary one.
 *
 * TOKENS, NOT PATHS. The screen names a picture as `image:<id>` (one of this
 * product's rows) or `media:<id>` (a Media Library image chosen in this
 * visit). Everything is resolved again here: an image id of another product,
 * a media id that is not an image, anything malformed — all ignored. A
 * request can never make a product show a file the library does not know.
 *
 * FILES ARE NEVER DELETED HERE. A library picture belongs to the library and
 * may be used elsewhere (MEDIA.md, "Verwijderen"); a picture uploaded before
 * the library stays on disk too, because an order e-mail or another row may
 * still point at it. Removing a picture removes the row, that is all.
 *
 * Runs inside the caller's transaction (api/admin/update-product.php and
 * create-product.php save the product, its words and its pictures as one).
 */
final class ProductGallery
{
    /** More than any shop page shows; a ceiling for what one request may add. */
    public const MAX_PICTURES = 60;

    private const TOKEN = '/^(image|media):([1-9][0-9]{0,9})$/';

    private PDO $db;
    private ProductImageRepository $images;
    private ProductVariantImageRepository $links;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->images = new ProductImageRepository($this->db);
        $this->links = new ProductVariantImageRepository($this->db);
    }

    /**
     * The well-formed tokens of a posted list, in order, each once, at most
     * MAX_PICTURES. Anything else is dropped.
     *
     * @return list<string>
     */
    public static function tokens(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $tokens = [];
        foreach ($posted as $token) {
            if (!is_string($token) || preg_match(self::TOKEN, trim($token)) !== 1) {
                continue;
            }
            $token = trim($token);
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
            if (count($tokens) >= self::MAX_PICTURES) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * The variant selections a request carries: variant id => tokens, for the
     * variants the screen says it showed (`variant_images_submitted[]`). A
     * variant that was shown with nothing ticked arrives as an empty list,
     * which means "all of the product's pictures".
     *
     * @return array<int, list<string>>
     */
    public static function variantTokens(mixed $submitted, mixed $posted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $posted = is_array($posted) ? $posted : [];
        $selections = [];

        foreach ($submitted as $variantId) {
            $id = filter_var($variantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                continue;
            }
            $selections[$id] = self::tokens($posted[$id] ?? $posted[(string) $id] ?? []);
        }

        return $selections;
    }

    /**
     * Makes the product's pictures exactly $tokens, in that order, and each
     * listed variant's selection exactly its tokens. Returns the product's
     * picture ids in their new order.
     *
     * @param list<string>             $tokens
     * @param array<int, list<string>> $variantTokens
     * @return list<int>
     */
    public function save(int $productId, array $tokens, array $variantTokens = []): array
    {
        $existing = [];
        foreach ($this->images->findByProductId($productId) as $row) {
            $existing[(int) $row['id']] = $row;
        }

        $byMedia = [];
        foreach ($existing as $id => $row) {
            if (!empty($row['media_id'])) {
                $byMedia[(int) $row['media_id']] = $id;
            }
        }

        $order = [];
        $idForToken = [];

        foreach ($tokens as $token) {
            if (preg_match(self::TOKEN, $token, $match) !== 1) {
                continue;
            }

            $number = (int) $match[2];
            $imageId = null;

            if ($match[1] === 'image') {
                $imageId = isset($existing[$number]) ? $number : null;
            } elseif (isset($byMedia[$number])) {
                $imageId = $byMedia[$number];
            } else {
                $media = MediaService::findImage($number);
                if ($media !== null) {
                    $imageId = $this->images->addFromMedia($productId, $media->id, $media->path);
                    $byMedia[$media->id] = $imageId;
                }
            }

            if ($imageId === null || in_array($imageId, $order, true)) {
                continue;
            }

            $order[] = $imageId;
            $idForToken[$token] = $imageId;
        }

        $this->images->deleteExcept($productId, $order);
        $this->images->applyOrder($productId, $order);

        $primary = $this->images->findPrimary($productId);
        (new ProductRepository($this->db))->updateImagePath($productId, $primary['image_path'] ?? null);

        if ($variantTokens !== []) {
            $ownVariants = array_map(
                static fn (array $variant): int => (int) $variant['id'],
                (new ProductVariantRepository($this->db))->findByProductId($productId)
            );

            foreach ($variantTokens as $variantId => $selection) {
                if (!in_array((int) $variantId, $ownVariants, true)) {
                    continue;
                }

                $ids = [];
                foreach ($selection as $token) {
                    $id = $idForToken[$token] ?? null;
                    if ($id !== null && !in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }

                $this->links->replaceForVariant((int) $variantId, $ids);
            }
        }

        return $order;
    }
}
