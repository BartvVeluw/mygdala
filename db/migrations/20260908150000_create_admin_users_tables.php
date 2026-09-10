<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CMS user accounts and their individual permissions.
 *
 * Until now the CMS had exactly one admin account, defined by
 * ADMIN_USERNAME/ADMIN_PASSWORD_HASH in .env (see App\Service\AdminAuth).
 * That model cannot express "an employee who may edit products but not
 * orders", so accounts move into the database. The .env pair is NOT removed:
 * it stays as the break-glass credential App\Service\AdminAuth falls back to
 * when this table holds no active Super Admin at all (table missing because
 * the migration has not run yet on a deploy, database unreachable, rows
 * lost). That is the only way the owner can be locked out, so it is the one
 * case the fallback covers.
 *
 * `username` exists next to `email` on purpose: the existing account logs in
 * as "admin", which is not an email address, and this migration must not
 * change the credential the owner already uses. The login form accepts
 * either identifier (see AdminAuth::attemptLogin()). `email` is nullable
 * only so this migration can never fail on an installation whose .env has no
 * usable address to seed; the CMS user forms require it for every account.
 *
 * Permissions are individual grants in a separate table — deliberately no
 * roles/groups (see MAIN.MD). `is_super_admin` is the single escape hatch:
 * a Super Admin implicitly holds every permission and never has rows here.
 * Valid permission names are defined once, server-side, in
 * App\Service\AdminPermissions; this table stores whatever that registry
 * validated, and unknown values are dropped before they ever reach it.
 *
 * There is no delete path for an account: deactivating (is_active = 0) is
 * the supported way to revoke access, so an account referenced by future
 * audit/history data always stays resolvable.
 */
final class CreateAdminUsersTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('admin_users', ['id' => true])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('username', 'string', ['limit' => 60])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('is_super_admin', 'boolean', ['default' => false])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['username'], ['unique' => true])
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['is_active', 'is_super_admin'])
            ->create();

        // user_id is explicitly unsigned to match admin_users.id — Phinx's
        // own `id` column is unsigned, and a signed reference silently makes
        // the foreign key impossible to create (this project hit exactly that
        // on orders/order_items, see MAIN.MD 2026-09-03).
        $this->table('admin_user_permissions', ['id' => false, 'primary_key' => ['user_id', 'permission']])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('permission', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addForeignKey('user_id', 'admin_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->seedFirstSuperAdmin();
    }

    public function down(): void
    {
        $this->table('admin_user_permissions')->drop()->save();
        $this->table('admin_users')->drop()->save();
    }

    /**
     * Converts the existing .env admin account into the first Super Admin,
     * reusing the hash that is already configured — no password is generated,
     * hardcoded or written in plain text anywhere, and the owner's current
     * credentials keep working unchanged after this migration.
     *
     * The hash is read from the environment at migration time (phinx.php
     * already loads .env), exactly the way AdminAuth read it before. If it is
     * missing the table is simply left empty: AdminAuth's break-glass path
     * then still lets the owner in and provisions the row on first login.
     */
    private function seedFirstSuperAdmin(): void
    {
        $username = trim((string) ($_ENV['ADMIN_USERNAME'] ?? ''));
        $hash = (string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? '');

        if ($username === '' || $hash === '' || !str_starts_with($hash, '$')) {
            return;
        }

        // A sensible default so the seeded account has a working email
        // address; the owner can change it in the CMS afterwards.
        $email = trim((string) ($_ENV['ADMIN_EMAIL'] ?? $_ENV['MAIL_FROM_ADDRESS'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = null;
        }

        $now = date('Y-m-d H:i:s');

        $this->table('admin_users')->insert([
            [
                'name' => 'Beheerder',
                'username' => $username,
                'email' => $email,
                'password_hash' => $hash,
                'is_super_admin' => 1,
                'is_active' => 1,
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }
}
