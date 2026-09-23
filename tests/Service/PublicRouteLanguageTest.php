<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\PageRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AppUrl;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RouteTable;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Service\Routing\PublicRouteContractTest;
use Tests\Service\Routing\QueryIdentityRoutesTest;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * EVERY PUBLIC ROUTE, IN EVERY LANGUAGE, OVER HTTP: the witnesses of
 * Tests\Service\Routing\PublicRouteContractTest, each requested under the
 * default language's address, /en/… and /de/… (docs/multilingual/ROUTING.md).
 *
 * One sweep instead of one test per template, because the mistakes it guards
 * against were never about one template: a canonical built with
 * App\Service\AppUrl alone, a link that dropped the prefix, a POST answered in
 * the default language. Each of those was fine in the default language and
 * wrong in every other, and each was found by hand, page by page. Here a new
 * route is swept the moment it has a witness, and a violation names the route,
 * the language and the offending URL.
 *
 * For every witness and every language:
 *   - it answers 200 as a document in that language;
 *   - its canonical is its own route in THAT language, or absent where its
 *     policy says so, never another language's;
 *   - every link to a system route (a route with a literal path, from the
 *     route table) carries that language's prefix, header and footer included;
 *   - each option of the language switch leads to its own language, and on a
 *     route whose query names its resource, to that same resource;
 *   - every form that POSTs is submitted, refused, and its answer lands on
 *     the same route in the same language.
 *
 * Its own content where the witness needs some (an order, a product, an old
 * project page, the CMS pages the system routes serve when a test database
 * lacks them), and German as a third language, all removed afterwards.
 */
final class PublicRouteLanguageTest extends TestCase
{
    private const LANGUAGES = ['nl', 'en', 'de'];

    private const CUSTOMER_EMAIL = 'public-route-language@__test__.invalid';

    /** The CMS pages behind system routes, created only where the database has none. */
    private const ROUTE_BOUND_PAGES = [
        'index' => '/',
        'contact' => '/contact.php',
        'diensten' => '/diensten.php',
        'over-mij' => '/over-mij.php',
        'portfolio' => '/portfolio.php',
        'shop' => '/shop.php',
    ];

    private static ?BuiltInServer $server = null;

    /** @var array<string, int|string>|null placeholder => value, once created */
    private static ?array $fixtures = null;

