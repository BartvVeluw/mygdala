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

    public function testAFreshInstallIsSingleLanguage(): void
    {
        // The default, and the reason a new site's editors see no duplicate
        // English fields at all.
        $this->withSettings([]);

        self::assertSame('nl', ContentLanguages::primary());
        self::assertSame(['nl'], ContentLanguages::enabled());
        self::assertNull(ContentLanguages::secondary());
        self::assertFalse(ContentLanguages::isMultilingual());
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
        self::assertSame(['nl'], ContentLanguages::enabled());
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

    public function testNormaliseForcesThePrimaryIntoTheEnabledList(): void
    {
        $values = ContentLanguages::normalise('en', []);

        self::assertSame('en', $values[ContentLanguages::SETTING_PRIMARY]);
        self::assertSame('en', $values[ContentLanguages::SETTING_ENABLED]);
    }

    public function testNormaliseKeepsAValidSecondLanguage(): void
    {
        $values = ContentLanguages::normalise('nl', ['en']);

        self::assertSame('nl,en', $values[ContentLanguages::SETTING_ENABLED]);
    }

    public function testNormaliseDropsAnUnknownLanguageRatherThanStoringIt(): void
    {
        $values = ContentLanguages::normalise('nl', ['de', 'en']);

        self::assertSame('nl,en', $values[ContentLanguages::SETTING_ENABLED]);
    }

    public function testNormaliseCannotBeTalkedIntoAnUnsupportedPrimary(): void
    {
        $values = ContentLanguages::normalise('../../etc/passwd', ['en']);

        self::assertSame('nl', $values[ContentLanguages::SETTING_PRIMARY]);
    }
}
