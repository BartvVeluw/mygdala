<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `redirects` SQL. See db/migrations/20260909240000_create_redirects_table.php
 * for the schema rationale and REDIRECTS.md for the behaviour.
 *
 * Dumb SQL, this project's repository convention: every rule about whether a
 * redirect may be written lives in App\Service\Redirects\RedirectValidator,
 * and every rule about which one fires lives in RedirectResolver. Nothing here
 * normalizes a path — callers hand in an already-normalized one, so there is
 * exactly one normalizer (App\Service\Redirects\RedirectPath) and no second
 * place a path could be shaped differently on the way in than on the way out.
 */
class RedirectRepository extends Repository
{
    /**
     * The one query the public side runs: an exact, indexed match on an
     * ACTIVE row. A disabled row is invisible here, which is what makes the
     * admin's on/off switch a real switch rather than a label.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveBySourcePath(string $sourcePath): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM redirects WHERE source_path = :source AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['source' => $sourcePath]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * One row whatever its state — the admin's own lookup, and the
     * conflict/loop checks, which must see a disabled row too: a disabled
     * redirect still owns its source path, and re-enabling it must not
     * suddenly reveal a duplicate.
     *
     * @return array<string, mixed>|null
     */
    public function findBySourcePath(string $sourcePath): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM redirects WHERE source_path = :source LIMIT 1');
        $stmt->execute(['source' => $sourcePath]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM redirects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every redirect, newest source path first alphabetically, optionally
     * narrowed by a plain substring of the source or the destination.
     *
     * No pagination and no query builder: this table holds one row per URL an
     * editor has ever moved, which for a site of this size is tens of rows,
     * not thousands. A LIKE over two columns is the whole search feature, and
     * building anything more would be infrastructure for a problem this
     * project does not have.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForAdmin(string $search = ''): array
    {
        $search = trim($search);

        if ($search === '') {
            $stmt = $this->db->query('SELECT * FROM redirects ORDER BY source_path ASC');

            return $stmt->fetchAll();
        }

        // "_" and "%" are LIKE wildcards, and a path is full of neither by
        // accident: an editor searching for "oude_pagina" means that literal
        // string, not "oude" plus any character plus "pagina". Escaped here so
        // a search can only ever narrow the list.
        $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

        $stmt = $this->db->prepare(
            'SELECT * FROM redirects
             WHERE source_path LIKE :needle OR target_value LIKE :target_needle
             ORDER BY source_path ASC'
        );
        // Two placeholders for one value: PDO with emulation off cannot bind
        // the same named parameter twice.
        $stmt->execute(['needle' => $needle, 'target_needle' => $needle]);

        return $stmt->fetchAll();
    }

    /**
     * Every row pointing at one internal path, used by the chain collapsing a
     * page rename performs. Compared on the exact stored value, so a target
     * that carries its own query string is left alone.
     *
     * @return list<array<string, mixed>>
     */
    public function findByInternalTarget(string $targetValue): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM redirects WHERE target_type = 'internal' AND target_value = :target"
        );
        $stmt->execute(['target' => $targetValue]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{source_path: string, target_type: string, target_value: string, status_code: int, is_active: bool, origin: string} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO redirects
                (source_path, target_type, target_value, status_code, is_active, origin, created_at, updated_at)
             VALUES
                (:source_path, :target_type, :target_value, :status_code, :is_active, :origin, NOW(), NOW())'
        );

        $stmt->execute([
            'source_path' => $data['source_path'],
            'target_type' => $data['target_type'],
            'target_value' => $data['target_value'],
            'status_code' => $data['status_code'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'origin' => $data['origin'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{source_path: string, target_type: string, target_value: string, status_code: int, is_active: bool} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE redirects SET
                source_path = :source_path,
                target_type = :target_type,
                target_value = :target_value,
                status_code = :status_code,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'source_path' => $data['source_path'],
            'target_type' => $data['target_type'],
            'target_value' => $data['target_value'],
            'status_code' => $data['status_code'],
            'is_active' => $data['is_active'] ? 1 : 0,
        ]);
    }

    /** Repoints one row without touching its source, status or origin. */
    public function updateTarget(int $id, string $targetType, string $targetValue): void
    {
        $stmt = $this->db->prepare(
            'UPDATE redirects
             SET target_type = :target_type, target_value = :target_value, updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute(['id' => $id, 'target_type' => $targetType, 'target_value' => $targetValue]);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $stmt = $this->db->prepare(
            'UPDATE redirects SET is_active = :is_active, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM redirects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
