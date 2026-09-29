<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The link tables between a content owner (a product, a project) and its
 * content page — `product_content_pages`, `portfolio_content_pages`
 * (db/migrations/20260930100000). One SQL shape for every owner: the table
 * and the owner's column come from the owner's own code constants
 * (App\Service\ContentOwners\ContentOwner::linkTable()/linkColumn()), never
 * from a request, and are checked against a plain identifier pattern anyway
 * before they reach a query.
 */
final class ContentPageRepository extends Repository
{
    public function pageIdFor(string $table, string $column, int $ownerId): ?int
    {
        [$table, $column] = self::identifiers($table, $column);
        $stmt = $this->db->prepare("SELECT page_id FROM `{$table}` WHERE `{$column}` = :owner_id LIMIT 1");
        $stmt->execute(['owner_id' => $ownerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function ownerIdFor(string $table, string $column, int $pageId): ?int
    {
        [$table, $column] = self::identifiers($table, $column);
        $stmt = $this->db->prepare("SELECT `{$column}` FROM `{$table}` WHERE page_id = :page_id LIMIT 1");
        $stmt->execute(['page_id' => $pageId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function link(string $table, string $column, int $ownerId, int $pageId): void
    {
        [$table, $column] = self::identifiers($table, $column);
        $stmt = $this->db->prepare("INSERT INTO `{$table}` (`{$column}`, page_id, created_at) VALUES (:owner_id, :page_id, NOW())");
        $stmt->execute(['owner_id' => $ownerId, 'page_id' => $pageId]);
    }

    public function unlink(string $table, string $column, int $ownerId): void
    {
        [$table, $column] = self::identifiers($table, $column);
        $stmt = $this->db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = :owner_id");
        $stmt->execute(['owner_id' => $ownerId]);
    }

    /** @return array{0: string, 1: string} */
    private static function identifiers(string $table, string $column): array
    {
        foreach ([$table, $column] as $identifier) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $identifier) !== 1) {
                throw new \InvalidArgumentException('Not a table or column name: ' . $identifier);
            }
        }

        return [$table, $column];
    }
}
