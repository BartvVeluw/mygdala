<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\PageRepository;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\Routing\LanguagePreference;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * THE ROUTING of Multilingual 2.0 phase 6, over real HTTP
 * (docs/multilingual/ROUTING.md).
 *
 * Everything here is a decision dispatcher.php makes about a whole request —
 * which language, which canonical spelling, which status code — and none of
 * it can be proven by calling a class: a redirect is a header, a 404 is a
 * status, and "the URL wins over the cookie" is only true if a cookie was
 * actually sent.
 *
 * The built-in server reads no `.htaccess`, so it is started with
 * tests/Support/dispatcher-router.php, which mirrors those rules. The
 * `.htaccess` file stays the production truth; this test says what the rules
 * have to ACHIEVE, and the router says how to achieve them without Apache.
 */
final class DispatcherRoutingTest extends TestCase
{
    private const PREFIX = 'zz-dispatcher';

    private static ?BuiltInServer $server = null;

    private PageRepository $pages;

    /** @var list<int> */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->pages = new PageRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->pages->delete($id);
        }
        $this->created = [];

        PageLocalization::clearCache();
        PageContent::clearCache();
        LanguagePreference::overrideForTests(null, false);
    }

    /**
     * @param array<string, string> $slugs language code => that language's address
     */
    private function page(string $suffix, array $slugs): void
    {
        $neutral = self::PREFIX . $suffix;

        $id = $this->pages->create([
            'content_key' => $neutral,
            'slug' => $neutral,
            'status' => PageContent::STATUS_PUBLISHED,
        ]);
        $this->created[] = $id;

        foreach ($slugs as $code => $slug) {
            PageLocalization::save($id, (string) $code, [PageTranslation::TITLE => 'ZZ ' . $code], $slug);
        }

        PageLocalization::clearCache();
        PageContent::clearCache();
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path, ?array $headers = null): array
    {
        return self::$server->request('GET', $path, null, [], [], $headers ?? []);
    }

    private function defaultLanguage(): string
    {
        return PageLocalization::defaultLanguage();
    }

    private function otherLanguage(): ?string
    {
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            if ($code !== $this->defaultLanguage()) {
                return $code;
            }
        }

        return null;
    }

    // --------------------------------------------------------- the contract

    public function testTheDefaultLanguageKeepsTheUnprefixedUrl(): void
    {
        $this->page('-plain', [$this->defaultLanguage() => self::PREFIX . '-plain']);

        $response = $this->get('/' . self::PREFIX . '-plain');

        self::assertSame(200, $response['status']);
    }

    public function testEveryOtherLanguageIsReachedBehindItsPrefix(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->page('-two', [
            $this->defaultLanguage() => self::PREFIX . '-two',
            $other => self::PREFIX . '-two-' . $other,
        ]);

        self::assertSame(200, $this->get('/' . self::PREFIX . '-two')['status']);
        self::assertSame(200, $this->get('/' . $other . '/' . self::PREFIX . '-two-' . $other)['status']);
    }

    public function testOneLanguagesAddressIsNotAnothersUrl(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->page('-strict', [
            $this->defaultLanguage() => self::PREFIX . '-strict',
            $other => self::PREFIX . '-strict-' . $other,
        ]);

        // The one rule the whole phase turns on: a prefixed URL may never
        // quietly serve another language's content.
        self::assertSame(404, $this->get('/' . $other . '/' . self::PREFIX . '-strict')['status']);
        self::assertSame(404, $this->get('/' . self::PREFIX . '-strict-' . $other)['status']);
    }

    public function testALanguageWithoutAnAddressHasNoUrl(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->page('-single', [$this->defaultLanguage() => self::PREFIX . '-single']);

        self::assertSame(404, $this->get('/' . $other . '/' . self::PREFIX . '-single')['status']);
    }

    // ------------------------------------------------------- normalization

    public function testTheDefaultLanguagesOwnPrefixIsNotACanonicalUrl(): void
    {
        $this->page('-nodup', [$this->defaultLanguage() => self::PREFIX . '-nodup']);

        $response = $this->get('/' . $this->defaultLanguage() . '/' . self::PREFIX . '-nodup');

        self::assertSame(301, $response['status'], 'one canonical spelling per route');
        self::assertSame('/' . self::PREFIX . '-nodup', $response['location']);
    }

    public function testAStrayTrailingSlashIsRemovedOnce(): void
    {
        $this->page('-slash', [$this->defaultLanguage() => self::PREFIX . '-slash']);

        $response = $this->get('/' . self::PREFIX . '-slash/');

        self::assertSame(301, $response['status']);
        self::assertSame('/' . self::PREFIX . '-slash', $response['location']);
    }

    public function testALanguageHomeKeepsItsTrailingSlash(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $response = $this->get('/' . $other);

        self::assertSame(301, $response['status']);
        self::assertSame('/' . $other . '/', $response['location']);
    }

    public function testAQueryStringSurvivesACanonicalRedirect(): void
    {
        $this->page('-query', [$this->defaultLanguage() => self::PREFIX . '-query']);

        $response = $this->get('/' . $this->defaultLanguage() . '/' . self::PREFIX . '-query?utm_source=zz&page=2');

        self::assertSame(301, $response['status']);
        self::assertSame('/' . self::PREFIX . '-query?utm_source=zz&page=2', $response['location']);
    }

    // ------------------------------------------------- unknown and inactive

    public function testAnUnknownLanguageIsNoLanguageAtAll(): void
    {
        // "zz" is not a registered language, so it is just a path segment —
        // and no route has that shape, so it is a plain 404.
        self::assertSame(404, $this->get('/zz/' . self::PREFIX . '-nothing')['status']);
    }

    public function testAnUnknownPathIsStillAnOrdinary404(): void
    {
        self::assertSame(404, $this->get('/' . self::PREFIX . '-never-existed')['status']);
    }

    // ------------------------------------------------------------- security

    public function testATechnicalNamespaceIsNeverRouted(): void
    {
        foreach (['/api/zz-nothing', '/admin/zz-nothing', '/assets/zz-nothing.css'] as $path) {
            $response = $this->get($path);

            /**
             * WHAT THIS PROVES is that the ROUTER never answers here.
             * Building the site's whole shell — navigation, footer, theme,
             * every query behind them — for a stray /assets/... request is a
             * page nobody reads, and every page this application renders
             * carries data-url-prefix.
             *
             * The STATUS is the web server's own business and differs between
             * them: Apache reaches ErrorDocument and 404.php answers terse
             * text, while PHP's built-in server falls back to the nearest
             * index.php, so /admin/zz-nothing becomes admin/index.php's
             * redirect to the login. Neither is this project's routing.
             */
            self::assertStringNotContainsString(
                'data-url-prefix',
                $response['body'],
                $path . ' must answer like the machine route it is, not with the site shell'
            );
        }
    }

    /**
     * A traversal attempt never reaches outside the document root.
     *
     * Note what is NOT asserted: that such a path 404s. Both Apache and PHP's
     * built-in server normalize "%2e%2e" to ".." and collapse it BEFORE any
     * of this project's code runs, so /en/%2e%2e simply becomes "/" and
     * answers with the homepage — which is a legitimate page, not an escape.
     * The same is true on the untouched baseline install, so it is the
     * server's behaviour rather than anything phase 6 introduced.
     *
     * What matters is that nothing outside the root is ever served, and that
     * App\Service\Routing\RequestPath refuses a ".." that does reach it —
     * Tests\Service\Routing\RequestPathTest owns that half.
     */
    public function testTraversalNeverLeavesTheDocumentRoot(): void
    {
        foreach (['/../etc/passwd', '/en/../../etc/passwd', '/en/..%2f..%2fetc%2fpasswd'] as $path) {
            $response = $this->get($path);

            self::assertNotSame(200, $response['status'], $path);
            self::assertStringNotContainsString('root:x:', $response['body'], $path);
        }
    }

    public function testAProtocolRelativePathIsNotAnOpenRedirect(): void
    {
        $response = $this->get('//evil.test/x');

        self::assertNotSame(301, $response['status']);
        self::assertNotSame(302, $response['status']);
        self::assertStringNotContainsString('evil.test', $response['location']);
    }

    // ------------------------------------------- negotiation at the site root

    public function testAStoredPreferenceMovesAVisitorOnlyAtTheSiteRoot(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->page('-cookie', [$this->defaultLanguage() => self::PREFIX . '-cookie']);

        $cookie = ['Cookie: ' . LanguagePreference::COOKIE_NAME . '=' . $other];

        $root = $this->get('/', $cookie);
        self::assertSame(302, $root['status'], 'the root asks once, temporarily');
        self::assertSame('/' . $other . '/', $root['location']);

        // Everywhere else the URL is the answer: /zz-dispatcher-cookie is the
        // default language's URL and says so in its canonical tag.
        $page = $this->get('/' . self::PREFIX . '-cookie', $cookie);
        self::assertSame(200, $page['status'], 'a canonical URL may not answer differently per visitor');
    }

    public function testAcceptLanguageIsAskedAtTheRootWhenNothingIsStored(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $response = $this->get('/', ['Accept-Language: ' . $other . ';q=0.9']);

        self::assertSame(302, $response['status']);
        self::assertSame('/' . $other . '/', $response['location']);
    }

    public function testTheRootSaysWhatItsAnswerDependedOn(): void
    {
        $response = $this->get('/');

        self::assertMatchesRegularExpression(
            '/^Vary:.*(Accept-Language|Cookie)/mi',
            $response['headers'],
            'a per-visitor answer has to name what it varied on'
        );
    }

    public function testAnExplicitUrlBeatsAStoredPreference(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->page('-explicit', [
            $this->defaultLanguage() => self::PREFIX . '-explicit',
            $other => self::PREFIX . '-explicit-' . $other,
        ]);

        // A link somebody sends you opens in the language it was written in.
        $response = $this->get(
            '/' . $other . '/' . self::PREFIX . '-explicit-' . $other,
            ['Cookie: ' . LanguagePreference::COOKIE_NAME . '=' . $this->defaultLanguage()]
        );

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('lang="' . $other . '"', $response['body']);
    }

    /**
     * REGRESSION, found in a real browser and not on paper.
     *
     * A visitor whose cookie says English clicks "NL" on /en/. That link used
     * to be a plain "/", and "/" is the one URL where the stored preference
     * DECIDES — so the request was read as "named no language" and answered
     * with a redirect straight back to /en/. The Dutch homepage could not be
     * reached through the language switch at all.
     *
     * The switch's link to the default language's home now says what it
     * means, and the dispatcher records the choice before it negotiates.
     */
    public function testChoosingTheDefaultLanguageOnAnotherLanguagesHomeActuallyGetsThere(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $default = $this->defaultLanguage();
        $cookie = ['Cookie: ' . LanguagePreference::COOKIE_NAME . '=' . $other];

        // 1. the link the switch prints on the other language's home
        $home = $this->get('/' . $other . '/', $cookie);
        self::assertSame(200, $home['status']);
        self::assertStringContainsString(
            'href="/?lang=' . $default . '" hreflang="' . $default . '"',
            $home['body'],
            'the one switch link whose URL cannot say which language was chosen says it in a parameter'
        );

        // 2. following it records the choice and answers with the CLEAN url
        $chosen = $this->get('/?lang=' . $default, $cookie);
        self::assertSame(302, $chosen['status']);
        self::assertSame('/', $chosen['location'], 'the parameter never reaches a page');
        self::assertMatchesRegularExpression(
            '/^Set-Cookie:\s*' . preg_quote(LanguagePreference::COOKIE_NAME, '/') . '=' . preg_quote($default, '/') . '\b/mi',
            $chosen['headers']
        );

        // 3. and with that preference the root stays where it is
        $root = $this->get('/', ['Cookie: ' . LanguagePreference::COOKIE_NAME . '=' . $default]);
        self::assertSame(200, $root['status'], 'no bounce back to /' . $other . '/');
    }

    public function testTheChoiceParameterOnlyEverNamesAnActiveLanguage(): void
    {
        foreach (['zz', '//evil.test', 'https://evil.test', '../admin', "nl\r\nLocation: https://evil.test"] as $value) {
            $response = $this->get('/?lang=' . rawurlencode($value));

            self::assertStringNotContainsString('evil.test', $response['location'], $value);
            self::assertStringNotContainsString('admin', $response['location'], $value);
            self::assertDoesNotMatchRegularExpression('/^Set-Cookie:\s*' . preg_quote(LanguagePreference::COOKIE_NAME, '/') . '=zz/mi', $response['headers']);
        }
    }

    public function testTheAlternatesStayCleanSoTheParameterNeverReachesACrawler(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $home = $this->get('/' . $other . '/');

        self::assertDoesNotMatchRegularExpression(
            '/rel="alternate"[^>]*href="[^"]*\?lang=/',
            $home['body'],
            'hreflang names the clean URL; only the switch link carries the choice'
        );
        self::assertDoesNotMatchRegularExpression('/rel="canonical"[^>]*\?lang=/', $home['body']);
    }

    public function testTheLanguageAVisitorReadsIsRemembered(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->page('-remember', [$other => self::PREFIX . '-remember-' . $other]);

        $response = $this->get('/' . $other . '/' . self::PREFIX . '-remember-' . $other);

        self::assertSame(200, $response['status']);
        self::assertMatchesRegularExpression(
            '/^Set-Cookie:\s*' . preg_quote(LanguagePreference::COOKIE_NAME, '/') . '=' . preg_quote($other, '/') . '\b/mi',
            $response['headers']
        );
    }
}
