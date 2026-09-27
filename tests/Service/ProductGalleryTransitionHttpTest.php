<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Product Gallery 2.0 over real HTTP, against PHP's built-in server with the
 * Shop on:
 *
 *  - the product editor's one save (api/admin/update-product.php) stores the
 *    gallery's transition with everything else, NULL for "Standaard van
 *    Shop", refuses a word off the list without storing anything, and
 *    leaves the stored value alone when the form did not carry the field;
 *  - a new product follows the Shop unless the editor chose otherwise;
 *  - Shop-instellingen stores the Shop's default, and refuses anything else;
 *  - the product page writes the RESOLVED word into its gallery: the
 *    product's own, else the Shop's, else the fallback, so a product that
 *    follows the Shop changes with it and one with its own choice does not;
 *  - the editor shows the choice in Afbeeldingen, as an ordinary control of
 *    the one form.
 *
 * Its products, accounts and the Shop's stored default are its own and are
 * put back in tearDown(). Without a server the test skips itself.
 */
final class ProductGalleryTransitionHttpTest extends TestCase
{
    private const JSON = ['Accept: application/json'];
    private const KEY = 'shop_gallery_transition';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $productIds = [];

    /** The Shop's stored default before this test: false when there was no row. */
    private string|false $storedDefault = false;

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

