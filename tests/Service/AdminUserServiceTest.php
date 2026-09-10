<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ShopModule;
use App\Service\AdminPermissions;
use App\Service\AdminUserForbiddenException;
use App\Service\AdminUserService;
use App\Service\AdminUserValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The Super Admin safeguards. Every one of these is a rule that must hold
 * against a *crafted* request, not only against what the form renders — so
 * each test posts fields the UI would never show that user.
 */
final class AdminUserServiceTest extends TestCase
{
    private InMemoryAdminUserRepository $repository;
    private AdminUserService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAdminUserRepository();
        $this->service = new AdminUserService($this->repository);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nieuwe Medewerker',
            'username' => 'medewerker',
            'email' => 'medewerker@example.test',
            'password' => 'een-lang-genoeg-wachtwoord',
            'password_confirmation' => 'een-lang-genoeg-wachtwoord',
            'is_active' => '1',
            'permissions' => [ShopModule::PRODUCTS_VIEW],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(int $id): array
    {
        return $this->repository->rows[$id];
    }

    // --- creating -------------------------------------------------------

    public function testASuperAdminCreatesAUserWithTheRequestedPermissions(): void
    {
        $superAdminId = $this->repository->seed('bart', true);

        $newId = $this->service->create($this->actor($superAdminId), $this->input([
            'permissions' => [ShopModule::PRODUCTS_MANAGE, ShopModule::ORDERS_VIEW],
        ]));

        $created = $this->repository->findById($newId);

        $this->assertNotNull($created);
        $this->assertSame('medewerker', $created['username']);
        $this->assertFalse($created['is_super_admin']);
        $this->assertTrue($created['is_active']);
        $this->assertSame(
            [ShopModule::PRODUCTS_MANAGE, ShopModule::ORDERS_VIEW],
            $created['granted_permissions']
        );
    }

    public function testAPasswordIsAlwaysStoredAsAHashThatVerifies(): void
    {
        $superAdminId = $this->repository->seed('bart', true);

        $newId = $this->service->create($this->actor($superAdminId), $this->input());
        $hash = (string) $this->repository->findById($newId)['password_hash'];

        $this->assertNotSame('een-lang-genoeg-wachtwoord', $hash);
        $this->assertTrue(password_verify('een-lang-genoeg-wachtwoord', $hash));
        $this->assertFalse(password_verify('iets-anders-helemaal', $hash));
    }

    public function testUnknownPermissionNamesInACreateAreDroppedInsteadOfStored(): void
    {
        $superAdminId = $this->repository->seed('bart', true);

        $newId = $this->service->create($this->actor($superAdminId), $this->input([
            'permissions' => ['products.view', 'products.delete_everything', 'root', '*'],
        ]));

        $this->assertSame(
            [ShopModule::PRODUCTS_VIEW],
            $this->repository->findById($newId)['granted_permissions']
        );
    }

    public function testOnlyASuperAdminCanCreateAnotherSuperAdmin(): void
    {
        $superAdminId = $this->repository->seed('bart', true);
        $managerId = $this->repository->seed('manager', false, true, [AdminPermissions::USERS_MANAGE]);

        $promoted = $this->service->create($this->actor($superAdminId), $this->input([
            'username' => 'tweede',
            'email' => 'tweede@example.test',
            'is_super_admin' => '1',
        ]));
        $this->assertTrue($this->repository->findById($promoted)['is_super_admin']);

        // The same crafted field from a users.manage holder is ignored.
        $notPromoted = $this->service->create($this->actor($managerId), $this->input([
            'username' => 'derde',
            'email' => 'derde@example.test',
            'is_super_admin' => '1',
        ]));
        $this->assertFalse($this->repository->findById($notPromoted)['is_super_admin']);
    }

    public function testANonSuperAdminCannotHandOutTheUsersManagePermission(): void
    {
        $managerId = $this->repository->seed('manager', false, true, [AdminPermissions::USERS_MANAGE]);

        $newId = $this->service->create($this->actor($managerId), $this->input([
            'permissions' => [ShopModule::PRODUCTS_VIEW, AdminPermissions::USERS_MANAGE],
        ]));

        $this->assertSame(
            [ShopModule::PRODUCTS_VIEW],
            $this->repository->findById($newId)['granted_permissions']
        );
    }

    public function testAUserWithoutUsersManageCannotCreateAccountsAtAll(): void
    {
        $editorId = $this->repository->seed('editor', false, true, [AdminPermissions::PAGES_MANAGE]);

        $this->expectException(AdminUserForbiddenException::class);
        $this->service->create($this->actor($editorId), $this->input());
    }

    public function testASuperAdminAccountStoresNoIndividualPermissions(): void
    {
        $superAdminId = $this->repository->seed('bart', true);

        $newId = $this->service->create($this->actor($superAdminId), $this->input([
            'is_super_admin' => '1',
            'permissions' => [ShopModule::PRODUCTS_MANAGE],
        ]));

        $created = $this->repository->findById($newId);
        $this->assertTrue($created['is_super_admin']);
        $this->assertSame([], $created['granted_permissions']);
    }

