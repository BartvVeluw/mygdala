<?php

namespace App\Repository;

use App\Service\Blog\BlogSlug;

/**
 * All `blog_tags` SQL.
 *
 * A tag is a name and a slug, and that is the whole model: no description, no
 * hierarchy, no per-tag SEO copy, no colours. Tags exist to gather posts that
 * mention the same thing; a taxonomy that needs a description is a category
 * (BLOG.md). Since Multilingual 2.0 phase 5 wave B the NAME lives per website
 * language in blog_tag_translations, through
 * App\Service\Blog\BlogLocalization; this table holds the slug.
 *
 * THE SLUG IS THE IDENTITY. "Laser cutting", "laser-cutting" and "Laser
 * Cutting" normalise to one slug, and findOrCreate() below reuses the
 * existing row instead of making a third one — which is what keeps a tag
 * list from filling up with near-duplicates that each hold a third of the
 * posts. The unique index is the backstop. That identity is language-neutral:
 * naming a tag in a second language never makes a second tag, and never moves
 * /blog/tag/<slug>.
 *
 * ORDER IS LANGUAGE-NEUTRAL TOO. A tag has no sort order, so this table used
 * to order alphabetically on its Dutch name. It now orders by id, and what a
 * visitor or an editor sees alphabetically is sorted by its LABEL, one step
 * later — because an order that depends on the reader's language would shuffle
 * the chips on every language of the site.
 */
class BlogTagRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_tags WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_tags WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM blog_tags ORDER BY id ASC')->fetchAll();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM blog_tags WHERE slug = :slug AND id <> :exclude LIMIT 1');
        $stmt->execute(['slug' => $slug, 'exclude' => $excludeId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The id of the tag with this name, creating it only if its normalised
     * form is genuinely new. This is the whole de-duplication rule, and it is
     * here rather than in a service because the uniqueness it protects is a
     * database constraint.
     *
     * Returns null for a name that normalises to nothing at all ("---", an
     * emoji, whitespace): an unnameable tag is not created rather than being
     * stored under a generated slug nobody can find.
     */
    public function findOrCreateByName(string $name): ?int
    {
        $name = trim($name);
        $slug = BlogSlug::sanitize($name);

        if ($name === '' || $slug === '') {
            return null;
        }

        $existing = $this->findBySlug($slug);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        // Only the row. Its NAME is written per website language by the
        // caller, through App\Service\Blog\BlogLocalization, in the same
        // transaction — see api/admin/update-blog-post.php.
        $this->db
            ->prepare('INSERT INTO blog_tags (slug, created_at, updated_at) VALUES (:slug, NOW(), NOW())')
            ->execute(['slug' => $slug]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $stmt = $this->db->prepare('UPDATE blog_tags SET slug = :slug, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'slug' => (string) ($values['slug'] ?? ''),
        ]);
    }

    /**
     * Removes the tag. Its link rows go with it (ON DELETE CASCADE); the
     * posts that carried it keep everything else they had.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM blog_tags WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
