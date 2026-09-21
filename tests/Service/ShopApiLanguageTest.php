<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Language\SiteLanguages;
use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * THE SHOP'S JSON ANSWERS IN THE LANGUAGE OF THE PAGE ASKING
 * (Multilingual 2.0 phase 7, wave B).
 *
 * A page is answered in the language of its URL; the data its scripts fetch
 * comes from /api/*.php, which has no prefix. The script sends the page's
 * language as ?lang= and App\Service\Routing\ApiLanguage takes it only when
 * it is an active website language. What comes back is ONE value per field —
 * `name`, `description`, `label` — never a Dutch/English pair, and a language
 * without its own words reads the default language's.
 *
 * Over real HTTP with the dispatcher router in front, like the storefront.
 */
final class ShopApiLanguageTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-shopapi-';

    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $productIds = [];

    private bool $addedGerman = false;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
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
    /* The product list and one product                                    */
    /* ------------------------------------------------------------------ */

    public function testTheProductListAnswersInThePagesLanguageWithOneNamePerProduct(): void
    {
        $id = $this->product(['nl' => 'Gegraveerde plank zz', 'en' => 'Engraved board zz']);

        $dutch = $this->listed($id, 'nl');
        self::assertSame('Gegraveerde plank zz', $dutch['name']);

        $english = $this->listed($id, 'en');
        self::assertSame('Engraved board zz', $english['name']);

        foreach (['name_en', 'description_en', 'name_nl'] as $pair) {
            self::assertArrayNotHasKey($pair, $english, 'one value per field, never a pair');
        }
        self::assertSame($dutch['price'], $english['price'], 'the language picks words, never a price');
    }

    /** German is a row in site_languages: its own words when it has them, the default's when not. */
    public function testAThirdLanguageReadsItsOwnWordsOrTheDefaultLanguages(): void
    {
        $this->addGerman();
        $translated = $this->product(['nl' => 'Onderzetter zz', 'en' => 'Coaster zz', 'de' => 'Untersetzer zz']);
        $untranslated = $this->product(['nl' => 'Sleutelhanger zz', 'en' => 'Keyring zz']);

        self::assertSame('Untersetzer zz', $this->listed($translated, 'de')['name']);
        self::assertSame('Sleutelhanger zz', $this->listed($untranslated, 'de')['name'], 'no German name: the default language, not English');
    }

    /** Anything that is not an active website language is the default language — never an error, never a guess. */
    public function testALanguageTheSiteDoesNotPublishIsTheDefault(): void
    {
        $id = $this->product(['nl' => 'Gegraveerde plank zz', 'en' => 'Engraved board zz']);

        foreach (['xx', 'fr', 'EN-gb', '../en', '', 'de'] as $sent) {
            self::assertSame('Gegraveerde plank zz', $this->listed($id, $sent)['name'], '?lang=' . $sent);
        }

        $response = self::$server->request('GET', '/api/products.php?ids=' . $id . '&lang[]=en');
        self::assertSame(200, $response['status'], 'an array is not a language, and not a crash either');
        self::assertSame('Gegraveerde plank zz', $this->decode($response)['data'][0]['name']);
    }

    public function testOneProductAnswersInThePagesLanguageWithItsDescriptionSanitized(): void
    {
        $id = $this->product(
            ['nl' => 'Gegraveerde plank zz', 'en' => 'Engraved board zz'],
            ['nl' => '<p>Van eiken</p>', 'en' => '<p>Made of oak</p><script>alert(1)</script>']
        );

        $english = $this->decode(self::$server->request('GET', '/api/product.php?id=' . $id . '&lang=en'))['data'];
        self::assertSame('Engraved board zz', $english['name']);
        self::assertStringContainsString('Made of oak', $english['description']);
        self::assertStringNotContainsString('<script', $english['description'], 'rich text leaves through the sanitizer');
        self::assertArrayNotHasKey('name_en', $english);
        self::assertArrayNotHasKey('description_en', $english);

        $dutch = $this->decode(self::$server->request('GET', '/api/product.php?id=' . $id))['data'];
        self::assertSame('Gegraveerde plank zz', $dutch['name'], 'no ?lang= at all: the default language');
        self::assertSame('<p>Van eiken</p>', $dutch['description']);
    }

    /* ------------------------------------------------------------------ */
    /* Shipping                                                            */
    /* ------------------------------------------------------------------ */

    public function testTheShippingCountriesAreNamedInThePagesLanguage(): void
    {
        $english = $this->decode(self::$server->request('GET', '/api/shipping-zones.php?lang=en'))['countries'] ?? [];
        $dutch = $this->decode(self::$server->request('GET', '/api/shipping-zones.php?lang=nl'))['countries'] ?? [];

        foreach ($english as $country) {
            self::assertSame(['code', 'label'], array_keys($country), 'one label, never a pair');
        }

        $byCode = static fn (array $countries): array => array_column($countries, 'label', 'code');
        if (isset($byCode($english)['NL'])) {
            self::assertSame('Netherlands', $byCode($english)['NL']);
            self::assertSame('Nederland', $byCode($dutch)['NL']);
        } else {
            self::assertSame([], $english, 'this database ships nowhere, so there is nothing to name');
        }
    }

    /* ------------------------------------------------------------------ */
    /* The words the Shop's scripts show                                   */
    /* ------------------------------------------------------------------ */

    /** Every Shop page carries its scripts' sentences, in its own language only. */
    public function testEveryShopPageCarriesItsScriptWordsInItsOwnLanguage(): void
    {
        $dutch = $this->scriptWords('/cart.php');
        $english = $this->scriptWords('/en/cart.php');

        self::assertSame('Je winkelwagen is leeg.', $dutch['cart_empty']);
        self::assertSame('Your cart is empty.', $english['cart_empty']);
        self::assertSame('{count} items', $english['cart_count_many']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, string> $names       language => name
     * @param array<string, string> $descriptions language => description
     */
    private function product(array $names, array $descriptions = []): int
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

        foreach ($names as $language => $name) {
            $words = [ShopLocalization::NAME => $name];
            if (isset($descriptions[$language])) {
                $words[ShopLocalization::DESCRIPTION] = $descriptions[$language];
            }
            ShopLocalization::saveProduct($id, $language, $words);
        }
        ShopLocalization::clearCache();

        return $id;
    }

    /** @return array<string, mixed> the product as /api/products.php lists it for $language */
    private function listed(int $id, string $language): array
    {
        $response = self::$server->request('GET', '/api/products.php?ids=' . $id . '&lang=' . rawurlencode($language));
        self::assertSame(200, $response['status']);

        $data = $this->decode($response)['data'] ?? [];
        self::assertCount(1, $data, 'the product is listed');

        return $data[0];
    }

    /** @return array<string, string> the JSON data block a Shop page hands its scripts */
    private function scriptWords(string $path): array
    {
        $response = self::$server->request('GET', $path);
        self::assertSame(200, $response['status'], $path);
        self::assertSame(1, preg_match('#<script type="application/json" id="shop-text">(.*?)</script>#s', $response['body'], $match), $path);

        $words = json_decode($match[1], true);
        self::assertIsArray($words);

        return $words;
    }

    /**
     * @param array{body: string} $response
     * @return array<string, mixed>
     */
    private function decode(array $response): array
    {
        $decoded = json_decode($response['body'], true);
        self::assertIsArray($decoded, $response['body']);

        return $decoded;
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
}
