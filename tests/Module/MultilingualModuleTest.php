<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\MultilingualModule;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\SiteLanguages;
use App\Service\Routing\LanguageResolver;
use App\Service\Routing\LanguageSwitch;
use App\Service\Routing\LocalizedUrl;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * THE MULTILINGUAL MODULE decides one thing: whether the website publishes
 * more than its default language (Multilingual 2.0 phase 7, wave D).
 *
 * Core never asks for it by key. App\Service\Language\SiteLanguages asks the
 * module registry by capability (ModuleDefinition::publishesTranslations()),
 * and every route, switch, editor and endpoint asks SiteLanguages — so
 * switching the module off narrows ALL of them to the default language at
 * once, and switching it on restores them, while the registry's own rows and
 * every translation stay exactly as they were.
 *
 * No database: the registry is replaced in memory, and the module state is
 * pinned through ModuleRegistry's own test seam.
 */
final class MultilingualModuleTest extends TestCase
{
    protected function setUp(): void
    {
        // The registry as storage holds it: Dutch default, English and German
        // switched on. Publishing is left to the module registry.
        SiteLanguages::overrideForTests([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ], null);
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        ContentEditingLanguage::overrideForTests(null);
        SiteLanguageFixture::reset();
    }

    public function testItIsARegisteredModuleThatANewInstallationStartsWithout(): void
    {
        $module = ModuleRegistry::definition('multilingual');

        self::assertInstanceOf(MultilingualModule::class, $module);
        self::assertFalse($module->enabledByDefault(), 'a new site publishes its default language until it asks for more; existing ones are pinned on by a migration');
        self::assertSame([], $module->dependencies());
        self::assertSame('MODULE_MULTILINGUAL_ENABLED', ModuleConfig::variableName('multilingual'));
    }

    public function testOnlyThisModulePublishesTranslations(): void
    {
        foreach (ModuleRegistry::all() as $key => $module) {
            self::assertSame($key === 'multilingual', $module->publishesTranslations(), $key);
        }
    }

    public function testOnThePublishedLanguagesAreTheRegistrysActiveRows(): void
    {
        $this->module(true);

        self::assertSame(['nl', 'en', 'de'], SiteLanguages::activeCodes());
        self::assertTrue(SiteLanguages::isActive('de'));
        self::assertTrue(LanguageSwitch::isAvailable());
        self::assertSame('/en/over-ons', LocalizedUrl::path('/over-ons', 'en'));

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
        try {
            self::assertSame('de', LanguageResolver::negotiate(), 'on, a browser asking for German is offered German');
        } finally {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }
    }

    /**
     * OFF: the default language only, for everything that asks — while the
     * registry's rows, their own flags and their order stay as stored.
     */
    public function testOffPublishesTheDefaultLanguageOnlyAndChangesNoRow(): void
    {
        $this->module(false);

        self::assertSame(['nl'], SiteLanguages::activeCodes());
        self::assertFalse(SiteLanguages::isActive('en'));
        self::assertFalse(SiteLanguages::isActive('de'));
        self::assertSame('nl', SiteLanguages::defaultCode(), 'the website still has its default language');

        self::assertFalse(LanguageSwitch::isAvailable(), 'no language switch');
        self::assertSame('/over-ons', LocalizedUrl::path('/over-ons', 'en'), 'no prefixed address for another language');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';
        try {
            self::assertSame('nl', LanguageResolver::negotiate(), 'no Accept-Language negotiation into another language');
        } finally {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }

        ContentEditingLanguage::overrideForTests(null);
        self::assertSame(['nl'], array_map(static fn ($language): string => $language->code, ContentEditingLanguage::choices()), 'editors write the default language only');

        self::assertSame(['nl', 'en', 'de'], array_map(static fn ($language): string => $language->code, SiteLanguages::all()), 'the registry keeps every language');
        self::assertTrue(SiteLanguages::find('en')?->isActive, 'and every language keeps its own flag');
    }

    /** OFF -> ON -> OFF -> ON: each state answers the same way every time. */
    public function testSwitchingBackAndForthIsLossless(): void
    {
        foreach ([false, true, false, true] as $on) {
            $this->module($on);

            self::assertSame($on ? ['nl', 'en', 'de'] : ['nl'], SiteLanguages::activeCodes());
            self::assertSame($on ? '/de/over-ons' : '/over-ons', LocalizedUrl::path('/over-ons', 'de'));
            self::assertSame(['nl', 'en', 'de'], array_map(static fn ($language): string => $language->code, SiteLanguages::all()));
        }
    }

    /** SiteLanguages is the only language-layer class that asks, and it asks by capability. */
    public function testTheLanguageLayerAsksByCapabilityNeverByKey(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Language/SiteLanguages.php');

        self::assertStringContainsString('ModuleRegistry::publishesTranslations()', $source);
        self::assertStringNotContainsString("'multilingual'", $source);
        self::assertStringNotContainsString('isEnabled(', $source);
    }

    private function module(bool $on): void
    {
        ModuleRegistry::overrideForTests(['multilingual' => $on]);
    }
}
