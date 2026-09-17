<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\FooterService;
use App\Service\LocalizedSiteSettings;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The footer's slogan and the social profiles, as data rather than markup.
 *
 * No database and no web server: App\Service\SiteSettings::overrideForTests()
 * stands in for the stored settings, App\Service\LocalizedSiteSettings::
 * overrideForTests() for the slogan's words per language (Multilingual 2.0
 * phase 4) and
 * App\Service\SocialProfiles::overrideForTests() for the footer_social_links
 * rows (Footer phase B). What the table and the Footer screen do with real
 * rows is Tests\Repository\FooterSocialLinkRepositoryTest and
 * Tests\Service\FooterAdminHttpTest.
 *
 * The header's call-to-action button used to be tested here as well. Header
 * buttons are navigation items since Navigation phase A; every rule this file
 * held for the single button now holds per button in
 * Tests\Service\NavigationServiceTest (no database) and
 * Tests\Service\HeaderFooterRenderingTest (a CMS-page target). The legacy
 * header_cta_* keys are gone (db/migrations/20260918130000).
 */
final class HeaderFooterSettingsTest extends TestCase
{
    /** The seven settings the social profiles lived in until Footer phase B. */
    private const LEGACY_SOCIAL_KEYS = [
        'social_instagram_url',
        'social_facebook_url',
        'social_pinterest_url',
        'social_linkedin_url',
        'social_youtube_url',
        'social_tiktok_url',
        'social_etsy_url',
    ];

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        LocalizedSiteSettings::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
        LocalizedSiteSettings::overrideForTests(null);
        SiteSettings::overrideForTests(null);
        SocialProfiles::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    /** @param array<string, string> $settings */
    private function withSettings(array $settings): void
    {
        SiteSettings::overrideForTests($settings);
    }

    /** @param array<string, string> $words language code => the closing line */
    private function withSlogan(array $words): void
    {
        LocalizedSiteSettings::overrideForTests([LocalizedSiteSettings::FOOTER_SLOGAN => $words]);
    }

    /**
     * Visible footer_social_links rows, in order, as the repository returns
     * them.
     *
     * @param list<array{0: string, 1: string}> $profiles network, url
     */
    private function withProfiles(array $profiles): void
    {
        $rows = [];
        foreach ($profiles as $index => [$network, $url]) {
            $rows[] = ['id' => $index + 1, 'network' => $network, 'url' => $url, 'sort_order' => $index, 'is_visible' => 1];
        }

        SocialProfiles::overrideForTests($rows);
    }

    // ---------------------------------------------------------------- defaults

    public function testAFreshInstallGetsNoSloganAndNoSocialProfiles(): void
    {
        $defaults = SiteSettings::defaults();

        $this->assertArrayNotHasKey('header_cta_enabled', $defaults, 'the legacy header button settings are gone');
        $this->assertArrayNotHasKey('footer_slogan_nl', $defaults, 'the closing line is website text per language');
        $this->assertSame('0', $defaults['footer_slogan_enabled']);

        foreach (self::LEGACY_SOCIAL_KEYS as $key) {
            $this->assertSame('', $defaults[$key], $key . ' must be empty on a fresh install');
        }
    }

    /**
     * A localized setting has no code default at all: a fresh install has no
     * row, so no install inherits somebody's wording.
     */
    public function testTheCodeDefaultsCarryNoCompanySpecificCopy(): void
    {
        foreach (array_keys(LocalizedSiteSettings::KEYS) as $key) {
            $this->assertSame([], LocalizedSiteSettings::words($key), $key);
        }
        $this->assertNull(FooterService::description());
    }

    public function testTheDefaultsRenderNothingAtAll(): void
    {
        $this->withSettings([]);
        $this->withProfiles([]);

        $this->assertNull(FooterService::slogan());
        $this->assertSame([], SocialProfiles::forFooter());
    }

