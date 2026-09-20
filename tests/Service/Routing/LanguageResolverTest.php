<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\LanguagePreference;
use App\Service\Routing\LanguageResolver;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The resolver chain (docs/multilingual/ROUTING.md):
 *
 *   1. the URL  2. the stored preference  3. Accept-Language  4. the default
 *
 * The two rules worth breaking a build over are asserted first: the URL
 * always wins, and an UNPREFIXED URL is the default language rather than a
 * negotiated one — otherwise every canonical URL of the site would answer
 * differently per visitor.
 */
final class LanguageResolverTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
            SiteLanguageFixture::language('fr', isActive: false, sortOrder: 3),
        ]);

        LanguagePreference::overrideForTests(null);
        RequestLanguage::reset();
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
        LanguagePreference::overrideForTests(null, false);
        RequestLanguage::reset();
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    // ------------------------------------------------------- step 1: the URL

    public function testTheUrlDecides(): void
    {
        self::assertSame('de', LanguageResolver::forRequest('de'));
    }

    public function testTheUrlBeatsAStoredPreference(): void
    {
        LanguagePreference::overrideForTests('en');

        self::assertSame('de', LanguageResolver::forRequest('de'));
    }

    public function testTheUrlBeatsAcceptLanguage(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        self::assertSame('de', LanguageResolver::forRequest('de'));
    }

    public function testAnInactiveLanguageInTheUrlIsNoLanguage(): void
    {
        self::assertSame('nl', LanguageResolver::forRequest('fr'));
    }

    public function testAnUnregisteredLanguageInTheUrlIsNoLanguage(): void
    {
        self::assertSame('nl', LanguageResolver::forRequest('zz'));
    }

    // ------------------------------------- an unprefixed URL is NOT negotiated

    public function testAnUnprefixedUrlIsAlwaysTheDefaultLanguage(): void
    {
        LanguagePreference::overrideForTests('de');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';

        // /over-ons carries a canonical tag saying it is the Dutch version of
        // that page. It may not answer in German because of a cookie.
        self::assertSame('nl', LanguageResolver::forRequest(null));
    }

    // --------------------------------------------------- negotiate(): 2, 3, 4

    public function testAStoredPreferenceIsTheSecondStep(): void
    {
        LanguagePreference::overrideForTests('de');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        self::assertSame('de', LanguageResolver::negotiate());
    }

    public function testAStoredPreferenceForAnInactiveLanguageIsSkipped(): void
    {
        LanguagePreference::overrideForTests('fr');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        self::assertSame('en', LanguageResolver::negotiate());
    }

    public function testAcceptLanguageIsTheThirdStep(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';

        self::assertSame('de', LanguageResolver::negotiate());
    }

    public function testAcceptLanguageOnlyEverNamesAnActiveLanguage(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr,en;q=0.5';

        self::assertSame('en', LanguageResolver::negotiate());
    }

    public function testTheSiteDefaultIsTheLastStep(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es,it';

        self::assertSame('nl', LanguageResolver::negotiate());
    }

    public function testNobodySaidAnythingAtAll(): void
    {
        self::assertSame('nl', LanguageResolver::negotiate());
    }

    // ------------------------------------------------------- the default itself

    public function testTheDefaultFollowsTheRegistry(): void
    {
        self::assertSame('nl', LanguageResolver::defaultLanguage());
        self::assertTrue(LanguageResolver::isDefault('nl'));
        self::assertFalse(LanguageResolver::isDefault('en'));

        SiteLanguageFixture::useBilingual('en');

        self::assertSame('en', LanguageResolver::defaultLanguage());
        self::assertTrue(LanguageResolver::isDefault('en'));
        self::assertFalse(LanguageResolver::isDefault('nl'));
    }

    // ------------------------------------------------- what the request carries

    public function testTheRequestLanguageRemembersWhetherTheUrlNamedIt(): void
    {
        RequestLanguage::set('en', true);
        self::assertSame('en', RequestLanguage::current());
        self::assertTrue(RequestLanguage::isFromUrl());
        self::assertFalse(RequestLanguage::isDefault());

        RequestLanguage::set('nl', false);
        self::assertFalse(RequestLanguage::isFromUrl());
        self::assertTrue(RequestLanguage::isDefault());
    }

    public function testAnEntrypointNobodyPinnedResolvesTheDefaultLanguage(): void
    {
        LanguagePreference::overrideForTests('de');

        // /shop.php is a real file: Apache serves it without the dispatcher,
        // so nothing pinned a language — and it is still the Dutch URL.
        self::assertSame('nl', RequestLanguage::current());
        self::assertFalse(RequestLanguage::isFromUrl());
    }
}
