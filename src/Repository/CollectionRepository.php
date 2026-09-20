<?php

namespace App\Repository;

/**
 * All `collections` and `collection_products` SQL lives here — the
 * CMS-managed shop collections (see
 * db/migrations/20260908140000_create_collections_table.php) and the
 * many-to-many relation to `products`.
 *
 * Unlike the Portfolio taxonomy — where the category rows and the item's
 * category assignments are split across two repositories — both sides live
 * here, because a collection's whole purpose IS its product membership:
 * splitting them would mean every caller needs two repositories to do
 * anything useful.
 *
 * Deliberately NOT here: loading full product rows. That is
 * App\Repository\ProductRepository's job and it already does it —
 * findAllActive(int $collectionId) takes an optional collection filter for
 * the public side, and findAllForAdmin() serves the CMS product picker. This
 * repository only ever deals in product *ids* plus their position inside a
 * collection, so there is exactly one place in the project that knows which
 * columns a product row has.
 */
class CollectionRepository extends Repository
{
    /**
     * Every collection, CMS order (sort_order, then id as a stable tiebreak).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM collections ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    /**
     * Only collections the owner has published — the public /shop section
     * and the public collection page both read through this.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM collections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Every publicly indexable collection, as App\Service\Sitemap needs it:
     * the slug its canonical URL is built from, plus the row's own
     * last-modified timestamp.
     *
     * Deliberately the same `is_active = 1` rule as findAllActive() — the one
     * that decides whether /collecties/<slug> answers 200 or 404 — and NOT
     * findActiveWithActiveProductCounts()'s extra "must contain an active
     * product". That stricter rule exists so the /shop overview never links
     * to an empty grid; an empty published collection is still a real, public
     * page that returns 200, so leaving it out of the sitemap would hide a
     * URL the owner deliberately published.
     *
     * @return array<int, array{id:int, slug:string, updated_at:?string}>
     */
    public function findActiveForSitemap(): array
    {
        $stmt = $this->db->query(
            // `id` travels along since Multilingual 2.0 phase 6: a collection's
            // addresses per language hang off its id, and without it the
            // sitemap could only ever list the default language's URL.
            'SELECT id, slug, updated_at FROM collections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Every collection plus how many products it currently contains — the
     * admin overview (admin/collections.php) shows that count per card.
     *
     * @return array<int, array<string, mixed>> each row + 'product_count' (int)
     */
    public function findAllWithProductCounts(): array
    {
        $stmt = $this->db->query(
            'SELECT c.*, COUNT(cp.product_id) AS product_count
             FROM collections c
             LEFT JOIN collection_products cp ON cp.collection_id = c.id
             GROUP BY c.id
             ORDER BY c.sort_order ASC, c.id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Active collections that contain at least one ACTIVE product, plus that
     * count. The /shop overview never links to a collection whose page would
     * render an empty product grid — see App\Service\CollectionContent.
     *
     * @return array<int, array<string, mixed>> each row + 'product_count' (int)
     */
    public function findActiveWithActiveProductCounts(): array
    {
        $stmt = $this->db->query(
            'SELECT c.*, COUNT(p.id) AS product_count
             FROM collections c
             INNER JOIN collection_products cp ON cp.collection_id = c.id
             INNER JOIN products p ON p.id = cp.product_id AND p.active = 1
             WHERE c.is_active = 1
             GROUP BY c.id
             ORDER BY c.sort_order ASC, c.id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM collections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Regardless of is_active — the caller decides whether an inactive
     * collection may be shown. The public page (collectie.php, via
     * App\Service\CollectionContent::forPublicPage()) checks is_active and
     * 404s, so an unpublished collection can never leak through this.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM collections WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Filters a list of submitted ids down to the ones that actually exist —
     * how the admin endpoints avoid ever trusting collection ids from a
     * form. Unknown/stale ids are simply absent from the result.
     *
     * @param array<int, mixed> $ids
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM collections WHERE id IN ({$placeholders}) ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT 1 FROM collections WHERE slug = :slug AND id != :id LIMIT 1');
            $stmt->execute(['slug' => $slug, 'id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM collections WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Adds a collection row. Its WORDS are not here: since Multilingual 2.0
     * phase 5 wave C the name, description, SEO copy and related-products
     * heading live per website language in collection_translations and are
     * written through App\Service\ShopLocalization, in the same transaction
     * as this row.
     *
     * @param array{slug:string,image_path:?string,is_active:bool} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO collections
                (slug, image_path, is_active, sort_order, created_at, updated_at)
             VALUES
                (:slug, :image_path, :is_active, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'slug' => $data['slug'],
            'image_path' => $data['image_path'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates every editable field except image_path and og_image_path — see
     * updateImagePath() / updateOgImagePath(), kept separate for the same
     * reason ProductRepository::update() does: saving the form without
     * picking a new file must never clear or replace the existing image.
     *
     * Its words are saved separately and per language, through
     * App\Service\ShopLocalization, in the same transaction.
     *
     * @param array{slug:string,is_active:bool} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE collections SET slug = :slug, is_active = :is_active, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'slug' => $data['slug'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $stmt = $this->db->prepare('UPDATE collections SET image_path = :image_path, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['image_path' => $imagePath, 'id' => $id]);
    }

    /**
     * The optional dedicated social-sharing image — same contract as
     * ProductRepository::updateOgImagePath(): written only on an explicit
     * upload or removal, NULL restores the automatic fallback chain in
     * App\Service\CollectionContent::socialImagePath().
     */
    public function updateOgImagePath(int $id, ?string $imagePath): void
    {
        $stmt = $this->db->prepare('UPDATE collections SET og_image_path = :og_image_path, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['og_image_path' => $imagePath, 'id' => $id]);
    }

    /**
     * The "Gerelateerde producten" settings for one collection — whether a
     * product in it may use it as its related-products source, and the
     * optional heading override (NULL/'' = use the global heading).
     *
     * Kept separate from update() for the same reason updateImagePath() is:
     * this field is edited on its own screen (admin/related-products.php),
     * and saving that screen must not be able to touch a collection's slug or
     * published state — nor the collection editor able to reset it.
     *
     * The HEADING itself is words, and since Multilingual 2.0 phase 5 wave C
     * it lives per website language in collection_translations, written
     * through App\Service\ShopLocalization in the same transaction. What is
     * left here is the switch.
     */
    public function updateRelatedProductsSettings(int $id, bool $showRelatedProducts): void
    {
        $stmt = $this->db->prepare(
            'UPDATE collections SET
                show_related_products = :show_related_products,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'show_related_products' => $showRelatedProducts ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Removes the collection row. Its `collection_products` rows go with it
     * through the pivot's ON DELETE CASCADE; no product is ever touched.
     * Callers should go through App\Service\CollectionService::delete(),
     * which also cleans up the uploaded collection image.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM collections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /* ------------------------------------------------------------------
       Relations (collection_products)
       ------------------------------------------------------------------ */

    /**
     * The product ids in this collection, in the collection's own order.
     * Full product rows come from ProductRepository — see the class docblock.
     *
     * @return list<int>
     */
    public function productIdsForCollection(int $collectionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT product_id FROM collection_products
             WHERE collection_id = :collection_id
             ORDER BY sort_order ASC, product_id ASC'
        );
        $stmt->execute(['collection_id' => $collectionId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The collections this product belongs to — zero, one or many. Used by
     * the product editor's Collections field and by the product-side
     * relation sync.
     *
     * @return list<int>
     */
    public function collectionIdsForProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT cp.collection_id
             FROM collection_products cp
             INNER JOIN collections c ON c.id = cp.collection_id
             WHERE cp.product_id = :product_id
             ORDER BY c.sort_order ASC, c.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Full collection rows for one product, same order as
     * collectionIdsForProduct().
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectionsForProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*
             FROM collection_products cp
             INNER JOIN collections c ON c.id = cp.collection_id
             WHERE cp.product_id = :product_id
             ORDER BY c.sort_order ASC, c.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }

    /**
     * How many products each of these collections holds, keyed by collection
     * id — one query instead of one per collection.
     *
     * @param array<int, mixed> $collectionIds
     * @return array<int, int>
     */
    public function productCountsByCollectionIds(array $collectionIds): array
    {
        $collectionIds = array_values(array_unique(array_map('intval', $collectionIds)));
        if ($collectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT collection_id, COUNT(*) AS product_count
             FROM collection_products
             WHERE collection_id IN ({$placeholders})
             GROUP BY collection_id"
        );
        $stmt->execute($collectionIds);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['collection_id']] = (int) $row['product_count'];
        }

        return $counts;
    }

    /**
     * Replaces this collection's product membership with exactly
     * $productIds, in the given order (position in the array becomes
     * sort_order). Callers must have validated the ids against
     * ProductRepository first — this method trusts them.
     *
     * Deselected products are removed, newly selected ones added, and a
     * product listed twice is stored once: array_unique plus the pivot's
     * composite primary key make a duplicate row impossible. Runs in a
     * transaction so a collection is never left half-assigned.
     *
     * @param array<int, mixed> $productIds
     */
    public function setCollectionProducts(int $collectionId, array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        // JOINS A TRANSACTION THAT IS ALREADY OPEN, and opens one only when
        // there is none — the same rule App\Service\Language\
        // EntityTranslations::save() follows, and for the same reason: since
        // Multilingual 2.0 phase 5 wave C the collection endpoints write the
        // row and its words in ONE transaction and call this inside it. PDO
        // refuses a nested beginTransaction(), so opening a second one here
        // turned every save that carried the product picker into "Collectie
        // kon niet worden opgeslagen" — with the membership untouched and
        // nothing on screen to say why.
        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $delete = $this->db->prepare('DELETE FROM collection_products WHERE collection_id = :collection_id');
            $delete->execute(['collection_id' => $collectionId]);

            if ($productIds !== []) {
                $insert = $this->db->prepare(
                    'INSERT INTO collection_products (collection_id, product_id, sort_order)
                     VALUES (:collection_id, :product_id, :sort_order)'
                );

                foreach ($productIds as $sortOrder => $productId) {
                    $insert->execute([
                        'collection_id' => $collectionId,
                        'product_id' => $productId,
                        'sort_order' => $sortOrder,
                    ]);
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            // Only the owner rolls back; a caller's transaction is the
            // caller's to undo, and its catch block already does.
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * The product-editor side of the same relation: makes this product a
     * member of exactly $collectionIds and of no others.
     *
     * Deliberately NOT a delete-all-then-reinsert like
     * setCollectionProducts(): a product is only one row inside each
     * collection's ordered list, and rewriting it would throw away the
     * position it already has there. Existing memberships that stay selected
     * are therefore left completely untouched (same sort_order), removals
     * delete only their own row, and each addition is appended at the end of
     * that collection's list. Callers must have validated the ids first.
     *
     * @param array<int, mixed> $collectionIds
     */
    public function setProductCollections(int $productId, array $collectionIds): void
    {
        $collectionIds = array_values(array_unique(array_map('intval', $collectionIds)));
        $current = $this->collectionIdsForProduct($productId);

        $toAdd = array_values(array_diff($collectionIds, $current));
        $toRemove = array_values(array_diff($current, $collectionIds));

        if ($toAdd === [] && $toRemove === []) {
            return;
        }

        // Joins an open transaction — see setCollectionProducts(). The
        // product endpoints write the row, its words and this membership in
        // one transaction too.
        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if ($toRemove !== []) {
                $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                $delete = $this->db->prepare(
                    "DELETE FROM collection_products WHERE product_id = ? AND collection_id IN ({$placeholders})"
                );
                $delete->execute(array_merge([$productId], $toRemove));
            }

            if ($toAdd !== []) {
                // INSERT IGNORE is belt and braces on top of the diff above:
                // even a concurrent save that already added the same pair
                // cannot turn into a duplicate-key error here.
                $insert = $this->db->prepare(
                    'INSERT IGNORE INTO collection_products (collection_id, product_id, sort_order)
                     VALUES (:collection_id, :product_id, :sort_order)'
                );

                foreach ($toAdd as $collectionId) {
                    $insert->execute([
                        'collection_id' => $collectionId,
                        'product_id' => $productId,
                        'sort_order' => $this->nextProductSortOrder($collectionId),
                    ]);
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Removes one product/collection relation. A no-op when the pair does
     * not exist, so a double-submitted form is harmless.
     */
    public function removeProductFromCollection(int $collectionId, int $productId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM collection_products WHERE collection_id = :collection_id AND product_id = :product_id'
        );
        $stmt->execute(['collection_id' => $collectionId, 'product_id' => $productId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Rewrites the order of the products inside one collection
     * (drag-and-drop in admin/collection.php). Ids that are not actually in
     * this collection are ignored, and members missing from the submitted
     * list keep their relative order at the end — same defensive shape as
     * PortfolioGalleryRepository::reorderItems(), so a stale or filtered
     * browser list can never drop a product out of a collection.
     *
     * @param array<int, mixed> $orderedProductIds
     */
    public function reorderCollectionProducts(int $collectionId, array $orderedProductIds): void
    {
        $existingIds = $this->productIdsForCollection($collectionId);

        $ordered = [];
        foreach ($orderedProductIds as $id) {
            $id = (int) $id;
            if (in_array($id, $existingIds, true) && !in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        foreach ($existingIds as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE collection_products SET sort_order = :sort_order
             WHERE collection_id = :collection_id AND product_id = :product_id'
        );

        foreach ($ordered as $sortOrder => $productId) {
            $stmt->execute([
                'sort_order' => $sortOrder,
                'collection_id' => $collectionId,
                'product_id' => $productId,
            ]);
        }
    }

    /**
     * Rewrites the order of the collections themselves (drag-and-drop in
     * admin/collections.php); drives the CMS overview and the Collections
     * section on /shop. Same "unknown ids ignored, missing ids appended"
     * rules as reorderCollectionProducts().
     *
     * @param array<int, mixed> $orderedIds
     */
    public function reorderCollections(array $orderedIds): void
    {
        $existingIds = array_map(
            static fn (array $collection): int => (int) $collection['id'],
            $this->findAll()
        );

        $ordered = [];
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if (in_array($id, $existingIds, true) && !in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        foreach ($existingIds as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        $stmt = $this->db->prepare('UPDATE collections SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');

        foreach ($ordered as $sortOrder => $id) {
            $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
        }
    }

    private function nextSortOrder(): int
    {
        $stmt = $this->db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM collections');

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private function nextProductSortOrder(int $collectionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM collection_products WHERE collection_id = :collection_id'
        );
        $stmt->execute(['collection_id' => $collectionId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
