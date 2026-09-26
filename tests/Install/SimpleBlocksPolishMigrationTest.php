<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Content Blocks Polish 1's two migrations on a database built from zero and
 * on one upgraded from the migration before them:
 *
 *   20260926100000  the Tekstblok's width, the Formulier's and the
 *                   carousel's heading alignment, the carousel's picture
 *                   height, a Tekst met afbeelding item's link type and target
 *   20260926110000  the Witruimte block's table
 *
 * Additive: every existing row gets the value that looks like it did before,
 * an item's typed address stays where it was (a row without a type but with
 * an address is an address, LinkChoice::storedType()), and nothing is
 * rewritten. Both end on the same schema, and running them again changes
 * nothing.
 */
#[Group('migration-backfill')]
final class SimpleBlocksPolishMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_simple_polish_fresh';
    private const UPGRADED = 'mygdala_scratch_simple_polish_upgraded';

    /** The last migration before this round. */
    private const BEFORE = '20260925170000';

    private const FIRST = '20260926100000';

    private const LAST = '20260926110000';

    private const TABLES = ['rich_text_sections', 'form_blocks', 'card_carousels', 'text_image_split_items', 'spacers'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterFirstRun = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::LAST);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$upgraded->catchUp(self::LAST);
        self::$afterFirstRun = self::snapshot(self::$upgraded);
        self::$upgraded->replay(self::FIRST, self::LAST);
        self::$afterReplay = self::snapshot(self::$upgraded);
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

    public function testExistingBlocksGetTheValuesThatLookLikeBefore(): void
    {
        self::assertSame(
            [['content_width' => 'medium', 'text_align' => 'left']],
            self::$upgraded->rows('SELECT content_width, text_align FROM rich_text_sections WHERE section_key = ?', ['zz-simple-polish'])
        );
        self::assertSame(
            [['header_align' => 'left']],
            self::$upgraded->rows('SELECT header_align FROM form_blocks WHERE section_key = ?', ['zz-simple-polish'])
        );
        self::assertSame(
            [['desktop_layout' => 'row', 'header_align' => 'left', 'image_height' => 'medium']],
            self::$upgraded->rows('SELECT desktop_layout, header_align, image_height FROM card_carousels WHERE section_key = ?', ['zz-simple-polish'])
        );
    }

    public function testAnItemsTypedAddressStaysAndGetsNoType(): void
    {
        self::assertSame(
            [
                ['button_link_type' => null, 'button_link_target_id' => null, 'button_url' => '/contact'],
                ['button_link_type' => null, 'button_link_target_id' => null, 'button_url' => null],
            ],
            self::$upgraded->rows('SELECT button_link_type, button_link_target_id, button_url FROM text_image_split_items ORDER BY sort_order')
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertNotSame([], self::$afterFirstRun['card_carousels']);
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        self::assertSame(0, self::$fresh->count('spacers'), 'a fresh install has no spacer');
        self::assertSame(0, self::$upgraded->count('spacers'), 'an upgrade makes no spacer');
    }

    public function testTheSpacerTableIsABlockTable(): void
    {
        $unique = self::$fresh->rows(
            "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'spacers' AND non_unique = 0 AND index_name <> 'PRIMARY'
              GROUP BY index_name"
        );
        self::assertSame([['columns' => 'page_slug,section_key']], $unique);

        $default = self::$fresh->rows(
            "SELECT column_default FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'spacers' AND column_name = 'size'"
        );
        self::assertStringContainsString('medium', (string) $default[0]['column_default'], 'a new spacer starts at medium');
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-simple-polish', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO form_blocks (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-simple-polish', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO card_carousels (page_slug, section_key, is_active, desktop_layout, created_at, updated_at) VALUES ('zz', 'zz-simple-polish', 1, 'row', NOW(), NOW())");
        $pdo->exec("INSERT INTO text_image_splits (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-simple-polish', 1, NOW(), NOW())");
        $split = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            "INSERT INTO text_image_split_items (text_image_split_id, image_side, image_column, image_height, image_focus, button_url, sort_order, created_at, updated_at)
             VALUES (?, 'left', '50', 'large', 'center', ?, ?, NOW(), NOW())"
        );
        $item->execute([$split, '/contact', 0]);
        $item->execute([$split, null, 1]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function snapshot(ScratchInstall $install): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = $install->rows('SELECT * FROM ' . $table . ' ORDER BY id');
        }

        return $snapshot;
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT column_name, column_type, is_nullable, column_default FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table]
        );
    }
}
