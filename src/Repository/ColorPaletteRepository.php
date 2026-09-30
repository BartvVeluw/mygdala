<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `color_palettes` SQL: the website's named colour palettes, one of which
 * is active. Validation, names, the one-active rule and the delete protection
 * live in App\Service\Theme\ColorPaletteService; how the active palette
 * becomes the site's colours is App\Service\Theme\ThemeSettings.
 *
 * `is_active` is 1 or NULL under a UNIQUE index, never 0: the database itself
 * holds at most one active palette (migration 20261003100000).
 */
final class ColorPaletteRepository extends Repository
{
    /** The columns a palette is made of, besides its id, state and timestamps. */
    public const COLUMNS = [
        'name',
        'primary_color',
        'on_primary_color',
        'background_color',
        'surface_color',
        'text_color',
    ];

    /**
     * Every palette: the active one first, then by name.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->db->query(
            'SELECT * FROM color_palettes ORDER BY is_active IS NULL ASC, name ASC, id ASC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM color_palettes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null the active palette, or null when none is */
    public function findActive(): ?array
    {
        $row = $this->db->query('SELECT * FROM color_palettes WHERE is_active = 1 LIMIT 1')->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null the oldest palette, or null for an empty table */
    public function findFirst(): ?array
    {
        $row = $this->db->query('SELECT * FROM color_palettes ORDER BY id ASC LIMIT 1')->fetch();

        return $row === false ? null : $row;
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM color_palettes')->fetchColumn();
    }

    /** Whether another palette already has this name (the collation ignores case). */
    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM color_palettes WHERE name = :name'
            . ($exceptId !== null ? ' AND id != :id' : '') . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($exceptId !== null ? ['name' => $name, 'id' => $exceptId] : ['name' => $name]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * A new, inactive palette.
     *
     * @param array<string, string> $values the COLUMNS
     */
    public function create(array $values, bool $active = false): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO color_palettes
                (name, primary_color, on_primary_color, background_color, surface_color, text_color, is_active, created_at, updated_at)
             VALUES
                (:name, :primary_color, :on_primary_color, :background_color, :surface_color, :text_color, :is_active, NOW(), NOW())'
        );
        $stmt->execute(self::only($values) + ['is_active' => $active ? 1 : null]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Writes the given columns of one palette; a column that is not passed
     * keeps its value. Never touches `is_active`: activating is activate().
     *
     * @param array<string, string> $values a subset of the COLUMNS
     */
    public function update(int $id, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::COLUMNS));
        if ($values === []) {
            return;
        }

        $sets = [];
        foreach (array_keys($values) as $column) {
            // $column is one of the COLUMNS literals, never input.
            $sets[] = $column . ' = :' . $column;
        }

        $stmt = $this->db->prepare(
            'UPDATE color_palettes SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(array_map('strval', $values) + ['id' => $id]);
    }

    /**
     * Makes one palette the active one, atomically: the old one is cleared
     * and the new one set inside one transaction (or inside the caller's,
     * when one is already open, as in the Setup Wizard), with the target row
     * locked first. False — and nothing changed — when the palette is gone.
     */
    public function activate(int $id): bool
    {
        return $this->inTransaction(function () use ($id): bool {
            $stmt = $this->db->prepare('SELECT id, is_active FROM color_palettes WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if ($row === false) {
                return false;
            }

            if ((int) ($row['is_active'] ?? 0) === 1) {
                return true;
            }

            // Clear first: the unique index allows one 1 at any moment.
            $this->db->exec('UPDATE color_palettes SET is_active = NULL, updated_at = NOW() WHERE is_active = 1');
            $this->db
                ->prepare('UPDATE color_palettes SET is_active = 1, updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);

            return true;
        });
    }

    /**
     * Deletes one palette, but never the active one: the WHERE clause refuses
     * it whatever the caller checked. True when a row was removed.
     */
    public function deleteInactive(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM color_palettes WHERE id = :id AND is_active IS NULL');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    private function inTransaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            return $work();
        }

        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return $result;
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
