<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The CMS interface language, and the rule the whole feature hangs on:
 * changing it changes nothing about the website.
 *
 * The independence checks below are the ones worth staring at. They are why
 * "CMS in English, website in Dutch" is a supported configuration rather than
 * something that happens to work today.
 */
final class AdminLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminLocale::overrideForTests(null);
        AdminTranslator::clearCache();
        SiteSettings::overrideForTests(null);
    }

    public function testDutchIsTheDefaultForSomebodyWhoHasNotChosen(): void
    {
        self::assertSame('nl', AdminLocale::normalise(null));
        self::assertSame('nl', AdminLocale::normalise(''));
        self::assertSame('nl', AdminLocale::normalise('   '));
    }

    public function testAStoredPreferenceIsHonoured(): void
    {
        self::assertSame('en', AdminLocale::normalise('en'));
        self::assertSame('nl', AdminLocale::normalise('nl'));
    }

    public function testAnUnknownPreferenceFallsBackRatherThanBreakingTheCms(): void
    {
        // A row written by a newer version, or by hand. A CMS that cannot
        // read a preference must still render.
        self::assertSame('nl', AdminLocale::normalise('de'));
        self::assertSame('nl', AdminLocale::normalise('../messages/secret'));
    }

    public function testOnlyLanguagesWithACuratedInterfaceAreOffered(): void
    {
        foreach (AdminLocale::choices() as $code => $definition) {
            self::assertTrue($definition->availableAsAdminLocale, $code . ' is offered as a CMS language');
        }
    }

    // -------------------------------------------------- the independence

    public function testTheCmsLanguageDoesNotChangeTheWebsiteLanguage(): void
    {
        SiteSettings::overrideForTests([
            'primary_content_language' => 'nl',
            'enabled_content_languages' => 'nl',
        ]);

        AdminLocale::overrideForTests('en');

        self::assertSame('en', AdminLocale::current(), 'the CMS is in English');
        self::assertSame('nl', ContentLanguages::primary(), 'the website is still Dutch');
        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
    }

    public function testTheWebsiteLanguageDoesNotChangeTheCmsLanguage(): void
    {
        SiteSettings::overrideForTests([
            'primary_content_language' => 'en',
            'enabled_content_languages' => 'en',
        ]);

        AdminLocale::overrideForTests('nl');

        self::assertSame('en', ContentLanguages::primary(), 'the website is English');
        self::assertSame('nl', AdminLocale::current(), 'the CMS is still Dutch');
    }

    // -------------------------------------------------- the catalogs

    public function testTheInterfaceSpeaksTheChosenLanguage(): void
    {
        AdminLocale::overrideForTests('nl');
        self::assertSame('Opslaan', AdminTranslator::trans('common.save'));

        AdminLocale::overrideForTests('en');
        AdminTranslator::clearCache();
        self::assertSame('Save', AdminTranslator::trans('common.save'));
    }

    public function testEveryDutchKeyExistsInEveryOtherCatalog(): void
    {
        // Dutch is the reference catalog: it is the language this CMS was
        // written in, so a key it has and another does not is a gap somebody
        // will see on a screen. Catch it here instead.
        $reference = AdminTranslator::catalog(LanguageRegistry::DEFAULT_LANGUAGE);
        self::assertNotSame([], $reference, 'the Dutch catalog is not empty');

        foreach (array_keys(AdminLocale::choices()) as $code) {
            if ($code === LanguageRegistry::DEFAULT_LANGUAGE) {
                continue;
            }

            $missing = array_diff(array_keys($reference), array_keys(AdminTranslator::catalog($code)));
            self::assertSame([], $missing, 'the ' . $code . ' catalog is missing: ' . implode(', ', $missing));
        }
    }

    public function testNoCatalogInventsKeysTheReferenceDoesNotHave(): void
    {
        $reference = array_keys(AdminTranslator::catalog(LanguageRegistry::DEFAULT_LANGUAGE));

        foreach (array_keys(AdminLocale::choices()) as $code) {
            $extra = array_diff(array_keys(AdminTranslator::catalog($code)), $reference);
            self::assertSame([], $extra, 'the ' . $code . ' catalog has orphans: ' . implode(', ', $extra));
        }
    }

    public function testAMissingKeyRendersDutchRatherThanAnEmptyButton(): void
    {
        AdminLocale::overrideForTests('en');

        // A key no catalog has falls all the way through to itself rather
        // than to an empty string — visible, findable, never a blank control.
        self::assertSame('nothing.like.this', AdminTranslator::trans('nothing.like.this'));
    }

    public function testPlaceholdersAreSubstituted(): void
    {
        AdminLocale::overrideForTests('en');
        AdminTranslator::clearCache();

        self::assertSame(
            'Translate to Dutch',
            AdminTranslator::trans('translate.action', ['language' => 'Dutch']),
        );
    }

    public function testAnUnknownLocaleAsksForTheDefaultCatalog(): void
    {
        self::assertSame(
            AdminTranslator::catalog('nl'),
            AdminTranslator::catalog('de'),
        );
    }
}
