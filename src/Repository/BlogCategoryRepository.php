<?php

namespace App\Repository;

/**
 * All `blog_categories` SQL.
 *
 * A category is an editorial grouping with its own public archive at
 * /blog/categorie/<slug>, so it carries a slug, a sort order and — since
 * Multilingual 2.0 phase 5 wave B, per website language in
 * blog_category_translations — a name and an optional short description. It
 * carries no hierarchy: there are no parent categories, and that is a decision
 * rather than an omission (BLOG.md).
 *
 * ORDER IS LANGUAGE-NEUTRAL. The list used to fall back to the Dutch name when
 * two categories shared a sort order; it now falls back to the id, because an
 * order that depends on the reader's language would put the same two
 * categories in a different order on every language of the site. The CMS's own
 * sort order still decides, and nextPosition() gives every new category a
 * distinct one.
 *
 * Deleting one is SAFE BY CONSTRUCTION: `blog_post_categories` cascades, so
 * the link rows go and the posts themselves are untouched. A post that loses
 * its only category becomes an uncategorised post, which the listing and the
 * detail page both render without complaint.
 */
class BlogCategoryRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_categories WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The public archive's category, or null. An inactive category is the
     * same answer as an unknown one, so /blog/categorie/<slug> 404s for both.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM blog_categories WHERE slug = :slug AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every category, in the order the editor arranged them — the admin list
     * and the editor's checkboxes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db
            ->query('SELECT * FROM blog_categories ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    /**
     * The categories a public page may show, in the same order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        return $this->db
            ->query('SELECT * FROM blog_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM blog_categories WHERE slug = :slug AND id <> :exclude LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'exclude' => $excludeId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Adds a category row. Its NAME and DESCRIPTION are not here: since
     * Multilingual 2.0 phase 5 wave B they live per website language in
     * blog_category_translations and are written through
     * App\Service\Blog\BlogLocalization, in the same transaction as this row.
     *
     * @param array<string, mixed> $values
     */
    public function create(array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO blog_categories (slug, is_active, sort_order, created_at, updated_at)
             VALUES (:slug, :is_active, :sort_order, NOW(), NOW())'
        );
        $stmt->execute($this->parameters($values));

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $parameters = $this->parameters($values);
        $parameters['id'] = $id;

        $stmt = $this->db->prepare(
            'UPDATE blog_categories
             SET slug = :slug, is_active = :is_active, sort_order = :sort_order, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute($parameters);
    }

    /**
     * Removes the category. Its link rows go with it (ON DELETE CASCADE); no
     * post is deleted, and no post's other categories are touched.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM blog_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** The position a newly created category lands in: after the last one. */
    public function nextPosition(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM blog_categories')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function parameters(array $values): array
    {
        return [
            'slug' => (string) ($values['slug'] ?? ''),
            'is_active' => (int) (bool) ($values['is_active'] ?? true),
            'sort_order' => (int) ($values['sort_order'] ?? 0),
        ];
    }
}
