<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionContent;
use App\Service\ProductSeo;
use App\Service\RelatedProductsContent;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Shop's editors through their real endpoints (Multilingual 2.0 phase 5
 * wave C, MODULES.md "Shop"): ONE website language per request, and every
 * other translation left exactly as it was.
 *
 * Over real HTTP against PHP's built-in server with the Shop switched on
 * (Tests\Support\BuiltInServer), because an endpoint's answer is its redirect
 * and its session flash, and because a save that is refused must come back
 * with what was typed. The products, collections and accounts it makes are
 * its own and are removed again in tearDown(). When the server cannot be
 * started the test skips itself, like the HTTP tier does (TESTING.md).
 *
 * THE PROPERTY THIS FILE EXISTS FOR, beside the one-language contract: a
 * language switch changes labels and nothing else. The same product id, the
 * same slug and the same price come out of a save in English as out of a save
 * in Dutch — because none of those are words.
 */
final class ShopEditingHttpTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-shop-http-';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $collectionIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }

        $this->accounts->forget();

        $this->productIds = [];
        $this->collectionIds = [];

        ShopLocalization::clearCache();
        CollectionContent::clearCache();
        ProductSeo::clearCache();
        RelatedProductsContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Creating                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A NEW product is written in the DEFAULT website language, whatever
     * language the screen happened to show — like a new page and a new blog
     * post — so its slug comes from a name the shop will really print.
     */
    public function testANewProductIsWrittenInTheDefaultLanguageAndItsSlugComesFromThatName(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/create-product.php', $session, [
            'csrf_token' => $csrf,
            // The screen was in English; the endpoint writes the default
            // language regardless.
            'language_code' => 'en',
            'name' => 'ZZ Http Onderzetter',
            'description' => '<p>Van berkenhout.</p>',
            'price' => '12,50',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/products.php?created=1', $response['location']);

        $productId = $this->newestProductId();
        $stored = (new ProductRepository())->findByIdForAdmin($productId);
        $this->assertNotNull($stored);

        ShopLocalization::clearCache();
        $this->assertSame('ZZ Http Onderzetter', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'nl'));
        $this->assertSame('', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'en'));
        $this->assertSame('zz-http-onderzetter', $stored['slug']);
    }

    /** Row and words are one transaction: a nameless product is never listed. */
    public function testAProductWithoutANameInTheDefaultLanguageIsNotCreatedAtAll(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $before = $this->productCount();

        $response = self::$server->request('POST', '/api/admin/create-product.php', $session, [
            'csrf_token' => $csrf,
            'name' => '',
            'price' => '12,50',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/product-form.php', $response['location']);
        $this->assertSame($before, $this->productCount(), 'nothing was created');
        $this->assertNotEmpty($this->accounts->read($session, 'admin_product_errors'));
    }

    /* ------------------------------------------------------------------ */
    /* Editing one language                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Saving Dutch leaves English standing, and the other way round. That used
     * to be a property of a form that posted both columns at once; since
     * wave C it is a property of the storage.
     */
    public function testSavingOneLanguageThroughTheEndpointLeavesTheOtherAlone(): void
    {
        $productId = $this->storedProduct('ZZ Onderzetter');
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $save = fn (string $language, string $name): array => self::$server->request(
            'POST',
            '/api/admin/update-product.php',
            $session,
            [
                'csrf_token' => $csrf,
                'id' => (string) $productId,
                'language_code' => $language,
                'name' => $name,
                'description' => '',
                'meta_title' => '',
                'meta_description' => '',
                'price' => '12,50',
                'active' => '1',
                'in_shop' => '1',
                'shipping_profile' => 'letter',
                'shipping_weight_grams' => '20',
            ]
        );

        $this->assertSame(302, $save('en', 'ZZ Coaster')['status']);
        ShopLocalization::clearCache();
        $this->assertSame('ZZ Onderzetter', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'nl'), 'English did not touch Dutch');
        $this->assertSame('ZZ Coaster', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'en'));

        $this->assertSame(302, $save('nl', 'ZZ Plank')['status']);
        ShopLocalization::clearCache();
        $this->assertSame('ZZ Plank', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'nl'));
        $this->assertSame('ZZ Coaster', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'en'), 'Dutch did not touch English');
    }

    /**
     * A LANGUAGE SWITCH CHANGES LABELS AND NOTHING ELSE. Saving the English
     * translation leaves the id, the slug and the price exactly where they
     * were — the identity a cart, a checkout and a price lookup use.
     */
    public function testSavingATranslationChangesNoIdentityAndNoPrice(): void
    {
        $productId = $this->storedProduct('ZZ Identiteit');
        $before = (new ProductRepository())->findByIdForAdmin($productId);
        $this->assertNotNull($before);

        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'en',
            'name' => 'ZZ Completely Different Name',
            'description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'price' => $before['price'],
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => (string) $before['shipping_profile'],
            'shipping_weight_grams' => (string) $before['shipping_weight_grams'],
        ]);

        $this->assertSame(302, $response['status']);

        $after = (new ProductRepository())->findByIdForAdmin($productId);
        $this->assertNotNull($after);

        foreach (['id', 'slug', 'price', 'active', 'in_shop', 'shipping_profile'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column . ' may never follow a translation');
        }
    }

    /** A language outside the registry is refused, and writes nothing. */
    public function testALanguageTheRegistryDoesNotHaveIsRefusedAndWritesNothing(): void
    {
        $productId = $this->storedProduct('ZZ Onbekende taal');
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'xx',
            'name' => 'ZZ Nope',
            'description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'price' => '12,50',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/product-form.php?id=' . $productId, $response['location']);
        $this->assertNotEmpty($this->accounts->read($session, 'admin_product_errors'));

        ShopLocalization::clearCache();
        $this->assertSame('ZZ Onbekende taal', ShopLocalization::rawProduct($productId, ShopLocalization::NAME, 'nl'));
        $this->assertSame([], ShopLocalization::products()->words($productId)['xx'] ?? []);
    }

    /**
     * A REFUSED SAVE KEEPS WHAT WAS TYPED, in the language it was typed in —
     * the contract admin/_localized_fields.php and the save bar rely on.
     */
    public function testARefusedSaveComesBackWithTheWordsAndTheLanguage(): void
    {
        $productId = $this->storedProduct('ZZ Geweigerd');
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'en',
            'name' => 'ZZ Typed In English',
            'description' => '<p>Typed.</p>',
            'meta_title' => '',
            'meta_description' => '',
            // Refused on the price, which has nothing to do with language.
            'price' => 'geen bedrag',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ]);

        $this->assertSame(302, $response['status']);

        $old = $this->accounts->read($session, 'admin_product_old');
        $this->assertIsArray($old);
        $this->assertSame('en', $old['language_code']);
        $this->assertSame('ZZ Typed In English', $old['name']);
        $this->assertStringContainsString('Typed.', (string) $old['description']);
    }

    /* ------------------------------------------------------------------ */
    /* Collections                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * A COLLECTION'S ADDRESS IS LANGUAGE-NEUTRAL, and the editor form posts
     * the slug it is showing — so an ordinary save of a translation leaves
     * /collecties/<slug> answering exactly what it answered.
     */
    public function testAnOrdinarySaveOfATranslationLeavesTheAddressWhereItIs(): void
    {
        $collectionId = $this->storedCollection('ZZ Onderzetters');
        $before = (new CollectionRepository())->findById($collectionId);
        $this->assertNotNull($before);

        [$session, $csrf] = $this->accounts->signIn([ShopModule::COLLECTIONS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-collection.php', $session, [
            'csrf_token' => $csrf,
            'id' => (string) $collectionId,
            'language_code' => 'en',
            'name' => 'ZZ Coasters',
            'slug' => (string) $before['slug'],
            'description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response['status']);

        $after = (new CollectionRepository())->findById($collectionId);
        $this->assertNotNull($after);
        $this->assertSame($before['slug'], $after['slug'], 'a translation may never move a collection');

        ShopLocalization::clearCache();
        $this->assertSame('ZZ Onderzetters', ShopLocalization::rawCollection($collectionId, ShopLocalization::NAME, 'nl'));
        $this->assertSame('ZZ Coasters', ShopLocalization::rawCollection($collectionId, ShopLocalization::NAME, 'en'));
    }

    /**
     * And a BLANK slug field still means "make one from the name" — from the
     * DEFAULT language's name, never from the translation on screen. Before
     * wave C the form posted both names at once and this produced the Dutch
     * slug; it still does, which is the whole point.
     */
    public function testARegeneratedSlugComesFromTheDefaultLanguagesNameNotTheTranslation(): void
    {
        $collectionId = $this->storedCollection('ZZ Onderzetters');
        [$session, $csrf] = $this->accounts->signIn([ShopModule::COLLECTIONS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-collection.php', $session, [
            'csrf_token' => $csrf,
            'id' => (string) $collectionId,
            'language_code' => 'en',
            'name' => 'ZZ Completely Different English Name',
            'slug' => '',
            'description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response['status']);

        $after = (new CollectionRepository())->findById($collectionId);
        $this->assertNotNull($after);
        $this->assertSame('zz-onderzetters', $after['slug'], 'the slug follows the default language, not the screen');
        $this->assertStringNotContainsString('english', (string) $after['slug']);
    }

    /**
     * The related-products screen saves one language too, and its per-collection
     * heading is a word of the collection rather than a column of it.
     */
    public function testTheRelatedProductsScreenSavesOneLanguageOfEveryHeading(): void
    {
        $collectionId = $this->storedCollection('ZZ Koppen');
        [$session, $csrf] = $this->accounts->signIn([ShopModule::COLLECTIONS_MANAGE]);

        $save = fn (string $language, string $global, string $own): array => self::$server->request(
            'POST',
            '/api/admin/update-related-products-settings.php',
            $session,
            [
                'csrf_token' => $csrf,
                'language_code' => $language,
                'enabled' => '1',
                'heading' => $global,
                'max_items' => '4',
                'collections_submitted' => '1',
                'collections[' . $collectionId . '][enabled]' => '1',
                'collections[' . $collectionId . '][heading]' => $own,
            ]
        );

        $this->assertSame(302, $save('nl', 'ZZ Ook interessant', 'ZZ Meer hiervan')['status']);
        $this->assertSame(302, $save('en', 'ZZ Also interesting', 'ZZ More of this')['status']);

        ShopLocalization::clearCache();
        $this->assertSame('ZZ Meer hiervan', ShopLocalization::rawCollection($collectionId, ShopLocalization::RELATED_HEADING, 'nl'));
        $this->assertSame('ZZ More of this', ShopLocalization::rawCollection($collectionId, ShopLocalization::RELATED_HEADING, 'en'));
    }

    /* ------------------------------------------------------------------ */
    /* The screens render at all                                           */
    /* ------------------------------------------------------------------ */

    /**
     * EVERY Shop screen answers, and prints no PHP diagnostic.
     *
     * A wave that drops columns can leave one reader behind in a template,
     * and a template is where no other test looks. That is exactly what
     * happened to admin/product-form.php's collection picker during phase 5
     * wave C: `Warning: Undefined array key "name"` above the form, and every
     * test still green.
     *
     * Be honest about what this half catches: a warning only reaches the body
     * where `display_errors` is on, which is a development container and not
     * the test one, so here it is mostly a guard against a fatal error and a
     * white page. The test below is the one that would have caught that
     * particular bug, and the shape to copy: assert the screen really PRINTS
     * what it went to the database for.
     */
    public function testEveryShopScreenRendersWithoutAPhpDiagnostic(): void
    {
        $productId = $this->storedProduct('ZZ Render');
        $collectionId = $this->storedCollection('ZZ Render collectie');
        [$session] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE, ShopModule::COLLECTIONS_MANAGE]);

        foreach ([
            '/admin/products.php',
            '/admin/product-form.php',
            '/admin/product-form.php?id=' . $productId,
            '/admin/collections.php',
            '/admin/collection.php',
            '/admin/collection.php?id=' . $collectionId,
            '/admin/related-products.php',
            '/shop.php',
            '/product.php?id=' . $productId,
        ] as $path) {
            $response = self::$server->request('GET', $path, $session);

            $this->assertSame(200, $response['status'], $path);
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:Warning|Notice|Deprecated|Fatal error|Parse error):/',
                $response['body'],
                $path . ' printed a PHP diagnostic'
            );
        }
    }

    /**
     * And the one that got away: the collection picker on the product form
     * names its collections, which it can only do through the words store.
     */
    public function testTheProductFormNamesTheCollectionsItOffers(): void
    {
        $collectionId = $this->storedCollection('ZZ Picker collectie');
        [$session] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $response = self::$server->request('GET', '/admin/product-form.php', $session);

        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression(
            '/name="collection_ids\[\]"\s+value="' . $collectionId . '"/',
            $response['body'],
            'the picker must offer this collection'
        );
        $this->assertStringContainsString('ZZ Picker collectie', $response['body'], 'and name it');
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function storedProduct(string $name): int
    {
        $id = (new ProductRepository())->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(5)),
            'price' => 12.50,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name]);
        ShopLocalization::clearCache();

        $this->productIds[] = $id;

        return $id;
    }

    private function storedCollection(string $name): int
    {
        $id = (new CollectionRepository())->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(5)),
            'image_path' => null,
            'is_active' => true,
        ]);

        ShopLocalization::saveCollection($id, 'nl', [ShopLocalization::NAME => $name]);
        ShopLocalization::clearCache();

        $this->collectionIds[] = $id;

        return $id;
    }

    /** The product the endpoint just created, remembered so tearDown removes it. */
    private function newestProductId(): int
    {
        $id = (int) Database::connection()
            ->query("SELECT id FROM products WHERE slug LIKE 'zz-http-%' ORDER BY id DESC LIMIT 1")
            ->fetchColumn();

        $this->assertGreaterThan(0, $id, 'the endpoint should have created a product');
        $this->productIds[] = $id;

        return $id;
    }

    private function productCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }
}
