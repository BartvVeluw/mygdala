<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\AdminUserRepository;
use App\Service\AdminPermissions;

/**
 * In-memory stand-in for admin_users/admin_user_permissions. Every method
 * AdminUserService uses is overridden, so no database is touched — these are
 * rules, not storage, and they have to hold for every caller.
 */
final class InMemoryAdminUserRepository extends AdminUserRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;

    /** No PDO on purpose: this stand-in never touches a database. */
    public function __construct()
    {
    }

    /**
     * @param list<string> $permissions
     */
    public function seed(
        string $username,
        bool $isSuperAdmin = false,
        bool $isActive = true,
        array $permissions = [],
        string $passwordHash = 'hash'
    ): int {
        $id = $this->nextId++;

        $this->rows[$id] = [
            'id' => $id,
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => $passwordHash,
            'is_super_admin' => $isSuperAdmin,
            'is_active' => $isActive,
            'granted_permissions' => AdminPermissions::sanitize($permissions),
            'permissions' => AdminPermissions::expand($permissions),
        ];

        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        foreach ($this->rows as $row) {
            if ($row['username'] === $username && $row['id'] !== $excludeId) {
                return true;
            }
        }

        return false;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        foreach ($this->rows as $row) {
            if ($row['email'] === $email && $row['id'] !== $excludeId) {
                return true;
            }
        }

        return false;
    }

    public function create(array $data): int
    {
        $id = $this->nextId++;

        $this->rows[$id] = [
            'id' => $id,
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'is_super_admin' => $data['is_super_admin'],
            'is_active' => $data['is_active'],
            'granted_permissions' => [],
            'permissions' => [],
        ];

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $this->rows[$id] = array_merge($this->rows[$id], [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'is_super_admin' => $data['is_super_admin'],
            'is_active' => $data['is_active'],
        ]);
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $this->rows[$id]['password_hash'] = $passwordHash;
    }

    public function permissionsFor(int $id): array
    {
        return $this->rows[$id]['granted_permissions'] ?? [];
    }

    public function setPermissions(int $id, array $permissions): void
    {
        $permissions = AdminPermissions::sanitize($permissions);
        $this->rows[$id]['granted_permissions'] = $permissions;
        $this->rows[$id]['permissions'] = AdminPermissions::expand($permissions);
    }

    public function activeSuperAdminCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['is_super_admin'] === true && $row['is_active'] === true
        ));
    }
}
