<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\FooterService;
use App\Service\HeaderCta;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;
use PHPUnit\Framework\TestCase;

/**
 * The shared header's call-to-action button, the footer's slogan and the
 * social profiles, as settings rather than markup.
 *
 * No database and no web server: App\Service\SiteSettings::overrideForTests()
 * stands in for the stored rows, and the two target kinds that need neither
 * (a registered route and an external URL) are exercised here. The CMS-PAGE
 * target needs a real `pages` row and lives in
 * Tests\Service\HeaderFooterRenderingTest with the rest of the integration.
 */
final class HeaderFooterSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    /** @param array<string, string> $settings */
    private function withSettings(array $settings): void
    {
        SiteSettings::overrideForTests($settings);
    }

    // ---------------------------------------------------------------- defaults

    public function testAFreshInstallGetsNoButtonNoSloganAndNoSocialProfiles(): void
    {
        $defaults = SiteSettings::defaults();

        $this->assertSame('0', $defaults['header_cta_enabled']);
        $this->assertSame('', $defaults['header_cta_label_nl']);
        $this->assertSame('', $defaults['header_cta_label_en']);
        $this->assertSame('0', $defaults['footer_slogan_enabled']);
        $this->assertSame('', $defaults['footer_slogan_nl']);

        foreach (SocialProfiles::settingKeys() as $key) {
            $this->assertSame('', $defaults[$key], $key . ' must be empty on a fresh install');
        }
    }

    public function testTheCodeDefaultsCarryNoCompanySpecificCopy(): void
    {
        $defaults = SiteSettings::defaults();

        $this->assertSame('', $defaults['header_cta_label_nl']);
        $this->assertSame('', $defaults['footer_slogan_nl']);
        $this->assertSame('', $defaults['footer_slogan_en']);
    }

    public function testEverySocialSettingKeyIsAKnownSetting(): void
    {
        foreach (SocialProfiles::settingKeys() as $key) {
            $this->assertArrayHasKey($key, SiteSettings::defaults());
        }
    }

    public function testTheDefaultsRenderNothingAtAll(): void
    {
        $this->withSettings([]);

        $this->assertNull(HeaderCta::forHeader());
        $this->assertNull(FooterService::slogan());
        $this->assertSame([], SocialProfiles::forFooter());
    }

    // -------------------------------------------------------------- header CTA

    /** @return array<string, string> */
    private function enabledCta(array $overrides = []): array
    {
        return array_merge([
            'header_cta_enabled' => '1',
            'header_cta_label_nl' => 'Vraag offerte aan',
            'header_cta_label_en' => 'Request a quote',
            'header_cta_link_type' => 'external',
            'header_cta_external_url' => '/contact.php',
        ], $overrides);
    }

    public function testAnEnabledButtonRenders(): void
    {
        $this->withSettings($this->enabledCta());

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertSame('Vraag offerte aan', $cta['label_nl']);
        $this->assertSame('Request a quote', $cta['label_en']);
        $this->assertSame('/contact.php', $cta['href']);
    }

    public function testADisabledButtonRendersNothing(): void
    {
        $this->withSettings($this->enabledCta(['header_cta_enabled' => '0']));

        $this->assertNull(HeaderCta::forHeader());
    }

    public function testAButtonWithoutADutchLabelRendersNothing(): void
    {
        $this->withSettings($this->enabledCta(['header_cta_label_nl' => '']));

        $this->assertNull(HeaderCta::forHeader());
    }

    public function testAnEmptyEnglishLabelFallsBackToTheDutchOne(): void
    {
        $this->withSettings($this->enabledCta(['header_cta_label_en' => '']));

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertSame('Vraag offerte aan', $cta['label_en']);
    }

    public function testARegisteredRouteTargetResolvesToItsUrl(): void
    {
        $this->withSettings($this->enabledCta([
            'header_cta_link_type' => 'route',
            'header_cta_target_route' => 'home',
            'header_cta_external_url' => '',
        ]));

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertSame('/index.php', $cta['href']);
    }

    public function testAnExternalTargetKeepsItsUrl(): void
    {
        $this->withSettings($this->enabledCta([
            'header_cta_external_url' => 'https://example.com/offerte',
        ]));

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertSame('https://example.com/offerte', $cta['href']);
    }

    public function testOpeningInANewTabAddsTheSafeRel(): void
    {
        $this->withSettings($this->enabledCta(['header_cta_open_in_new_tab' => '1']));

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertTrue($cta['open_in_new_tab']);
        $this->assertSame('noopener noreferrer', $cta['rel']);
    }

    public function testAButtonWithNoTargetAtAllRendersNothing(): void
    {
        $this->withSettings($this->enabledCta([
            'header_cta_link_type' => 'route',
            'header_cta_target_route' => '',
            'header_cta_external_url' => '',
        ]));

        $this->assertNull(HeaderCta::forHeader());
    }

    /**
     * The case the whole target model exists for: a button pointing at a
     * route the Shop owns, on a deployment where the Shop is switched off.
     * The route is not registered any more, so the button is not rendered —
     * and the SETTING is untouched, so switching the Shop back on brings it
     * back without anybody re-entering anything.
     */
    public function testAButtonPointingAtASwitchedOffModulesRouteIsNotRendered(): void
    {
        $settings = $this->enabledCta([
            'header_cta_link_type' => 'route',
            'header_cta_target_route' => 'shop',
            'header_cta_external_url' => '',
        ]);
        $this->withSettings($settings);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        $enabled = HeaderCta::forHeader();
        $this->assertNotNull($enabled, 'with the Shop on, the route target must resolve');
        $this->assertSame('/shop.php', $enabled['href']);

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);

        $this->assertNull(HeaderCta::forHeader());
        $this->assertSame('shop', SiteSettings::get('header_cta_target_route'), 'the setting must be preserved');
        $this->assertNotNull(HeaderCta::adminWarning(), 'the admin screen must be told why the button is gone');
    }

    public function testNoWarningWhileEverythingResolves(): void
    {
        $this->withSettings($this->enabledCta());

        $this->assertNull(HeaderCta::adminWarning());
    }

    public function testADisabledButtonIsNotWarnedAbout(): void
    {
        $this->withSettings($this->enabledCta([
            'header_cta_enabled' => '0',
            'header_cta_link_type' => 'route',
            'header_cta_target_route' => 'nonexistent',
            'header_cta_external_url' => '',
        ]));

        $this->assertNull(HeaderCta::adminWarning());
    }

    // ----------------------------------------------------------- footer slogan

    public function testAnEnabledSloganRenders(): void
    {
        $this->withSettings([
            'footer_slogan_enabled' => '1',
            'footer_slogan_nl' => 'Ontworpen & gebouwd met zorg in Nijmegen',
            'footer_slogan_en' => 'Designed & built with care in Nijmegen',
        ]);

        $this->assertSame(
            ['nl' => 'Ontworpen & gebouwd met zorg in Nijmegen', 'en' => 'Designed & built with care in Nijmegen'],
            FooterService::slogan()
        );
    }

    public function testADisabledSloganRendersNothing(): void
    {
        $this->withSettings([
            'footer_slogan_enabled' => '0',
            'footer_slogan_nl' => 'Ontworpen & gebouwd met zorg in Nijmegen',
        ]);

        $this->assertNull(FooterService::slogan());
    }

    public function testASloganWithoutDutchTextRendersNothing(): void
    {
        $this->withSettings(['footer_slogan_enabled' => '1', 'footer_slogan_nl' => '']);

        $this->assertNull(FooterService::slogan());
    }

    public function testAnEmptyEnglishSloganFallsBackToTheDutchOne(): void
    {
        $this->withSettings([
            'footer_slogan_enabled' => '1',
            'footer_slogan_nl' => 'Met zorg gemaakt',
            'footer_slogan_en' => '',
        ]);

        $this->assertSame(['nl' => 'Met zorg gemaakt', 'en' => 'Met zorg gemaakt'], FooterService::slogan());
    }

    // ---------------------------------------------------------------- social

    public function testAConfiguredProfileRendersWithItsAccessibleLabel(): void
    {
        $this->withSettings(['social_instagram_url' => 'https://www.instagram.com/vanveluwlaserdesign/']);

        $profiles = SocialProfiles::forFooter();

        $this->assertCount(1, $profiles);
        $this->assertSame('instagram', $profiles[0]['network']);
        $this->assertSame('Instagram', $profiles[0]['label']);
        $this->assertSame('https://www.instagram.com/vanveluwlaserdesign/', $profiles[0]['url']);
        $this->assertNotSame('', $profiles[0]['icon']);
    }

    public function testProfilesComeBackInRegistryOrderRatherThanStorageOrder(): void
    {
        $this->withSettings([
            'social_etsy_url' => 'https://www.etsy.com/shop/example',
            'social_instagram_url' => 'https://instagram.com/example',
            'social_linkedin_url' => 'https://nl.linkedin.com/in/example',
        ]);

        $this->assertSame(
            ['instagram', 'linkedin', 'etsy'],
            array_column(SocialProfiles::forFooter(), 'network')
        );
    }

    public function testAnEmptyUrlRendersNoProfile(): void
    {
        $this->withSettings(['social_facebook_url' => '', 'social_instagram_url' => '   ']);

        $this->assertSame([], SocialProfiles::forFooter());
    }

    /**
     * A value that somehow got into the settings table without going through
     * the admin endpoint still never reaches a page.
     */
    public function testAStoredInvalidUrlIsNotRendered(): void
    {
        $this->withSettings(['social_facebook_url' => 'javascript:alert(1)']);

        $this->assertSame([], SocialProfiles::forFooter());
    }

    /** @return list<array{0: string, 1: string}> */
    public static function rejectedUrls(): array
    {
        return [
            'javascript scheme' => ['facebook', 'javascript:alert(1)'],
            'data scheme' => ['instagram', 'data:text/html,<script>alert(1)</script>'],
            'plain http' => ['instagram', 'http://instagram.com/example'],
            'no scheme' => ['instagram', 'instagram.com/example'],
            'relative path' => ['instagram', '/instagram'],
            'markup' => ['instagram', '<a href="https://instagram.com/x">x</a>'],
            'another host entirely' => ['instagram', 'https://example.com/instagram'],
            'brand as a subdomain of somebody else' => ['facebook', 'https://facebook.evil.example/page'],
            'credentials in the url' => ['facebook', 'https://facebook.com@evil.example/'],
            'wrong network for the field' => ['linkedin', 'https://www.instagram.com/example/'],
        ];
    }

    /** @dataProvider rejectedUrls */
    public function testInvalidProfileUrlsAreRejected(string $network, string $url): void
    {
        $this->assertFalse(SocialProfiles::isValidProfileUrl($network, $url));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function acceptedUrls(): array
    {
        return [
            ['instagram', 'https://www.instagram.com/example/'],
            ['facebook', 'https://www.facebook.com/example'],
            ['facebook', 'https://fb.com/example'],
            ['linkedin', 'https://nl.linkedin.com/company/example'],
            ['pinterest', 'https://www.pinterest.de/example/'],
            ['pinterest', 'https://nl.pinterest.com/example/'],
            ['youtube', 'https://www.youtube.com/@example'],
            ['youtube', 'https://youtu.be/example'],
            ['tiktok', 'https://www.tiktok.com/@example'],
            ['etsy', 'https://www.etsy.com/nl/shop/example'],
        ];
    }

    /** @dataProvider acceptedUrls */
    public function testRealProfileUrlsAreAccepted(string $network, string $url): void
    {
        $this->assertTrue(SocialProfiles::isValidProfileUrl($network, $url));
    }

    /**
     * The registry is closed: a network name cannot arrive from a request,
     * a settings row or anywhere else.
     */
    public function testAnUnknownNetworkIsRefusedWhateverTheUrl(): void
    {
        $this->assertFalse(SocialProfiles::isKnownNetwork('myspace'));
        $this->assertFalse(SocialProfiles::isValidProfileUrl('myspace', 'https://myspace.com/example'));
        $this->assertNull(SocialProfiles::label('myspace'));
    }

    public function testEveryRegisteredNetworkHasALabelAndAnIcon(): void
    {
        $networks = SocialProfiles::networks();

        $this->assertNotSame([], $networks);

        foreach ($networks as $network => $definition) {
            $this->assertTrue(SocialProfiles::isKnownNetwork($network));
            $this->assertNotSame('', $definition['label']);
            $this->assertStringStartsWith('social_', $definition['key']);
        }
    }

    /**
     * The icons are ours: shapes and nothing else. No script, no external
     * reference, no styling that could carry a URL.
     */
    public function testTheIconsContainNothingButShapes(): void
    {
        $this->withSettings([
            'social_instagram_url' => 'https://instagram.com/x',
            'social_facebook_url' => 'https://facebook.com/x',
            'social_pinterest_url' => 'https://pinterest.com/x',
            'social_linkedin_url' => 'https://linkedin.com/x',
            'social_youtube_url' => 'https://youtube.com/x',
            'social_tiktok_url' => 'https://tiktok.com/x',
            'social_etsy_url' => 'https://etsy.com/x',
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