    /**
     * The seven legacy settings stay known (their rows remain in the
     * database), but a value in them no longer reaches the footer: the
     * profiles are footer_social_links rows now.
     */
    public function testALegacySocialSettingIsNoLongerRendered(): void
    {
        $this->withSettings(['social_instagram_url' => 'https://www.instagram.com/example/']);
        $this->withProfiles([]);

        foreach (self::LEGACY_SOCIAL_KEYS as $key) {
            $this->assertArrayHasKey($key, SiteSettings::defaults());
        }
        $this->assertSame([], SocialProfiles::forFooter());
    }

    // ----------------------------------------------------------- footer slogan

    public function testAnEnabledSloganRenders(): void
    {
        $this->withSettings(['footer_slogan_enabled' => '1']);
        $this->withSlogan(['nl' => 'Ontworpen & gebouwd met zorg in Nijmegen', 'en' => 'Designed & built with care in Nijmegen']);

        $this->assertSame(
            ['nl' => 'Ontworpen & gebouwd met zorg in Nijmegen', 'en' => 'Designed & built with care in Nijmegen'],
            FooterService::slogan()?->attributeValues()
        );
    }

    public function testADisabledSloganRendersNothing(): void
    {
        $this->withSettings(['footer_slogan_enabled' => '0']);
        $this->withSlogan(['nl' => 'Ontworpen & gebouwd met zorg in Nijmegen']);

        $this->assertNull(FooterService::slogan());
    }

    /** The default language decides whether the line exists, whichever language that is. */
    public function testASloganWithoutWordsInTheDefaultLanguageRendersNothing(): void
    {
        $this->withSettings(['footer_slogan_enabled' => '1']);
        $this->withSlogan(['en' => 'Made with care']);

        $this->assertNull(FooterService::slogan());

        SiteLanguageFixture::useBilingual('en');
        $this->assertSame(['nl' => 'Made with care', 'en' => 'Made with care'], FooterService::slogan()?->attributeValues());
    }

    public function testAnEmptyTranslationFallsBackToTheDefaultLanguage(): void
    {
        $this->withSettings(['footer_slogan_enabled' => '1']);
        $this->withSlogan(['nl' => 'Met zorg gemaakt']);

        $this->assertSame(['nl' => 'Met zorg gemaakt', 'en' => 'Met zorg gemaakt'], FooterService::slogan()?->attributeValues());
    }

    // ---------------------------------------------------------------- social

    public function testAConfiguredProfileRendersWithItsIcon(): void
    {
        $this->withProfiles([['instagram', 'https://www.instagram.com/vanveluwlaserdesign/']]);

        $profiles = SocialProfiles::forFooter();

        $this->assertCount(1, $profiles);
        $this->assertSame('instagram', $profiles[0]['network']);
        $this->assertSame('Instagram', $profiles[0]['label']);
        $this->assertSame('https://www.instagram.com/vanveluwlaserdesign/', $profiles[0]['url']);
        $this->assertNotSame('', $profiles[0]['icon']);
        $this->assertNull($profiles[0]['number'], 'one profile on a network needs no number');
    }

    /** The editor's order, not the registry's. */
    public function testProfilesComeBackInTheStoredOrder(): void
    {
        $this->withProfiles([
            ['etsy', 'https://www.etsy.com/shop/example'],
            ['instagram', 'https://instagram.com/example'],
            ['linkedin', 'https://nl.linkedin.com/in/example'],
        ]);

        $this->assertSame(
            ['etsy', 'instagram', 'linkedin'],
            array_column(SocialProfiles::forFooter(), 'network')
        );
    }

    /**
     * Two accounts on one network are both rendered, and numbered so a screen
     * reader can tell the two links apart; the other network stays unnumbered.
     */
    public function testTwoProfilesOnOneNetworkAreBothRenderedAndNumbered(): void
    {
        $this->withProfiles([
            ['instagram', 'https://www.instagram.com/winkel/'],
            ['facebook', 'https://www.facebook.com/winkel'],
            ['instagram', 'https://www.instagram.com/atelier/'],
        ]);

        $profiles = SocialProfiles::forFooter();

        $this->assertSame(
            [['instagram', 1], ['facebook', null], ['instagram', 2]],
            array_map(static fn (array $p): array => [$p['network'], $p['number']], $profiles)
        );
    }

    public function testAnEmptyUrlRendersNoProfile(): void
    {
        $this->withProfiles([['facebook', ''], ['instagram', '   ']]);

        $this->assertSame([], SocialProfiles::forFooter());
    }