        $stmt = Database::connection()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $stmt->execute(['key' => self::KEY]);
        $this->storedDefault = $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        if ($this->storedDefault === false) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => self::KEY]);
        } else {
            $this->shopDefault((string) $this->storedDefault);
        }

        $this->accounts->forget();
        $this->productIds = [];
        SiteSettings::clearCache();
        ShopLocalization::clearCache();
    }

    public function testTheEditorStoresEveryChoiceInItsOneSave(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Galerij Keuze');
        // A Shop default no product chooses by accident, so "follow the Shop" is visible.
        $this->shopDefault('none');

        foreach (['slide' => 'slide', 'fade' => 'fade', 'none' => 'none', '' => null] as $posted => $stored) {
            $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, ['gallery_transition' => $posted]), [], self::JSON);

            $this->assertSame(200, $response['status'], (string) $posted);
            $this->assertTrue(json_decode($response['body'], true)['ok']);
            $this->assertSame($stored, $this->stored($productId), 'stored for "' . $posted . '"');
            $this->assertSame($stored ?? 'none', $this->onThePage($productId), 'shown for "' . $posted . '"');
        }
    }

    public function testAWordOffTheListIsRefusedAndNothingIsStored(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Galerij Geweigerd');
        (new ProductRepository())->updateGalleryTransition($productId, 'slide');

        foreach (['zoom-spin', 'Fade', ' slide'] as $posted) {
            $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
                'price' => '55',
                'gallery_transition' => $posted,
            ]), [], self::JSON);

            $this->assertSame(422, $response['status'], $posted);
            $body = json_decode($response['body'], true);
            $this->assertFalse($body['ok']);
            $this->assertSame(['Kies Geen, Vervagen of Schuiven als overgang.'], $body['errors']['gallery_transition']);
            $this->assertSame('slide', $this->stored($productId), 'the refused save changed nothing');
            $this->assertSame('10.00', (string) (new ProductRepository())->findByIdForAdmin($productId)['price'], 'not even the valid price beside it');
        }
    }

    public function testASaveWithoutTheFieldLeavesTheChoiceAlone(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Galerij Zonder Veld');
        (new ProductRepository())->updateGalleryTransition($productId, 'fade');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, ['price' => '12']), [], self::JSON);

        $this->assertSame(200, $response['status']);
        $this->assertSame('fade', $this->stored($productId));
        $this->assertSame('12.00', (string) (new ProductRepository())->findByIdForAdmin($productId)['price']);
    }

    public function testARolledBackSaveLeavesTheChoiceAsItWas(): void
    {
        $db = Database::connection();
        $productId = $this->product('ZZ Galerij Terugdraaien');
        $repository = new ProductRepository($db);
        $repository->updateGalleryTransition($productId, 'none');

        $db->beginTransaction();
        $repository->updateGalleryTransition($productId, 'slide');
        $this->assertSame('slide', $repository->findGalleryTransition($productId), 'written inside the transaction');
        $db->rollBack();

        $this->assertSame('none', $repository->findGalleryTransition($productId));

        // The repository stores a word off the list as NULL, never as itself.
        $repository->updateGalleryTransition($productId, 'flip');
        $this->assertNull($repository->findGalleryTransition($productId));
    }

    public function testANewProductFollowsTheShopUnlessTheEditorChose(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        foreach (['zz-galerij-nieuw-zonder' => null, 'zz-galerij-nieuw-standaard' => '', 'zz-galerij-nieuw-vervagen' => 'fade'] as $slug => $posted) {
            $fields = ['csrf_token' => $csrf, 'name' => str_replace('-', ' ', $slug), 'price' => '9', 'active' => '1', 'in_shop' => '1', 'shipping_profile' => 'letter', 'shipping_weight_grams' => '20'];
            if ($posted !== null) {
                $fields['gallery_transition'] = $posted;
            }

            $response = self::$server->request('POST', '/api/admin/create-product.php', $session, $fields, [], self::JSON);
            $this->assertSame(200, $response['status'], $slug);

            $id = (int) Database::connection()->query("SELECT id FROM products WHERE slug = '" . $slug . "'")->fetchColumn();
            $this->productIds[] = $id;
            $this->assertSame($posted === 'fade' ? 'fade' : null, $this->stored($id), $slug);
        }

        $refused = self::$server->request('POST', '/api/admin/create-product.php', $session, [
            'csrf_token' => $csrf, 'name' => 'zz galerij nieuw fout', 'price' => '9', 'shipping_profile' => 'letter', 'shipping_weight_grams' => '20', 'gallery_transition' => 'spin',
        ], [], self::JSON);
        $this->assertSame(422, $refused['status']);
        $this->assertArrayHasKey('gallery_transition', json_decode($refused['body'], true)['errors']);
        $this->assertFalse(Database::connection()->query("SELECT id FROM products WHERE slug = 'zz-galerij-nieuw-fout'")->fetchColumn());
    }

    public function testThePageFollowsTheShopUntilTheProductChooses(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $follows = $this->product('ZZ Galerij Volgt');
        $ownFade = $this->product('ZZ Galerij Eigen');
        (new ProductRepository())->updateGalleryTransition($ownFade, 'fade');

        $this->saveShopDefault($session, $csrf, 'fade');
        $this->assertSame('fade', $this->onThePage($follows));
        $this->assertSame('fade', $this->onThePage($ownFade));

        $this->saveShopDefault($session, $csrf, 'slide');
        $this->assertSame('slide', $this->onThePage($follows), 'a product on "Standaard van Shop" changes with the Shop');
        $this->assertSame('fade', $this->onThePage($ownFade), 'a product with its own choice keeps it');

        $this->saveShopDefault($session, $csrf, 'none');
        $this->assertSame('none', $this->onThePage($follows));
        $this->assertSame('fade', $this->onThePage($ownFade));

        // Values that should never be stored still render one of the three words.
        Database::connection()->prepare('UPDATE products SET gallery_transition = :v WHERE id = :id')->execute(['v' => 'zz"q="1', 'id' => $ownFade]);
        $this->assertSame('none', $this->onThePage($ownFade), 'an unknown product value follows the Shop');
        $this->shopDefault('kaleidoscope');
        $this->assertSame('fade', $this->onThePage($follows), 'an unknown Shop value is the fallback');
        $this->assertStringNotContainsString('zz"q', self::$server->request('GET', '/product.php?id=' . $ownFade)['body']);
    }

    public function testShopSettingsStoresTheDefaultAndRefusesAnythingElse(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        foreach (['none', 'fade', 'slide'] as $word) {
            $response = $this->saveShopDefault($session, $csrf, $word);
            $this->assertSame('/admin/shop-settings.php?saved=1&section=productpagina', $response['location'], $word);
            $this->assertSame($word, $this->storedShopDefault(), $word);
        }

        $refused = self::$server->request('POST', '/api/admin/update-shop-settings.php', $session, [
            'csrf_token' => $csrf, 'section' => 'productpagina', self::KEY => 'flip',
        ]);
        $this->assertSame(302, $refused['status']);
        $this->assertSame('/admin/shop-settings.php', $refused['location']);
        $this->assertSame(['Kies Geen, Vervagen of Schuiven als overgang.'], $this->accounts->read($session, 'admin_shop_settings_errors'));
        $this->assertSame('slide', $this->storedShopDefault(), 'the refused word was not stored');

        $screen = self::$server->request('GET', '/admin/shop-settings.php', $session);
        $this->assertSame(200, $screen['status']);
        $this->assertMatchesRegularExpression('/<select class="admin-select" id="shop-gallery-transition" name="shop_gallery_transition">\s*<option value="none">Geen<\/option>\s*<option value="fade">Vervagen<\/option>\s*<option value="slide" selected>Schuiven<\/option>/', $screen['body']);
    }

    public function testTheEditorShowsTheChoiceInAfbeeldingen(): void
    {
        [$session] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $this->shopDefault('slide');
        $follows = $this->product('ZZ Galerij Scherm');
        $own = $this->product('ZZ Galerij Scherm Eigen');
        (new ProductRepository())->updateGalleryTransition($own, 'none');

        $html = self::$server->request('GET', '/admin/product-form.php?id=' . $follows, $session)['body'];
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)/', $html);

        $images = $this->between($html, 'data-admin-editor-section="images"', 'data-admin-editor-section="variants"');
        $region = $this->between($images, 'data-admin-editor-region="images"', 'id="product-gallery-transition"');
        $this->assertStringContainsString('</div>', $region, 'outside the region the server draws again');
        $this->assertMatchesRegularExpression(
            '/<select class="admin-select" id="product-gallery-transition" name="gallery_transition">\s*<option value="" selected>Standaard van Shop \(Schuiven\)<\/option>\s*<option value="none">Geen<\/option>\s*<option value="fade">Vervagen<\/option>\s*<option value="slide">Schuiven<\/option>/',
            $images,
            'the Shop default is named in the inherit option, and selected'
        );
        // An ordinary control of the one form: a change makes the editor dirty.
        $this->assertStringNotContainsString('data-no-dirty-track', $this->between($images, 'id="product-gallery-transition"', '</select>'));
        $this->assertStringContainsString('Overgang productgalerij', $images);

        $ownHtml = self::$server->request('GET', '/admin/product-form.php?id=' . $own, $session)['body'];
        $this->assertStringContainsString('<option value="none" selected>Geen</option>', $ownHtml);
        $this->assertStringContainsString('<option value="">Standaard van Shop (Schuiven)</option>', $ownHtml);

        $new = self::$server->request('GET', '/admin/product-form.php', $session)['body'];
        $this->assertStringContainsString('<option value="" selected>Standaard van Shop (Schuiven)</option>', $new, 'a new product starts following the Shop');
    }

    /** @return array{status: int, body: string, location: ?string} */
    private function saveShopDefault(string $session, string $csrf, string $word): array
    {
        $response = self::$server->request('POST', '/api/admin/update-shop-settings.php', $session, [
            'csrf_token' => $csrf, 'section' => 'productpagina', self::KEY => $word,
        ]);
        $this->assertSame(302, $response['status'], $word);

        return $response;
    }

    private function shopDefault(string $value): void
    {
        Database::connection()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (:key, :value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute(['key' => self::KEY, 'value' => $value]);
    }

    private function storedShopDefault(): string|false
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $stmt->execute(['key' => self::KEY]);

        return $stmt->fetchColumn();
    }

    private function stored(int $productId): ?string
    {
        return (new ProductRepository())->findGalleryTransition($productId);
    }

    private function onThePage(int $productId): string
    {
        $page = self::$server->request('GET', '/product.php?id=' . $productId);
        $this->assertSame(200, $page['status']);
        $this->assertSame(1, preg_match('/<div class="product-detail__gallery" data-product-gallery data-gallery-transition="([a-z]+)">/', $page['body'], $match), 'one resolved word on the gallery');

        return $match[1];
    }

    /** @param array<string, mixed> $overrides */
    private function fields(int $productId, string $csrf, array $overrides): array
    {
        return $overrides + [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'nl',
            'name' => 'ZZ Galerij',
            'description' => '',
            'price' => '10',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ];
    }

    private function product(string $name): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_gallery_transition_' . bin2hex(random_bytes(4)),
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name]);
        $this->productIds[] = $id;

        return $id;
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, $from);
        $end = strpos($html, $to, (int) $start);
        $this->assertNotFalse($end, $to);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
