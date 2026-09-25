<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL for `media_folders`, the virtual folders of the Media Library
 * (MEDIA.md, "Mappen").
 *
 * A folder is a row and a label. Nothing here touches a file or a directory,
 * and a folder name never becomes part of a path: moving an item between
 * folders changes media.folder_id and nothing else (MediaRepository::moveToFolder()).
 * One level only: a folder holds media, never another folder.
 */
class MediaFolderRepository extends Repository
{
    /**
     * Every folder by name, with how many items it holds: one query for the
     * whole list, because the library prints a count next to each.
     *
     * @return list<array{id: int, name: string, item_count: int}>
     */
    public function allWithCounts(): array
    {
        $rows = $this->db->query(
            'SELECT f.id, f.name, COUNT(m.id) AS item_count
               FROM media_folders f
               LEFT JOIN media m ON m.folder_id = f.id
              GROUP BY f.id, f.name
              ORDER BY f.name ASC, f.id ASC'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'item_count' => (int) $row['item_count'],
        ], $rows);
    }

    /** @return array{id: int, name: string}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name FROM media_folders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * Whether another folder already has this name, in the column's own
     * collation: "Kerst" and "kerst" are one name, as the unique index says.
     */
    public function nameTaken(string $name, int $exceptId = 0): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM media_folders WHERE name = :name AND id <> :id LIMIT 1');
        $stmt->execute(['name' => $name, 'id' => $exceptId]);

        return $stmt->fetchColumn() !== false;
    }

    /** How many items have no folder: the count next to "Geen map". */
    public function countWithoutFolder(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM media WHERE folder_id IS NULL')->fetchColumn();
    }

    public function create(string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO media_folders (name, created_at, updated_at) VALUES (:name, NOW(), NOW())');
        $stmt->execute(['name' => $name]);

        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->db->prepare('UPDATE media_folders SET name = :name, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    /**
     * Removes a folder and nothing else: its items go to "Geen map" first,
     * in the same transaction, so no item is ever deleted with it. The
     * foreign key (ON DELETE SET NULL) would do the same; this does not rely
     * on it.
     *
     * @return int how many items went back to "Geen map"
     */
    public function deleteKeepingItems(int $id): int
    {
        $this->db->beginTransaction();

        try {
            $release = $this->db->prepare('UPDATE media SET folder_id = NULL WHERE folder_id = :id');
            $release->execute(['id' => $id]);
            $released = $release->rowCount();

            $delete = $this->db->prepare('DELETE FROM media_folders WHERE id = :id');
            $delete->execute(['id' => $id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $released;
    }
}
