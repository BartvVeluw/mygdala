<?php

namespace App\Repository;

use App\Service\AdminPermissions;
use PDO;

/**
 * All `admin_users` and `admin_user_permissions` SQL lives here — the CMS
 * user accounts and their individual permission grants (see
 * db/migrations/20260908150000_create_admin_users_tables.php).
 *
 * Both sides live in one repository for the same reason CollectionRepository
 * owns its pivot: a CMS account without its permissions is not a useful
 * thing to load, so every read here returns the account *with* its grants.
 *
 * Deliberately NOT here: password hashing, who-may-change-what rules and
 * the last-Super-Admin safeguard. Those are policy, not persistence, and
 * live in App\Service\AdminUserService so they hold no matter which UI or
 * endpoint reaches the data. This class stores what it is given.
 */
class AdminUserRepository extends Repository
{
    /**
     * Every account, newest-relevant-first is not useful here — the CMS user
     * list is small, so it reads alphabetically with the Super Admins on top.
     *
     * @return array<int, array<string, mixed>> each row + 'permissions' (list<string>, expanded)
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM admin_users ORDER BY is_super_admin DESC, name ASC, id ASC'
        );
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }

        $permissions = $this->permissionsForAll();

        foreach ($rows as &$row) {
            $row = $this->hydrate($row, $permissions[(int) $row['id']] ?? []);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>|null the account + 'permissions', or null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row, $this->permissionsFor($id));
    }

    /**
     * Looks up an account by the identifier typed into the login form, which
     * may be either the username or the email address — the CMS moved from a
     * username-only .env account to database accounts with an email, and both
     * have to keep working.
     *
     * Returns inactive accounts too: whether a disabled account may log in is
     * an authentication decision (App\Service\AdminAuth), and answering it
     * here would make "wrong password" and "disabled account" distinguishable
     * by response timing.
     *
     * @return array<string, mixed>|null
     */
    public function findByLogin(string $identifier): ?array
    {
        if ($identifier === '') {
            return null;
        }

        // Two placeholders for one value on purpose: this project runs PDO
        // with ATTR_EMULATE_PREPARES off, and MySQL's native prepared
        // statements reject a named parameter that appears twice.
        $stmt = $this->db->prepare(
            'SELECT * FROM admin_users WHERE username = :username OR email = :email LIMIT 1'
        );
        $stmt->execute(['username' => $identifier, 'email' => $identifier]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row, $this->permissionsFor((int) $row['id']));
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        return $this->valueExists('username', $username, $excludeId);
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return $this->valueExists('email', $email, $excludeId);
    }

    private function valueExists(string $column, string $value, ?int $excludeId): bool
    {
        $sql = "SELECT 1 FROM admin_users WHERE {$column} = :value";
        $params = ['value' => $value];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * @param array{name: string, username: string, email: ?string, password_hash: string, is_super_admin: bool, is_active: bool} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (name, username, email, password_hash, is_super_admin, is_active, created_at, updated_at)
             VALUES (:name, :username, :email, :password_hash, :is_super_admin, :is_active, NOW(), NOW())'
        );

        $stmt->execute([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'is_super_admin' => $data['is_super_admin'] ? 1 : 0,
            'is_active' => $data['is_active'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates the account fields only. The password hash has its own method
     * so an edit that leaves the password fields empty can never blank it,
     * and permissions have their own method so they are never implied by an
     * account edit.
     *
     * @param array{name: string, username: string, email: ?string, is_super_admin: bool, is_active: bool} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users
             SET name = :name, username = :username, email = :email,
                 is_super_admin = :is_super_admin, is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'is_super_admin' => $data['is_super_admin'] ? 1 : 0,
            'is_active' => $data['is_active'] ? 1 : 0,
        ]);
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET password_hash = :hash, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'hash' => $passwordHash]);
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * The account's stored grants, without the implied ones — this is what
     * the edit form's checkboxes reflect. Use AdminPermissions::expand() (as
     * hydrate() does) when checking access.
     *
     * @return list<string>
     */
    public function permissionsFor(int $id): array
    {
        $stmt = $this->db->prepare('SELECT permission FROM admin_user_permissions WHERE user_id = :id');
        $stmt->execute(['id' => $id]);

        return AdminPermissions::sanitize($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, list<string>> keyed by user id
     */
    private function permissionsForAll(): array
    {
        $stmt = $this->db->query('SELECT user_id, permission FROM admin_user_permissions');

        $byUser = [];
        foreach ($stmt->fetchAll() as $row) {
            $byUser[(int) $row['user_id']][] = (string) $row['permission'];
        }

        foreach ($byUser as $userId => $permissions) {
            $byUser[$userId] = AdminPermissions::sanitize($permissions);
        }

        return $byUser;
    }

    /**
     * Replaces the account's grants with exactly $permissions. Unknown names
     * are dropped here as well as in the service layer, so no code path can
     * ever write a permission this CMS does not define.
     *
     * @param list<string> $permissions
     */
    public function setPermissions(int $id, array $permissions): void
    {
        $permissions = AdminPermissions::sanitize($permissions);

        $this->db->beginTransaction();

        try {
            $delete = $this->db->prepare('DELETE FROM admin_user_permissions WHERE user_id = :id');
            $delete->execute(['id' => $id]);

            if ($permissions !== []) {
                $insert = $this->db->prepare(
                    'INSERT INTO admin_user_permissions (user_id, permission, created_at) VALUES (:id, :permission, NOW())'
                );
                foreach ($permissions as $permission) {
                    $insert->execute(['id' => $id, 'permission' => $permission]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * How many Super Admins can currently log in. The safeguard against
     * locking the CMS out of its own user management reads this, and so does
     * AdminAuth when deciding whether the .env break-glass credential applies.
     */
    public function activeSuperAdminCount(): int
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) FROM admin_users WHERE is_super_admin = 1 AND is_active = 1'
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * Normalises the raw row: booleans as booleans (MySQL hands back "0"/"1"
     * strings), and the permission list already expanded with its implied
     * entries so every caller checks the same thing.
     *
     * @param array<string, mixed> $row
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function hydrate(array $row, array $permissions): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_super_admin'] = (int) $row['is_super_admin'] === 1;
        $row['is_active'] = (int) $row['is_active'] === 1;
        $row['granted_permissions'] = $permissions;
        $row['permissions'] = AdminPermissions::expand($permissions);

        return $row;
    }
}
