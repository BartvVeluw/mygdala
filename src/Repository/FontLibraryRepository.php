<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All Font Library SQL: `font_families`, `font_files` and the website's own
 * choice per role, `theme_font_roles` (migration 20261004100000). Rules,
 * files on disk and messages live in App\Service\Theme\FontLibrary; which
 * page theme uses a family is asked of the page themes' own repository
 * (App\Repository\PageThemeRepository::findUsingFontFamily()), through the
 * module, never here.
 */
final class FontLibraryRepository extends Repository
{
    public const ROLES = ['heading', 'body'];

    /**
     * Every family by name, with how many variants it has.
     *
     * @return list<array<string, mixed>>
     */
    public function families(): array
    {
        return $this->db->query(
            'SELECT f.*, COUNT(v.id) AS variant_count, COALESCE(SUM(v.byte_size), 0) AS byte_size
               FROM font_families f
               LEFT JOIN font_files v ON v.font_family_id = f.id
              GROUP BY f.id
              ORDER BY f.name ASC, f.id ASC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function family(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM font_families WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM font_families WHERE name = :name' . ($exceptId !== null ? ' AND id != :id' : '') . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($exceptId !== null ? ['name' => $name, 'id' => $exceptId] : ['name' => $name]);

        return $stmt->fetchColumn() !== false;
    }

    public function createFamily(string $name, string $category, ?string $sourceUrl): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO font_families (name, category, source_url, created_at, updated_at)
             VALUES (:name, :category, :source_url, NOW(), NOW())'
        );
        $stmt->execute(['name' => $name, 'category' => $category, 'source_url' => $sourceUrl]);

        return (int) $this->db->lastInsertId();
    }

    public function updateFamily(int $id, string $name, string $category, ?string $sourceUrl): void
    {
        $stmt = $this->db->prepare(
            'UPDATE font_families SET name = :name, category = :category, source_url = :source_url, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'name' => $name, 'category' => $category, 'source_url' => $sourceUrl]);
    }

    /**
     * Deletes a family and, through the cascade, its variant rows. Every
     * foreign key that USES the family (the website's roles, page themes)
     * is RESTRICT, so this throws while anything still does.
     */
    public function deleteFamily(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM font_families WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * The variants of the given families, one row per file: upright before
     * italic, light before heavy.
     *
     * @param list<int> $familyIds
     * @return list<array<string, mixed>>
     */
    public function files(array $familyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $familyIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT * FROM font_files WHERE font_family_id IN (' . $placeholders . ') ORDER BY font_family_id ASC, style = \'italic\' ASC, weight ASC, id ASC'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function file(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM font_files WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{weight: int, style: string, format: string, file_name: string, original_filename: string, byte_size: int} $file
     */
    public function addFile(int $familyId, array $file): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO font_files (font_family_id, weight, style, format, file_name, original_filename, byte_size, created_at, updated_at)
             VALUES (:family, :weight, :style, :format, :file_name, :original_filename, :byte_size, NOW(), NOW())'
        );
        $stmt->execute([
            'family' => $familyId,
            'weight' => $file['weight'],
            'style' => $file['style'],
            'format' => $file['format'],
            'file_name' => $file['file_name'],
            'original_filename' => $file['original_filename'],
            'byte_size' => $file['byte_size'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Points a variant at a new file (Vervangen): same weight and style, a
     * new generated name, so no cache anywhere still has the old one.
     *
     * @param array{format: string, file_name: string, original_filename: string, byte_size: int} $file
     */
    public function replaceFile(int $id, array $file): void
    {
        $stmt = $this->db->prepare(
            'UPDATE font_files SET format = :format, file_name = :file_name, original_filename = :original_filename,
                    byte_size = :byte_size, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'format' => $file['format'],
            'file_name' => $file['file_name'],
            'original_filename' => $file['original_filename'],
            'byte_size' => $file['byte_size'],
        ]);
    }

    public function deleteFile(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM font_files WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** The bytes the whole library takes on disk, by its own bookkeeping. */
    public function totalBytes(): int
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(byte_size), 0) FROM font_files')->fetchColumn();
    }

    /**
     * The website's own choice per role: role => family id. A role without
     * a row follows the font pairing.
     *
     * @return array<string, int>
     */
    public function siteRoles(): array
    {
        $roles = [];
        foreach ($this->db->query('SELECT role, font_family_id FROM theme_font_roles')->fetchAll() as $row) {
            if (in_array($row['role'], self::ROLES, true)) {
                $roles[(string) $row['role']] = (int) $row['font_family_id'];
            }
        }

        return $roles;
    }

    /** Sets or clears (null) one role of the website. */
    public function setSiteRole(string $role, ?int $familyId): void
    {
        if (!in_array($role, self::ROLES, true)) {
            return;
        }

        if ($familyId === null) {
            $stmt = $this->db->prepare('DELETE FROM theme_font_roles WHERE role = :role');
            $stmt->execute(['role' => $role]);

            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO theme_font_roles (role, font_family_id, updated_at) VALUES (:role, :family, NOW())
             ON DUPLICATE KEY UPDATE font_family_id = VALUES(font_family_id), updated_at = NOW()'
        );
        $stmt->execute(['role' => $role, 'family' => $familyId]);
    }

    public function clearSiteRoles(): void
    {
        $this->db->exec('DELETE FROM theme_font_roles');
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
