<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Service\ReviewsContent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The Reviews migration (20261009100000) on a database built from zero and on
 * one upgraded from the migration before it:
 *
 *   - both tables exist with their unique instance key, the starting values
 *     (cards, left, no stars) and every column of the picture's Responsive
 *     Media slot;
 *   - the reviews cascade from their block, a used picture and a used button
 *     style cannot be deleted (RESTRICT);
 *   - a second run changes nothing and both installs end on the same schema.
 *
 * Nightly: this builds two databases from zero (CONTENT-BLOCKS.md, "Tests").
 */
#[Group('migration-backfill')]
final class ReviewsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_reviews_fresh';
    private const UPGRADED = 'mygdala_scratch_reviews_upgraded';

    /** The last migration before Reviews. */
    private const BEFORE = '20261008100000';

    private const MIGRATION = '20261009100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);
        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$upgraded->catchUp(self::MIGRATION);
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

    public function testBothTablesStartEmptyWithTheirDefaults(): void
    {
        $install = self::$upgraded;
        self::assertTrue($install->hasTable('review_blocks'));
        self::assertTrue($install->hasTable('review_block_items'));
        self::assertSame(0, $install->count('review_blocks'));

        $pdo = $install->pdo();
        $pdo->exec("INSERT INTO review_blocks (page_slug, section_key, created_at, updated_at) VALUES ('zz-reviews', 'zz-one', NOW(), NOW())");
        $blockId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO review_block_items (review_block_id, created_at, updated_at) VALUES ({$blockId}, NOW(), NOW())");

        $block = $install->rows('SELECT layout, header_align, featured_item_id, link_type, button_style_id, is_active FROM review_blocks WHERE id = ?', [$blockId])[0];
        self::assertSame(['layout' => 'cards', 'header_align' => 'left', 'featured_item_id' => null, 'link_type' => null, 'button_style_id' => null, 'is_active' => 1], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $block));
        self::assertSame(ReviewsContent::LAYOUTS[0], $block['layout'], 'the table and the read model agree on the start');

        $item = $install->rows('SELECT media_id, rating, review_date, source_url, image_focus_x, image_focus_y, image_zoom, sort_order FROM review_block_items WHERE review_block_id = ?', [$blockId])[0];
        self::assertSame([null, null, null, null, 50, 50, 100, 0], array_map(static fn ($v) => $v === null ? null : (is_numeric($v) ? (int) $v : $v), array_values($item)));

        // One row per instance.
        try {
            $pdo->exec("INSERT INTO review_blocks (page_slug, section_key, created_at, updated_at) VALUES ('zz-reviews', 'zz-one', NOW(), NOW())");
            self::fail('a second row for the same instance');
        } catch (\PDOException $e) {
            self::assertStringContainsString('Duplicate', $e->getMessage());
        }

        // The reviews go with their block.
        $pdo->exec("DELETE FROM review_blocks WHERE id = {$blockId}");
        self::assertSame(0, $install->count('review_block_items'));
    }

    public function testEveryColumnOfThePictureSlotIsThere(): void
    {
        $columns = array_column(self::$fresh->rows(
            "SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'review_block_items'"
        ), 'name');

        foreach (ReviewsContent::imageSlot()->columns() as $column) {
            self::assertContains($column, $columns, $column);
        }
    }

    public function testUsedPicturesAndButtonStylesCannotBeDeleted(): void
    {
        $constraints = self::$fresh->rows(
            "SELECT k.table_name AS t, k.column_name AS c, k.referenced_table_name AS r, rc.delete_rule AS d
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints rc ON rc.constraint_schema = k.constraint_schema AND rc.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name IN ('review_blocks', 'review_block_items')
              ORDER BY k.table_name, k.column_name"
        );

        self::assertSame([
            ['t' => 'review_block_items', 'c' => 'image_mobile_media_id', 'r' => 'media', 'd' => 'RESTRICT'],
            ['t' => 'review_block_items', 'c' => 'media_id', 'r' => 'media', 'd' => 'RESTRICT'],
            ['t' => 'review_block_items', 'c' => 'review_block_id', 'r' => 'review_blocks', 'd' => 'CASCADE'],
            ['t' => 'review_blocks', 'c' => 'button_style_id', 'r' => 'button_styles', 'd' => 'RESTRICT'],
        ], $constraints);
    }

    public function testASecondRunChangesNothingAndBothInstallsEndOnTheSameSchema(): void
    {
        $before = self::shape(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);

        self::assertSame($before, self::shape(self::$upgraded));
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT table_name AS t, column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name IN ('review_blocks', 'review_block_items') ORDER BY table_name, ordinal_position"
        );
    }
}