    /** @var array{pages: list<int>, products: list<int>, orders: list<int>, items: list<int>, customer: int|null, german: bool, overview: ?string} */
    private static array $created = ['pages' => [], 'products' => [], 'orders' => [], 'items' => [], 'customer' => null, 'german' => false, 'overview' => null];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([
            'MODULE_SHOP_ENABLED' => 'true',
            'MODULE_BLOG_ENABLED' => 'true',
            'MODULE_PERSONALIZATION_ENABLED' => 'true',
            'MODULE_PORTFOLIO_ENABLED' => 'true',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '9',
        ], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        if (self::$fixtures === null) {
            return;
        }

        $db = Database::connection();
        $gallery = new PortfolioGalleryRepository();
        $pages = new PageRepository();

        foreach (self::$created['items'] as $id) {
            $gallery->deleteItem($id);
        }
        foreach (self::$created['products'] as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach (self::$created['orders'] as $id) {
            $db->prepare('DELETE FROM withdrawal_requests WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $id]);
        }
        if (self::$created['customer'] !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => self::$created['customer']]);
        }
        foreach (self::$created['pages'] as $id) {
            $pages->delete($id);
        }
        if (self::$created['german']) {
            $db->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
        }
        if (self::$created['overview'] === 'none') {
            $db->prepare("DELETE FROM site_settings WHERE setting_key = 'shop_overview'")->execute();
        } elseif (self::$created['overview'] === 'empty') {
            $db->prepare("UPDATE site_settings SET setting_value = '' WHERE setting_key = 'shop_overview'")->execute();
        }

        self::$fixtures = null;
        self::$created = ['pages' => [], 'products' => [], 'orders' => [], 'items' => [], 'customer' => null, 'german' => false, 'overview' => null];

        SiteLanguages::clearCache();
        ShopLocalization::clearCache();
        PageContent::clearCache();
        PortfolioGalleryContent::clearCache();
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        if (!in_array('en', SiteLanguages::activeCodes(), true) || SiteLanguages::defaultCode() !== 'nl') {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        self::$fixtures ??= self::createFixtures();
    }

    /* ------------------------------------------------------------------ */
    /* The canonical                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Every witness is a document in the language of its address, and its
     * canonical is that same route in that same language — or absent, where
     * the route's policy says there is none.
     */
    public function testEveryRouteCanonicalizesInItsOwnLanguage(): void
    {
        $violations = [];

        foreach ($this->witnesses() as $key => $route) {
            foreach (self::LANGUAGES as $language) {
                $url = self::prefix($language) . $route['url'];
                $page = $this->get($url);

                if ($page['status'] !== 200) {
                    $violations[] = "{$key} {$url}: answers {$page['status']}";
                    continue;
                }

                if (!str_contains($page['body'], '<html lang="' . $language . '"')) {
                    $violations[] = "{$key} {$url}: not a document in {$language}";
                }

                $expected = $route['canonical'] === null ? null : AppUrl::canonical(self::prefix($language) . $route['canonical']);
                $canonical = $this->canonical($page['body']);

                if ($canonical !== $expected) {
                    $violations[] = "{$key} {$url}: canonical " . json_encode($canonical) . ', expected ' . json_encode($expected);
                }
            }
        }

        self::assertSame([], $violations, "a canonical that is not the route's own in the request's language");
    }

    /**
     * The storefront renders the Shop's own automatic overview when no CMS
     * page carries it and the installation still has that overview
     * (shop_overview = 'builtin', App\Service\ShopOverview), and builds that
     * head itself. It is a canonical of its own in every language, exactly
     * like the page it stands in for.
    public function testTheStorefrontWithoutAPageCanonicalizesInItsOwnLanguageToo(): void
    {
        $shop = (new PageRepository())->findByContentKey('shop');
        $db = Database::connection();

        $overview = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'shop_overview'");
        $overview->execute();
        $overviewBefore = $overview->fetchColumn();
        $db->prepare(
            "INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES ('shop_overview', 'builtin', NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = 'builtin'"
        )->execute();

        if ($shop !== null) {
            $db->prepare('UPDATE pages SET content_key = :away WHERE id = :id')
                ->execute(['away' => 'zz-shop-away-' . bin2hex(random_bytes(3)), 'id' => $shop['id']]);
        }

        try {
            PageContent::clearCache();
            self::assertNull(PageContent::forContentKey('shop'), 'no CMS page carries the storefront now');

            foreach (self::LANGUAGES as $language) {
                $page = $this->get(self::prefix($language) . '/shop.php');

                self::assertSame(200, $page['status'], $language);
                self::assertSame(AppUrl::canonical(self::prefix($language) . '/shop.php'), $this->canonical($page['body']), $language);
            }
        } finally {
            if ($shop !== null) {
                $db->prepare('UPDATE pages SET content_key = :key WHERE id = :id')->execute(['key' => 'shop', 'id' => $shop['id']]);
            }
            if ($overviewBefore === false) {
                $db->prepare("DELETE FROM site_settings WHERE setting_key = 'shop_overview'")->execute();
            } else {
                $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'shop_overview'")->execute([$overviewBefore]);
            }
            PageContent::clearCache();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Links and the switch                                                */
    /* ------------------------------------------------------------------ */

    /**
     * A link to a system route stays in the language being read: the header,
     * the footer, the breadcrumb, the page's own buttons. A system route exists
     * in every language, so there is never a reason to send a visitor to the
     * default language's copy. (A link to a CMS page may fall back to the
     * default language's address when the page has no version here, ROUTING.md
     * §9; that is not a system route and not what this checks.)
     *
     * Where a CMS page is behind the route, its <main> is the editor's: a URL
     * typed into a block field (a hero button saying "/") prints as typed, in
     * every language, and is content rather than a link this code builds.
     * There the sweep checks the site's own chrome — header, footer, cookie
     * banner, breadcrumb — and on every other route the whole document.
     */
    public function testEveryLinkToASystemRouteStaysInTheLanguageBeingRead(): void
    {
        $systemPaths = self::systemPaths();
        $violations = [];

        foreach ($this->witnesses() as $key => $route) {
            $scope = $route['post'] === PublicRouteContractTest::POST_FORM_SOURCE
                ? '[not(ancestor::main) or ancestor::nav[contains(@class, "breadcrumb-bar")]]'
                : '';

            foreach (self::LANGUAGES as $language) {
                $url = self::prefix($language) . $route['url'];
                $xpath = $this->xpath($this->get($url)['body']);

                foreach ($xpath->query('//a[@href][not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " lang-switch ")])]' . $scope . '/@href') as $attribute) {
                    $href = $attribute->nodeValue;
                    if (!str_starts_with($href, '/') || str_starts_with($href, '//')) {
                        continue;
                    }

                    [$bare, $linked] = LocalizedUrl::strip($href);
                    $path = (string) parse_url($bare, PHP_URL_PATH);

                    if (in_array($path, $systemPaths, true) && ($linked ?? 'nl') !== $language) {
                        $violations[] = "{$key} {$url}: links {$href}";
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($violations)), 'a link to a system route that leaves the language being read');
    }

    /**
     * Each option of the switch leads to its own language. On a route whose
     * query string names its resource, it leads to that same resource and
     * carries nothing else (QueryIdentityRoutesTest).
     */
    public function testTheSwitchLeadsToEachLanguageAndKeepsAQueryIdentity(): void
    {
        $templates = [];
        foreach (RouteTable::all() as $table) {
            $templates[$table['key']] = $table['template'];
        }

        $violations = [];

        foreach ($this->witnesses() as $key => $route) {
            $identity = QueryIdentityRoutesTest::QUERY_IDENTITY[$templates[$key]] ?? null;

            foreach (self::LANGUAGES as $language) {
                $url = self::prefix($language) . $route['url'];
                $xpath = $this->xpath($this->get($url)['body']);

                foreach (self::LANGUAGES as $target) {
                    $links = $xpath->query('//div[contains(@class, "lang-switch")]/a[@hreflang="' . $target . '"]/@href');
                    if ($links->length === 0) {
                        $violations[] = "{$key} {$url}: the switch offers no {$target}";
                        continue;
                    }

                    $href = $links->item(0)->nodeValue;
                    if ((LocalizedUrl::strip($href)[1] ?? 'nl') !== $target) {
                        $violations[] = "{$key} {$url}: the {$target} option leads to {$href}";
                    }

                    if ($identity !== null && $href !== self::prefix($target) . $route['url']) {
                        $violations[] = "{$key} {$url}: the {$target} option leads to {$href}, not the same ?{$identity}=";
                    }
                }
            }
        }

        self::assertSame([], $violations, 'a switch option in the wrong language, or without the resource');
    }

    /* ------------------------------------------------------------------ */
    /* Forms                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Every form that POSTs is sent the way a browser sends it, with its own
     * hidden fields and nothing typed, so the endpoint refuses it. The answer
     * lands on the same route in the language the form was read in. A route
     * whose policy says it has no form may not grow one unnoticed, and a route
     * whose policy is a language field must really carry one.
     */
    public function testEveryFormIsAnsweredInTheLanguageItWasFilledIn(): void
    {
        $violations = [];
        $posted = [];

        foreach ($this->witnesses() as $key => $route) {
            $ownPath = (string) parse_url($route['url'], PHP_URL_PATH);

            foreach (self::LANGUAGES as $language) {
                $url = self::prefix($language) . $route['url'];
                $xpath = $this->xpath($this->get($url)['body']);

                foreach ($xpath->query('//form[translate(@method, "POST", "post") = "post"]') as $form) {
                    $posted[$key] = true;
                    $action = $form->getAttribute('action');

                    if ($route['post'] === PublicRouteContractTest::POST_NONE) {
                        $violations[] = "{$key} {$url}: posts to {$action}, but its policy says it has no form";
                    }

                    $fields = [];
                    foreach ($xpath->query('.//input[@type="hidden"][@name]', $form) as $input) {
                        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
                    }
                    if (isset($fields['form_ts'])) {
                        $fields['form_ts'] = (string) (time() - 60);
                    }

                    $answer = self::$server->request('POST', $action === '' ? $url : $action, null, $fields);
                    $location = $answer['location'];

                    if ($answer['status'] < 300 || $answer['status'] > 399 || !str_starts_with($location, '/') || str_starts_with($location, '//')) {
                        $violations[] = "{$key} {$url}: the answer to {$action} is {$answer['status']} " . json_encode($location);
                        continue;
                    }

                    [$bare, $answered] = LocalizedUrl::strip($location);
                    if (($answered ?? 'nl') !== $language || parse_url($bare, PHP_URL_PATH) !== $ownPath) {
                        $violations[] = "{$key} {$url}: the answer to {$action} lands on {$location}";
                    }
                }
            }
        }

        foreach ($this->witnesses() as $key => $route) {
            if ($route['post'] === PublicRouteContractTest::POST_LANGUAGE_FIELD && !isset($posted[$key])) {
                $violations[] = "{$key}: its policy is a language field, but no form on it posts";
            }
        }

        self::assertSame([], $violations, 'a form whose answer leaves the language it was filled in');
        self::assertArrayHasKey('core.herroeping', $posted, 'the sweep did submit a form');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Every witnessed route, with its placeholders filled in from this test's
     * own content.
     *
     * @return array<string, array{url: string, canonical: string|null, post: string}>
     */
    private function witnesses(): array
    {
        $witnesses = [];

        foreach (PublicRouteContractTest::ROUTES as $key => $route) {
            if ($route['witness'] === null) {
                continue;
            }

            $witnesses[$key] = [
                'url' => PublicRouteContractTest::fill($route['witness'], (array) self::$fixtures),
                'canonical' => $route['canonical'] === null ? null : PublicRouteContractTest::fill($route['canonical'], (array) self::$fixtures),
                'post' => $route['post'],
            ];
        }

        self::assertNotSame([], $witnesses);

        return $witnesses;
    }

    /**
     * The routes whose address is a literal path, the same in every language:
     * '/', '/cart.php', '/herroeping.php' and so on, straight from the table.
     *
     * @return list<string>
     */
    private static function systemPaths(): array
    {
        $paths = [];

        foreach (RouteTable::all() as $route) {
            if (!str_contains($route['pattern'], '{')) {
                $paths[] = '/' . $route['pattern'];
            }
        }

        return $paths;
    }

    /** The prefix a language's URLs carry on this nl-default test database. */
    private static function prefix(string $language): string
    {
        return $language === 'nl' ? '' : '/' . $language;
    }

    /**
     * The content the witnesses need, created once for the whole class.
     *
     * @return array<string, int|string>
     */
    private static function createFixtures(): array
    {
        $db = Database::connection();

        if (!SiteLanguages::exists('de')) {
            (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
            self::$created['german'] = true;
            SiteLanguages::clearCache();
        }

        $pages = new PageRepository();
        foreach (self::ROUTE_BOUND_PAGES as $contentKey => $routePath) {
            if ($pages->findByContentKey($contentKey) !== null) {
                continue;
            }

            $id = PageFixture::create([
                'content_key' => $contentKey,
                'slug' => 'zz-route-' . $contentKey . '-' . bin2hex(random_bytes(3)),
                'status' => PageContent::STATUS_PUBLISHED,
            ], 'ZZ ' . $contentKey);
            self::$created['pages'][] = $id;

            // PageRepository::create() never writes a fixed route; only the
            // migrations do, so the fixture sets it the same way.
            $db->prepare('UPDATE pages SET is_system = 1, route_path = :route WHERE id = :id')
                ->execute(['route' => $routePath, 'id' => $id]);
        }
        PageContent::clearCache();

        // /shop.php answers only while the storefront page is the chosen
        // product overview (App\Service\ShopOverview). A test database that
        // never made a choice (a fresh one) gets it for this sweep, and gets
        // its own answer back in tearDownAfterClass().
        $overview = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'shop_overview'")->fetchColumn();
        if ($overview === false || $overview === '') {
            self::$created['overview'] = $overview === false ? 'none' : 'empty';
            $db->prepare(
                "INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES ('shop_overview', ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([(string) (int) $pages->findByContentKey('shop')['id']]);
        }

        $product = (new ProductRepository())->create([
            'slug' => 'zz-route-language-' . bin2hex(random_bytes(6)),
            'price' => 12.5,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        self::$created['products'][] = $product;
        ShopLocalization::saveProduct($product, 'nl', [ShopLocalization::NAME => 'Routetest ' . $product]);
        ShopLocalization::saveProduct($product, 'en', [ShopLocalization::NAME => 'Route test ' . $product]);
        ShopLocalization::clearCache();

        self::$created['customer'] = (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Route Language',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);
        $order = (new OrderRepository())->create(
            self::$created['customer'],
            24.95,
            4.95,
            'verzenden',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Route', 'last_name' => 'Language', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
        self::$created['orders'][] = $order;
        $db->prepare("UPDATE orders SET status = 'paid' WHERE id = :id")->execute(['id' => $order]);

        // An old project page: nothing in the application writes its switch or
        // slug any more, so they are set directly, like
        // Tests\Module\PortfolioModuleHttpTest does.
        $gallery = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(3));
        $item = $gallery->createItem((int) $gallery->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-route-language-' . $marker . '.jpg',
            'thumbnail_path' => null,
        ]);
        self::$created['items'][] = $item;
        PortfolioLocalization::saveItem($item, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::ALT => 'ZZ alt ' . $marker,
            PortfolioLocalization::TITLE => 'ZZ Routeproject ' . $marker,
            PortfolioLocalization::SUBTITLE => 'ZZ onderschrift ' . $marker,
        ]);
        $project = 'zz-route-language-' . $marker;
        $db->prepare('UPDATE portfolio_gallery_items SET has_detail_page = 1, slug = :slug WHERE id = :id')
            ->execute(['slug' => $project, 'id' => $item]);
        PortfolioGalleryContent::clearCache();

        return ['order' => $order, 'product' => $product, 'project' => $project];
    }

    private function canonical(string $html): ?string
    {
        $links = $this->xpath($html)->query('//link[@rel="canonical"]/@href');

        return $links->length === 0 ? null : $links->item(0)->nodeValue;
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
