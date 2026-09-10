<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ShopModule;
use App\Database;
use App\Repository\AdminUserRepository;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;

/**
 * The authentication behaviour itself, against the real dev database and a
 * real PHP session — the questions no source inspection can answer: does a
 * deactivated account get in, does a changed password invalidate the old
 * one, does an already-signed-in account lose access the moment it is
 * disabled, and did the migration really leave the pre-existing login intact.
 *
 * Accounts here use the same obviously-fake '__test_admin_user_*' prefix as
 * AdminUserRepositoryIntegrationTest and are removed in tearDown(). Real CMS
 * accounts are only ever read, never modified.
 */
final class AdminAuthLoginTest extends TestCase
{
    private const USERNAME_PREFIX = '__test_admin_user_';
    private const PASSWORD = 'een-lang-genoeg-testwachtwoord';

    private AdminUserRepository $repository;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->repository = new AdminUserRepository();
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->userIds as $id) {
            $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
        }

        $this->userIds = [];
        $this->resetSession();
    }

    /**
     * AdminAuth is deliberately static and session-backed; this puts the
     * process back in a "nobody is logged in" state between tests without
     * asking AdminAuth for a back door it should not have.
     */
    private function resetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        $_SESSION = [];
        AdminAuth::logout();
    }

    /**
     * Stands in for "the browser comes back with the same session cookie on
     * the next request": AdminAuth's per-request cache is dropped, and the
     * session it would have been handed is put back exactly as it was. This
     * is what proves permission checks read current database state rather
     * than whatever was true at login.
     */
    private function startNextRequestFor(int $userId): void
    {
        AdminAuth::logout();

        AdminAuth::start();
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user_id'] = $userId;
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(string $suffix, bool $isActive = true, array $permissions = []): array
    {
        $username = self::USERNAME_PREFIX . $suffix . '_' . bin2hex(random_bytes(3));

        $id = $this->repository->create([
            'name' => 'Test ' . $suffix,
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'is_super_admin' => false,
            'is_active' => $isActive,
        ]);

        $this->userIds[] = $id;

        if ($permissions !== []) {
            $this->repository->setPermissions($id, $permissions);
        }

        return ['id' => $id, 'username' => $username];
    }

    public function testAValidLoginSignsTheAccountIn(): void
    {
        $user = $this->createUser('login_ok', true, [ShopModule::PRODUCTS_VIEW]);

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], self::PASSWORD));
        $this->assertTrue(AdminAuth::isLoggedIn());
        $this->assertSame($user['id'], AdminAuth::userId());
        $this->assertFalse(AdminAuth::isSuperAdmin());
    }

    public function testTheSessionCarriesOnlyTheAccountIdNotItsPermissions(): void
    {
        $user = $this->createUser('session_shape', true, [ShopModule::PRODUCTS_MANAGE]);

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], self::PASSWORD));

        $this->assertSame($user['id'], $_SESSION['admin_user_id'] ?? null);
        $this->assertTrue($_SESSION['admin_logged_in'] ?? false);

        foreach ($_SESSION as $value) {
            $this->assertIsNotArray($value, 'no permission list belongs in the session');
        }

        $serialised = var_export($_SESSION, true);
        $this->assertStringNotContainsString(self::PASSWORD, $serialised);
        $this->assertStringNotContainsString('$2y$', $serialised, 'no password hash belongs in the session');
    }

    public function testLoginIssuesAFreshSessionId(): void
    {
        $user = $this->createUser('fixation');

        AdminAuth::start();
        $before = session_id();

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], self::PASSWORD));

        $this->assertNotSame('', (string) session_id());
        $this->assertNotSame($before, session_id(), 'the session id must change on login');
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $user = $this->createUser('wrong_password');

        $this->assertFalse(AdminAuth::attemptLogin($user['username'], 'niet-het-goede-wachtwoord'));
        $this->assertFalse(AdminAuth::isLoggedIn());
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        $this->assertFalse(AdminAuth::attemptLogin(self::USERNAME_PREFIX . 'nobody', self::PASSWORD));
        $this->assertFalse(AdminAuth::isLoggedIn());
    }

    public function testADeactivatedAccountCannotLogInEvenWithTheRightPassword(): void
    {
        $user = $this->createUser('disabled', false);

        $this->assertFalse(AdminAuth::attemptLogin($user['username'], self::PASSWORD));
        $this->assertFalse(AdminAuth::isLoggedIn());
        $this->assertNull(AdminAuth::userId());
    }

    public function testDeactivatingAnAccountEndsItsExistingSession(): void
    {
        $user = $this->createUser('revoked', true, [ShopModule::PRODUCTS_MANAGE]);

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], self::PASSWORD));
        $this->assertTrue(AdminAuth::can(ShopModule::PRODUCTS_MANAGE));

        $this->repository->update($user['id'], [
            'name' => 'Test revoked',
            'username' => $user['username'],
            'email' => $user['username'] . '@example.test',
            'is_super_admin' => false,
            'is_active' => false,
        ]);

        $this->startNextRequestFor($user['id']);

        $this->assertFalse(AdminAuth::isLoggedIn());
        $this->assertFalse(AdminAuth::can(ShopModule::PRODUCTS_MANAGE));
    }

    public function testRevokingAPermissionAppliesToTheNextRequest(): void
    {
        $user = $this->createUser('revoke_perm', true, [ShopModule::PRODUCTS_MANAGE]);

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], self::PASSWORD));
        $this->assertTrue(AdminAuth::can(ShopModule::PRODUCTS_MANAGE));

        $this->repository->setPermissions($user['id'], [ShopModule::PRODUCTS_VIEW]);

        $this->startNextRequestFor($user['id']);

        $this->assertTrue(AdminAuth::isLoggedIn());
        $this->assertTrue(AdminAuth::can(ShopModule::PRODUCTS_VIEW));
        $this->assertFalse(AdminAuth::can(ShopModule::PRODUCTS_MANAGE));
    }

    public function testChangingThePasswordStopsTheOldOneFromWorking(): void
    {
        $user = $this->createUser('rotate');

        $this->repository->updatePasswordHash($user['id'], password_hash('een-heel-ander-wachtwoord', PASSWORD_DEFAULT));

        $this->assertFalse(AdminAuth::attemptLogin($user['username'], self::PASSWORD));
        $this->assertFalse(AdminAuth::isLoggedIn());

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], 'een-heel-ander-wachtwoord'));
        $this->assertTrue(AdminAuth::isLoggedIn());
    }

    public function testLogoutEndsTheSession(): void
    {
        $user = $this->createUser('logout');

        $this->assertTrue(AdminAuth::attemptLogin($user['username'], self::PASSWORD));
        $this->assertTrue(AdminAuth::isLoggedIn());

        AdminAuth::logout();

        $this->assertFalse(AdminAuth::isLoggedIn());
        $this->assertNull(AdminAuth::userId());
        $this->assertSame('', AdminAuth::userName());
        $this->assertFalse(AdminAuth::can(AdminPermissions::DASHBOARD_VIEW));
    }

    public function testNobodySignedInHoldsNoPermissionAtAll(): void
    {
        foreach (AdminPermissions::all() as $permission) {
            $this->assertFalse(AdminAuth::can($permission), $permission);
        }
    }

    /**
     * The migration had to carry the account that used to live in .env into
     * the database *without changing the credential*. Comparing the stored
     * hash with the configured one proves the owner's existing password still
     * works, without this test ever knowing what that password is.
     */
    public function testTheExistingEnvAccountStillLogsInAfterTheMigration(): void
    {
        $credentials = AdminAuth::envCredentials();

        if ($credentials === null) {
            $this->markTestSkipped('No ADMIN_USERNAME/ADMIN_PASSWORD_HASH configured in this environment.');
        }

        $account = $this->repository->findByLogin($credentials['username']);

        $this->assertNotNull($account, 'the .env admin account should exist in admin_users after the migration');
        $this->assertTrue($account['is_super_admin'], 'it should have become the first Super Admin');
        $this->assertTrue($account['is_active']);
        $this->assertSame(
            $credentials['password_hash'],
            (string) $account['password_hash'],
            'the migrated account must keep the exact hash that was configured in .env'
        );
    }
}
