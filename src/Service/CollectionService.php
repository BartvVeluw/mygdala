<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;

/**
 * The write-side rules for shop collections: slug normalisation, generation
 * and validation, product-id validation, and safe deletion.
 *
 * App\Repository\CollectionRepository is deliberately dumb SQL (this
 * project's repository convention) — every rule that decides whether a write
 * is allowed lives here, and all three admin endpoints
 * (api/admin/create-collection.php, update-collection.php,
 * delete-collection.php) go through it. Mirrors App\Service\PageService,
 * which does the same job for CMS pages.
 *
 * Slug handling follows the pattern the CMS pages and Portfolio items
 * already use: sanitize what the admin typed, only ever auto-generate when
 * the field was left blank, and never silently regenerate an existing
 * collection's slug from a changed name. Unlike a page slug, a collection
 * slug needs no App\Service\ReservedRoutes check: it lives under
 * /collecties/<slug>, a namespace no application route or CMS page can
 * occupy — and since Multilingual 2.0 phase 6 under /en/collections/<slug>
 * too, whose first two segments are just as much a namespace of their own.
 *
 * A SLUG BELONGS TO ONE LANGUAGE since that phase
 * (docs/multilingual/ROUTING.md). Generation and validation therefore take
 * the language they are for, and ask it of the store that holds the
 * addresses; which language an editor is writing, and what an empty field
 * means, is App\Service\Routing\LocalizedSlugInput's answer and not this
 * class's.
 */
class CollectionService
{
    public const MAX_NAME_LENGTH = 150;
    public const MAX_SLUG_LENGTH = 170;
    public const MAX_DESCRIPTION_LENGTH = 20000;