    // --- validation -----------------------------------------------------

    /**
     * @dataProvider invalidInputProvider
     *
     * @param array<string, mixed> $overrides
     */
    public function testInvalidInputIsRejectedWithAMessage(array $overrides, string $expectedFragment): void
    {
        $superAdminId = $this->repository->seed('bart', true);
        $this->repository->seed('bestaand');

        try {
            $this->service->create($this->actor($superAdminId), $this->input($overrides));
            $this->fail('expected the save to be rejected');
        } catch (AdminUserValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($expectedFragment, implode(' | ', $e->getErrors()));
        }

        $this->assertCount(2, $this->repository->rows, 'nothing may be written on a rejected save');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'empty name' => [['name' => '   '], 'Naam is verplicht'],
            'invalid email' => [['email' => 'geen-adres'], 'geldig e-mailadres'],
            'duplicate username' => [['username' => 'bestaand'], 'gebruikersnaam is al in gebruik'],
            'duplicate email' => [['email' => 'bestaand@example.test'], 'e-mailadres is al in gebruik'],
            'empty username' => [['username' => ''], 'Gebruikersnaam is verplicht'],
            'username with spaces' => [['username' => 'met spatie'], 'alleen letters, cijfers'],
            'missing password' => [['password' => '', 'password_confirmation' => ''], 'Kies een wachtwoord'],
            'short password' => [['password' => 'kort', 'password_confirmation' => 'kort'], 'minimaal'],
            'mismatched confirmation' => [['password_confirmation' => 'iets-anders-langs'], 'niet gelijk'],
            'password equals username' => [
                [
                    'username' => 'wachtwoordgelijk',
                    'password' => 'wachtwoordgelijk',
                    'password_confirmation' => 'wachtwoordgelijk',
                ],
                'niet gelijk zijn aan de gebruikersnaam',
            ],
        ];
    }

    // --- editing --------------------------------------------------------

    public function testEditingNameAndEmailNeverRequiresAPasswordChange(): void
    {
        $superAdminId = $this->repository->seed('bart', true);
        $targetId = $this->repository->seed('medewerker', false, true, [ShopModule::PRODUCTS_VIEW], 'original-hash');

        $this->service->update($this->actor($superAdminId), $targetId, [
            'name' => 'Nieuwe Naam',
            'username' => 'medewerker',
            'email' => 'nieuw@example.test',
            'password' => '',
            'password_confirmation' => '',
            'is_active' => '1',
            'permissions' => [ShopModule::PRODUCTS_VIEW],
        ]);

        $target = $this->repository->findById($targetId);
        $this->assertSame('Nieuwe Naam', $target['name']);
        $this->assertSame('nieuw@example.test', $target['email']);
        $this->assertSame('original-hash', $target['password_hash'], 'the password must be left alone');
    }

    public function testAnAdminCanResetSomeoneElsesPasswordAndTheOldOneStopsWorking(): void
    {
        $superAdminId = $this->repository->seed('bart', true);
        $targetId = $this->repository->seed(
            'medewerker',
            false,
            true,
            [],
            password_hash('het-oude-wachtwoord', PASSWORD_DEFAULT)
        );

        $this->service->update($this->actor($superAdminId), $targetId, [
            'name' => 'Medewerker',
            'username' => 'medewerker',
            'email' => 'medewerker@example.test',
            'password' => 'het-nieuwe-wachtwoord',
            'password_confirmation' => 'het-nieuwe-wachtwoord',
            'is_active' => '1',
            'permissions' => [],
        ]);

        $hash = (string) $this->repository->findById($targetId)['password_hash'];
        $this->assertTrue(password_verify('het-nieuwe-wachtwoord', $hash));
        $this->assertFalse(password_verify('het-oude-wachtwoord', $hash));
    }

    public function testDeactivatingAUserIsAnOrdinaryEdit(): void
    {
        $superAdminId = $this->repository->seed('bart', true);
        $targetId = $this->repository->seed('medewerker');

        $this->service->update($this->actor($superAdminId), $targetId, [
            'name' => 'Medewerker',
            'username' => 'medewerker',
            'email' => 'medewerker@example.test',
            'password' => '',
            'password_confirmation' => '',
            'permissions' => [],
        ]);

        $this->assertFalse($this->repository->findById($targetId)['is_active']);
    }

    // --- the safeguards -------------------------------------------------

    public function testAUserCannotGrantThemselvesExtraPermissions(): void
    {
        $selfId = $this->repository->seed(
            'manager',
            false,
            true,
            [AdminPermissions::USERS_MANAGE, ShopModule::PRODUCTS_VIEW]
        );

        $this->service->update($this->actor($selfId), $selfId, [
            'name' => 'Manager',
            'username' => 'manager',
            'email' => 'manager@example.test',
            'password' => '',
            'password_confirmation' => '',
            'is_active' => '1',
            'permissions' => AdminPermissions::all(),
        ]);

        $this->assertSame(
            [ShopModule::PRODUCTS_VIEW, AdminPermissions::USERS_MANAGE],
            $this->repository->findById($selfId)['granted_permissions']
        );
    }

    public function testANormalAdminCannotPromoteThemselvesToSuperAdmin(): void
    {
        $selfId = $this->repository->seed('manager', false, true, [AdminPermissions::USERS_MANAGE]);

        $this->service->update($this->actor($selfId), $selfId, [
            'name' => 'Manager',
            'username' => 'manager',
            'email' => 'manager@example.test',
            'password' => '',
            'password_confirmation' => '',
            'is_active' => '1',
            'is_super_admin' => '1',
            'permissions' => [AdminPermissions::USERS_MANAGE],
        ]);

        $this->assertFalse($this->repository->findById($selfId)['is_super_admin']);
    }

    public function testNobodyDeactivatesTheirOwnAccountByAccident(): void
    {
        $selfId = $this->repository->seed('manager', false, true, [AdminPermissions::USERS_MANAGE]);
        $this->repository->seed('bart', true);

        $this->service->update($this->actor($selfId), $selfId, [
            'name' => 'Manager',
            'username' => 'manager',
            'email' => 'manager@example.test',
            'password' => '',
            'password_confirmation' => '',
            'permissions' => [AdminPermissions::USERS_MANAGE],
        ]);

        $this->assertTrue($this->repository->findById($selfId)['is_active']);
    }

    public function testAUsersManageHolderCannotEditASuperAdmin(): void
    {
        $managerId = $this->repository->seed('manager', false, true, [AdminPermissions::USERS_MANAGE]);
        $superAdminId = $this->repository->seed('bart', true);

        $this->expectException(AdminUserForbiddenException::class);

        $this->service->update($this->actor($managerId), $superAdminId, [
            'name' => 'Overgenomen',
            'username' => 'bart',
            'email' => 'bart@example.test',
            'password' => 'een-nieuw-wachtwoord',
            'password_confirmation' => 'een-nieuw-wachtwoord',
            'permissions' => [],
        ]);
    }

    public function testTheLastActiveSuperAdminCannotBeDeactivated(): void
    {
        $onlySuperAdminId = $this->repository->seed('bart', true);
        $secondSuperAdminId = $this->repository->seed('reserve', true, false);

        // The break-glass session has no account id of its own, so the
        // "nobody edits themselves" rule does not cover it — this is the
        // guard that does.
        $breakGlassActor = ['id' => null, 'is_super_admin' => true, 'permissions' => AdminPermissions::all()];

        try {
            $this->service->update($breakGlassActor, $onlySuperAdminId, [
                'name' => 'Bart',
                'username' => 'bart',
                'email' => 'bart@example.test',
                'password' => '',
                'password_confirmation' => '',
                'is_super_admin' => '1',
                'permissions' => [],
            ]);
            $this->fail('expected the deactivation to be refused');
        } catch (AdminUserValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('laatste actieve Super Admin', implode(' ', $e->getErrors()));
        }

        $this->assertTrue($this->repository->findById($onlySuperAdminId)['is_active']);
        $this->assertTrue($this->repository->findById($onlySuperAdminId)['is_super_admin']);

        // With a second active Super Admin the same edit is allowed again.
        $this->repository->rows[$secondSuperAdminId]['is_active'] = true;

        $this->service->update($breakGlassActor, $onlySuperAdminId, [
            'name' => 'Bart',
            'username' => 'bart',
            'email' => 'bart@example.test',
            'password' => '',
            'password_confirmation' => '',
            'is_super_admin' => '1',
            'permissions' => [],
        ]);

        $this->assertFalse($this->repository->findById($onlySuperAdminId)['is_active']);
    }

    public function testTheLastActiveSuperAdminCannotBeDemoted(): void
    {
        $onlySuperAdminId = $this->repository->seed('bart', true);
        $breakGlassActor = ['id' => null, 'is_super_admin' => true, 'permissions' => AdminPermissions::all()];

        $this->expectException(AdminUserValidationException::class);

        $this->service->update($breakGlassActor, $onlySuperAdminId, [
            'name' => 'Bart',
            'username' => 'bart',
            'email' => 'bart@example.test',
            'password' => '',
            'password_confirmation' => '',
            'is_active' => '1',
            'permissions' => [ShopModule::PRODUCTS_VIEW],
        ]);
    }

    public function testAnUnknownTargetIsRejectedRatherThanCreated(): void
    {
        $superAdminId = $this->repository->seed('bart', true);

        $this->expectException(AdminUserValidationException::class);
        $this->service->update($this->actor($superAdminId), 9999, $this->input());
    }
}
