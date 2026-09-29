<?php

declare(strict_types=1);

namespace Tests\Service\Search;

use App\Module\ModuleRegistry;
use App\Service\PageAssets;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\ReservedPaths;
use App\Service\Routing\RouteResolver;
use App\Service\Search\SearchService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * Search in the navigation (SEARCH.md): OFF until an administrator switches
 * it on, so an update changes no existing header; then a magnifier that is
 * a plain link without JavaScript, a real role="search" form, a results
 * route per language, and nothing unescaped. No database: the setting, the
 * language registry and the module set are overridden.
 */
final class SearchNavigationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'portfolio' => false, 'blog' => false, 'multilingual' => true]);
        PageAssets::reset();
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        SiteLanguageFixture::reset();
        ModuleRegistry::overrideForTests(null);
        RequestLanguage::reset();
        PageAssets::reset();
    }

    private static function source(string $path): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $path);
    }

    private static function switchSearch(bool $on): void
    {
        SiteSettings::overrideForTests([SearchService::SETTING => $on ? '1' : '0']);
    }

    private static function renderControl(): string
    {
        ob_start();
        require self::ROOT . '/partials/header-search.php';

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------ the switch

    public function testSearchIsOffByDefault(): void
    {
        self::assertSame('0', SiteSettings::defaults()[SearchService::SETTING]);
        SiteSettings::overrideForTests([]);
        self::assertFalse(SearchService::isEnabled());
        self::assertFileDoesNotExist(self::ROOT . '/db/migrations/' . SearchService::SETTING . '.php');
    }

    public function testOffTheHeaderAndTheShellAreUnchanged(): void
    {
        self::switchSearch(false);

        self::assertSame('', trim(self::renderControl()), 'no control, no empty wrapper');
        $assets = PageAssets::collected();
        self::assertNotContains(SearchService::STYLE, $assets['styles']);
        self::assertNotContains(SearchService::SCRIPT, $assets['scripts']);
    }

    public function testOnTheShellLoadsTheControlsFiles(): void
    {
        self::switchSearch(true);

        $assets = PageAssets::collected();
        self::assertSame('assets/css/core.css', $assets['styles'][0], 'the shell still goes first');
        self::assertContains(SearchService::STYLE, $assets['styles']);
        self::assertContains(SearchService::SCRIPT, $assets['scripts']);
        self::assertFileExists(self::ROOT . '/' . SearchService::STYLE);
        self::assertFileExists(self::ROOT . '/' . SearchService::SCRIPT);
    }

    // ------------------------------------------------------------ the control

    public function testOnTheControlIsALinkAndARealSearchForm(): void
    {
        self::switchSearch(true);
        RequestLanguage::set('nl', false);

        $html = self::renderControl();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $xpath = new \DOMXPath($document);

        $toggle = $xpath->query('//a[@data-site-search-toggle]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $toggle);
        self::assertSame('/zoeken', $toggle->getAttribute('href'), 'without JavaScript the magnifier goes to the results page');
        self::assertSame('Zoeken', $toggle->getAttribute('aria-label'));
        self::assertSame('site-search-panel', $toggle->getAttribute('aria-controls'));
        self::assertSame('true', $xpath->query('//a[@data-site-search-toggle]/svg')->item(0)?->getAttribute('aria-hidden'), 'the icon is decoration');

        $form = $xpath->query('//form[@role="search"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame('/zoeken', $form->getAttribute('action'));
        self::assertSame('get', $form->getAttribute('method'), 'Enter goes to a shareable URL');

        $input = $xpath->query('//input[@type="search"][@name="q"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);
        self::assertSame('100', $input->getAttribute('maxlength'));
        self::assertSame(1, $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length, 'the field has a label');
        self::assertSame(1, $xpath->query('//*[@role="status"][@aria-live="polite"]')->length, 'the number of results is announced');
        self::assertSame('Zoeken sluiten', $xpath->query('//button[@data-site-search-close]')->item(0)?->getAttribute('aria-label'));
        self::assertSame('/api/search.php', $xpath->query('//*[@data-site-search]')->item(0)?->getAttribute('data-search-endpoint'));
    }

    public function testTheControlSpeaksThePagesLanguage(): void
    {
        self::switchSearch(true);
        RequestLanguage::set('en', true);

        $html = self::renderControl();
        self::assertStringContainsString('href="/en/search"', $html);
        self::assertStringContainsString('action="/en/search"', $html);
        self::assertStringContainsString('aria-label="Search"', $html);
        self::assertStringContainsString('data-search-lang="en"', $html);
        self::assertStringContainsString('View all results', $html);
        self::assertStringNotContainsString('Zoeken', $html);
    }

    public function testTheHeaderAsksForTheControlFirstInItsActions(): void
    {
        $header = self::source('partials/header.php');
        $actions = strpos($header, '<div class="header-actions">');
        self::assertNotFalse($actions);
        self::assertLessThan(strpos($header, 'LanguageSwitch::isAvailable()'), strpos($header, "require __DIR__ . '/header-search.php';", $actions));
    }

    // ------------------------------------------------------------ the route

    public function testTheResultsPageHasAWordPerLanguage(): void
    {
        self::assertSame('core.search', RouteResolver::resolve(['zoeken'], 'nl')?->key);
        self::assertSame('zoeken.php', RouteResolver::resolve(['zoeken'], 'nl')?->template);
        self::assertSame('core.search', RouteResolver::resolve(['search'], 'en')?->key);
        self::assertSame('/zoeken', SearchService::path('nl'));
        self::assertSame('/en/search', SearchService::path('en'));
        self::assertTrue(ReservedPaths::isReserved('zoeken'), 'no page can take the word');
        self::assertTrue(ReservedPaths::isReserved('search'));
    }

    public function testTheResultsPageAndTheLiveListAnswer404WhileSearchIsOff(): void
    {
        foreach (['zoeken.php', 'api/search.php'] as $file) {
            $source = self::source($file);
            $guard = strpos($source, 'if (!SearchService::isEnabled()) {');
            self::assertNotFalse($guard, $file);
            self::assertLessThan(strpos($source, 'SearchService::search('), $guard, $file . ' refuses before it searches');
            self::assertStringContainsString('http_response_code(404);', substr($source, $guard, 200), $file);
        }
    }

    public function testTheResultsPageEscapesTheQueryAndIsNotIndexed(): void
    {
        $page = self::source('zoeken.php');

        self::assertStringContainsString('value="<?= $h($query->text) ?>"', $page);
        self::assertStringContainsString('indexable: false', $page);
        self::assertStringContainsString('canonical: LocalizedUrl::absolute($searchPath)', $page, 'canonical to the bare route, never to a query');
        self::assertStringContainsString('SearchQuery::fromInput($_GET[\'q\'] ?? null)', $page);
        self::assertStringNotContainsString('$_GET[\'q\']) ?>', $page, 'the raw query is never printed');
        self::assertStringContainsString('<?= $h($hit->title) ?>', $page);
        self::assertStringContainsString('<?= $h($hit->excerpt) ?>', $page);
        self::assertStringContainsString('<?= $h($hit->url) ?>', $page);
        self::assertStringContainsString('rel="next"', $page, 'a pager for many results');
    }

    public function testTheLiveListIsPlainJsonForAKnownLanguage(): void
    {
        $api = self::source('api/search.php');

        self::assertStringContainsString("ApiLanguage::apply(\$_GET['lang'] ?? null)", $api);
        self::assertStringContainsString('SearchService::LIVE_LIMIT', $api);
        self::assertStringContainsString('JSON_HEX_TAG', $api);
        self::assertStringContainsString("header('X-Robots-Tag: noindex');", $api);
        self::assertStringNotContainsString('PageViewTracker::', $api, 'a search term is not tracked');
    }

    // ------------------------------------------------------------ the script

    public function testTheScriptBuildsResultsAsTextAndOnlyLinksThisSite(): void
    {
        $script = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', self::source(SearchService::SCRIPT));

        self::assertStringNotContainsString('innerHTML', $script);
        self::assertStringNotContainsString('insertAdjacentHTML', $script);
        self::assertStringContainsString('span.textContent = part[1];', $script);
        self::assertStringContainsString('function isSiteUrl(url)', $script);
        self::assertStringContainsString('data.results.filter(function (hit) { return hit && isSiteUrl(hit.url); })', $script);
        self::assertStringContainsString('if (isSiteUrl(hit.thumbnail))', $script);
    }

    public function testTheScriptIsADisclosureThatEscapeClosesBackToTheButton(): void
    {
        $script = self::source(SearchService::SCRIPT);

        self::assertStringContainsString('toggle.setAttribute("aria-expanded", "false");', $script);
        self::assertStringContainsString('toggle.setAttribute("aria-expanded", "true");', $script);
        self::assertMatchesRegularExpression('/event\.key === "Escape" && isOpen\(\)\) \{\s*event\.stopPropagation\(\);\s*close\(true\);/', $script);
        self::assertMatchesRegularExpression('/if \(returnFocus\) toggle\.focus\(\);/', $script);
        self::assertStringContainsString('if (mine !== sequence) return;', $script, 'an older answer never overwrites a newer one');
        self::assertStringContainsString('var DEBOUNCE = ', $script, 'one request per pause in typing');
        self::assertStringNotContainsString('focus-trap', $script);
    }

    public function testThePhoneMenuShowsTheFieldItself(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::source(SearchService::STYLE));

        self::assertMatchesRegularExpression('/@media \(max-width: 900px\)\{[^@]*\.main-nav \.site-search__toggle,\s*\.main-nav \.site-search__close\{ display: none; \}/', $css);
        self::assertMatchesRegularExpression('/\.main-nav \.site-search__panel\{\s*display: block;\s*position: static;/', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        self::assertMatchesRegularExpression('/\.site-search__input::-webkit-search-cancel-button/', $css, 'no second, browser-native icon');
    }

    // ------------------------------------------------------------ the CMS switch

    public function testNavigationHasOneSwitchWrittenOnEverySave(): void
    {
        $screen = self::source('admin/navigation.php');
        self::assertStringContainsString('action="/api/admin/update-navigation-settings.php"', $screen);
        self::assertStringContainsString('name="<?= $h(\App\Service\Search\SearchService::SETTING) ?>"', $screen);
        self::assertStringContainsString("admin_te('navigation.search_toggle')", $screen);

        $endpoint = self::source('api/admin/update-navigation-settings.php');
        $order = array_map(static fn (string $needle): int|false => strpos($endpoint, $needle), [
            'AdminAuth::requireLoginForApi();',
            "AdminAuth::requirePermissionForApi('pages.manage');",
            "\$_SERVER['REQUEST_METHOD'] !== 'POST'",
            'Csrf::validate(',
            'upsertMany(',
        ]);
        self::assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'login, permission, POST, CSRF, then the write');
        self::assertStringContainsString("SearchService::SETTING => isset(\$_POST[SearchService::SETTING]) ? '1' : '0'", $endpoint, 'off is written too');
        self::assertStringContainsString('SiteSettings::clearCache();', $endpoint);
    }
}
