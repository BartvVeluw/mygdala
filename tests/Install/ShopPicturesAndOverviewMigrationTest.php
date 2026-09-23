<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The three migrations of the Shop UX phase, run the way a real installation
 * runs them:
 *
 *   20260923120000  product_images/collections get media_id, variants link to
 *                   their product's pictures (product_variant_images), and
 *                   every old variant picture is backfilled into that shape;
 *   20260923130000  product_variant_translations (a variant's own text);
 *   20260923140000  the pin that keeps an existing storefront where it is.
 *
 * An "existing" database is built up to the migration before these, filled
 * the way the old admin filled it (a product with a picture of its own and
 * two variants with uploaded pictures), and then caught up. A fresh one is
 * built from zero. Each migration is replayed once to prove it adds nothing
 * the second time.
 */
#[Group('migration-backfill')]
final class ShopPicturesAndOverviewMigrationTest extends TestCase
{
    private const EXISTING = 'mygdala_scratch_shop_ux_existing';
    private const BUILTIN = 'mygdala_scratch_shop_ux_builtin';
    private const FRESH = 'mygdala_scratch_shop_ux_fresh';

    private const BEFORE = '20260923100000';
    private const PICTURES = '20260923120000';
    private const WORDS = '20260923130000';
    private const PIN = '20260923140000';

    private static ?ScratchInstall $existing = null;
    private static ?ScratchInstall $builtin = null;
    private static ?ScratchInstall $fresh = null;

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var array<string, int> */
    private static array $countsAfterFirstRun = [];

    /** @var array<string, int> */
    private static array $countsAfterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        // An existing installation WITH a storefront page, and pictures on
        // variants the old way.
        self::$existing = ScratchInstall::upTo(self::EXISTING, self::BEFORE);
        self::markLegacy(self::$existing);
        self::$ids = self::seed(self::$existing);
        self::$existing->catchUp(self::PIN);
        self::$countsAfterFirstRun = self::counts(self::$existing);
        self::$existing->replay(self::PICTURES, self::PIN);
        self::$existing->replay(self::WORDS, self::PIN);
        self::$existing->replay(self::PIN, self::PIN);
        self::$countsAfterReplay = self::counts(self::$existing);

        // An existing installation WITHOUT a storefront page: it showed the
        // automatic listing, and keeps it.
        self::$builtin = ScratchInstall::upTo(self::BUILTIN, self::BEFORE);
        self::markLegacy(self::$builtin);
        self::$builtin->pdo()->exec("DELETE FROM pages WHERE content_key = 'shop'");
        self::$builtin->catchUp(self::PIN);

        self::$fresh = ScratchInstall::fresh(self::FRESH);
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$existing?->drop();
        self::$builtin?->drop();
        self::$fresh?->drop();
        self::$existing = self::$builtin = self::$fresh = null;
    }

    /* ------------------------------------------------------------ schema */

    public function testBothKindsOfDatabaseEndWithTheSameNewSchema(): void
    {
        foreach ([self::$existing, self::$fresh] as $install) {
            $this->assertTrue($install->hasTable('product_variant_images'), $install->database);
            $this->assertTrue($install->hasTable('product_variant_translations'), $install->database);
            $this->assertContains('media_id', array_column($install->rows('SHOW COLUMNS FROM product_images'), 'Field'));
            $this->assertContains('media_id', array_column($install->rows('SHOW COLUMNS FROM collections'), 'Field'));
        }
    }

    /* ---------------------------------------------------------- backfill */

    public function testEveryOldVariantPictureIsNowAProductPictureLinkedInTheSameOrder(): void
    {
        $install = self::$existing;
        $productId = self::$ids['product'];

        $paths = array_column($install->rows(
            'SELECT image_path FROM product_images WHERE product_id = ? ORDER BY sort_order, id',
            [$productId]
        ), 'image_path');

        // The product's own picture first, then the variants' pictures in
        // variant order, each path once (the shared one is not doubled).
        $this->assertSame([
            'assets/images/products/own.webp',
            'assets/images/products/red-1.webp',
            'assets/images/products/shared.webp',
            'assets/images/products/blue-1.webp',
        ], $paths);

        $this->assertSame(
            ['assets/images/products/red-1.webp', 'assets/images/products/shared.webp'],
            self::variantPaths($install, self::$ids['red'])
        );
        $this->assertSame(
            ['assets/images/products/shared.webp', 'assets/images/products/blue-1.webp'],
            self::variantPaths($install, self::$ids['blue'])
        );
    }

    public function testThePrimaryPictureAndTheOldRowsAreLeftAlone(): void
    {
        $install = self::$existing;

        $primary = $install->rows(
            'SELECT image_path FROM product_images WHERE product_id = ? AND is_primary = 1',
            [self::$ids['product']]
        );
        $this->assertSame([['image_path' => 'assets/images/products/own.webp']], $primary);
        $this->assertSame(5, $install->count('variant_images'), 'the old table is kept as it was: all five seeded rows');
    }

    public function testAProductWhosePicturesWereOnlyOnVariantsGetsAPrimaryOne(): void
    {
        $install = self::$existing;

        $rows = $install->rows(
            'SELECT image_path, is_primary FROM product_images WHERE product_id = ? ORDER BY sort_order',
            [self::$ids['variant_only']]
        );

        $this->assertSame([['image_path' => 'assets/images/products/only.webp', 'is_primary' => 1]], array_map(
            static fn (array $r): array => ['image_path' => $r['image_path'], 'is_primary' => (int) $r['is_primary']],
            $rows
        ));
        $this->assertSame(
            'assets/images/products/only.webp',
            $install->rows('SELECT image_path FROM products WHERE id = ?', [self::$ids['variant_only']])[0]['image_path']
        );
    }

    public function testReplayingAddsNothing(): void
    {
        $this->assertSame(self::$countsAfterFirstRun, self::$countsAfterReplay);
    }

    public function testNoVariantGetsACopyOfTheProductsText(): void
    {
        $this->assertSame(0, self::$existing->count('product_variant_translations'));
    }

    /* --------------------------------------------------------------- pin */

    public function testAnExistingSiteWithAStorefrontPageKeepsThatPage(): void
    {
        $page = self::$existing->rows("SELECT id FROM pages WHERE content_key = 'shop'")[0] ?? null;
        $this->assertNotNull($page, 'the historical seed made a storefront page here');

        $this->assertSame((string) (int) $page['id'], self::setting(self::$existing));
    }

    public function testAnExistingSiteWithoutOneKeepsTheAutomaticListing(): void
    {
        $this->assertSame('builtin', self::setting(self::$builtin));
    }

    public function testAFreshInstallationStartsWithoutAnOverview(): void
    {
        $this->assertNull(self::setting(self::$fresh));
    }

    /* ----------------------------------------------------------- helpers */

    private static function markLegacy(ScratchInstall $install): void
    {
        $install->pdo()->exec(
            "UPDATE install_state SET state_value = 'legacy_existing_site' WHERE state_key = 'install_kind'"
        );
    }

    /** @return array<string, int> */
    private static function seed(ScratchInstall $install): array
    {
        $pdo = $install->pdo();
        $pdo->exec("INSERT IGNORE INTO pages (content_key, slug, status, created_at, updated_at) VALUES ('shop', 'shop', 'published', NOW(), NOW())");

        $product = self::product($pdo, 'zz-ux-product', 'assets/images/products/own.webp');
        $pdo->prepare('INSERT INTO product_images (product_id, image_path, sort_order, is_primary) VALUES (?, ?, 0, 1)')
            ->execute([$product, 'assets/images/products/own.webp']);

        $red = self::variant($pdo, $product, 0);
        $blue = self::variant($pdo, $product, 1);
        self::variantPicture($pdo, $red, 'assets/images/products/red-1.webp', 0);
        self::variantPicture($pdo, $red, 'assets/images/products/shared.webp', 1);
        self::variantPicture($pdo, $blue, 'assets/images/products/shared.webp', 0);
        self::variantPicture($pdo, $blue, 'assets/images/products/blue-1.webp', 1);

        $only = self::product($pdo, 'zz-ux-variant-only', null);
        self::variantPicture($pdo, self::variant($pdo, $only, 0), 'assets/images/products/only.webp', 0);

        return ['product' => $product, 'red' => $red, 'blue' => $blue, 'variant_only' => $only];
    }

    private static function product(\PDO $pdo, string $slug, ?string $imagePath): int
    {
        $pdo->prepare(
            "INSERT INTO products (slug, price, image_path, active, created_at, updated_at) VALUES (?, 10.00, ?, 1, NOW(), NOW())"
        )->execute([$slug, $imagePath]);

        return (int) $pdo->lastInsertId();
    }

    private static function variant(\PDO $pdo, int $productId, int $sortOrder): int
    {
        $pdo->prepare('INSERT INTO product_variants (product_id, price, active, sort_order, created_at, updated_at) VALUES (?, NULL, 1, ?, NOW(), NOW())')
            ->execute([$productId, $sortOrder]);

        return (int) $pdo->lastInsertId();
    }

    private static function variantPicture(\PDO $pdo, int $variantId, string $path, int $sortOrder): void
    {
        $pdo->prepare('INSERT INTO variant_images (variant_id, image_path, sort_order, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
            ->execute([$variantId, $path, $sortOrder]);
    }

    /** @return list<string> */
    private static function variantPaths(ScratchInstall $install, int $variantId): array
    {
        return array_column($install->rows(
            'SELECT pi.image_path FROM product_variant_images pvi
             JOIN product_images pi ON pi.id = pvi.product_image_id
             WHERE pvi.variant_id = ? ORDER BY pvi.sort_order',
            [$variantId]
        ), 'image_path');
    }

    /** @return array<string, int> */
    private static function counts(ScratchInstall $install): array
    {
        return [
            'product_images' => $install->count('product_images'),
            'product_variant_images' => $install->count('product_variant_images'),
            'product_variant_translations' => $install->count('product_variant_translations'),
            'site_settings' => $install->count('site_settings'),
        ];
    }

    private static function setting(ScratchInstall $install): ?string
    {
        $rows = $install->rows("SELECT setting_value FROM site_settings WHERE setting_key = 'shop_overview'");

        return $rows === [] ? null : (string) $rows[0]['setting_value'];
    }
}