    /**
     * A row that somehow got into the table without going through the admin
     * endpoint — or was copied from the looser check of before Footer
     * phase B — still never reaches a page. Nor does a network the registry
     * does not know. The numbering counts only what is rendered.
     */
    public function testAStoredInvalidRowIsNotRendered(): void
    {
        $this->withProfiles([
            ['facebook', 'javascript:alert(1)'],
            ['pinterest', 'https://pin.nl/'],
            ['myspace', 'https://myspace.com/example'],
            ['instagram', 'https://www.instagram.com/example/'],
        ]);

        $profiles = SocialProfiles::forFooter();

        $this->assertSame(['instagram'], array_column($profiles, 'network'));
        $this->assertNull($profiles[0]['number']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedUrls(): array
    {
        return [
            'javascript scheme' => ['facebook', 'javascript:alert(1)'],
            'data scheme' => ['instagram', 'data:text/html,<script>alert(1)</script>'],
            'plain http' => ['instagram', 'http://instagram.com/example'],
            'no scheme' => ['instagram', 'instagram.com/example'],
            'relative path' => ['instagram', '/instagram'],
            'protocol-relative' => ['instagram', '//instagram.com/example'],
            'markup' => ['instagram', '<a href="https://instagram.com/x">x</a>'],
            'another host entirely' => ['instagram', 'https://example.com/instagram'],
            'brand as a subdomain of somebody else' => ['facebook', 'https://facebook.evil.example/page'],
            'brand followed by somebody else\'s domain' => ['instagram', 'https://instagram.com.evil.com/x'],
            'brand inside a longer name' => ['instagram', 'https://instagram-login.com/x'],
            'credentials in the url' => ['facebook', 'https://facebook.com@evil.example/'],
            'wrong network for the field' => ['linkedin', 'https://www.instagram.com/example/'],
            'a space in the address' => ['instagram', 'https://www.instagram.com/my name'],
            'too long' => ['instagram', 'https://www.instagram.com/' . str_repeat('a', SocialProfiles::MAX_URL_LENGTH)],
            'empty' => ['instagram', ''],
            // Accepted before Footer phase B, because only the label before
            // the last dot was compared, and short names like "pin" or
            // "linked" matched anybody's domain.
            'regression: a short name on anybody\'s domain (pin.nl)' => ['pinterest', 'https://pin.nl/'],
            'regression: linked.com is not LinkedIn' => ['linkedin', 'https://linked.com/'],
            'regression: fb.org is not Facebook' => ['facebook', 'https://fb.org/'],
            'regression: the brand on a generic domain nobody checked' => ['facebook', 'https://facebook.xyz/'],
            'regression: the brand on a made-up top-level domain' => ['instagram', 'https://instagram.evil/'],
        ];
    }

    /** @dataProvider rejectedUrls */
    public function testInvalidProfileUrlsAreRejected(string $network, string $url): void
    {
        $this->assertFalse(SocialProfiles::isValidProfileUrl($network, $url));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function acceptedUrls(): array
    {
        return [
            'instagram' => ['instagram', 'https://www.instagram.com/example/'],
            'instagram short domain' => ['instagram', 'https://instagr.am/example'],
            'instagram with a query string' => ['instagram', 'https://www.instagram.com/example?igsh=abc123'],
            'upper case scheme and host' => ['instagram', 'HTTPS://WWW.INSTAGRAM.COM/example'],
            'surrounding whitespace' => ['instagram', '  https://www.instagram.com/example  '],
            'facebook' => ['facebook', 'https://www.facebook.com/example'],
            'facebook mobile subdomain' => ['facebook', 'https://m.facebook.com/example'],
            'facebook profile id' => ['facebook', 'https://www.facebook.com/profile.php?id=100064'],
            'facebook short domain' => ['facebook', 'https://fb.com/example'],
            'linkedin country subdomain' => ['linkedin', 'https://nl.linkedin.com/company/example'],
            'linkedin short domain' => ['linkedin', 'https://lnkd.in/abc'],
            'pinterest country domain' => ['pinterest', 'https://www.pinterest.de/example/'],
            'pinterest country subdomain' => ['pinterest', 'https://nl.pinterest.com/example/'],
            'pinterest short domain' => ['pinterest', 'https://pin.it/abc'],
            'youtube handle' => ['youtube', 'https://www.youtube.com/@example'],
            'youtube short domain' => ['youtube', 'https://youtu.be/example'],
            'tiktok' => ['tiktok', 'https://www.tiktok.com/@example'],
            'tiktok short subdomain' => ['tiktok', 'https://vm.tiktok.com/ZMabc/'],
            'etsy' => ['etsy', 'https://www.etsy.com/nl/shop/example'],
            // Refused before Footer phase B: on a two-part country domain the
            // label before the last dot is "co" or "com", not the network.
            'regression: pinterest.co.uk' => ['pinterest', 'https://www.pinterest.co.uk/example/'],
            'regression: pinterest.com.au' => ['pinterest', 'https://pinterest.com.au/example/'],
            'regression: pinterest.com.mx' => ['pinterest', 'https://www.pinterest.com.mx/example/'],
            // Refused before: PHP's URL filter only knows ASCII.
            'regression: letters with accents in the path' => ['facebook', 'https://www.facebook.com/café.bakker'],
            'regression: umlauts in a linkedin address' => ['linkedin', 'https://www.linkedin.com/in/jürgen-müller'],
        ];
    }

    /** @dataProvider acceptedUrls */
    public function testRealProfileUrlsAreAccepted(string $network, string $url): void
    {
        $this->assertTrue(SocialProfiles::isValidProfileUrl($network, $url));
    }

    /**
     * The registry is closed: a network name cannot arrive from a request,
     * a table row or anywhere else.
     */
    public function testAnUnknownNetworkIsRefusedWhateverTheUrl(): void
    {
        $this->assertFalse(SocialProfiles::isKnownNetwork('myspace'));
        $this->assertFalse(SocialProfiles::isValidProfileUrl('myspace', 'https://myspace.com/example'));
        $this->assertNull(SocialProfiles::label('myspace'));
    }

    public function testEveryRegisteredNetworkHasALabelAndAcceptsItsOwnDomain(): void
    {
        $networks = SocialProfiles::networks();

        $this->assertSame(['instagram', 'facebook', 'pinterest', 'linkedin', 'youtube', 'tiktok', 'etsy'], array_keys($networks));

        foreach ($networks as $network => $definition) {
            $this->assertTrue(SocialProfiles::isKnownNetwork($network));
            $this->assertNotSame('', $definition['label']);
            $this->assertArrayNotHasKey('icon', $definition, 'the screen never gets markup');
            $this->assertTrue(SocialProfiles::isValidProfileUrl($network, 'https://www.' . $network . '.com/example'));
        }
    }

    /**
     * The icons are ours: shapes and nothing else. No script, no external
     * reference, no styling that could carry a URL.
     */
    public function testTheIconsContainNothingButShapes(): void
    {
        $this->withProfiles([
            ['instagram', 'https://instagram.com/x'],
            ['facebook', 'https://facebook.com/x'],
            ['pinterest', 'https://pinterest.com/x'],
            ['linkedin', 'https://linkedin.com/x'],
            ['youtube', 'https://youtube.com/x'],
            ['tiktok', 'https://tiktok.com/x'],
            ['etsy', 'https://etsy.com/x'],
        ]);

        $profiles = SocialProfiles::forFooter();
        $this->assertCount(count(SocialProfiles::networks()), $profiles);

        foreach ($profiles as $profile) {
            foreach (['<script', 'javascript:', 'href', 'url(', 'xlink', '<image', '<foreignObject', 'on'] as $forbidden) {
                if ($forbidden === 'on') {
                    // Event handlers, not the letters "on" inside a path.
                    $this->assertSame(
                        0,
                        preg_match('/\son[a-z]+\s*=/i', $profile['icon']),
                        $profile['network'] . ' icon must carry no event handler'
                    );
                    continue;
                }

                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $profile['icon'],
                    $profile['network'] . ' icon must contain nothing but shapes'
                );
            }
        }
    }
}
