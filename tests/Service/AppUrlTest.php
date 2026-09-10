<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AppUrl;
use App\Service\SiteSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the site's public base URL comes from, in what order, and what it is
 * allowed to be.
 *
 * The order matters more than it looks. Canonical tags, og:url and
 * sitemap.xml are all built on this one value, so two sources that can
 * disagree would mean a site whose canonical domain depends on which reader
 * asked. There is one chain — APP_URL, then the CMS setting, then a
 * placeholder — and {@see AppUrl::source()} says out loud which step
 * answered, so an admin screen can show the owner where the value lives
 * instead of offering a field that something else overrules.
 *
 * The placeholder used to be this site's own domain, which is why a
 * brand-new installation published canonical links pointing at Van Veluw
 * Laserdesign. That is the regression the last group below defends.
 *
 * No database: SiteSettings' override seam stands in for the settings table
 * and $_ENV for the .env.
 */
final class AppUrlTest extends TestCase
{
    private ?string $originalAppUrl = null;

    protected function setUp(): void
    {
        $this->originalAppUrl = $_ENV['APP_URL'] ?? null;
        unset($_ENV['APP_URL']);
        SiteSettings::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);

        if ($this->originalAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->originalAppUrl;
        }
    }

    /* ------------------------------------------------------------------ */
    /* The precedence chain                                                */
    /* ------------------------------------------------------------------ */

    public function testTheEnvironmentVariableWins(): void
    {
        $_ENV['APP_URL'] = 'https://www.uit-de-omgeving.example';
        SiteSettings::overrideForTests(['canonical_base_url' => 'https://www.uit-de-cms.example']);

        $this->assertSame('https://www.uit-de-omgeving.example', AppUrl::base());
        $this->assertSame(AppUrl::SOURCE_ENVIRONMENT, AppUrl::source());
        $this->assertTrue(AppUrl::isPinnedByEnvironment());
    }

    public function testTheStoredSettingAnswersWhenTheEnvironmentIsSilent(): void
    {
        SiteSettings::overrideForTests(['canonical_base_url' => 'https://www.uit-de-cms.example']);

        $this->assertSame('https://www.uit-de-cms.example', AppUrl::base());
        $this->assertSame(AppUrl::SOURCE_SETTING, AppUrl::source());
        $this->assertFalse(AppUrl::isPinnedByEnvironment());
        $this->assertTrue(AppUrl::isConfigured());
    }

    public function testWithNeitherConfiguredTheAnswerIsAPlaceholderNobodyCanMistakeForRealSite(): void
    {
        $this->assertSame(AppUrl::SOURCE_FALLBACK, AppUrl::source());
        $this->assertFalse(AppUrl::isConfigured());

        $base = AppUrl::base();

        $this->assertStringNotContainsString(
            'vanveluwlaserdesign',
            $base,
            'an unconfigured installation must never publish another company\'s domain'
        );
        $this->assertMatchesRegularExpression('#^https?://#', $base);
    }

    public function testAnUnconfiguredInstallationStillProducesWellFormedAbsoluteUrls(): void
    {
        $this->assertSame(AppUrl::base() . '/contact', AppUrl::canonical('contact'));
        $this->assertSame(AppUrl::base() . '/contact', AppUrl::canonical('/contact'));
        $this->assertSame(AppUrl::base() . '/', AppUrl::canonical('/'));
    }

    /* ------------------------------------------------------------------ */
    /* What counts as a base URL                                           */
    /* ------------------------------------------------------------------ */

    public function testATrailingSlashAndSurroundingSpaceAreNormalisedAway(): void
    {
        $this->assertSame('https://www.voorbeeld.nl', AppUrl::normalizeBase('  https://www.voorbeeld.nl/  '));
    }

    public function testASubdirectoryInstallationKeepsItsPath(): void
    {
        $this->assertSame('https://www.voorbeeld.nl/site', AppUrl::normalizeBase('https://www.voorbeeld.nl/site'));

        SiteSettings::overrideForTests(['canonical_base_url' => 'https://www.voorbeeld.nl/site']);

        $this->assertSame('https://www.voorbeeld.nl/site/contact', AppUrl::canonical('contact'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function refusedBaseUrls(): array
    {
        return [
            [''],
            ['   '],
            ['www.voorbeeld.nl'],
            ['voorbeeld'],
            ['javascript:alert(1)'],
            ['ftp://www.voorbeeld.nl'],
            ['https://www.voorbeeld.nl?utm_source=x'],
            ['https://www.voorbeeld.nl#top'],
        ];
    }

    #[DataProvider('refusedBaseUrls')]
    public function testAnythingThatIsNotAnHttpBaseUrlIsRefused(string $candidate): void
    {
        $this->assertNull(AppUrl::normalizeBase($candidate), $candidate . ' must not be storable as a base URL');
    }

    public function testAMalformedStoredValueFallsThroughInsteadOfBreakingEveryCanonicalTag(): void
    {
        SiteSettings::overrideForTests(['canonical_base_url' => 'niet-eens-een-url']);

        $this->assertSame(AppUrl::SOURCE_FALLBACK, AppUrl::source());
        $this->assertMatchesRegularExpression('#^https?://#', AppUrl::base());
    }

    public function testAMalformedEnvironmentValueFallsThroughToTheStoredOne(): void
    {
        $_ENV['APP_URL'] = 'niet-eens-een-url';
        SiteSettings::overrideForTests(['canonical_base_url' => 'https://www.uit-de-cms.example']);

        $this->assertSame('https://www.uit-de-cms.example', AppUrl::base());
        $this->assertSame(AppUrl::SOURCE_SETTING, AppUrl::source());
    }

    public function testTheEnvironmentVariableIsNamedRatherThanSpelledOutInATemplate(): void
    {
        $this->assertSame('APP_URL', AppUrl::environmentVariableName());
        $this->assertSame('canonical_base_url', AppUrl::SETTING_KEY);
        $this->assertArrayHasKey(AppUrl::SETTING_KEY, SiteSettings::defaults());
    }
}
