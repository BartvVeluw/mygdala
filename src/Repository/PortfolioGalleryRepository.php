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
     * The category rows (id + slug) assigned to one item — used by
     * portfolio-detail.php to render its metadata pills, whose NAMES come
     * per website language from App\Service\PortfolioLocalization. Unlike
     * categoryIdsForItem() (admin checkbox state) this carries the slug the
     * pill links on, and unlike categorySlugsByItemIds() (bulk, slugs only,
     * keyed by item id) it's a single-item convenience for the one-item
     * detail page.
     *
     * @return array<int, array<string, mixed>> id/slug, ordered by sort_order ASC, id ASC
     */
    public function categoriesForItemId(int $itemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.id, pc.slug
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
     * The category ids of every item in $itemIds at once, keyed by item id:
     * what App\Service\PortfolioRelatedProjects measures the overlap of two
     * projects with, in one query for a whole catalogue.
     *
     * @param list<int> $itemIds
     * @return array<int, list<int>> keyed by portfolio_item_id
     */
    public function categoryIdsByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT portfolio_item_id, portfolio_category_id
             FROM portfolio_item_categories
             WHERE portfolio_item_id IN ({$placeholders})"
        );
        $stmt->execute($itemIds);

        $byItem = [];
        foreach ($stmt->fetchAll() as $row) {
            $byItem[(int) $row['portfolio_item_id']][] = (int) $row['portfolio_category_id'];
        }

        return $byItem;
    }

    /**
     * The ids of every item in one category, visible or not: the caller
     * intersects them with what may be shown.
     *
     * @return list<int>
     */
    public function itemIdsInCategory(int $categoryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT portfolio_item_id FROM portfolio_item_categories WHERE portfolio_category_id = :category_id'
        );
        $stmt->execute(['category_id' => $categoryId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
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
     * The item's own project page: whether it is shown ("Projectpagina
     * tonen", has_detail_page) and the slug of its address /portfolio/<slug>.
     * Separate from updateItem() for the reason setItemPage() is: the caller
     * validates the slug first (App\Service\PortfolioSlug), and only then is
     * it written. NULL clears the slug; the unique index allows any number of
     * items without one.
     */
    public function setItemProjectPage(int $itemId, bool $hasDetailPage, ?string $slug): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_gallery_items SET has_detail_page = :has_detail_page, slug = :slug, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'has_detail_page' => $hasDetailPage ? 1 : 0,
            'slug' => $slug !== null && $slug !== '' ? $slug : null,
            'id' => $itemId,
        ]);
    }

    /** Whether another item than $excludeId already has this slug. */
    public function slugTakenByAnotherItem(string $slug, ?int $excludeId = null): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM portfolio_gallery_items WHERE slug = :slug AND id <> :exclude_id LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'exclude_id' => $excludeId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The items that have one of these slugs, whatever their visibility or
     * project page: what a CMS page directly under the Portfolio page would
     * collide with at /portfolio/<slug> (App\Module\PortfolioModule::systemPages()).
     *
     * @param list<string> $slugs
     * @return list<array{id: int, slug: string}>
     */
    public function itemsWithSlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs), static fn (string $slug): bool => $slug !== '')));
        if ($slugs === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($slugs), '?'));
        $stmt = $this->db->prepare('SELECT id, slug FROM portfolio_gallery_items WHERE slug IN (' . $placeholders . ') ORDER BY id');
        $stmt->execute($slugs);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'slug' => (string) $row['slug']],
            $stmt->fetchAll()
        );
    }

    /**
     * Every item whose project page can be public, as the Portfolio's
     * sitemap collector needs it: slug, last-modified timestamp, and the page
     * the item links to now.
     *
     * The three conditions are the ones
     * App\Service\PortfolioGalleryContent::itemForDetailPage() checks before
     * it renders the page at all (visible, project page switched on, and a
     * slug to reach it by), so an address that 404s is never listed. Whether
     * the address redirects instead, because a legacy published page is
     * linked, is PortfolioGalleryContent::projectPagesForSitemap()'s to
     * decide — which is why page_id comes along.
     *
     * Not scoped to one gallery: the sitemap wants every project page on
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
     * Looks up the item behind a project address
     * (portfolio-detail.php?slug=...). What that address does is the caller's
     * question — redirect to a legacy linked page, or show the item's own
     * project page, which needs is_active/has_detail_page checked: see
     * App\Service\PortfolioGalleryContent::legacyProjectRedirectUrl() and
     * itemForDetailPage().
     *
     * The slug is written by setItemProjectPage(), unique across the whole
     * table (its unique index, and App\Service\PortfolioSlug before it).
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
     * Appends a new item to the end of a gallery. Its picture is a Media
     * Library item (media_id), with that item's path and thumbnail written
     * along into image_path and thumbnail_path, which every public reader
     * reads (MEDIA.md, "Hoe een feature naar media verwijst") — this
     * repository never touches the filesystem itself. Categories are set
     * separately via setItemCategories() once the item (and therefore its
     * id) exists — see api/admin/create-portfolio-item.php. The legacy
     * `categories` string column is left at its schema default and never
     * written by this method — see
     * db/migrations/20260906080000_create_portfolio_item_categories_table.php.
     *
     * Its WORDS are not here: since Multilingual 2.0 phase 5 the alt text,
     * title and subtitle live per website language in
     * portfolio_item_translations and are written through
     * App\Service\PortfolioLocalization, in the same transaction as this row.
     *
     * @param array<string, string|int|null> $values media_id, image_path, thumbnail_path
     */
    public function createItem(int $galleryId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder($galleryId);

        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_gallery_items
                (portfolio_gallery_id, media_id, image_path, thumbnail_path, sort_order, is_active, created_at, updated_at)
             VALUES
                (:portfolio_gallery_id, :media_id, :image_path, :thumbnail_path, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'portfolio_gallery_id' => $galleryId,
            'media_id' => isset($values['media_id']) && (int) $values['media_id'] > 0 ? (int) $values['media_id'] : null,
            'image_path' => $values['image_path'],
            'thumbnail_path' => $values['thumbnail_path'] ?? null,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Saves what an item's own editor edits about the ROW: its image, and
     * whether and where it is shown. Its words are saved separately and per
     * language, through App\Service\PortfolioLocalization, in the same
     * transaction; categories, the legacy linked page and the item's own
     * project page through setItemCategories(), setItemPage() and
     * setItemProjectPage() — see api/admin/update-portfolio-item.php. The
     * legacy `categories` string column is never written by this method (see
     * createItem()'s docblock).
     *
     * media_id is the library item, or null for a picture that still lives
     * on Portfolio's own path from before the library.
     *
     * @param array<string, string|bool|int|null> $values media_id, image_path, thumbnail_path, is_active
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_gallery_items SET
                media_id = :media_id,
                image_path = :image_path,
                thumbnail_path = :thumbnail_path,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'media_id' => isset($values['media_id']) && (int) $values['media_id'] > 0 ? (int) $values['media_id'] : null,
            'image_path' => $values['image_path'],
            'thumbnail_path' => $values['thumbnail_path'] ?? null,
            'is_active' => $values['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * The settings of an item's related projects (App\Service\PortfolioRelatedProjects),
     * each already one word of its closed list or a checked number: the caller
     * validates, this only writes. Separate from updateItem() for the reason
     * setItemCategories() is.
     *
     * @param array{related_enabled: bool, related_mode: string, related_max: int, related_sort: string, related_fallback: string, related_layout: string, related_show_text: bool} $settings
     */
    public function updateRelatedSettings(int $id, array $settings): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_gallery_items SET
                related_enabled = :related_enabled,
                related_mode = :related_mode,
                related_max = :related_max,
                related_sort = :related_sort,
                related_fallback = :related_fallback,
                related_layout = :related_layout,
                related_show_text = :related_show_text,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'related_enabled' => $settings['related_enabled'] ? 1 : 0,
            'related_mode' => $settings['related_mode'],
            'related_max' => $settings['related_max'],
            'related_sort' => $settings['related_sort'],
            'related_fallback' => $settings['related_fallback'],
            'related_layout' => $settings['related_layout'],
            'related_show_text' => $settings['related_show_text'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * The projects picked by hand for an item's related projects, in their
     * own order (portfolio_related_items). Visible or not: the reader decides
     * what may be shown.
     *
     * @return list<int>
     */
    public function relatedItemIds(int $itemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT related_item_id FROM portfolio_related_items
             WHERE portfolio_item_id = :item_id
             ORDER BY sort_order ASC, related_item_id ASC'
        );
        $stmt->execute(['item_id' => $itemId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Replaces an item's hand-picked related projects with $relatedIds, in
     * that order. The item itself and a repeated id are left out here too,
     * whatever the caller sends; an id no item has is refused by the foreign
     * key, so the caller passes only ids it checked.
     *
     * @param list<int> $relatedIds
     */
    public function replaceRelatedItems(int $itemId, array $relatedIds): void
    {
        $this->db->prepare('DELETE FROM portfolio_related_items WHERE portfolio_item_id = :item_id')
            ->execute(['item_id' => $itemId]);

        $insert = $this->db->prepare(
            'INSERT INTO portfolio_related_items (portfolio_item_id, related_item_id, sort_order, created_at)
             VALUES (:item_id, :related_id, :sort_order, NOW())'
        );

        $position = 0;
        foreach (self::cleanIds($relatedIds, $itemId) as $relatedId) {
            $insert->execute(['item_id' => $itemId, 'related_id' => $relatedId, 'sort_order' => $position++]);
        }
    }

    /**
     * The projects picked by hand for one gallery block (item_galleries.id,
     * the Projecten block or a gallery on portfolio items), in their own order
     * (item_gallery_portfolio_items). Visible or not: the reader decides.
     *
     * @return list<int>
     */
    public function gallerySelection(int $itemGalleryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT portfolio_item_id FROM item_gallery_portfolio_items
             WHERE item_gallery_id = :gallery_id
             ORDER BY sort_order ASC, portfolio_item_id ASC'
        );
        $stmt->execute(['gallery_id' => $itemGalleryId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Replaces one gallery block's hand-picked projects with $itemIds, in that
     * order, a repeated id once. The foreign keys refuse an id no item or no
     * block has, so the caller passes only ids it checked.
     *
     * @param list<int> $itemIds
     */
    public function replaceGallerySelection(int $itemGalleryId, array $itemIds): void
    {
        $this->db->prepare('DELETE FROM item_gallery_portfolio_items WHERE item_gallery_id = :gallery_id')
            ->execute(['gallery_id' => $itemGalleryId]);

        $insert = $this->db->prepare(
            'INSERT INTO item_gallery_portfolio_items (item_gallery_id, portfolio_item_id, sort_order, created_at)
             VALUES (:gallery_id, :item_id, :sort_order, NOW())'
        );

        $position = 0;
        foreach (self::cleanIds($itemIds, null) as $itemId) {
            $insert->execute(['gallery_id' => $itemGalleryId, 'item_id' => $itemId, 'sort_order' => $position++]);
        }
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
     * Persists a full new display order for a gallery's items (drag-and-drop
     * reordering in admin/portfolio.php). Only ids that actually belong to
     * $galleryId are honored; any of the gallery's items missing from
     * $orderedIds (e.g. currently hidden by a search/category filter in the
     * admin overview, so never part of the dragged subset) keep their
     * relative order and are placed after the given ones — same
     * "never drop an item out of the list" guarantee as
     * the old VariantImageRepository::reorder(). This is what lets drag-and-drop work
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
     * Positive ids in their first order, each once, without $exclude.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function cleanIds(array $ids, ?int $exclude): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $exclude && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return $clean;
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
}
