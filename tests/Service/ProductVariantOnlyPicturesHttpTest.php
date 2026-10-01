<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\MediaRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantImageRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Media\MediaService;
use App\Service\ProductGallery;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Pictures meant for variants only through the product editor's one save
 * (api/admin/update-product.php, admin/product-form.php), over real HTTP with
 * the Shop on (v0.1.15 phase 12.1):
 *
 *  - the guards come first: no session, no permission or a wrong CSRF token
 *    changes no flag;
 *  - the two lists decide (`gallery[]`, `gallery_variant_only[]` with its
 *    marker), and every token is checked against THIS product: another
 *    product's picture or variant, an unknown id, a forged product context —
 *    all ignored;
 *  - a variant-only picture is never primary, whatever is posted;
 *  - the screen shows the general pictures in Productafbeeldingen and the
 *    variant-only ones in their own list in Varianten, and a refused save
 *    shows both lists as they were sent.
 *
 * Its products, media rows and accounts are its own and are removed in
 * tearDown(). Without a server the test skips itself.
 */
final class ProductVariantOnlyPicturesHttpTest extends TestCase
{
    private const JSON = ['Accept: application/json'];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

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
            $db->prepare('DELETE FROM product_variants WHERE product_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->mediaIds as $id) {
            $db->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        }

