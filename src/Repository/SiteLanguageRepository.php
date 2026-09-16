<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Language\LanguageCode;

/**
 * All site_languages SQL. The schema, and why `is_default` is 1 or NULL, are
 * in db/migrations/20260917120000_create_the_site_language_registry.php.
 *
 * THE INVARIANTS LIVE IN THE SQL, not only in the service above it, so no
 * later caller can get around them by skipping
 * App\Service\Language\SiteLanguages:
 *
 *   - at most one default        the unique index on is_default
 *   - the default is active      setDefault() only picks an active row
 *   - the default stays          deactivate() and delete() never match it
 *   - codes are valid and unique LanguageCode on create(), unique index
 *
 * A new language is never created as the default. Moving the default is
 * setDefault()'s job alone, so there is exactly one statement that can do it.
 *
 * Every write takes part in a transaction that is already open on this
 * connection (the Setup Wizard saves everything in one) and opens its own
 * otherwise.
 */
final class SiteLanguageRepository extends Repository
{
    /** @return list<array<string, mixed>> every language, in order */
    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM site_languages ORDER BY sort_order ASC, id ASC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM site_languages WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * A new, non-default language at the end of the order.
     *
     * @throws \InvalidArgumentException when the code is not a valid language code
     * @throws \PDOException when the code already exists
     */
    public function create(string $code, string $name, string $nativeName, bool $isActive = true): int
    {
        $normalised = LanguageCode::normalise($code);
        if ($normalised === null) {
            throw new \InvalidArgumentException('Not a valid language code.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO site_languages (code, name, native_name, is_default, is_active, sort_order, created_at, updated_at)
             VALUES (:code, :name, :native_name, NULL, :is_active, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'code' => $normalised,
            'name' => $name,
            'native_name' => $nativeName,
            'is_active' => $isActive ? 1 : 0,
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Make $code the default language. False, with nothing changed, when no
     * such language exists or it is not active.
     */
    public function setDefault(string $code): bool
    {
        return $this->inTransaction(function () use ($code): bool {
            $stmt = $this->db->prepare(
                'SELECT id, is_default, is_active FROM site_languages WHERE code = :code LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch();

            if ($row === false || (int) $row['is_active'] !== 1) {
                return false;
            }

            if ((int) $row['is_default'] === 1) {
                return true;
            }

            // Clear first: the unique index allows one 1 at any moment.
            $this->db->exec('UPDATE site_languages SET is_default = NULL, updated_at = NOW() WHERE is_default = 1');

            $this->db
                ->prepare('UPDATE site_languages SET is_default = 1, updated_at = NOW() WHERE id = :id AND is_active = 1')
                ->execute(['id' => (int) $row['id']]);

            return true;
        });
    }

    /**
     * Switch a language off. Never the default: true only when $code exists,
     * is not the default and is inactive afterwards.
     */
    public function deactivate(string $code): bool
    {
        $this->db
            ->prepare('UPDATE site_languages SET is_active = 0, updated_at = NOW() WHERE code = :code AND is_default IS NULL')
            ->execute(['code' => $code]);

        $row = $this->findByCode($code);

        return $row !== null && (int) $row['is_active'] === 0;
    }

    /** Remove a language. Never the default: true only when a row was removed. */
    public function delete(string $code): bool
    {
        $stmt = $this->db->prepare('DELETE FROM site_languages WHERE code = :code AND is_default IS NULL');
        $stmt->execute(['code' => $code]);

        return $stmt->rowCount() > 0;
    }

    private function nextSortOrder(): int
    {
        $max = $this->db->query('SELECT MAX(sort_order) FROM site_languages')->fetchColumn();

        return $max === null || $max === false ? 0 : (int) $max + 1;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
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
}
