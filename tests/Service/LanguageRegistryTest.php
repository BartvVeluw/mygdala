<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The closed language registry and the site's content-language configuration.
 *
 * No database and no webserver: everything here reads SiteSettings through
 * its test seam, which is what keeps this file in the `fast` tier
 * (TESTING.md).
 */
final class LanguageRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
    }

    /** @param array<string, string> $settings */
    private function withSettings(array $settings): void
    {
        SiteSettings::overrideForTests($settings);
    }

    public function testRegistersDutchAndEnglish(): void
    {
        self::assertSame(['nl', 'en'], LanguageRegistry::codes());
        self::assertTrue(LanguageRegistry::has('nl'));
        self::assertTrue(LanguageRegistry::has('en'));
    }

    public function testAnUnknownLanguageIsSimplyUnknown(): void
    {
        // The whole point of a closed registry: a code from a request, a
        // settings row or a future version can only hit a key or miss it.
        self::assertFalse(LanguageRegistry::has('de'));
        self::assertNull(LanguageRegistry::get('de'));
        self::assertFalse(LanguageRegistry::has('nl; DROP TABLE pages'));
        self::assertFalse(LanguageRegistry::has(''));
    }

    public function testEveryDefinitionCarriesWhatTheCmsNeedsFromIt(): void
    {
        foreach (LanguageRegistry::all() as $code => $definition) {
            self::assertSame($code, $definition->code, 'the map key is the code');
            self::assertNotSame('', $definition->nativeLabel);
            self::assertNotSame('', $definition->dutchLabel);
            self::assertNotSame('', $definition->englishLabel);
        }
    }

    public function testFilterKeepsRegistryOrderAndDropsUnknownCodes(): void
    {
        self::assertSame(['nl', 'en'], LanguageRegistry::filter(['en', 'de', 'nl', 'en']));
        self::assertSame([], LanguageRegistry::filter(['de', 'fr']));
    }

    public function testLabelsFollowTheReadersLanguage(): void
    {
        self::assertSame('Engels', LanguageRegistry::label('en', 'nl'));
        self::assertSame('English', LanguageRegistry::label('en', 'en'));
        self::assertSame('Dutch', LanguageRegistry::label('nl', 'en'));
    }

    // ------------------------------------------------------------ the site

    public function testAFreshInstallPublishesBothLanguages(): void
    {
        // The product is bilingual. A site with no language rows at all is
        // still readable in Dutch and in English, and its editors can still
        // write both - there is no configuration that takes a language away.
        $this->withSettings([]);

        self::assertSame('nl', ContentLanguages::primary());
        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
        self::assertSame('en', ContentLanguages::secondary());
        self::assertTrue(ContentLanguages::isMultilingual());
    }

    public function testAStoredSingleLanguageRowNoLongerTakesEnglishAway(): void
    {
        // THE REGRESSION THIS STEP EXISTS FOR. A row saying "this site
        // publishes Dutch only" used to hide the public language switch and
        // every English field in the CMS. It is deprecated and no longer
        // read, and the row itself is left in the database untouched.
        $this->withSettings([
            'primary_content_language' => 'nl',
            'enabled_content_languages' => 'nl',
        ]);

        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
        self::assertTrue(ContentLanguages::isMultilingual());
        self::assertSame(['nl'], ContentLanguages::storedEnabled(), 'the stored row is preserved verbatim');
    }

    public function testASecondLanguageMakesTheSiteMultilingual(): void
    {
        $this->withSettings([
            'primary_content_language' => 'nl',
            'enabled_content_languages' => 'nl,en',
        ]);

        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
        self::assertSame('en', ContentLanguages::secondary());
        self::assertTrue(ContentLanguages::isMultilingual());
    }

    public function testEnglishMayBeThePrimaryLanguage(): void
    {
        $this->withSettings([
            'primary_content_language' => 'en',
            'enabled_content_languages' => 'en,nl',
        ]);

        self::assertSame('en', ContentLanguages::primary());
        self::assertSame(['en', 'nl'], ContentLanguages::enabled(), 'primary comes first');
        self::assertSame('nl', ContentLanguages::secondary());
    }

    public function testThePrimaryLanguageIsAlwaysEnabledWhateverTheRowSays(): void
    {
        // A site that publishes nothing is not a state this CMS can render,
        // and a settings row must not be able to create one.
        $this->withSettings([
            'primary_content_language' => 'en',
            'enabled_content_languages' => 'nl',
        ]);

        self::assertContains('en', ContentLanguages::enabled());
        self::assertSame('en', ContentLanguages::enabled()[0]);
    }

    public function testAnUnknownStoredLanguageFallsBackInsteadOfBreaking(): void
    {
        $this->withSettings([
            'primary_content_language' => 'de',
            'enabled_content_languages' => 'de,fr',
        ]);

        self::assertSame('nl', ContentLanguages::primary());
        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
        self::assertSame([], ContentLanguages::storedEnabled(), 'unknown codes are dropped, not stored');
    }

    public function testV1AcceptsAtMostOneSecondaryLanguage(): void
    {
        $this->withSettings([
            'primary_content_language' => 'nl',
            'enabled_content_languages' => 'nl,en',
        ]);

        self::assertLessThanOrEqual(ContentLanguages::MAX_ENABLED, count(ContentLanguages::enabled()));
    }

    // ------------------------------------------------------------ normalise

    public function testNormalisePutsThePrimaryFirstAndPublishesEveryLanguage(): void
    {
        $values = ContentLanguages::normalise('en');

        self::assertSame('en', $values[ContentLanguages::SETTING_PRIMARY]);
        self::assertSame('en,nl', $values[ContentLanguages::SETTING_ENABLED]);
    }

    public function testNormaliseAlwaysWritesTheFullSetWhateverItIsAsked(): void
    {
        // The deprecated row stays truthful for anything reading the database
        // directly, and an owner cannot store a configuration that takes a
        // language away from a visitor or an editor.
        self::assertSame('nl,en', ContentLanguages::normalise('nl')[ContentLanguages::SETTING_ENABLED]);
        self::assertSame('nl,en', ContentLanguages::normalise('nl', [])[ContentLanguages::SETTING_ENABLED]);
        self::assertSame('nl,en', ContentLanguages::normalise('nl', ['de'])[ContentLanguages::SETTING_ENABLED]);
    }

    public function testNormaliseCannotBeTalkedIntoAnUnsupportedPrimary(): void
    {
        $values = ContentLanguages::normalise('../../etc/passwd');

        self::assertSame('nl', $values[ContentLanguages::SETTING_PRIMARY]);
    }
}