        $this->accounts->forget();
        $this->productIds = [];
        $this->mediaIds = [];
        MediaService::clearCache();
        ShopLocalization::clearCache();
    }

    public function testTheSaveStoresBothListsAndTheLinks(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        [$productId, $ids, $variant] = $this->product('ZZ Variantbeeld Opslaan');
        $newMedia = $this->media('new');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'gallery' => ['image:' . $ids[1], 'image:' . $ids[0]],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[2], 'media:' . $newMedia],
            'variants_submitted' => [(string) $variant],
            'variant_images' => [(string) $variant => ['media:' . $newMedia, 'image:' . $ids[1]]],
        ]), [], self::JSON);

        $this->assertSame(200, $response['status'], $response['body']);
        $images = new ProductImageRepository();
        $this->assertSame([$ids[1], $ids[0]], $this->idsOf($images->findByProductId($productId)));
        $pool = $this->idsOf($images->findPoolByProductId($productId));
        $this->assertCount(4, $pool);
        $added = $pool[3];
        $this->assertSame([$added, $ids[1]], $this->idsOf((new ProductVariantImageRepository())->findByVariantIds([$variant])[$variant] ?? []));
        $this->assertSame(1, $this->flag($added, 'variant_only'), 'a new picture sent in the variants-only list is variant-only');
        $this->assertSame(1, $this->flag($ids[2], 'variant_only'));
        $this->assertSame(1, $this->flag($ids[1], 'is_primary'));
    }

    public function testAVariantOnlyPictureCannotBeForcedToBePrimary(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        [$productId, $ids] = $this->product('ZZ Variantbeeld Hoofdfoto');

        // The same picture in both lists: it is general, and the first general one leads.
        self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'gallery' => ['image:' . $ids[1]],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[0], 'image:' . $ids[1], 'image:' . $ids[2]],
            'is_primary' => (string) $ids[0],
            'primary' => 'image:' . $ids[0],
        ]), [], self::JSON);

        $this->assertSame([1, 0], [$this->flag($ids[1], 'is_primary'), $this->flag($ids[1], 'variant_only')]);
        $this->assertSame([0, 1], [$this->flag($ids[0], 'is_primary'), $this->flag($ids[0], 'variant_only')]);
        $this->assertSame([0, 1], [$this->flag($ids[2], 'is_primary'), $this->flag($ids[2], 'variant_only')]);

        // Only variant-only pictures left: none is primary.
        self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'gallery' => [],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[0], 'image:' . $ids[1], 'image:' . $ids[2]],
        ]), [], self::JSON);
        $this->assertNull((new ProductImageRepository())->findPrimary($productId));
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM product_images WHERE product_id = ' . $productId . ' AND is_primary = 1')->fetchColumn());
    }

    public function testForeignAndUnknownIdsChangeNothing(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        [$productId, $ids, $variant] = $this->product('ZZ Variantbeeld Eigen');
        [$otherId, $otherIds, $otherVariant] = $this->product('ZZ Variantbeeld Ander');

        // In THIS product's context: the other product's picture and variant,
        // and ids that do not exist.
        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'gallery' => ['image:' . $ids[0], 'image:' . $ids[1], 'image:' . $ids[2]],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $otherIds[0], 'image:999999999', 'media:999999999'],
            'variants_submitted' => [(string) $variant, (string) $otherVariant],
            'variant_images' => [
                (string) $variant => ['image:' . $otherIds[1]],
                (string) $otherVariant => ['image:' . $ids[0]],
            ],
        ]), [], self::JSON);
        $this->assertSame(200, $response['status'], $response['body']);

        $this->assertSame($ids, $this->idsOf((new ProductImageRepository())->findPoolByProductId($productId)), 'no foreign row joined this product');
        $this->assertSame($otherIds, $this->idsOf((new ProductImageRepository())->findByProductId($otherId)), 'the other product keeps its general pictures');
        $this->assertSame(0, $this->flag($otherIds[0], 'variant_only'));
        $this->assertSame([], $this->idsOf((new ProductVariantImageRepository())->findByVariantIds([$variant])[$variant] ?? []));
        $this->assertSame([], $this->idsOf((new ProductVariantImageRepository())->findByVariantIds([$otherVariant])[$otherVariant] ?? []));

        // A forged product context: this product's picture named in the other
        // product's save is not this product's to mark.
        self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($otherId, $csrf, [
            'gallery' => ['image:' . $otherIds[0], 'image:' . $otherIds[1], 'image:' . $otherIds[2]],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[0]],
        ]), [], self::JSON);
        $this->assertSame([0, 1], [$this->flag($ids[0], 'variant_only'), $this->flag($ids[0], 'is_primary')]);

        // An unknown product id is refused before anything is read.
        $unknown = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields(999999999, $csrf, [
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[0]],
        ]), [], self::JSON);
        $this->assertNotSame(200, $unknown['status']);
        $this->assertSame(0, $this->flag($ids[0], 'variant_only'));
    }

    public function testTheGuardsComeFirst(): void
    {
        [$productId, $ids] = $this->product('ZZ Variantbeeld Guards');
        $post = fn (string $csrf): array => $this->fields($productId, $csrf, [
            'gallery' => ['image:' . $ids[1]],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[0]],
        ]);

        [$session] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/update-product.php', $session, $post('wrong'), [], self::JSON)['status']);

        [$viewer, $viewerCsrf] = $this->accounts->signIn([ShopModule::PRODUCTS_VIEW]);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/update-product.php', $viewer, $post($viewerCsrf), [], self::JSON)['status']);

        $this->assertSame(401, self::$server->request('POST', '/api/admin/update-product.php', null, $post('x'), [], self::JSON)['status']);

        $this->assertSame([0, 1], [$this->flag($ids[0], 'variant_only'), $this->flag($ids[0], 'is_primary')]);
    }

    public function testTheScreenKeepsTheTwoListsApart(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        [$productId, $ids, $variant] = $this->product('ZZ Variantbeeld Scherm');
        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids[0], 'image:' . $ids[1]],
            [$variant => ['image:' . $ids[2]]],
            ['image:' . $ids[2]]
        );

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $productId, $session);
        $this->assertSame(200, $page['status']);
        $html = $page['body'];
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)/', $html);

        $images = $this->between($html, 'data-admin-editor-section="images"', 'data-admin-editor-section="variants"');
        $variants = $this->between($html, 'data-admin-editor-section="variants"', 'data-admin-editor-section="specifications"');

        // Productafbeeldingen: the general pictures only, and their count.
        $this->assertStringContainsString('name="gallery[]" value="image:' . $ids[0] . '"', $images);
        $this->assertStringContainsString('name="gallery[]" value="image:' . $ids[1] . '"', $images);
        $this->assertStringNotContainsString('image:' . $ids[2], $images);
        $this->assertStringContainsString('<span data-product-gallery-count>2</span>', $images);

        // Varianten: the variant-only list with its marker, and the tiles offer both kinds.
        $this->assertStringContainsString('data-variant-only-pool', $variants);
        $this->assertStringContainsString('name="gallery_variant_only_submitted" value="1"', $variants);
        $this->assertStringContainsString('name="gallery_variant_only[]" value="image:' . $ids[2] . '"', $variants);
        $this->assertStringNotContainsString('name="gallery_variant_only[]" value="image:' . $ids[0] . '"', $variants);
        $this->assertMatchesRegularExpression('/class="admin-gallery-tile is-chosen is-variant-only" data-token="image:' . $ids[2] . '"/', $variants);
        $this->assertMatchesRegularExpression('/class="admin-gallery-tile" data-token="image:' . $ids[0] . '"/', $variants);
        $this->assertStringContainsString('data-variant-gallery-add', $variants);
        // Linked to a variant, so the "not linked" note is hidden.
        $this->assertMatchesRegularExpression('/data-variant-only-unlinked hidden/', $variants);

        // A refused save shows both lists as they were sent.
        $refused = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'price' => '',
            'gallery' => ['image:' . $ids[2]],
            'gallery_variant_only_submitted' => '1',
            'gallery_variant_only' => ['image:' . $ids[0], 'image:' . $ids[1]],
        ]));
        $this->assertSame(302, $refused['status']);
        $again = self::$server->request('GET', '/admin/product-form.php?id=' . $productId, $session)['body'];
        $images = $this->between($again, 'data-admin-editor-section="images"', 'data-admin-editor-section="variants"');
        $variants = $this->between($again, 'data-admin-editor-section="variants"', 'data-admin-editor-section="specifications"');
        $this->assertStringContainsString('name="gallery[]" value="image:' . $ids[2] . '"', $images);
        $this->assertStringNotContainsString('name="gallery[]" value="image:' . $ids[0] . '"', $images);
        $this->assertStringContainsString('name="gallery_variant_only[]" value="image:' . $ids[0] . '"', $variants);
        $this->assertSame(1, $this->flag($ids[2], 'variant_only'), 'nothing was stored');
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $overrides */
    private function fields(int $productId, string $csrf, array $overrides): array
    {
        return $overrides + [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'nl',
            'name' => 'ZZ Variantbeeld',
            'description' => '',
            'price' => '10',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
            'gallery_submitted' => '1',
        ];
    }

    /**
     * A product with three general pictures and one variant.
     *
     * @return array{0: int, 1: list<int>, 2: int}
     */
    private function product(string $name): array
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_variant_only_http_' . bin2hex(random_bytes(4)),
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

        $options = new ProductOptionRepository();
        $kleur = $options->createOption($id, 'Kleur', 'color');
        $rood = $options->createValue($kleur, 'Rood', '#AA0000');
        $variant = (new ProductVariantRepository())->create($id, [$rood], null, true);

        $key = bin2hex(random_bytes(3));
        (new ProductGallery())->save($id, [
            'media:' . $this->media($key . '-a'),
            'media:' . $this->media($key . '-b'),
            'media:' . $this->media($key . '-c'),
        ]);

        return [$id, $this->idsOf((new ProductImageRepository())->findByProductId($id)), $variant];
    }

    private function media(string $name): int
    {
        $file = '__test_variant_only_http_' . $name . '.png';
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/' . $file,
            'original_filename' => $file,
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => '',
            'checksum' => null,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function flag(int $imageId, string $column): int
    {
        $stmt = Database::connection()->prepare('SELECT ' . $column . ' FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
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
