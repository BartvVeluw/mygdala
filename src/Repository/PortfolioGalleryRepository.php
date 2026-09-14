<?php

namespace App\Repository;

/**
 * All portfolio_galleries / portfolio_gallery_items SQL lives here.
 *
 * `portfolio_galleries` is the item CATALOGUE's container — one row, which
 * every portfolio item hangs off — and nothing more. It used to double as a
 * page section: a hardcoded "portfolio:gallery" page_slug/section_key pair
 * plus an is_active that hid the section on the public site. Phase 4 of
 * docs/content-blocks/ROADMAP.md moved both onto the real block
 * (App\Service\ItemGalleryContent) and dropped those columns, so WHERE the
 * items are shown is a page-builder question now and this table has no
 * opinion about it.
 *
 * Same shape/conventions as StatStripRepository (parent + child table,
 * sort_order swap for reordering). This repository only stores the resulting
 * image_path for items; the actual upload/validation/delete is
 * App\Service\SectionImageUploader's job, called from the API layer.
 */
class PortfolioGalleryRepository extends Repository
{
    /**
     * The one catalogue row every portfolio item hangs off, or null when the
     * site has none yet.
     *
     * @return array<string, mixed>|null
     */
    public function findCatalogue(): ?array
    {
        $stmt = $this->db->query('SELECT * FROM portfolio_galleries ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The catalogue row, creating it on first use — admin/portfolio.php's
     * lazy create, so items can be attached the first time the overview is
     * opened on a fresh install.
     *
     * @return array<string, mixed>
     */
    public function ensureCatalogue(): array
    {
        $catalogue = $this->findCatalogue();
        if ($catalogue !== null) {
            return $catalogue;
        }

        $this->db->prepare('INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())')
            ->execute();

        return $this->findCatalogue() ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_galleries WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsByGalleryId(int $galleryId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM portfolio_gallery_items WHERE portfolio_gallery_id = :portfolio_gallery_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['portfolio_gallery_id' => $galleryId]);

        return $stmt->fetchAll();
    }

    /**
     * Items curated for the homepage teaser: visible (is_active = 1) AND
     * marked is_featured = 1, in their own independent homepage order. An
     * item hidden on the portfolio page is excluded here too, even if it
     * still carries is_featured = 1 from before it was hidden.
     *
     * @return array<int, array<string, mixed>> ordered by featured_sort_order ASC, sort_order ASC, id ASC
     */
    public function findFeaturedItemsByGalleryId(int $galleryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM portfolio_gallery_items
             WHERE portfolio_gallery_id = :portfolio_gallery_id AND is_active = 1 AND is_featured = 1
             ORDER BY featured_sort_order ASC, sort_order ASC, id ASC'
        );
        $stmt->execute(['portfolio_gallery_id' => $galleryId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_gallery_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<int> portfolio_category_id values assigned to this item, ordered by the category's own sort_order
     */
    public function categoryIdsForItem(int $itemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pic.portfolio_category_id
             FROM portfolio_item_categories pic
             INNER JOIN portfolio_categories pc ON pc.id = pic.portfolio_category_id
             WHERE pic.portfolio_item_id = :item_id
             ORDER BY pc.sort_order ASC, pc.id ASC'
        );
        $stmt->execute(['item_id' => $itemId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The full category rows (slug + display names) assigned to one item —
     * used by portfolio-detail.php to render its metadata pills. Unlike
     * categoryIdsForItem() (admin checkbox state) this carries display data,
     * and unlike categorySlugsByItemIds() (bulk, slugs only, keyed by item
     * id) it's a single-item convenience for the one-item detail page.
     *
     * @return array<int, array<string, mixed>> slug/name_nl/name_en, ordered by sort_order ASC, id ASC
     */
    public function categoriesForItemId(int $itemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.slug, pc.name_nl, pc.name_en
             FROM portfolio_item_categories pic
             INNER JOIN portfolio_categories pc ON pc.id = pic.portfolio_category_id
             WHERE pic.portfolio_item_id = :item_id
             ORDER BY pc.sort_order ASC, pc.id ASC'
        );
        $stmt->execute(['item_id' => $itemId]);

        return $stmt->fetchAll();
    }

    /**
     * Bulk variant of categoryIdsForItem() — the category *slugs* (not ids)
     * for every item in $itemIds at once, avoiding an N+1 query when
     * rendering the admin overview grid or the public portfolio.php grid.
     *
     * @param list<int> $itemIds
     * @return array<int, list<string>> keyed by portfolio_item_id
     */
    public function categorySlugsByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT pic.portfolio_item_id, pc.slug
             FROM portfolio_item_categories pic
             INNER JOIN portfolio_categories pc ON pc.id = pic.portfolio_category_id
             WHERE pic.portfolio_item_id IN ({$placeholders})
             ORDER BY pc.sort_order ASC, pc.id ASC"
        );
        $stmt->execute($itemIds);

        $bySlug = [];
        foreach ($stmt->fetchAll() as $row) {
            $bySlug[(int) $row['portfolio_item_id']][] = (string) $row['slug'];
        }

        return $bySlug;
    }

    /**
     * Replaces an item's full set of category relationships — the admin
     * item editor always submits the complete checked set, so a plain
     * "delete all, insert selected" is simpler and just as correct as a
     * diff, and this table is tiny per item (a handful of rows at most).
     * Unknown/invalid category ids are the caller's responsibility to have
     * already filtered out (see api/admin/update-portfolio-item.php).
     *
     * @param list<int> $categoryIds
     */
    public function setItemCategories(int $itemId, array $categoryIds): void
    {
        $delete = $this->db->prepare('DELETE FROM portfolio_item_categories WHERE portfolio_item_id = :item_id');
        $delete->execute(['item_id' => $itemId]);

        if ($categoryIds === []) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO portfolio_item_categories (portfolio_item_id, portfolio_category_id) VALUES (:item_id, :category_id)'
        );
        foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
            $insert->execute(['item_id' => $itemId, 'category_id' => $categoryId]);
        }
    }

    /**
     * Links an item to the ordinary CMS page that is its project page, or
     * takes the link off again with null.
     *
     * Separate from updateItem() for the reason setItemCategories() is: the
     * caller checks the choice first, and only then is it written. Only the
     * page's id is stored, never its address — App\Service\PortfolioGalleryContent
     * resolves that per request, so a renamed page is followed.
     *
     * The foreign key refuses an id no page has, and deleting the page later
     * sets the link back to NULL
     * (db/migrations/20260914200000_link_a_portfolio_item_to_a_page.php).
     */
    public function setItemPage(int $itemId, ?int $pageId): void
    {
        $stmt = $this->db->prepare('UPDATE portfolio_gallery_items SET page_id = :page_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['page_id' => $pageId, 'id' => $itemId]);
    }

    /**
     * Every item whose OLD project page can be public, as the Portfolio's
     * sitemap collector needs it: slug, last-modified timestamp, and the page
     * the item links to now.
     *
     * The three conditions are the ones
     * App\Service\PortfolioGalleryContent::itemForDetailPage() checks before
     * it renders the old page at all (visible, old project page switched on,
     * and a slug to reach it by), so an address that 404s is never listed.
     * Whether the address redirects instead, because a published page is
     * linked, is PortfolioGalleryContent::legacyProjectPagesForSitemap()'s to
     * decide — which is why page_id comes along.
     *
     * Not scoped to one gallery: the sitemap wants every old project page on
     * the site, whichever gallery an item happens to belong to.
     *
     * @return array<int, array{slug:string, updated_at:?string, page_id:?int}>
     */
    public function findDetailPageItemsForSitemap(): array
    {
        $stmt = $this->db->query(
            "SELECT slug, updated_at, page_id
             FROM portfolio_gallery_items
             WHERE is_active = 1 AND has_detail_page = 1 AND slug IS NOT NULL AND slug <> ''
             ORDER BY sort_order ASC, id ASC"
        );

        return array_map(
            static fn (array $row): array => [
                'slug' => (string) $row['slug'],
                'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
                'page_id' => $row['page_id'] !== null ? (int) $row['page_id'] : null,
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Looks up the item behind an old project address
     * (portfolio-detail.php?slug=...). What that address does is the caller's
     * question — redirect to the linked page, or show the old page, which
     * needs is_active/has_detail_page checked: see
     * App\Service\PortfolioGalleryContent::legacyProjectRedirectUrl() and
     * itemForDetailPage().
     *
     * Nothing writes a slug any more: the editor that set one is gone, and a
     * slug now only names an address that already existed.
     *
     * @return array<string, mixed>|null
     */
    public function findItemBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_gallery_items WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new item to the end of a gallery. image_path is the
     * already-stored path returned by SectionImageUploader::store() — this
     * repository never touches the filesystem itself. Categories are set
     * separately via setItemCategories() once the item (and therefore its
     * id) exists — see api/admin/create-portfolio-item.php. The legacy
     * `categories` string column is left at its schema default and never
     * written by this method — see
     * db/migrations/20260906080000_create_portfolio_item_categories_table.php.
     *
     * @param array<string, string|null> $values image_path, thumbnail_path, alt_nl, alt_en, title_nl, title_en, subtitle_nl, subtitle_en
     */
    public function createItem(int $galleryId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder($galleryId);

        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_gallery_items
                (portfolio_gallery_id, image_path, thumbnail_path, alt_nl, alt_en, title_nl, title_en, subtitle_nl, subtitle_en, sort_order, is_active, created_at, updated_at)
             VALUES
                (:portfolio_gallery_id, :image_path, :thumbnail_path, :alt_nl, :alt_en, :title_nl, :title_en, :subtitle_nl, :subtitle_en, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'portfolio_gallery_id' => $galleryId,
            'image_path' => $values['image_path'],
            'thumbnail_path' => $values['thumbnail_path'] ?? null,
            'alt_nl' => $values['alt_nl'],
            'alt_en' => self::nullIfEmpty($values['alt_en'] ?? null),
            'title_nl' => $values['title_nl'],
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'subtitle_nl' => $values['subtitle_nl'],
            'subtitle_en' => self::nullIfEmpty($values['subtitle_en'] ?? null),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Saves what an item's own editor edits: its image, its words, and whether
     * and where it is shown. Categories and the linked page are saved
     * separately, through setItemCategories() and setItemPage() — see
     * api/admin/update-portfolio-item.php. The legacy `categories` string
     * column is never written by this method (see createItem()'s docblock).
     *
     * Neither are the old project page's columns (has_detail_page, slug,
     * intro_*, description_*): nothing edits that page any more, and a save
     * must never blank what it still shows at its old address
     * (portfolio-detail.php). They keep exactly the values they have.
     *
     * @param array<string, string|bool|int|null> $values image_path, thumbnail_path, alt_nl, alt_en, title_nl, title_en, subtitle_nl, subtitle_en, is_active, is_featured, featured_sort_order
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_gallery_items SET
                image_path = :image_path,
                thumbnail_path = :thumbnail_path,
                alt_nl = :alt_nl,
                alt_en = :alt_en,
                title_nl = :title_nl,
                title_en = :title_en,
                subtitle_nl = :subtitle_nl,
                subtitle_en = :subtitle_en,
                is_active = :is_active,
                is_featured = :is_featured,
                featured_sort_order = :featured_sort_order,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'image_path' => $values['image_path'],
            'thumbnail_path' => $values['thumbnail_path'] ?? null,
            'alt_nl' => $values['alt_nl'],
            'alt_en' => self::nullIfEmpty($values['alt_en'] ?? null),
            'title_nl' => $values['title_nl'],
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'subtitle_nl' => $values['subtitle_nl'],
            'subtitle_en' => self::nullIfEmpty($values['subtitle_en'] ?? null),
            'is_active' => $values['is_active'] ? 1 : 0,
            'is_featured' => $values['is_featured'] ? 1 : 0,
            'featured_sort_order' => $values['featured_sort_order'] ?? null,
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes an item — distinct from hiding one via is_active
     * (see updateItem). Used by the admin "Verwijderen" action.
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM portfolio_gallery_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps sort_order with the previous/next item (in current display
     * order) within the same gallery — same approach as
     * StatStripRepository::moveItem().
     */
    public function moveItem(int $galleryId, int $itemId, string $direction): void
    {
        $items = $this->findItemsByGalleryId($galleryId);

        $index = null;
        foreach ($items as $i => $item) {
            if ((int) $item['id'] === $itemId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($items)) {
            return;
        }

        $a = $items[$index];
        $b = $items[$swapWith];

        $this->updateSortOrder((int) $a['id'], (int) $b['sort_order']);
        $this->updateSortOrder((int) $b['id'], (int) $a['sort_order']);
    }

    /**
     * Swaps featured_sort_order with the previous/next item within the
     * homepage-featured subset only — same "swap with neighbour" approach as
     * moveItem(), scoped to is_featured = 1 items in their own order.
     */
    public function moveFeaturedItem(int $galleryId, int $itemId, string $direction): void
    {
        $items = $this->findFeaturedItemsByGalleryId($galleryId);

        $index = null;
        foreach ($items as $i => $item) {
            if ((int) $item['id'] === $itemId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($items)) {
            return;
        }

        $a = $items[$index];
        $b = $items[$swapWith];

        $this->updateFeaturedSortOrder((int) $a['id'], (int) $b['featured_sort_order']);
        $this->updateFeaturedSortOrder((int) $b['id'], (int) $a['featured_sort_order']);
    }

    /**
     * Persists a full new display order for a gallery's items (drag-and-drop
     * reordering in admin/portfolio.php). Only ids that actually belong to
     * $galleryId are honored; any of the gallery's items missing from
     * $orderedIds (e.g. currently hidden by a search/category filter in the
     * admin overview, so never part of the dragged subset) keep their
     * relative order and are placed after the given ones — same
     * "never drop an item out of the list" guarantee as
     * VariantImageRepository::reorder(). This is what lets drag-and-drop work
     * safely even while the overview is filtered.
     *
     * @param array<int, int> $orderedIds
     */
    public function reorderItems(int $galleryId, array $orderedIds): void
    {
        $existing = $this->findItemsByGalleryId($galleryId);
        $existingIds = array_map(static fn (array $item): int => (int) $item['id'], $existing);

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

        foreach ($ordered as $sortOrder => $id) {
            $this->updateSortOrder($id, $sortOrder);
        }
    }

    /**
     * Next free featured_sort_order value within a gallery — used to append
     * an item to the end of the homepage order the moment it is marked
     * featured. Public because the caller (the admin save handler) needs it
     * to decide the value before calling updateItem().
     */
    public function nextFeaturedSortOrder(int $galleryId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(featured_sort_order), -1) + 1 AS next_sort_order
             FROM portfolio_gallery_items WHERE portfolio_gallery_id = :portfolio_gallery_id'
        );
        $stmt->execute(['portfolio_gallery_id' => $galleryId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private function updateFeaturedSortOrder(int $id, int $featuredSortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE portfolio_gallery_items SET featured_sort_order = :featured_sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['featured_sort_order' => $featuredSortOrder, 'id' => $id]);
    }

    private function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE portfolio_gallery_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    private function nextSortOrder(int $galleryId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM portfolio_gallery_items WHERE portfolio_gallery_id = :portfolio_gallery_id'
        );
        $stmt->execute(['portfolio_gallery_id' => $galleryId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }
}
