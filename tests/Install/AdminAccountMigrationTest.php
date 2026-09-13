<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The migration that moved CMS accounts into the database had one promise to
 * keep for an existing site: the account configured in .env
 * (ADMIN_USERNAME / ADMIN_PASSWORD_HASH) becomes the first Super Admin with
 * EXACTLY that hash, so the owner's password keeps working without anybody
 * having to know it (db/migrations/20260908150000_create_admin_users_tables.php).
 *
 * This used to be checked by comparing the developer's own .env hash with a
 * row in the test database. That compared two things the test did not
 * control — a .env changed since the database was copied failed it, a
 * missing one skipped it — and printed a real hash when it failed. Here the
 * account is a fixture: the hash of a made-up password, handed to a from-zero
 * install as its deployment configuration (Tests\Support\ScratchInstall) and
 * read back after every migration has run.
 */
#[Group('migration-backfill')]
final class AdminAccountMigrationTest extends TestCase
{
    private const DATABASE = 'mygdala_scratch_admin_account';

    private const USERNAME = '__test_env_admin__';
    private const PASSWORD = 'een-verzonnen-testwachtwoord';

    private static ?ScratchInstall $install = null;

    private static string $hash = '';

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        self::$install = ScratchInstall::fresh(self::DATABASE, [
            'ADMIN_USERNAME' => self::USERNAME,
            'ADMIN_PASSWORD_HASH' => self::$hash,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$install?->drop();
        self::$install = null;
    }

    private function install(): ScratchInstall
    {
        if (self::$install === null) {
            $this->markTestSkipped(
                'A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env), like scripts/test-db.php.'
            );
        }

        return self::$install;
    }

    /** @return array<string, mixed> */
    private function account(): array
    {
        $rows = $this->install()->rows('SELECT * FROM admin_users WHERE username = ?', [self::USERNAME]);

        $this->assertCount(1, $rows, 'the .env admin account should exist in admin_users after the migrations');

        return $rows[0];
    }

    public function testTheConfiguredAccountBecomesTheOnlyActiveSuperAdmin(): void
    {
        $account = $this->account();

        $this->assertSame(1, (int) $account['is_super_admin'], 'it should have become the first Super Admin');
        $this->assertSame(1, (int) $account['is_active']);
        $this->assertSame(1, $this->install()->count('admin_users'), 'the migrations must not invent a second account');
    }

    public function testTheAccountKeepsTheExactConfiguredHash(): void
    {
        $stored = (string) $this->account()['password_hash'];

        $this->assertSame(self::$hash, $stored, 'the migrated account must keep the exact hash that was configured in .env');
        $this->assertTrue(password_verify(self::PASSWORD, $stored), 'the configured password must still log in');
    }
}