    /**
     * Normalises an admin-submitted slug: lowercase, ASCII-transliterated,
     * runs of non-[a-z0-9] collapsed to a single "-", trimmed of leading/
     * trailing "-". Returns '' for empty/unsalvageable input so the caller
     * can fall back to generateSlug(). Byte-for-byte the same implementation
     * as PageService::sanitizeSlug(), so a name slugifies identically
     * wherever it is entered in this CMS.
     */
    public static function sanitizeSlug(string $rawSlug): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $rawSlug) : $rawSlug;
        $slug = strtolower((string) ($ascii !== false ? $ascii : $rawSlug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, self::MAX_SLUG_LENGTH);
    }

    /**
     * A URL-safe slug derived from the collection name, made unique against
     * existing collections IN THIS LANGUAGE — used when the admin left the
     * slug field blank.
     */
    public static function generateSlug(
        CollectionRepository $repository,
        string $name,
        string $languageCode,
        ?int $excludeId = null
    ): string {
        $base = self::sanitizeSlug($name);

        if ($base === '') {
            $base = 'collectie';
        }

        $base = substr($base, 0, self::MAX_SLUG_LENGTH - 10);

        $slug = $base;
        $suffix = 2;
        while (self::slugIsTaken($repository, $slug, $languageCode, $excludeId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Validates a sanitized, non-empty slug the admin typed by hand.
     * Returns the message to show, or null when the slug is fine.
     */
    public static function validateSlug(
        CollectionRepository $repository,
        string $slug,
        ?int $excludeId,
        string $languageCode
    ): ?string {
        if ($slug === '') {
            return 'Slug is verplicht en mag alleen letters, cijfers en koppeltekens bevatten.';
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return 'Slug mag alleen kleine letters, cijfers en koppeltekens bevatten.';
        }

        if (self::slugIsTaken($repository, $slug, $languageCode, $excludeId)) {
            return 'Deze slug is al in gebruik door een andere collectie.';
        }

        return null;
    }

    /**
     * Is this address already another collection's, IN THIS LANGUAGE?
     *
     * Two questions since Multilingual 2.0 phase 6, and both have to be
     * asked — the same pair App\Service\PageService::slugIsTaken() explains
     * for pages:
     *
     *   - `collection_translations` holds the address of every language, so
     *     that is where two collections' Dutch slugs, or two collections'
     *     English ones, clash. Dutch and English may share a word —
     *     /collecties/hout and /en/collections/hout are different URLs — so
     *     the check is scoped to one language and never across them;
     *   - `collections.slug` is still the neutral key, kept in step with the
     *     DEFAULT language's address, so a default-language slug has to clear
     *     that column too.
     *
     * A lookup that fails counts as TAKEN (App\Service\Language\
     * EntityTranslations::slugTaken()): refusing a save the editor can retry
     * is the safe direction.
     */
    private static function slugIsTaken(
        CollectionRepository $repository,
        string $slug,
        string $languageCode,
        ?int $excludeId
    ): bool {
        if (ShopLocalization::collections()->slugTaken($slug, $languageCode, $excludeId)) {
            return true;
        }

        if ($languageCode !== ShopLocalization::defaultLanguage()) {
            return false;
        }

        return $repository->slugExists($slug, $excludeId);
    }

    /**
     * Filters submitted product ids down to the ones that actually exist,
     * preserving the submitted order (which becomes the collection's
     * `sort_order`). Unknown/stale ids — a product deleted in another tab
     * since the form loaded, or an id injected by hand — are silently
     * dropped rather than failing the whole save, the same way
     * validatePortfolioCategoryIds() treats stale category ids.
     *
     * This is the single reason the admin endpoints never have to trust the
     * ids or the ordering values a browser sends: the ordering is derived
     * from the array position here, never read from the request, and every
     * id is confirmed against the products table.
     *
     * @param mixed $submitted raw $_POST value (expected: list of ids)
     * @return list<int>
     */
    public static function validateProductIds(mixed $submitted, ProductRepository $repository): array
    {
        $ids = self::normalizeIdList($submitted);
        if ($ids === []) {
            return [];
        }

        $existing = [];
        foreach ($repository->findAllForAdmin() as $product) {
            $existing[(int) $product['id']] = true;
        }

        return array_values(array_filter($ids, static fn (int $id): bool => isset($existing[$id])));
    }

    /**
     * The product-editor counterpart: submitted collection ids filtered down
     * to existing collections, in the CMS's own collection order.
     *
     * @param mixed $submitted raw $_POST value (expected: list of ids)
     * @return list<int>
     */
    public static function validateCollectionIds(mixed $submitted, CollectionRepository $repository): array
    {
        $ids = self::normalizeIdList($submitted);
        if ($ids === []) {
            return [];
        }

        return array_map(
            static fn (array $collection): int => (int) $collection['id'],
            $repository->findByIds($ids)
        );
    }

    /**
     * Accepts either a real array of ids (checkbox list) or the
     * comma-separated string the ordered product picker submits, and
     * normalises both to a de-duplicated list of positive ints in the order
     * given.
     *
     * @return list<int>
     */
    public static function normalizeIdList(mixed $submitted): array
    {
        if (is_string($submitted)) {
            $submitted = $submitted === '' ? [] : explode(',', $submitted);
        }

        if (!is_array($submitted)) {
            return [];
        }

        $ids = [];
        foreach ($submitted as $value) {
            if (is_array($value)) {
                continue;
            }

            $id = (int) trim((string) $value);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Permanently deletes a collection: the row itself, its pivot rows, and
     * its own uploaded image.
     *
     * The products in it are deliberately, structurally safe. Nothing here
     * touches `products` or any product image: the pivot's
     * collection_products.collection_id foreign key is ON DELETE CASCADE, so
     * MySQL removes exactly the membership rows and nothing else (see
     * db/migrations/20260908140100_create_collection_products_table.php), and
     * the only file this method can unlink is the collection's own image,
     * through SectionImageUploader::delete(), which is a no-op for any path
     * outside assets/images/sections/.
     *
     * File cleanup runs after the row is gone — an unlinked file cannot be
     * rolled back, so the database is made authoritative first. Same
     * ordering as App\Service\ProductDeletionService::delete().
     *
     * @return bool false when no such collection exists (already deleted, or
     *              a bogus id) — deliberately not an error, so a
     *              double-submitted delete form is harmless.
     */
    public static function delete(int $collectionId, ?CollectionRepository $repository = null, ?SectionImageUploader $uploader = null): bool
    {
        if ($collectionId < 1) {
            return false;
        }

        $repository = $repository ?? new CollectionRepository();
        $collection = $repository->findById($collectionId);

        if ($collection === null) {
            return false;
        }

        // Only a picture uploaded before the Media Library is the collection's
        // own file. A library picture stays: it belongs to the library, and
        // may be on a product or a page too (MEDIA.md, "Verwijderen").
        $imagePath = empty($collection['media_id']) ? ($collection['image_path'] ?? null) : null;

        $deleted = $repository->delete($collectionId);

        if ($deleted && $imagePath !== null && $imagePath !== '') {
            ($uploader ?? new SectionImageUploader())->delete((string) $imagePath);
        }

        CollectionContent::clearCache();

        return $deleted;
    }
}
