<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Media Library 2.0's three migrations, on the kinds of database they meet:
 *
 *   20260925120000  media_folders + media.folder_id (ON DELETE SET NULL)
 *   20260925130000  portfolio_gallery_items.media_id (RESTRICT)
 *   20260925140000  products.og_media_id, collections.og_media_id (RESTRICT)
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before them, with a library
 *              item, a Portfolio item on its own old path, and a product
 *              and a collection with an old own share image
 *
 * What must hold: every existing library item is in "Geen map"; nothing old
 * is classified, moved or pointed at the library (every new reference is
 * NULL and every old path column is byte-for-byte what it was); deleting a
 * folder never deletes an item; and a second run of each changes nothing.
 */
#[Group('migration-backfill')]
final class MediaLibraryTwoMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_media_library_2_fresh';
    private const UPGRADED = 'mygdala_scratch_media_library_2_upgraded';

    /** The last migration before these. */
    private const BEFORE = '20260925100000';

    private const FOLDERS = '20260925120000';
    private const PORTFOLIO = '20260925130000';
    private const SHARE = '20260925140000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var array<string, array<string, mixed>> */
    private static array $before = [];

    /** @var array<string, array<string, mixed>> */
    private static array $afterFirstRun = [];

    /** @var array<string, array<string, mixed>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::SHARE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$before = self::snapshot(self::$upgraded);

        self::$upgraded->catchUp(self::SHARE);
        self::$afterFirstRun = self::snapshot(self::$upgraded);

        foreach ([self::FOLDERS, self::PORTFOLIO, self::SHARE] as $version) {
            self::$upgraded->replay($version, self::SHARE);
        }
        self::$afterReplay = self::snapshot(self::$upgraded);
    }

    protected function setUp(): void
    {
        if (self::$fresh === null || self::$upgraded === null) {
            $this->markTestSkipped('scratch databases need DB_ROOT_PASSWORD (TESTING.md)');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
    }

    public function testAFreshInstallHasTheFoldersAndTheNewReferences(): void
    {
        $this->assertTrue(self::$fresh->hasTable('media_folders'));

        foreach ([['media', 'folder_id'], ['portfolio_gallery_items', 'media_id'], ['products', 'og_media_id'], ['collections', 'og_media_id']] as [$table, $column]) {
            $this->assertSame(['YES'], self::columnNullability(self::$fresh, $table, $column), $table . '.' . $column);
        }

        $this->assertSame('SET NULL', self::deleteRule(self::$fresh, 'media', 'folder_id'));
        $this->assertSame('RESTRICT', self::deleteRule(self::$fresh, 'portfolio_gallery_items', 'media_id'));
        $this->assertSame('RESTRICT', self::deleteRule(self::$fresh, 'products', 'og_media_id'));
        $this->assertSame('RESTRICT', self::deleteRule(self::$fresh, 'collections', 'og_media_id'));
    }

    public function testEveryExistingItemIsInNoFolderAndKeepsEverythingElse(): void
    {
        $this->assertNull(self::$afterFirstRun['media']['folder_id']);

        foreach (self::$before['media'] as $column => $value) {
            $this->assertSame($value, self::$afterFirstRun['media'][$column], 'media.' . $column);
        }
    }

    public function testNothingOldIsPointedAtTheLibraryOrMoved(): void
    {
        $this->assertNull(self::$afterFirstRun['portfolio']['media_id']);
        $this->assertNull(self::$afterFirstRun['product']['og_media_id']);
        $this->assertNull(self::$afterFirstRun['collection']['og_media_id']);

        foreach (['portfolio', 'product', 'collection'] as $row) {
            foreach (self::$before[$row] as $column => $value) {
                $this->assertSame($value, self::$afterFirstRun[$row][$column], $row . '.' . $column);
            }
        }
    }

    public function testDeletingAFolderKeepsItsItems(): void
    {
        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO media_folders (name, created_at, updated_at) VALUES ('ZZ Kerst', NOW(), NOW())");
        $folderId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE media SET folder_id = ? WHERE id = ?')->execute([$folderId, self::$ids['media']]);

        $pdo->prepare('DELETE FROM media_folders WHERE id = ?')->execute([$folderId]);

        $row = self::$upgraded->rows('SELECT id, folder_id FROM media WHERE id = ?', [self::$ids['media']]);
        $this->assertCount(1, $row, 'the item is still there');
        $this->assertNull($row[0]['folder_id'], 'and back in Geen map');
    }

    public function testAFolderNameIsUniqueWhateverItsCase(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO media_folders (name, created_at, updated_at) VALUES ('ZZ Zomer', NOW(), NOW())");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO media_folders (name, created_at, updated_at) VALUES ('zz zomer', NOW(), NOW())");
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->assertSame(self::$afterFirstRun, self::$afterReplay);
        $this->assertSame(1, self::countForeignKeys(self::$upgraded, 'media', 'folder_id'));
        $this->assertSame(1, self::countForeignKeys(self::$upgraded, 'portfolio_gallery_items', 'media_id'));
        $this->assertSame(1, self::countForeignKeys(self::$upgraded, 'products', 'og_media_id'));
    }

    /* ------------------------------------------------------------------ */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $pdo->exec(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, checksum, created_at, updated_at)
             VALUES ('assets/media/zz-ml2-bestaand.webp', NULL, 'bestaand.jpg', 'bestaand.webp', 'image/webp', 10, 10, 100, 'Een bestaande foto', NULL, NOW(), NOW())"
        );
        self::$ids['media'] = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())");
        $galleryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, thumbnail_path, sort_order, is_active, created_at, updated_at)
             VALUES (?, 'assets/images/sections/zz-ml2-oud.webp', 'assets/images/sections/thumbs/zz-ml2-oud.webp', 1, 1, NOW(), NOW())"
        )->execute([$galleryId]);
        self::$ids['portfolio'] = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO products (slug, price, stock, active, in_shop, in_personalization_catalog,
                                   shipping_profile, shipping_weight_grams, requires_parcel, og_image_path, created_at, updated_at)
             VALUES ('zz-ml2-product', '10.00', 1, 1, 1, 0, 'letter', 20, 0, 'assets/images/products/zz-ml2-deel.webp', NOW(), NOW())"
        )->execute();
        self::$ids['product'] = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO collections (slug, is_active, sort_order, og_image_path, created_at, updated_at)
             VALUES ('zz-ml2-collectie', 1, 1, 'assets/images/sections/zz-ml2-collectie-deel.webp', NOW(), NOW())"
        );
        self::$ids['collection'] = (int) $pdo->lastInsertId();
    }

    /** @return array<string, array<string, mixed>> */
    private static function snapshot(ScratchInstall $install): array
    {
        $one = static fn (string $sql, int $id): array => $install->rows($sql, [$id])[0] ?? [];

        return [
            'media' => $one('SELECT * FROM media WHERE id = ?', self::$ids['media']),
            'portfolio' => $one('SELECT * FROM portfolio_gallery_items WHERE id = ?', self::$ids['portfolio']),
            'product' => $one('SELECT * FROM products WHERE id = ?', self::$ids['product']),
            'collection' => $one('SELECT * FROM collections WHERE id = ?', self::$ids['collection']),
        ];
    }

    /** @return list<string> */
    private static function columnNullability(ScratchInstall $install, string $table, string $column): array
    {
        return array_column($install->rows(
            'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ), 'IS_NULLABLE');
    }

    private static function deleteRule(ScratchInstall $install, string $table, string $column): string
    {
        $rows = $install->rows(
            'SELECT rc.DELETE_RULE
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints rc
                 ON rc.constraint_schema = k.constraint_schema AND rc.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.column_name = ? AND k.referenced_table_name IS NOT NULL',
            [$table, $column]
        );

        return (string) ($rows[0]['DELETE_RULE'] ?? '');
    }

    private static function countForeignKeys(ScratchInstall $install, string $table, string $column): int
    {
        return count($install->rows(
            'SELECT constraint_name FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL',
            [$table, $column]
        ));
    }
}
