<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * THE MULTILINGUAL MODULE OFF AND ON, over real HTTP with the dispatcher in
 * front (Multilingual 2.0 phase 7, wave D).
 *
 * Two servers on the same code and the same database: one with
 * MODULE_MULTILINGUAL_ENABLED=true, one with false. Off, the website is its
 * default language and nothing else — unprefixed URLs, no /en/, no switch,
 * no hreflang, a single-language sitemap, no Accept-Language redirect. On,
 * the same page is back at the same English address with the same English
 * words: switching the module off wrote nothing and deleted nothing.
 */
final class MultilingualModuleHttpTest extends TestCase
{
    private const KEY = 'zz-multilingual-module';
    private const SLUG_EN = 'zz-multilingual-module-en';

    private static ?BuiltInServer $on = null;
    private static ?BuiltInServer $off = null;

    private ?int $pageId = null;

    public static function setUpBeforeClass(): void
    {
        self::$on = BuiltInServer::start(['MODULE_MULTILINGUAL_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
        self::$off = BuiltInServer::start(['MODULE_MULTILINGUAL_ENABLED' => 'false'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$on?->stop();
        self::$off?->stop();
        self::$on = null;
        self::$off = null;
    }

    protected function setUp(): void
    {
        if (self::$on === null || !self::$on->answers() || self::$off === null || !self::$off->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        SiteLanguages::clearCache();
        if (!in_array('en', array_map(static fn ($l): string => $l->code, SiteLanguages::all()), true) || SiteLanguages::defaultCode() !== 'nl') {
            $this->markTestSkipped('this test expects the test database to register nl (default) and en');
        }

        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Meertaligheidstest');
        PageLocalization::save($this->pageId, 'en', [PageTranslation::TITLE => 'Multilingual test'], self::SLUG_EN);
        PageContent::clearCache();
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM pages WHERE content_key = ?')->execute([self::KEY]);
        PageContent::clearCache();
        PageLocalization::clearCache();
    }

    public function testOffTheWebsiteIsItsDefaultLanguageOnly(): void
    {
        $home = self::$off->request('GET', '/');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('<html lang="nl"', $home['body']);
        self::assertStringNotContainsString('class="lang-switch"', $home['body'], 'no language switch');
        self::assertStringNotContainsString('hreflang=', $home['body'], 'no hreflang, and no x-default either');

        // /en/ is not a website language any more, and it answers 404 at
        // once: a permanent trailing-slash redirect to /en would be cached by
        // the browser and loop against /en -> /en/ once the module is back on.
        $root = self::$off->request('GET', '/en/');
        self::assertSame(404, $root['status'], '/en/ is not a website language any more');
        self::assertSame('', $root['location'], 'no redirect a browser could cache');
        self::assertSame(404, self::$off->request('GET', '/en')['status']);
        self::assertSame(404, self::$off->request('GET', '/en/' . self::SLUG_EN)['status']);

        $page = self::$off->request('GET', '/' . self::KEY);
        self::assertSame(200, $page['status'], 'the default language keeps its unprefixed address');
        self::assertStringContainsString('Meertaligheidstest', $page['body']);

        $sitemap = self::$off->request('GET', '/sitemap.xml');
        self::assertSame(200, $sitemap['status']);
        self::assertStringNotContainsString('/en/', $sitemap['body'], 'a single-language sitemap');
        self::assertStringNotContainsString('xhtml:link', $sitemap['body']);
    }

    /** A browser that prefers English is not sent to a language the site does not publish. */
    public function testOffNoBrowserIsNegotiatedIntoAnotherLanguage(): void
    {
        $home = self::$off->request('GET', '/', null, [], [], ['Accept-Language: en-GB,en;q=0.9']);

        self::assertSame(200, $home['status'], 'no redirect to /en/');
        self::assertSame('', $home['location']);
        self::assertStringContainsString('<html lang="nl"', $home['body']);
    }

    public function testOnTheSameEnglishPageIsBackAtTheSameAddress(): void
    {
        // Ask the OFF server first: it must have changed nothing.
        self::$off->request('GET', '/' . self::KEY);

        $english = self::$on->request('GET', '/en/' . self::SLUG_EN);
        self::assertSame(200, $english['status']);
        self::assertStringContainsString('<html lang="en"', $english['body']);
        self::assertStringContainsString('Multilingual test', $english['body'], 'the translation was kept');

        $dutch = self::$on->request('GET', '/' . self::KEY);
        self::assertStringContainsString('class="lang-switch"', $dutch['body']);
        self::assertStringContainsString('hreflang="en"', $dutch['body']);

        PageLocalization::clearCache();
        self::assertSame('Multilingual test', PageLocalization::raw((int) $this->pageId, PageTranslation::TITLE, 'en'));
    }

    /**
     * The blog index is one fixed route in every published language. On, it
     * names every version in its head, exactly as the Blog sitemap collector
     * lists it; off, it names none. Before phase 7 the index declared nothing,
     * so its head had no hreflang while the sitemap gave it alternates.
     */
    public function testTheBlogIndexNamesItsVersionsExactlyWhenTheModuleIsOn(): void
    {
        if (!\App\Module\ModuleRegistry::isEnabled('blog')) {
            self::markTestSkipped('the Blog module is off here');
        }

        $on = self::$on->request('GET', '/blog');
        self::assertSame(200, $on['status']);
        foreach (SiteLanguages::activeCodes() as $code) {
            self::assertMatchesRegularExpression(
                '#<link rel="alternate" hreflang="' . $code . '" href="[^"]*' . preg_quote(\App\Service\Blog\BlogUrls::indexPath(1, $code), '#') . '">#',
                $on['body'],
                'the index names its ' . $code . ' version'
            );
        }

        $off = self::$off->request('GET', '/blog');
        self::assertSame(200, $off['status']);
        self::assertStringNotContainsString('hreflang=', $off['body']);
    }

    /**
     * A language that is registered but switched off answers 404 on its own
     * home and below it, without a redirect: the same loop-free answer as
     * every language while the module is off.
     */
    public function testASwitchedOffLanguageAnswers404WithoutARedirect(): void
    {
        if (SiteLanguages::exists('zy')) {
            self::markTestSkipped('the test database already registers zy');
        }

        (new \App\Repository\SiteLanguageRepository())->create('zy', 'Test language', 'Testtaal', false);
        try {
            foreach (['/zy/', '/zy', '/zy/' . self::SLUG_EN] as $path) {
                $response = self::$on->request('GET', $path);
                self::assertSame(404, $response['status'], $path);
                self::assertSame('', $response['location'], $path . ': no redirect a browser could cache');
            }
        } finally {
            (new \App\Repository\SiteLanguageRepository())->delete('zy');
            SiteLanguages::clearCache();
        }
    }

    /** OFF -> ON -> OFF -> ON: every request answers the same way for the same state. */
    public function testSwitchingBackAndForthAnswersTheSameEachTime(): void
    {
        foreach ([self::$off, self::$on, self::$off, self::$on] as $index => $server) {
            $on = $index % 2 === 1;
            $response = $server->request('GET', '/en/' . self::SLUG_EN);

            self::assertSame($on ? 200 : 404, $response['status'], ($on ? 'on' : 'off') . ' #' . $index);
        }
    }
}
