<?php

namespace App\Repository;

/**
 * All portfolio_categories SQL lives here — the CMS-managed Portfolio
 * taxonomy (see db/migrations/20260906070000_create_portfolio_categories_table.php).
 * Relationship rows in the portfolio_item_categories junction table are
 * managed from the "item" side, in PortfolioGalleryRepository (categories
 * for one item are naturally an item-editing concern) — this repository
 * only owns the category rows themselves: create/rename/delete/list, plus
 * the usage counts the category manager (admin/portfolio.php) and the
 * public filter bar (portfolio.php) both need.
 */
class PortfolioCategoryRepository extends Repository
{
    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    /**
     * Every category with how many Portfolio items currently use it —
     * admin/portfolio.php's "Portfolio categorieën" manager. A category with
     * 0 items may be deleted; one with 1+ may not (see
     * api/admin/delete-portfolio-category.php).
     *
     * @return array<int, array<string, mixed>> each row + 'item_count' (int), ordered by sort_order ASC, id ASC
     */
    public function findAllWithUsageCounts(): array
    {
        $stmt = $this->db->query(
            'SELECT pc.*, COUNT(pic.portfolio_item_id) AS item_count
             FROM portfolio_categories pc
             LEFT JOIN portfolio_item_categories pic ON pic.portfolio_category_id = pc.id
             GROUP BY pc.id
             ORDER BY pc.sort_order ASC, pc.id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Categories used by at least one visible (is_active = 1) Portfolio
     * item — the public filter bar (portfolio.php) never shows an empty
     * filter button, see App\Service\PortfolioGalleryContent.
     *
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findUsedByActiveItems(): array
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT pc.*
             FROM portfolio_categories pc
             INNER JOIN portfolio_item_categories pic ON pic.portfolio_category_id = pc.id
             INNER JOIN portfolio_gallery_items pgi ON pgi.id = pic.portfolio_item_id
             WHERE pgi.is_active = 1
             ORDER BY pc.sort_order ASC, pc.id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC — unknown ids are silently skipped
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM portfolio_categories WHERE id IN ({$placeholders}) ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT 1 FROM portfolio_categories WHERE slug = :slug AND id != :id LIMIT 1');
            $stmt->execute(['slug' => $slug, 'id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM portfolio_categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
        }

        return $stmt->fetch() !== false;
    }

    public function create(string $nameNl, ?string $nameEn, string $slug): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_categories (name_nl, name_en, slug, sort_order, created_at, updated_at)
             VALUES (:name_nl, :name_en, :slug, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'name_nl' => $nameNl,
            'name_en' => $nameEn,
            'slug' => $slug,
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Renames a category. The slug is deliberately never touched here — see
     * this table's migration docblock on why it must stay stable.
     */
    public function rename(int $id, string $nameNl, ?string $nameEn): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_categories SET name_nl = :name_nl, name_en = :name_en, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['name_nl' => $nameNl, 'name_en' => $nameEn, 'id' => $id]);
    }

    /**
     * Permanently removes a category row. Callers must check
     * findAllWithUsageCounts()'s item_count is 0 first — the
     * portfolio_item_categories.portfolio_category_id FK is RESTRICT, so a
     * category still in use would fail here with a foreign key constraint
     * error rather than silently orphaning relationships.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM portfolio_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function nextSortOrder(): int
    {
        $stmt = $this->db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM portfolio_categories');

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
