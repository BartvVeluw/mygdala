<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `page_themes` SQL (the Paginathema's module, App\Module\PageThemesModule).
 * Validation, slugs, usage and the delete protection live in
 * App\Service\PageThemes\PageThemeService; which page uses which theme is a
 * column of `pages` and is read through App\Repository\PageRepository.
 */
final class PageThemeRepository extends Repository
{
    /** The columns a theme is made of, besides its id and timestamps. */
    public const COLUMNS = [
        'name',
        'slug',
        'primary_color',
        'on_primary_color',
        'background_color',
        'surface_color',
        'text_color',
        'font_pairing',
    ];

    /**
     * Every theme, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM page_themes ORDER BY name ASC, id ASC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM page_themes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Whether another theme already has this name (the collation ignores case). */
    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->taken('name', $name, $exceptId);
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        return $this->taken('slug', $slug, $exceptId);
    }

    /**
     * @param array<string, string> $values the COLUMNS
     */
    public function create(array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO page_themes
                (name, slug, primary_color, on_primary_color, background_color, surface_color, text_color, font_pairing, created_at, updated_at)
             VALUES
                (:name, :slug, :primary_color, :on_primary_color, :background_color, :surface_color, :text_color, :font_pairing, NOW(), NOW())'
        );
        $stmt->execute(self::only($values));

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string> $values the COLUMNS
     */
    public function update(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE page_themes SET
                name = :name, slug = :slug,
                primary_color = :primary_color, on_primary_color = :on_primary_color,
                background_color = :background_color, surface_color = :surface_color,
                text_color = :text_color, font_pairing = :font_pairing,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(self::only($values) + ['id' => $id]);
    }

    /**
     * Deletes one theme. The foreign key on `pages.page_theme_id` refuses
     * while a page still uses it (RESTRICT), whatever the caller checked.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM page_themes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function taken(string $column, string $value, ?int $exceptId): bool
    {
        // $column is one of two literals above, never input.
        $sql = 'SELECT 1 FROM page_themes WHERE ' . $column . ' = :value'
            . ($exceptId !== null ? ' AND id != :id' : '') . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($exceptId !== null ? ['value' => $value, 'id' => $exceptId] : ['value' => $value]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private static function only(array $values): array
    {
        $clean = [];
        foreach (self::COLUMNS as $column) {
            $clean[$column] = (string) ($values[$column] ?? '');
        }

        return $clean;
    }
}
