<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Module\ShopModule;
use App\Database;
use App\Repository\AdminUserRepository;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database, same convention as
 * tests/Repository/CollectionRepositoryIntegrationTest.php — the parts of
 * this feature that only the schema can prove (the pivot's composite primary
 * key, its ON DELETE CASCADE, the unique username/email indexes, and the
 * "log in with either identifier" query, which is exactly where a reused
 * named placeholder would have broken under this project's non-emulated
 * prepared statements).
 *
 * Every account created here uses an obviously-fake '__test_admin_user_*'
 * username so it can never collide with a real CMS account, and tearDown()
 * removes whatever a test did not remove itself. The real accounts in the
 * table are never read, written or counted on.
 */
final class AdminUserRepositoryIntegrationTest extends TestCase
{
    private const USERNAME_PREFIX = '__test_admin_user_';

    private AdminUserRepository $repository;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->repository = new AdminUserRepository();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->userIds as $id) {
            $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
        }

        $this->userIds = [];
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(
        string $suffix,
        bool $isSuperAdmin = false,
        bool $isActive = true,
        array $permissions = [],
        string $password = 'een-lang-genoeg-wachtwoord'
    ): int {
        $username = self::USERNAME_PREFIX . $suffix . '_' . bin2hex(random_bytes(3));

        $id = $this->repository->create([
            'name' => 'Test ' . $suffix,
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_super_admin' => $isSuperAdmin,
            'is_active' => $isActive,
        ]);

        $this->userIds[] = $id;

        if ($permissions !== []) {
            $this->repository->setPermissions($id, $permissions);
        }

        return $id;
    }

    public function testAnAccountIsStoredAndReadBackWithTypedFlags(): void
    {
        $id = $this->createUser('basic', false, true, [ShopModule::PRODUCTS_VIEW]);

        $user = $this->repository->findById($id);

        $this->assertNotNull($user);
        $this->assertSame($id, $user['id']);
        $this->assertIsBool($user['is_super_admin']);
        $this->assertIsBool($user['is_active']);
        $this->assertFalse($user['is_super_admin']);
        $this->assertTrue($user['is_active']);
        $this->assertSame([ShopModule::PRODUCTS_VIEW], $user['granted_permissions']);
    }

    public function testAPasswordIsOnlyEverStoredAsAVerifiableHash(): void
    {
        $id = $this->createUser('hashing', false, true, [], 'het-echte-wachtwoord');

        $hash = (string) $this->repository->findById($id)['password_hash'];

        $this->assertNotSame('het-echte-wachtwoord', $hash);
        $this->assertStringNotContainsString('het-echte-wachtwoord', $hash);
        $this->assertTrue(password_verify('het-echte-wachtwoord', $hash));
    }

    public function testChangingThePasswordInvalidatesTheOldOne(): void
    {
        $id = $this->createUser('rotate', false, true, [], 'het-oude-wachtwoord');

        $this->repository->updatePasswordHash($id, password_hash('het-nieuwe-wachtwoord', PASSWORD_DEFAULT));

        $hash = (string) $this->repository->findById($id)['password_hash'];
        $this->assertTrue(password_verify('het-nieuwe-wachtwoord', $hash));
        $this->assertFalse(password_verify('het-oude-wachtwoord', $hash));
    }

    public function testAnAccountIsFoundByEitherItsUsernameOrItsEmail(): void
    {
        $id = $this->createUser('login');
        $user = $this->repository->findById($id);

        $byUsername = $this->repository->findByLogin((string) $user['username']);
        $byEmail = $this->repository->findByLogin((string) $user['email']);

        $this->assertNotNull($byUsername);
        $this->assertNotNull($byEmail);
        $this->assertSame($id, $byUsername['id']);
        $this->assertSame($id, $byEmail['id']);

        $this->assertNull($this->repository->findByLogin('__test_admin_user_does_not_exist'));
        $this->assertNull($this->repository->findByLogin(''));
    }

    /**
     * A disabled account is still *found* — whether it may log in is
     * AdminAuth's decision, made after the password check so the two cases
     * cannot be told apart by timing.
     */
    public function testADisabledAccountIsStillReturnedByTheLoginLookup(): void
    {
        $id = $this->createUser('disabled', false, false);
        $user = $this->repository->findById($id);

        $found = $this->repository->findByLogin((string) $user['username']);

        $this->assertNotNull($found);
        $this->assertFalse($found['is_active']);
    }

    public function testUsernameAndEmailAreUniqueAcrossAccounts(): void
    {
        $id = $this->createUser('unique');
        $user = $this->repository->findById($id);

        $this->assertTrue($this->repository->usernameExists((string) $user['username']));
        $this->assertTrue($this->repository->emailExists((string) $user['email']));

        // ...but not against the account itself, so an edit that keeps its
        // own username is not rejected as a duplicate.
        $this->assertFalse($this->repository->usernameExists((string) $user['username'], $id));
        $this->assertFalse($this->repository->emailExists((string) $user['email'], $id));

        $this->assertFalse($this->repository->usernameExists('__test_admin_user_nobody'));
    }

    public function testTheDatabaseRefusesADuplicateUsername(): void
    {
        $id = $this->createUser('duplicate');
        $username = (string) $this->repository->findById($id)['username'];

        $this->expectException(\PDOException::class);

        $this->repository->create([
            'name' => 'Kopie',
            'username' => $username,
            'email' => $username . '.other@example.test',
            'password_hash' => password_hash('een-lang-genoeg-wachtwoord', PASSWORD_DEFAULT),
            'is_super_admin' => false,
            'is_active' => true,
        ]);
    }

    public function testPermissionsAreReplacedWholesaleAndUnknownNamesNeverReachTheTable(): void
    {
        $id = $this->createUser('permissions', false, true, [ShopModule::PRODUCTS_VIEW]);

        $this->repository->setPermissions($id, [
            ShopModule::ORDERS_MANAGE,
            'products.delete_everything',
            'root',
        ]);

        $user = $this->repository->findById($id);
        $this->assertSame([ShopModule::ORDERS_MANAGE], $user['granted_permissions']);

        // ...and the implied view permission is added on read, not stored.
        $this->assertSame(
            [ShopModule::ORDERS_VIEW, ShopModule::ORDERS_MANAGE],
            $user['permissions']
        );

        $this->repository->setPermissions($id, []);
        $this->assertSame([], $this->repository->findById($id)['granted_permissions']);
    }

    public function testTheSamePermissionCannotBeStoredTwiceForOneAccount(): void
    {
        $id = $this->createUser('composite');
        $db = Database::connection();

        $db->prepare('INSERT INTO admin_user_permissions (user_id, permission, created_at) VALUES (:id, :p, NOW())')
            ->execute(['id' => $id, 'p' => AdminPermissions::PAGES_MANAGE]);

        $this->expectException(\PDOException::class);

        $db->prepare('INSERT INTO admin_user_permissions (user_id, permission, created_at) VALUES (:id, :p, NOW())')
            ->execute(['id' => $id, 'p' => AdminPermissions::PAGES_MANAGE]);
    }

    public function testDeletingAnAccountRemovesItsPermissionRows(): void
    {
        $id = $this->createUser('cascade', false, true, [AdminPermissions::PAGES_MANAGE]);
        $db = Database::connection();

        $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);

        $stmt = $db->prepare('SELECT COUNT(*) FROM admin_user_permissions WHERE user_id = :id');
        $stmt->execute(['id' => $id]);

        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testActiveSuperAdminCountOnlyCountsSuperAdminsThatCanStillLogIn(): void
    {
        $before = $this->repository->activeSuperAdminCount();

        $this->createUser('super_active', true, true);
        $this->assertSame($before + 1, $this->repository->activeSuperAdminCount());

        $this->createUser('super_disabled', true, false);
        $this->assertSame($before + 1, $this->repository->activeSuperAdminCount());

        $this->createUser('normal_active', false, true);
        $this->assertSame($before + 1, $this->repository->activeSuperAdminCount());
    }

    public function testLastLoginIsRecorded(): void
    {
        $id = $this->createUser('lastlogin');

        $this->assertNull($this->repository->findById($id)['last_login_at']);

        $this->repository->touchLastLogin($id);

        $this->assertNotNull($this->repository->findById($id)['last_login_at']);
    }

    /**
     * The migration converts the account that used to live in .env into the
     * first Super Admin, so a working CMS always has at least one — the
     * property the whole permission model rests on.
     */
    public function testTheMigrationLeftAtLeastOneActiveSuperAdminBehind(): void
    {
        $this->assertGreaterThanOrEqual(1, $this->repository->activeSuperAdminCount());
    }
}
