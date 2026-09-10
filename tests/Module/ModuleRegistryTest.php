<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\BlogModule;
use App\Module\ModuleConfig;
use App\Module\ModuleDefinition;
use App\Module\ModuleRegistry;
use App\Module\PersonalizationModule;
use App\Module\ShopModule;
use PHPUnit\Framework\TestCase;

/**
 * The registry itself: what is registered, how a module is switched off, and
 * the one dependency rule this application has.
 *
 * No database and no web server — the registry is a list and a resolution
 * rule, and both must be testable without either.
 */
final class ModuleRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* The registry contract                                               */
    /* ------------------------------------------------------------------ */

    public function testEveryRegisteredModuleIsAValidDefinitionWithAUniqueKey(): void
    {
        $keys = ModuleRegistry::keys();

        $this->assertNotSame([], $keys, 'the application registers at least one module');
        $this->assertSame(array_unique($keys), $keys, 'two modules may not share a key');

        foreach ($keys as $key) {
            $definition = ModuleRegistry::definition($key);

            $this->assertInstanceOf(ModuleDefinition::class, $definition, $key);
            $this->assertSame($key, $definition->key(), 'a module must be registered under its own key()');
            $this->assertNotSame('', trim($definition->label()), $key . ' needs an admin-facing label');
        }
    }

    public function testTheRegisteredModulesAreShopPersonalizationAndBlog(): void
    {
        $this->assertSame(['shop', 'personalization', 'blog'], ModuleRegistry::keys());
        $this->assertInstanceOf(ShopModule::class, ModuleRegistry::definition('shop'));
        $this->assertInstanceOf(PersonalizationModule::class, ModuleRegistry::definition('personalization'));
        $this->assertInstanceOf(BlogModule::class, ModuleRegistry::definition('blog'));
    }

    public function testAnUnregisteredKeyIsAMissAndNeverAClassName(): void
    {
        $this->assertFalse(ModuleRegistry::has('__nope__'));
        $this->assertNull(ModuleRegistry::definition('__nope__'));
        $this->assertFalse(ModuleRegistry::isEnabled('__nope__'));
    }

    /**
     * Registration is a written list, not something discovered at runtime —
     * the same rule App\Service\Blocks\BlockDefinitions follows, and for the
     * same reason: a module key arrives from configuration, and it must only
     * ever hit or miss a key of that list.
     */
    public function testRegistrationIsExplicitAndClosed(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Module/ModuleRegistry.php');

        foreach (['glob(', 'scandir(', 'ReflectionClass', 'get_declared_classes', '$_GET', '$_POST', 'composer'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'module registration must stay an explicit, closed list'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Every module runs by default                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The Van Veluw Laserdesign deployment configures nothing, so an absent
     * variable has to mean "on". Anything else would take the shop offline
     * the first time somebody deployed without reading a document.
     */
    public function testAModuleIsEnabledUnlessSomethingExplicitlySaysOtherwise(): void
    {
        $key = 'MODULE_SHOP_ENABLED';
        $before = $_ENV[$key] ?? null;

        try {
            foreach (['', 'true', '1', 'on', 'yes', 'nonsense'] as $value) {
                $_ENV[$key] = $value;
                $this->assertTrue(ModuleConfig::wants('shop'), 'value ' . var_export($value, true) . ' must mean enabled');
            }

            foreach (['false', '0', 'off', 'no', 'disabled', 'FALSE', ' Off '] as $value) {
                $_ENV[$key] = $value;
                $this->assertFalse(ModuleConfig::wants('shop'), 'value ' . var_export($value, true) . ' must mean disabled');
            }

            unset($_ENV[$key]);
            $this->assertTrue(ModuleConfig::wants('shop'), 'an unset variable must mean enabled');
        } finally {
            if ($before === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $before;
            }
        }
    }

    public function testTheEnvironmentVariableIsNamedAfterTheModule(): void
    {
        $this->assertSame('MODULE_SHOP_ENABLED', ModuleConfig::variableName('shop'));
        $this->assertSame('MODULE_PERSONALIZATION_ENABLED', ModuleConfig::variableName('personalization'));
    }

    /* ------------------------------------------------------------------ */
    /* The dependency contract                                             */
    /* ------------------------------------------------------------------ */

    public function testPersonalizationDeclaresItsDependencyOnTheShop(): void
    {
        $this->assertSame(['shop'], ModuleRegistry::definition('personalization')->dependencies());
        $this->assertSame([], ModuleRegistry::definition('shop')->dependencies());
    }

    /**
     * The contract this step exists to make true: Personalisatie cannot be
     * effectively enabled while the Shop is off, whatever the configuration
     * asks for. Running it half-on — an admin section configuring products
     * that are not there, a checkout writing personalization rows nothing
     * reads — is the failure this rule prevents.
     */
    public function testPersonalizationCannotBeEnabledWithoutTheShop(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => true]);

        $this->assertFalse(ModuleRegistry::isEnabled('shop'));
        $this->assertFalse(
            ModuleRegistry::isEnabled('personalization'),
            'personalization must switch itself off when the shop it depends on is off'
        );
        $this->assertSame([], array_keys(ModuleRegistry::enabled()));
    }

    public function testTheShopRunsPerfectlyWellWithoutPersonalization(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => false]);

        $this->assertTrue(ModuleRegistry::isEnabled('shop'));
        $this->assertFalse(ModuleRegistry::isEnabled('personalization'));
        $this->assertSame(['shop'], array_keys(ModuleRegistry::enabled()));
    }

    public function testEveryDependencyNamesARegisteredModule(): void
    {
        foreach (ModuleRegistry::all() as $key => $module) {
            foreach ($module->dependencies() as $dependency) {
                $this->assertTrue(
                    ModuleRegistry::has($dependency),
                    $key . ' depends on "' . $dependency . '", which is not registered'
                );
                $this->assertNotSame($key, $dependency, 'a module cannot depend on itself');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Contributions                                                       */
    /* ------------------------------------------------------------------ */

    public function testADisabledModuleContributesNothing(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);

        foreach (
            [
                'adminNavigationItems', 'permissionGroups', 'dashboardCards',
                'shellStyles', 'shellScripts', 'headerPartials', 'dashboardPanels',
            ] as $hook
        ) {
            $this->assertSame([], ModuleRegistry::collect($hook), $hook . ' must be empty with every module off');
        }

        foreach (['routes', 'sitemapCollectors', 'blockDefinitions', 'itemGallerySources'] as $hook) {
            $this->assertSame([], ModuleRegistry::collectMap($hook), $hook . ' must be empty with every module off');
        }
    }

    /**
     * Ownership survives being switched off, which is how Core tells "this
     * belongs to a module that is not running" apart from "this does not
     * exist".
     */
    public function testOwnershipIsKnownEvenWhileTheModuleIsOff(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);

        $this->assertSame('shop', ModuleRegistry::ownerOf('blockDefinitions', 'product_grid'));
        $this->assertSame('shop', ModuleRegistry::ownerOf('itemGallerySources', 'collection'));
        $this->assertNull(ModuleRegistry::ownerOf('blockDefinitions', 'rich_text'));
        $this->assertNull(ModuleRegistry::ownerOf('blockDefinitions', '__nope__'));
    }

    /** Every partial a module contributes has to be a file that is really there. */
    public function testContributedPartialsExist(): void
    {
        foreach (ModuleRegistry::all() as $key => $module) {
            foreach ([...$module->headerPartials(), ...$module->dashboardPanels()] as $partial) {
                $this->assertFileExists($partial, $key . ' contributes a partial that does not exist');
            }
        }
    }

    /** And every asset it puts in the site shell is a real file under assets/. */
    public function testContributedShellAssetsExist(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (ModuleRegistry::all() as $key => $module) {
            foreach ([...$module->shellStyles(), ...$module->shellScripts()] as $asset) {
                $this->assertStringStartsWith('assets/', $asset, $key);
                $this->assertFileExists($root . '/' . $asset, $key . ' contributes an asset that does not exist');
            }
        }
    }

    /** Two modules must not both claim the same contribution key. */
    public function testNoTwoModulesClaimTheSameContributionKey(): void
    {
        foreach (['routes', 'blockDefinitions', 'itemGallerySources', 'sitemapCollectors'] as $hook) {
            $seen = [];

            foreach (ModuleRegistry::all() as $key => $module) {
                foreach (array_keys($module->{$hook}()) as $name) {
                    $this->assertArrayNotHasKey(
                        $name,
                        $seen,
                        sprintf('"%s" is contributed to %s() by both %s and %s', $name, $hook, $seen[$name] ?? '?', $key)
                    );
                    $seen[$name] = $key;
                }
            }
        }
    }
}
