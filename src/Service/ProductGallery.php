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
 * nothing else. The first general picture is the product's primary one.
 *
 * GENERAL OR VARIANT-ONLY (product_images.variant_only). A general picture is
 * in the product's gallery and may also be linked to variants. A variant-only
 * picture is still the product's, but only a variant that links to it shows
 * it. The screen sends the two as two lists (`gallery[]`,
 * `gallery_variant_only[]`); nothing is inferred from the links, and a
 * variant-only picture whose last link goes stays in the pool, unlinked, until
 * it is removed on purpose.
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

    /** A variant on the screen: its id, or "new<n>" for one added there (App\Service\ProductVariantEditor). */
    private const VARIANT_KEY = '/^(?:[1-9][0-9]{0,9}|new[0-9]{1,4})$/';

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
     * The variant selections a request carries: variant key => tokens, for
     * the variants the screen says it showed (`variants_submitted[]`). The
     * key is a stored variant's id, or "new<n>" for a variant added on the
     * screen in this visit, whose id only exists once the endpoint has made
     * it (App\Service\ProductVariantEditor::save()); the endpoint translates
     * those before save(). A variant that was shown with nothing ticked
     * arrives as an empty list, which means "all of the product's general
     * pictures".
     *
     * @return array<int|string, list<string>> an id key comes out as an int
     */
    public static function variantTokens(mixed $submitted, mixed $posted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $posted = is_array($posted) ? $posted : [];
        $selections = [];

        foreach ($submitted as $key) {
            $key = is_scalar($key) ? trim((string) $key) : '';
            if (preg_match(self::VARIANT_KEY, $key) !== 1) {
                continue;
            }
            $selections[$key] = self::tokens($posted[$key] ?? []);
        }

        return $selections;
    }

    /**
     * Makes the product's general pictures exactly $tokens, in that order,
     * its variant-only pictures exactly $variantOnlyTokens, and each listed
     * variant's selection exactly its tokens. Returns the product's general
     * picture ids in their new order.
     *
     * GENERAL OR VARIANT-ONLY is decided by the list a picture is in, so a
     * picture moves between the two by being sent in the other list — its
     * row, its media item and its variant links stay. A picture in both
     * lists is general. A variant-only picture is never primary: the primary
     * is the first of $tokens, and only of $tokens.
     *
     * $variantOnlyTokens null means the request did not carry that list (an
     * older form, a caller that only knows the general pictures): the
     * product's variant-only pictures then stay exactly as they are, unless
     * $tokens names one, which makes it general again.
     *
     * @param list<string>             $tokens
     * @param array<int, list<string>> $variantTokens by variant id; any other key is skipped
     * @param list<string>|null        $variantOnlyTokens
     * @return list<int>
     */
    public function save(int $productId, array $tokens, array $variantTokens = [], ?array $variantOnlyTokens = null): array
    {
        $existing = [];
        foreach ($this->images->findPoolByProductId($productId) as $row) {
            $existing[(int) $row['id']] = $row;
        }

        $byMedia = [];
        foreach ($existing as $id => $row) {
            if (!empty($row['media_id'])) {
                $byMedia[(int) $row['media_id']] = $id;
            }
        }

        $idForToken = [];
        $resolve = function (string $token, bool $variantOnly) use ($productId, $existing, &$byMedia): ?int {
            if (preg_match(self::TOKEN, $token, $match) !== 1) {
                return null;
            }

            $number = (int) $match[2];
            if ($match[1] === 'image') {
                return isset($existing[$number]) ? $number : null;
            }
            if (isset($byMedia[$number])) {
                return $byMedia[$number];
            }

            $media = MediaService::findImage($number);
            if ($media === null) {
                return null;
            }

            $imageId = $this->images->addFromMedia($productId, $media->id, $media->path, $variantOnly);
            $byMedia[$media->id] = $imageId;

            return $imageId;
        };

        $order = [];
        foreach ($tokens as $token) {
            $imageId = $resolve($token, false);
            if ($imageId === null || in_array($imageId, $order, true)) {
                continue;
            }

            $order[] = $imageId;
            $idForToken[$token] = $imageId;
        }

        $variantOnly = [];
        if ($variantOnlyTokens === null) {
            foreach ($existing as $id => $row) {
                if ((int) $row['variant_only'] === 1 && !in_array($id, $order, true)) {
                    $variantOnly[] = $id;
                    $idForToken['image:' . $id] = $id;
                }
            }
        } else {
            foreach ($variantOnlyTokens as $token) {
                $imageId = $resolve($token, true);
                if ($imageId === null || in_array($imageId, $order, true) || in_array($imageId, $variantOnly, true)) {
                    continue;
                }

                $variantOnly[] = $imageId;
                $idForToken[$token] = $imageId;
            }
        }

        $this->images->deleteExcept($productId, [...$order, ...$variantOnly]);
        $this->images->applyOrder($productId, $order);
        $this->images->markVariantOnly($productId, $variantOnly, count($order));

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
