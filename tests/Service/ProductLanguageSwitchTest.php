<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AppUrl;
use App\Service\Language\SiteLanguages;
use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * THE LANGUAGE SWITCH ON A PRODUCT PAGE keeps the product
 * (docs/multilingual/ROUTING.md, §9).
 *
 * A product has no slug. Its identity is the id in the query string —
 * /product.php?id=7, /en/product.php?id=7 — and the switch's assumed paths
 * carry the path only. So product.php used to offer /en/product.php: the bare
 * route, without any product. It now declares its versions through
 * App\Service\ProductSeo::alternates(), built from the validated integer.
 *
 * Over real HTTP with the dispatcher router in front, because what matters is
 * what a visitor clicks and where that lands: an href that looks right but
 * resolves to another page is the bug this test exists for.
 */
final class ProductLanguageSwitchTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-productswitch-';

    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $productIds = [];

    private bool $addedGerman = false;

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

        if (!in_array('en', SiteLanguages::activeCodes(), true) || SiteLanguages::defaultCode() !== 'nl') {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        $this->productIds = [];

        if ($this->addedGerman) {
            $db->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            $this->addedGerman = false;
        }

        SiteLanguages::clearCache();
        ShopLocalization::clearCache();
        ProductSeo::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The same product, in every language                                 */
    /* ------------------------------------------------------------------ */

    public function testTheDutchPageLinksTheSameProductInEnglish(): void
    {
        $id = $this->product('Gegraveerde plank zz', 'Engraved board zz');

        $dutch = $this->get('/product.php?id=' . $id);
        self::assertSame(200, $dutch['status']);
        self::assertSame('/en/product.php?id=' . $id, $this->switchHref($dutch['body'], 'en'));

        $english = $this->follow($dutch['body'], 'en');
        self::assertSame(200, $english['status']);
        self::assertStringContainsString('<html lang="en"', $english['body']);
        self::assertSame(ProductSeo::canonicalUrl($id, 'en'), $this->canonical($english['body']), 'the link lands on the same product');
        self::assertStringContainsString('Engraved board zz', $english['body'], 'in its English words');
    }

    public function testTheEnglishPageLinksTheSameProductBackInDutch(): void
    {
        $id = $this->product('Gegraveerde plank zz', 'Engraved board zz');

        $english = $this->get('/en/product.php?id=' . $id);
        self::assertSame(200, $english['status']);
        self::assertSame('/product.php?id=' . $id, $this->switchHref($english['body'], 'nl'), 'the default language keeps the unprefixed URL');

        $dutch = $this->follow($english['body'], 'nl');
        self::assertSame(200, $dutch['status']);
        self::assertStringContainsString('<html lang="nl"', $dutch['body']);
        self::assertSame(ProductSeo::canonicalUrl($id, 'nl'), $this->canonical($dutch['body']));
        self::assertStringContainsString('Gegraveerde plank zz', $dutch['body']);
    }

    /**
     * A language is a row in site_languages and nothing else. German is
     * offered like every other language — a product is routable in every
     * active one; its words fall back — and the way back is just as exact.
     */
    public function testAThirdLanguageLinksTheSameProductToo(): void
    {
        $this->addGerman();
        $id = $this->product('Gegraveerde plank zz', 'Engraved board zz');

        $dutch = $this->get('/product.php?id=' . $id);
        self::assertSame('/de/product.php?id=' . $id, $this->switchHref($dutch['body'], 'de'));
        self::assertFalse($this->switchIsUnavailable($dutch['body'], 'de'), 'a product exists in every active language');

        $german = $this->follow($dutch['body'], 'de');
        self::assertSame(200, $german['status']);
        self::assertStringContainsString('<html lang="de"', $german['body']);
        self::assertSame(ProductSeo::canonicalUrl($id, 'de'), $this->canonical($german['body']));

        self::assertSame('/en/product.php?id=' . $id, $this->switchHref($german['body'], 'en'));
        self::assertSame('/product.php?id=' . $id, $this->switchHref($german['body'], 'nl'));
    }

    /**
     * The versions the switch links are the ones hreflang names, and the ones
     * the sitemap lists (App\Service\ProductSeo::alternates()): a visitor and
     * a crawler are offered the same URLs.
     */
    public function testHreflangNamesTheSameVersionsTheSwitchLinks(): void
    {
        $id = $this->product('Gegraveerde plank zz', 'Engraved board zz');

        $dutch = $this->get('/product.php?id=' . $id);

        foreach (ProductSeo::alternates($id) as $code => $path) {
            self::assertSame($path, $this->switchHref($dutch['body'], $code));
            self::assertStringContainsString(
                'hreflang="' . $code . '" href="' . AppUrl::canonical($path) . '"',
                $dutch['body']
            );
        }
        self::assertStringContainsString('hreflang="x-default" href="' . ProductSeo::canonicalUrl($id, 'nl') . '"', $dutch['body']);
    }

    /* ------------------------------------------------------------------ */
    /* Only the identity travels                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The id is the identity; everything else a visitor arrived with —
     * tracking, a form status, a stray `lang` — belongs to that one visit
     * and must not be handed to the next page, nor to a crawler.
     */
    public function testUnrelatedQueryParametersAreNotCarriedAlong(): void
    {
        $id = $this->product('Gegraveerde plank zz', 'Engraved board zz');

        foreach (
            [
                '/product.php?id=' . $id . '&utm_source=news&form-status=ok&lang=en&x=%3Cscript%3E',
                '/product.php?utm_campaign=spring&id=' . $id,
                '/en/product.php?id=' . $id . '&gclid=abc&status=success',
            ] as $url
        ) {
            $page = $this->get($url);
            self::assertSame(200, $page['status'], $url);
            self::assertSame('/product.php?id=' . $id, $this->switchHref($page['body'], 'nl'), $url);
            self::assertSame('/en/product.php?id=' . $id, $this->switchHref($page['body'], 'en'), $url);

            // Every URL on the page that names a version of this product —
            // the switch, the canonical, each alternate — is the product's
            // address and nothing more.
            $named = $this->xpath($page['body'])->query(
                '//div[contains(@class, "lang-switch")]/a/@href | //link[@rel="canonical" or @rel="alternate"]/@href'
            );
            self::assertNotFalse($named);
            self::assertGreaterThan(2, $named->length, $url);
            foreach ($named as $attribute) {
                self::assertMatchesRegularExpression(
                    '~\A(https?://[^/?#]+)?(/[a-z]{2})?/product\.php\?id=' . $id . '\z~',
                    $attribute->nodeValue,
                    $url . ' carried something besides the id'
                );
            }
        }
    }

    /**
     * An id that is not a positive integer names no product. Nothing of it is
     * carried: the switch offers the bare route in each language, which is
     * what this URL is, and never an address built from what was typed.
     */
    public function testAMalformedIdCarriesNothing(): void
    {
        foreach (['abc', '0', '-5', '12abc', '1e3', '//evil.test', 'https://evil.test', "1\r\nLocation: https://evil.test"] as $value) {
            $page = $this->get('/product.php?id=' . rawurlencode($value));

            self::assertSame(200, $page['status'], 'the route itself still resolves for ' . json_encode($value));
            self::assertSame('/en/product.php', $this->switchHref($page['body'], 'en'), json_encode($value));
            self::assertSame('/product.php', $this->switchHref($page['body'], 'nl'), json_encode($value));
            self::assertStringNotContainsString('evil.test', (string) $this->switchHref($page['body'], 'en'));
            self::assertStringNotContainsString('hreflang="x-default"', $page['body'], 'nothing is advertised for no product');
        }

        $array = $this->get('/product.php?id[]=1');
        self::assertSame('/en/product.php', $this->switchHref($array['body'], 'en'), 'an array is no id either');
    }

    /**
     * A product that is gone is gone in every language. The switch still
     * names the same id — reading that answer in English is a fair request —
     * so it leads to the same 404, not to the bare route and not to a
     * disabled option; and a page without a canonical advertises no hreflang.
     */
    public function testAMissingProductLeadsToTheSameAnswerInTheOtherLanguage(): void
    {
        $id = $this->product('Weg zz', 'Gone zz');
        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);

        $dutch = $this->get('/product.php?id=' . $id);
        self::assertSame(404, $dutch['status']);
        self::assertSame('/en/product.php?id=' . $id, $this->switchHref($dutch['body'], 'en'));
        self::assertStringNotContainsString('hreflang="x-default"', $dutch['body']);

        self::assertSame(404, $this->follow($dutch['body'], 'en')['status']);
    }

    /* ------------------------------------------------------------------ */
    /* The prefix belongs to the default language, not to Dutch             */
    /* ------------------------------------------------------------------ */

    /**
     * Make English the default and the English product loses its prefix while
     * the Dutch one gains one — no code names "nl" as unprefixed. Flipped
     * back whatever happens in between.
     */
    public function testFlippingTheDefaultLanguageMovesThePrefixOnly(): void
    {
        $id = $this->product('Gegraveerde plank zz', 'Engraved board zz');

        SiteLanguages::setDefault('en');

        try {
            $english = $this->get('/product.php?id=' . $id);
            self::assertSame(200, $english['status']);
            self::assertStringContainsString('<html lang="en"', $english['body']);
            self::assertSame('/nl/product.php?id=' . $id, $this->switchHref($english['body'], 'nl'));

            $dutch = $this->follow($english['body'], 'nl');
            self::assertSame(200, $dutch['status']);
            self::assertStringContainsString('<html lang="nl"', $dutch['body']);
            self::assertSame('/product.php?id=' . $id, $this->switchHref($dutch['body'], 'en'));
        } finally {
            SiteLanguages::setDefault('nl');
            SiteLanguages::clearCache();
        }

        self::assertSame('/en/product.php?id=' . $id, $this->switchHref($this->get('/product.php?id=' . $id)['body'], 'en'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function product(string $dutch, string $english): int
    {
        $id = (new ProductRepository())->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'price' => 12.5,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $dutch]);
        ShopLocalization::saveProduct($id, 'en', [ShopLocalization::NAME => $english]);
        ShopLocalization::clearCache();

        return $id;
    }

    private function addGerman(): void
    {
        if (SiteLanguages::exists('de')) {
            $this->markTestSkipped('the test database already registers de');
        }

        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    /** Click the switch: request exactly the href it prints for one language. */
    private function follow(string $html, string $language): array
    {
        $href = $this->switchHref($html, $language);
        self::assertNotNull($href, 'the switch links ' . $language);
        self::assertStringStartsWith('/', $href);
        self::assertStringStartsNotWith('//', $href, 'a switch link never leaves this site');

        return $this->get(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function canonical(string $html): ?string
    {
        $links = $this->xpath($html)->query('//link[@rel="canonical"]');
        self::assertNotFalse($links);

        return $links->length === 0 ? null : $links->item(0)->getAttribute('href');
    }

    /** Where the public language switch sends a visitor for one language, or null when it links nothing. */
    private function switchHref(string $html, string $language): ?string
    {
        $links = $this->xpath($html)->query('//div[contains(@class, "lang-switch")]/a[@hreflang="' . $language . '"]');
        self::assertNotFalse($links);

        return $links->length === 0 ? null : $links->item(0)->getAttribute('href');
    }

    /** Is this language shown as a version the page does not have? */
    private function switchIsUnavailable(string $html, string $language): bool
    {
        $spans = $this->xpath($html)->query(
            '//div[contains(@class, "lang-switch")]/span[contains(@class, "lang-switch__unavailable")][normalize-space() = "' . strtoupper($language) . '"]'
        );
        self::assertNotFalse($spans);

        return $spans->length === 1;
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
