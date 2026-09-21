<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\RedirectRepository;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectResolver;
use App\Service\Redirects\RedirectTarget;
use App\Service\Redirects\RedirectValidator;
use PHPUnit\Framework\TestCase;

/**
 * The Redirect Manager with a module switched off.
 *
 * Two separate promises, and MODULES.md is the reason both matter: switching a
 * module off is not an uninstall, so the stored redirect must survive — and
 * the module's files are still on disk and its routes are still reserved, so
 * nothing may quietly open them up as somewhere to point a visitor.
 *
 * Runs in-process against App\Module\ModuleRegistry::overrideForTests(), which
 * is the only way a test can change which modules are running: the real answer
 * comes from the environment a process was STARTED with (see TESTING.md).
 */
class RedirectModuleTest extends TestCase
{
    private const SOURCE = '/zz-redirect-module-old';

    private RedirectRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new RedirectRepository();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        ModuleRegistry::overrideForTests(null);
    }

    private function cleanUp(): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM redirects WHERE source_path = :source');
        $stmt->execute(['source' => self::SOURCE]);
    }

    private function storeShopRedirect(): int
    {
        return $this->repository->create([
            'source_path' => self::SOURCE,
            'target_type' => RedirectTarget::TYPE_INTERNAL,
            'target_value' => '/shop.php',
            'status_code' => Redirect::STATUS_PERMANENT,
            'is_active' => true,
            'origin' => Redirect::ORIGIN_MANUAL,
        ]);
    }

    /**
     * With the Shop off, /shop.php answers 404 (App\Module\ModuleGuard).
     * Executing the redirect would swap one dead URL for another and throw
     * away the one the visitor actually asked for, so it does not fire — and
     * they get the 404 they were already going to get.
     */
    public function testARedirectIntoADisabledModuleDoesNotFire(): void
    {
        $this->storeShopRedirect();

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);

        $this->assertNull((new RedirectResolver($this->repository))->resolve(self::SOURCE));
    }

    /** Switching a module off never deletes anything. */
    public function testTheStoredRedirectSurvivesTheModuleBeingSwitchedOff(): void
    {
        $id = $this->storeShopRedirect();

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);

        $stored = $this->repository->findById($id);

        $this->assertNotNull($stored);
        $this->assertSame('/shop.php', $stored['target_value']);
        $this->assertSame(1, (int) $stored['is_active']);
    }

    public function testTheSameRedirectWorksAgainOnceTheModuleIsBackOn(): void
    {
        $this->storeShopRedirect();

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);
        $this->assertNull((new RedirectResolver($this->repository))->resolve(self::SOURCE));

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'multilingual' => true]);

        $resolved = (new RedirectResolver($this->repository))->resolve(self::SOURCE);

        $this->assertNotNull($resolved);
        $this->assertSame(\App\Service\AppUrl::canonical('shop.php'), $resolved['location']);
        $this->assertSame(301, $resolved['status']);
    }

    /**
     * A disabled module's routes stay reserved (MODULES.md), so a redirect can
     * never be hung on one to "fill the gap" while the Shop is off — the file
     * is still on disk and would shadow it anyway.
     */
    public function testADisabledModulesOwnRoutesStayClosedToRedirectSources(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);

        $validator = new RedirectValidator($this->repository);

        foreach (['/shop.php', '/cart.php', '/collecties/iets', '/product.php'] as $reserved) {
            $this->assertNotNull(
                $validator->routeOwner($reserved),
                $reserved . ' belongs to the Shop whether or not the Shop is running'
            );
        }
    }
}
