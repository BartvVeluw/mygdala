<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\ModuleSettings;
use PHPUnit\Framework\TestCase;

/**
 * The precedence chain behind "does this installation want module X":
 * the environment first, the preference stored in the CMS second, enabled
 * last.
 *
 * The stored half is new with the Setup Wizard, and the whole risk of adding
 * it is that it becomes a SECOND module-state system — one that can disagree
 * with .env, quietly overrule a deployment, or answer differently depending
 * on who asks. Everything below is about that risk: the environment always
 * wins, an absent row changes nothing, and dependencies are still resolved in
 * one place.
 *
 * No database and no web server: App\Module\ModuleSettings has the same
 * override seam SiteSettings and ThemeSettings have, so storage is simulated
 * rather than written.
 */
final class ModuleConfigurationTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        foreach (ModuleRegistry::keys() as $key) {
            $variable = ModuleConfig::variableName($key);
            $this->originalEnvironment[$variable] = $_ENV[$variable] ?? null;
            unset($_ENV[$variable]);
        }

        ModuleSettings::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $variable => $value) {
            if ($value === null) {
                unset($_ENV[$variable]);
            } else {
                $_ENV[$variable] = $value;
            }
        }

        ModuleSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* The precedence chain                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Step 3 of the chain: a module nobody has configured gets its OWN
     * default (ModuleDefinition::enabledByDefault()). For every module that
     * existed before that hook the answer is still "enabled", which is what
     * keeps a missing variable from ever taking the shop off the air; the
     * Blog and the Portfolio start off, and say so themselves.
     */
    public function testWithNothingConfiguredAtAllEveryModuleGetsItsOwnDefault(): void
    {
        foreach (ModuleRegistry::all() as $key => $module) {
            $this->assertSame(
                $module->enabledByDefault(),
                ModuleConfig::wants($key),
                $key . ' must fall back to its own default'
            );
        }

        $this->assertTrue(ModuleConfig::wants('shop'), 'the Shop still defaults to enabled');
    }

    public function testAStoredPreferenceDecidesWhenTheEnvironmentIsSilent(): void
    {
        ModuleSettings::overrideForTests(['shop' => false]);

        $this->assertFalse(ModuleConfig::wants('shop'));

        ModuleSettings::overrideForTests(['shop' => true]);

        $this->assertTrue(ModuleConfig::wants('shop'));
    }

    /**
     * The one rule that keeps every existing deployment behaving exactly as
     * it did: a hosting account that pins its modules in .env cannot have
     * them changed by anyone who is merely signed into the CMS, and the
     * php_cms container's MODULE_SHOP_ENABLED=false still decides.
     */
    public function testTheEnvironmentBeatsAStoredPreferenceInBothDirections(): void
    {
        ModuleSettings::overrideForTests(['shop' => true]);
        $_ENV['MODULE_SHOP_ENABLED'] = 'false';
        $this->assertFalse(ModuleConfig::wants('shop'), 'an environment "off" must beat a stored "on"');

        ModuleSettings::overrideForTests(['shop' => false]);
        $_ENV['MODULE_SHOP_ENABLED'] = 'true';
        $this->assertTrue(ModuleConfig::wants('shop'), 'an environment "on" must beat a stored "off"');
    }

    public function testAnEmptyEnvironmentValueIsNotAnAnswerAndHandsOverToStorage(): void
    {
        ModuleSettings::overrideForTests(['shop' => false]);
        $_ENV['MODULE_SHOP_ENABLED'] = '   ';

        $this->assertNull(ModuleConfig::environmentValue('shop'));
        $this->assertFalse(ModuleConfig::wants('shop'));
    }

    public function testWhetherTheEnvironmentPinsAModuleIsAnswerableOnItsOwn(): void
    {
        $this->assertFalse(ModuleConfig::isPinnedByEnvironment('shop'));

        $_ENV['MODULE_SHOP_ENABLED'] = 'false';

        $this->assertTrue(ModuleConfig::isPinnedByEnvironment('shop'));
        $this->assertFalse(ModuleConfig::environmentValue('shop'));
    }

    /* ------------------------------------------------------------------ */
    /* Storage stays a storage layer                                       */
    /* ------------------------------------------------------------------ */

    public function testAnUnregisteredKeyIsNeverStoredAndNeverRead(): void
    {
        ModuleSettings::overrideForTests(['shop' => false, 'not-a-module' => true]);

        $this->assertArrayNotHasKey('not-a-module', ModuleSettings::all());
        $this->assertNull(ModuleSettings::stored('not-a-module'));
    }

    public function testTheStorageKeyIsTheLowerCaseTwinOfTheEnvironmentVariable(): void
    {
        $this->assertSame('module_shop_enabled', ModuleSettings::settingKey('shop'));
        $this->assertSame('module_personalization_enabled', ModuleSettings::settingKey('personalization'));
        $this->assertSame('MODULE_SHOP_ENABLED', ModuleConfig::variableName('shop'));
    }

    public function testAnAbsentRowMeansNoOpinionRatherThanOff(): void
    {
        ModuleSettings::overrideForTests(['shop' => false]);

        $this->assertFalse(ModuleSettings::stored('shop'));
        $this->assertNull(
            ModuleSettings::stored('personalization'),
            'a module nobody has chosen must not read as "off"'
        );
        $this->assertTrue(ModuleConfig::wants('personalization'));
    }

    /* ------------------------------------------------------------------ */
    /* Dependencies are still resolved in exactly one place                */
    /* ------------------------------------------------------------------ */

    /**
     * Storage says what was ASKED FOR. What actually runs is still
     * ModuleRegistry's answer, dependencies included — a stored
     * "personalization on, shop off" must not produce a half-running module
     * any more than the same pair in .env does.
     */
    public function testAStoredPreferenceCannotRunPersonalisationWithoutTheShop(): void
    {
        ModuleSettings::overrideForTests(['shop' => false, 'personalization' => true]);
        ModuleRegistry::reset();

        $this->assertTrue(ModuleConfig::wants('personalization'), 'it was asked for');
        $this->assertFalse(ModuleRegistry::isEnabled('personalization'), 'but it must not run');
        $this->assertFalse(ModuleRegistry::isEnabled('shop'));
    }

    public function testAModuleDescriptionIsTheModulesOwnWordsRatherThanCores(): void
    {
        // The Setup Wizard offers modules by name and sentence. Both come
        // from the module, so Core never carries an `if` on a module key to
        // decide what a webshop is.
        foreach (ModuleRegistry::all() as $key => $module) {
            $this->assertNotSame('', $module->label(), $key . ' must have a name');
            $this->assertNotSame('', $module->description(), $key . ' must describe itself for the wizard');
        }
    }
}
