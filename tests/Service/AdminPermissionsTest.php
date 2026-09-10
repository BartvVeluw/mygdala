<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ShopModule;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;

/**
 * The permission registry and THE authorisation decision itself
 * (AdminPermissions::userHas()), which AdminAuth::can() delegates to. These
 * are the behavioural counterpart to AdminAccessControlTest's source
 * inspection: that test proves every page and endpoint asks the question,
 * this one proves the answer is right.
 */
final class AdminPermissionsTest extends TestCase
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function user(array $permissions, bool $isSuperAdmin = false): array
    {
        return [
            'id' => 42,
            'name' => 'Test',
            'is_super_admin' => $isSuperAdmin,
            'is_active' => true,
            'granted_permissions' => $permissions,
            'permissions' => AdminPermissions::expand($permissions),
        ];
    }

    public function testASuperAdminHoldsEveryPermissionThatExists(): void
    {
        $superAdmin = $this->user([], true);

        foreach (AdminPermissions::all() as $permission) {
            $this->assertTrue(
                AdminPermissions::userHas($superAdmin, $permission),
                "a super admin should hold {$permission}"
            );
        }
    }

    public function testAUserWithProductsViewMayViewButNotManageProducts(): void
    {
        $user = $this->user([ShopModule::PRODUCTS_VIEW]);

        $this->assertTrue(AdminPermissions::userHas($user, ShopModule::PRODUCTS_VIEW));
        $this->assertFalse(AdminPermissions::userHas($user, ShopModule::PRODUCTS_MANAGE));
    }

    public function testProductsManageImpliesProductsView(): void
    {
        $user = $this->user([ShopModule::PRODUCTS_MANAGE]);

        $this->assertTrue(AdminPermissions::userHas($user, ShopModule::PRODUCTS_MANAGE));
        $this->assertTrue(AdminPermissions::userHas($user, ShopModule::PRODUCTS_VIEW));
    }

    public function testOrdersViewDoesNotAllowOrderModification(): void
    {
        $user = $this->user([ShopModule::ORDERS_VIEW]);

        $this->assertTrue(AdminPermissions::userHas($user, ShopModule::ORDERS_VIEW));
        $this->assertFalse(AdminPermissions::userHas($user, ShopModule::ORDERS_MANAGE));
    }

    public function testOrdersManageImpliesOrdersView(): void
    {
        $user = $this->user([ShopModule::ORDERS_MANAGE]);

        $this->assertTrue(AdminPermissions::userHas($user, ShopModule::ORDERS_VIEW));
    }

    /**
     * A products-only account must not reach any other section — this is the
     * "no permission leaks sideways" check, run over the whole registry
     * rather than over a hand-picked pair.
     */
    public function testGrantsNeverLeakIntoOtherSections(): void
    {
        $user = $this->user([ShopModule::PRODUCTS_MANAGE]);
        $allowed = [ShopModule::PRODUCTS_MANAGE, ShopModule::PRODUCTS_VIEW];

        foreach (AdminPermissions::all() as $permission) {
            if (in_array($permission, $allowed, true)) {
                continue;
            }

            $this->assertFalse(
                AdminPermissions::userHas($user, $permission),
                "a products.manage account must not hold {$permission}"
            );
        }
    }

    public function testAnAccountWithoutGrantsHoldsNothing(): void
    {
        $user = $this->user([]);

        foreach (AdminPermissions::all() as $permission) {
            $this->assertFalse(AdminPermissions::userHas($user, $permission));
        }
    }

    public function testUnknownPermissionNamesAreDroppedRatherThanStored(): void
    {
        $sanitized = AdminPermissions::sanitize([
            'products.view',
            'products.delete_everything',
            '',
            'admin',
            '*',
            ['nested'],
            null,
            123,
            'orders.view',
        ]);

        $this->assertSame(['products.view', 'orders.view'], $sanitized);
    }

    public function testAnUnknownPermissionNeverGrantsAnything(): void
    {
        $user = $this->user(AdminPermissions::sanitize(['not.a.permission', 'users.manage.all']));

        $this->assertSame([], $user['granted_permissions']);
        $this->assertFalse(AdminPermissions::userHas($user, 'not.a.permission'));
        $this->assertFalse(AdminPermissions::userHas($user, AdminPermissions::USERS_MANAGE));
    }

    public function testSanitizeIsCanonicalSoStoredOrderNeverDependsOnFormOrder(): void
    {
        $this->assertSame(
            AdminPermissions::sanitize(['users.manage', 'dashboard.view', 'products.view']),
            AdminPermissions::sanitize(['products.view', 'users.manage', 'dashboard.view'])
        );
    }

    public function testSanitizeRemovesDuplicates(): void
    {
        $this->assertSame(
            ['products.view'],
            AdminPermissions::sanitize(['products.view', 'products.view'])
        );
    }

    public function testSanitizeRejectsNonArrayInput(): void
    {
        $this->assertSame([], AdminPermissions::sanitize('products.manage'));
        $this->assertSame([], AdminPermissions::sanitize(null));
        $this->assertSame([], AdminPermissions::sanitize(1));
    }

    public function testEveryPermissionInAGroupIsAlsoInAllAndViceVersa(): void
    {
        $fromGroups = [];
        foreach (AdminPermissions::groups() as $group) {
            $this->assertNotSame('', (string) $group['label']);

            foreach ($group['permissions'] as $permission => $meta) {
                $this->assertNotSame('', (string) $meta['label'], $permission);
                $this->assertNotSame('', (string) $meta['description'], $permission);
                $fromGroups[] = $permission;
            }
        }

        $this->assertSame(AdminPermissions::all(), $fromGroups);
        $this->assertSame(count($fromGroups), count(array_unique($fromGroups)));
    }

    public function testEveryImplicationPointsAtRealPermissions(): void
    {
        foreach (AdminPermissions::implies() as $source => $implied) {
            $this->assertTrue(AdminPermissions::isValid($source), $source);

            foreach ($implied as $permission) {
                $this->assertTrue(AdminPermissions::isValid($permission), $permission);
            }
        }

        foreach (AdminPermissions::SUPER_ADMIN_GRANTABLE_ONLY as $permission) {
            $this->assertTrue(AdminPermissions::isValid($permission), $permission);
        }
    }

    public function testTheAccessSummaryHidesAViewThatItsManageAlreadyImplies(): void
    {
        $this->assertSame(
            [AdminPermissions::label(ShopModule::PRODUCTS_MANAGE)],
            AdminPermissions::summarize([ShopModule::PRODUCTS_MANAGE])
        );

        $this->assertSame(
            [AdminPermissions::label(ShopModule::PRODUCTS_VIEW)],
            AdminPermissions::summarize([ShopModule::PRODUCTS_VIEW])
        );

        $this->assertSame([], AdminPermissions::summarize([]));
    }
}
