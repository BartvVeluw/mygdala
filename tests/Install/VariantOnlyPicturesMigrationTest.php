<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The variant-only pictures migration (20261016100000) on a database built
 * from zero and on one upgraded from the migration before it, with a product
 * seeded first that has three pictures, two variants and links from both
 * variants (one picture linked twice):
 *
 *   - every existing picture becomes general (variant_only = 0), also the
 *     ones a variant links to: nothing is inferred from the links;
 *   - no column the rows had changes, and the links stay exactly as they were;
 *   - both installs end on the same schema, and running it again changes
 *     nothing.
 */
#[Group('migration-backfill')]
final class VariantOnlyPicturesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_variant_only_fresh';
    private const UPGRADED = 'mygdala_scratch_variant_only_upgraded';

    /** The last migration before the variant-only pictures. */
    private const BEFORE = '20261015110000';

    private const MIGRATION = '20261016100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $before = [];

    /** @var list<array<string, mixed>> */
    private static array $linksBefore = [];

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
        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO products (slug, price, image_path, active, created_at, updated_at) VALUES ('zz-variant-only', 10.00, 'assets/images/products/a.webp', 1, NOW(), NOW())");
        $product = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO product_images (product_id, image_path, sort_order, is_primary, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $images = [];
        foreach (['a', 'b', 'c'] as $position => $name) {
            $insert->execute([$product, 'assets/images/products/' . $name . '.webp', $position, $position === 0 ? 1 : 0]);
            $images[] = (int) $pdo->lastInsertId();
        }
        $variant = $pdo->prepare('INSERT INTO product_variants (product_id, price, active, sort_order, created_at, updated_at) VALUES (?, NULL, 1, ?, NOW(), NOW())');
        $variant->execute([$product, 0]);
        $red = (int) $pdo->lastInsertId();
        $variant->execute([$product, 1]);
        $blue = (int) $pdo->lastInsertId();
        $link = $pdo->prepare('INSERT INTO product_variant_images (variant_id, product_image_id, sort_order, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        $link->execute([$red, $images[1], 0]);
        $link->execute([$red, $images[2], 1]);
        $link->execute([$blue, $images[2], 0]);

        self::$before = self::$upgraded->rows('SELECT * FROM product_images ORDER BY id');
        self::$linksBefore = self::$upgraded->rows('SELECT * FROM product_variant_images ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::$upgraded->rows('SELECT * FROM product_images ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::$upgraded->rows('SELECT * FROM product_images ORDER BY id');
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

    public function testEveryExistingPictureStaysGeneralEvenWhenAVariantLinksToIt(): void
    {
        self::assertSame([0, 0, 0], array_map(static fn (array $row): int => (int) $row['variant_only'], self::$afterFirstRun));
    }

    public function testNoColumnTheRowsHadChangesAndTheLinksStay(): void
    {
        // Compared on the columns the rows had before (a new column is not a change).
        $columns = array_keys(self::$before[0]);
        $after = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($columns)), self::$afterFirstRun);

        self::assertSame(self::$before, $after);
        self::assertCount(3, self::$linksBefore);
        self::assertSame(self::$linksBefore, self::$upgraded->rows('SELECT * FROM product_variant_images ORDER BY id'));
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertCount(3, self::$afterFirstRun);
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
        self::assertContains(
            ['name' => 'variant_only', 'type' => 'tinyint(1)', 'nullable' => 'NO', 'default' => '0'],
            self::shape(self::$fresh)
        );
    }

    public function testANewRowIsGeneralByDefault(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO products (slug, price, image_path, active, created_at, updated_at) VALUES ('zz-variant-only-new', 10.00, NULL, 1, NOW(), NOW())");
        $product = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO product_images (product_id, image_path, sort_order, is_primary) VALUES (?, ?, 0, 1)')->execute([$product, 'assets/images/products/new.webp']);

        self::assertSame([['variant_only' => 0]], array_map(
            static fn (array $row): array => ['variant_only' => (int) $row['variant_only']],
            self::$fresh->rows('SELECT variant_only FROM product_images WHERE product_id = ' . $product)
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'product_images' ORDER BY ordinal_position"
        );
    }
}
