<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Product Gallery 2.1's lightbox switch over real HTTP, against PHP's
 * built-in server with the Shop on:
 *
 *  - without the setting (no row, '0', or anything but '1') the product page
 *    is what it was: the gallery's exact markup, no lightbox script and no
 *    overlay;
 *  - with '1' the gallery carries data-gallery-lightbox, the page asks for
 *    assets/js/lightbox.js before the gallery's own script, and prints the
 *    shared overlay once, after <main>;
 *  - Shop-instellingen → Productpagina stores '1' and '0' and refuses
 *    anything else, and draws the switch as stored.
 *
 * Its products, accounts and the stored setting are its own and are put back
 * in tearDown(). Without a server the test skips itself.
 */
final class ProductGalleryLightboxHttpTest extends TestCase
{
    private const KEY = 'shop_gallery_lightbox';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $productIds = [];

    /** The stored setting before this test: false when there was no row. */
    private string|false $stored = false;

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

        $this->stored = $this->storedValue();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        if ($this->stored === false) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => self::KEY]);
        } else {
            $this->store((string) $this->stored);
        }

        $this->accounts->forget();
        $this->productIds = [];
        SiteSettings::clearCache();
        ShopLocalization::clearCache();
    }

    public function testWithoutTheSettingTheProductPageIsWhatItWas(): void
    {
        $productId = $this->product('ZZ Lichtbak Uit');

        Database::connection()->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => self::KEY]);
        $this->assertOff($productId, 'no row');

        foreach (['0', '', 'yes', 'true', ' 1'] as $value) {
            $this->store($value);
            $this->assertOff($productId, var_export($value, true));
        }
    }

    public function testWithTheSettingOnThePageOpensTheSharedLightbox(): void
    {
        $productId = $this->product('ZZ Lichtbak Aan');
        $this->store('1');

        $body = $this->page($productId);

        $this->assertMatchesRegularExpression('/<div class="product-detail__gallery" data-product-gallery data-gallery-transition="[a-z]+" data-gallery-lightbox>/', $body);
        $this->assertSame(1, preg_match_all('#<script src="/assets/js/lightbox\.js[^"]*"#', $body), 'the site\'s one lightbox script, once');
        $this->assertLessThan(
            (int) strpos($body, 'assets/js/shop/product-gallery.js'),
            (int) strpos($body, 'assets/js/lightbox.js'),
            'the lightbox starts before the gallery'
        );
        $this->assertSame(1, substr_count($body, '<div class="lightbox" data-lightbox '), 'one overlay');
        $this->assertGreaterThan((int) strpos($body, '</main>'), (int) strpos($body, '<div class="lightbox" data-lightbox '), 'outside every section');
        $this->assertStringContainsString('aria-label="Vergrote afbeelding"', $body, 'the overlay in the page\'s language');
    }

    public function testShopSettingsStoresOnAndOffAndRefusesAnythingElse(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        foreach (['1', '0', '1'] as $value) {
            $response = self::$server->request('POST', '/api/admin/update-shop-settings.php', $session, [
                'csrf_token' => $csrf, 'section' => 'productpagina', 'shop_gallery_transition' => 'fade', self::KEY => $value,
            ]);
            $this->assertSame(302, $response['status'], $value);
            $this->assertSame('/admin/shop-settings.php?saved=1&section=productpagina', $response['location'], $value);
            $this->assertSame($value, $this->storedValue(), $value);
        }

        $refused = self::$server->request('POST', '/api/admin/update-shop-settings.php', $session, [
            'csrf_token' => $csrf, 'section' => 'productpagina', self::KEY => 'on',
        ]);
        $this->assertSame(302, $refused['status']);
        $this->assertSame('/admin/shop-settings.php', $refused['location']);
        $this->assertSame(['Kies aan of uit voor de lightbox.'], $this->accounts->read($session, 'admin_shop_settings_errors'));
        $this->assertSame('1', $this->storedValue(), 'the refused value was not stored');

        $screen = self::$server->request('GET', '/admin/shop-settings.php', $session);
        $this->assertSame(200, $screen['status']);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="shop_gallery_lightbox" value="0">\s*<label class="admin-checkbox-label">\s*<input type="checkbox" class="admin-switch" role="switch" id="shop-gallery-lightbox" name="shop_gallery_lightbox" value="1" checked>/', $screen['body']);

        $this->store('0');
        $off = self::$server->request('GET', '/admin/shop-settings.php', $session)['body'];
        $this->assertStringContainsString('name="shop_gallery_lightbox" value="1">', $off, 'unticked when off');
    }

    private function assertOff(int $productId, string $case): void
    {
        $body = $this->page($productId);

        $this->assertMatchesRegularExpression('/<div class="product-detail__gallery" data-product-gallery data-gallery-transition="[a-z]+">/', $body, $case);
        $this->assertStringNotContainsString('data-gallery-lightbox', $body, $case);
        $this->assertStringNotContainsString('assets/js/lightbox.js', $body, $case);
        $this->assertStringNotContainsString('data-lightbox', $body, $case);
    }

    private function page(int $productId): string
    {
        SiteSettings::clearCache();
        $page = self::$server->request('GET', '/product.php?id=' . $productId);
        $this->assertSame(200, $page['status']);
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)/', $page['body']);

        return $page['body'];
    }

    private function store(string $value): void
    {
        Database::connection()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (:key, :value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute(['key' => self::KEY, 'value' => $value]);
    }

    private function storedValue(): string|false
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $stmt->execute(['key' => self::KEY]);

        return $stmt->fetchColumn();
    }

    private function product(string $name): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_gallery_lightbox_' . bin2hex(random_bytes(4)),
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
}
