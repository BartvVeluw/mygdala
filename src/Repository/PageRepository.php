<?php

namespace App\Repository;

/**
 * All `pages` SQL. One row per CMS-managed public page — both the six
 * protected system pages (Homepage, Shop, Diensten, Portfolio, Over mij,
 * Contact, each rendered by its own root-level PHP template) and every
 * ordinary dynamic content page (rendered generically by pagina.php at
 * /<slug>). See db/migrations/20260908100000_create_pages_table.php for the
 * schema rationale, and App\Service\PageService for the create/update/delete
 * rules (slug normalisation, reserved slugs, system-page protection,
 * navigation/footer reference checks) that must not be bypassed by calling
 * this class directly.
 *
 * Two identifiers, deliberately:
 *   - `content_key` — immutable storage key; what page_sections.page_slug
 *     and every section content table's page_slug contains for this page.
 *     Never changes, so renaming a page never touches a section row.
 *   - `slug` — the mutable public URL slug of a content page.
 */
class PageRepository extends Repository
{
    /**
     * Every page, system pages first (in their fixed sidebar order), then
     * content pages in creation order — the order admin/pages.php lists them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForAdmin(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM pages ORDER BY is_system DESC, sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * The shape of the page tree and nothing else: one light row per page,
     * in sibling order. What App\Service\PagePath builds every page's
     * ancestor chain from, in one query for the whole site, so a list of a
     * hundred pages never asks for its parents one at a time.
     *
     * @return list<array<string, mixed>>
     */
    public function findStructure(): array
    {
        $stmt = $this->db->query(
            'SELECT id, parent_id, admin_group, slug, route_path, is_system, status, sort_order
             FROM pages ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /** How many pages sit directly under this one. */
    public function countChildren(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM pages WHERE parent_id = :id');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllPublished(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM pages WHERE status = 'published' ORDER BY is_system DESC, sort_order ASC, id ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Public lookup by stable id — used by App\Service\LinkResolver to
     * resolve a nav/footer link_type='page' target to its CURRENT slug, so a
     * later rename follows through automatically. Only ever returns a
     * published page: a draft or deleted target quietly resolves to "no
     * link" rather than exposing unpublished content in the menu.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdPublished(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE id = :id AND status = 'published' LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * findByIdPublished() for many ids in one query, keyed by id: for a list
     * of cards that each link to a page the way a menu item does, so a list
     * of thirty links is one query rather than thirty. A draft or a deleted
     * page is simply absent from the result.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> keyed by pages.id
     */
    public function findPublishedByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE status = 'published' AND id IN ({$placeholders})");
        $stmt->execute($ids);

        $pages = [];
        foreach ($stmt->fetchAll() as $row) {
            $pages[(int) $row['id']] = $row;
        }

        return $pages;
    }

    /**
     * Public lookup by URL slug — only ever returns a published page, so a
     * draft behaves exactly like a page that doesn't exist (pagina.php
     * renders its 404 either way).
     *
     * @return array<string, mixed>|null
     */
    public function findBySlugPublished(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Lookup by the immutable storage key — how a section editor resolves
     * "which page does this page_slug belong to" (see admin/page-hero.php
     * and friends).
     *
     * @return array<string, mixed>|null
     */
    public function findByContentKey(string $contentKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pages WHERE content_key = :content_key LIMIT 1');
        $stmt->execute(['content_key' => $contentKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT 1 FROM pages WHERE slug = :slug AND id != :id LIMIT 1');
            $stmt->execute(['slug' => $slug, 'id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM pages WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
        }

        return $stmt->fetch() !== false;
    }

    public function contentKeyExists(string $contentKey): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM pages WHERE content_key = :content_key LIMIT 1');
        $stmt->execute(['content_key' => $contentKey]);

        return $stmt->fetch() !== false;
    }

    /**
     * Creates a content page. System pages are only ever created by
     * migrations (they need a root-level PHP template to exist at all), so
     * this always writes is_system = 0 / route_path = NULL — an admin can
     * never mint a new protected page through the CMS.
     *
     * The page's text is not a column here: its title, SEO title and meta
     * description are written per language through
     * App\Service\PageLocalization::save(), in the same transaction as this
     * row (App\Service\PageTemplates\PageTemplateInstaller).
     *
     * Where it sits is decided by the caller too: `parent_id` (absent or null:
     * a root page) and `admin_group` (absent: the column's own default),
     * already validated by App\Service\PageService::validateParent(). A new
     * page gets the next sort_order of the whole table, which puts it after
     * every page that exists, so also last among its own siblings.
     *
     * @param array{content_key:string,slug:string,status:string,parent_id?:int|null,admin_group?:string} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO pages
                (parent_id, admin_group, content_key, slug, status, is_system, route_path,
                 sort_order, created_at, updated_at)
             VALUES
                (:parent_id, :admin_group, :content_key, :slug, :status, 0, NULL,
                 :sort_order, NOW(), NOW())'
        );
        $parentId = (int) ($data['parent_id'] ?? 0);
        $stmt->execute([
            'parent_id' => $parentId > 0 ? $parentId : null,
            'admin_group' => (string) ($data['admin_group'] ?? 'website'),
            'content_key' => $data['content_key'],
            'slug' => $data['slug'],
            'status' => $data['status'],
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates the language-neutral settings of one page. content_key,
     * is_system and route_path are deliberately absent: they are structural
     * identity, never editable content (see the create-table migration).
     * Callers must have already resolved slug/status through
     * App\Service\PageService, which is what keeps a system page's slug and
     * status locked to their current values. The page's text in each language
     * is App\Service\PageLocalization's.
     *
     * @param array{slug:string,status:string,noindex?:bool,show_breadcrumb?:bool} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pages SET
                slug = :slug, status = :status,
                noindex = :noindex, show_breadcrumb = :show_breadcrumb,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'slug' => $data['slug'],
            'status' => $data['status'],
            'noindex' => !empty($data['noindex']) ? 1 : 0,
            // Absent means "leave it on": every page showed a breadcrumb
            // before this was a choice (App\Service\Breadcrumbs\PageBreadcrumb).
            'show_breadcrumb' => (!array_key_exists('show_breadcrumb', $data) || !empty($data['show_breadcrumb'])) ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Where a page sits: under which page, and in which admin group.
     *
     * Separate from update() because only App\Service\PageService decides
     * whether a move is allowed (no cycle, no page under itself, no fixed-URL
     * page in a tree). $moveToEnd gives the page the next sort_order of the
     * table, which is what puts a page that changed parent after its new
     * siblings; a page that stays under the same parent keeps its place.
     */
    public function updatePlacement(int $id, ?int $parentId, string $adminGroup, bool $moveToEnd): void
    {
        $sql = 'UPDATE pages SET parent_id = :parent_id, admin_group = :admin_group, updated_at = NOW()';
        $params = [
            'parent_id' => ($parentId !== null && $parentId > 0) ? $parentId : null,
            'admin_group' => $adminGroup,
            'id' => $id,
        ];

        if ($moveToEnd) {
            $sql .= ', sort_order = :sort_order';
            $params['sort_order'] = $this->nextSortOrder();
        }

        $stmt = $this->db->prepare($sql . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Files these pages under one admin group — a whole subtree at once,
     * because a tree is never split over two lists
     * (App\Service\PageAdminGroup).
     *
     * @param list<int> $ids
     */
    public function updateAdminGroup(array $ids, string $adminGroup): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE pages SET admin_group = ?, updated_at = NOW() WHERE id IN ({$placeholders})"
        );
        $stmt->execute([$adminGroup, ...$ids]);
    }

    /**
     * Stores (or clears) one page's own social sharing image.
     *
     * Separate from update() because it is decided separately: the chosen
     * media item is validated first, and the reference is written only once
     * that has succeeded, so a bad choice can never blank a working image.
     * NULL for both puts the page back on the site-wide Standaard
     * deel-afbeelding.
     *
     * `og_media_id` is the Media Library reference; `og_image_path` is kept
     * alongside it with that item's own path, so the legacy column stays
     * true rather than stale while it still exists (MEDIA.md). The two
     * always move together.
     */
    public function updateOgImagePath(int $id, ?string $imagePath, ?int $mediaId = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pages SET og_media_id = :og_media_id, og_image_path = :og_image_path, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'og_media_id' => ($mediaId !== null && $mediaId > 0) ? $mediaId : null,
            'og_image_path' => $imagePath,
            'id' => $id,
        ]);
    }

    /**
     * Removes the page row itself, and with it the page's text in every
     * language (page_translations cascades). Never call this directly for an
     * admin delete — App\Service\PageService::delete() first refuses protected
     * pages and pages still referenced by navigation/footer links, and
     * removes every attached section (and its content/media) inside one
     * transaction.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function nextSortOrder(): int
    {
        $stmt = $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages');

        return (int) $stmt->fetchColumn();
    }
}
