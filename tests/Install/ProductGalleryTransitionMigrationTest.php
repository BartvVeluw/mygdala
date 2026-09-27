<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Product Gallery 2.0's migration (20260927100000) on a database built from
 * zero and on one upgraded from the migration before it, with two products
 * and their pictures seeded first:
 *
 *   zz-pg2-pictures  three pictures, the second one primary
 *   zz-pg2-bare      no pictures, inactive
 *
 * The migration only ADDS products.gallery_transition, nullable: every
 * product follows the Shop's default (NULL), and nothing a product already
 * had changes — not its row, not its pictures, their order or which one is
 * primary. No Shop default row is written either: App\Service\SiteSettings
 * falls back to 'fade', what the gallery already did. Both installs end on
 * the same schema, and running it again changes nothing.
 */
#[Group('migration-backfill')]
final class ProductGalleryTransitionMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_pg2_fresh';
    private const UPGRADED = 'mygdala_scratch_pg2_upgraded';

    /** The last migration before Product Gallery 2.0. */
    private const BEFORE = '20260926130000';

    private const MIGRATION = '20260927100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $productsBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $picturesBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $afterFirstRun = [];

    /** @var list<array<string, mixed>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$productsBefore = self::$upgraded->rows("SELECT * FROM products WHERE slug LIKE 'zz-pg2-%' ORDER BY id");
        self::$picturesBefore = self::$upgraded->rows('SELECT * FROM product_images ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::$upgraded->rows('SELECT * FROM products ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::$upgraded->rows('SELECT * FROM products ORDER BY id');
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
        self::$fresh = null;
        self::$upgraded = null;
    }

    public function testTheColumnIsANullableWordThatDefaultsToFollowingTheShop(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(
                [['type' => 'varchar(10)', 'nullable' => 'YES', 'default' => null]],
                $install->rows(
                    "SELECT column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'gallery_transition'"
                )
            );
        }
    }

    public function testEveryExistingProductFollowsTheShopAndKeepsEverythingElse(): void
    {
        self::assertCount(2, self::$productsBefore);

        $after = self::$upgraded->rows("SELECT * FROM products WHERE slug LIKE 'zz-pg2-%' ORDER BY id");
        foreach ($after as $i => $row) {
            self::assertNull($row['gallery_transition'], $row['slug']);
            // Compared on the columns the row had before: the new one is the only difference.
            self::assertSame(self::$productsBefore[$i], array_intersect_key($row, self::$productsBefore[$i]), $row['slug']);
        }
    }

    public function testNoPictureOrderOrPrimaryPictureChanges(): void
    {
        self::assertCount(3, self::$picturesBefore);
        self::assertSame(self::$picturesBefore, self::$upgraded->rows('SELECT * FROM product_images ORDER BY id'));
    }

    public function testNoShopDefaultIsWritten(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame([], $install->rows("SELECT setting_value FROM site_settings WHERE setting_key = 'shop_gallery_transition'"));
        }
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertNotSame([], self::$afterFirstRun);
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $product = $pdo->prepare(
            "INSERT INTO products (slug, price, image_path, active, in_shop, in_personalization_catalog, shipping_profile, shipping_weight_grams, requires_parcel, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 0, 'parcel', 250, 1, '2026-09-01 10:00:00', '2026-09-01 10:00:00')"
        );
        $picture = $pdo->prepare(
            "INSERT INTO product_images (product_id, image_path, sort_order, is_primary, created_at, updated_at)
             VALUES (?, ?, ?, ?, '2026-09-01 10:00:00', '2026-09-01 10:00:00')"
        );

        $product->execute(['zz-pg2-pictures', '24.95', 'assets/images/products/b.jpg', 1]);
        $withPictures = (int) $pdo->lastInsertId();
        foreach ([['a.jpg', 1, 0], ['b.jpg', 2, 1], ['c.jpg', 3, 0]] as [$file, $order, $primary]) {
            $picture->execute([$withPictures, 'assets/images/products/' . $file, $order, $primary]);
        }

        $product->execute(['zz-pg2-bare', '5.00', null, 0]);
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'products' ORDER BY ordinal_position"
        );
    }
}
