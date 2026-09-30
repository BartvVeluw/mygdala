<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The Extra vormgeving migration (20261008100000) on a database built from
 * zero and on one upgraded from the migration before it, seeded first with a
 * page, a Tekstblok, a gallery on 'soft', a Projecten block on 'default' and
 * a gallery draft on 'soft':
 *
 *   - every existing block gets the defaults: it renders as before;
 *   - the placed gallery on 'soft' moves its look to its instance (subtle
 *     background, a subtle line above and below: the same `.bg-soft`) and
 *     its own column becomes 'default', so there is one setting, not two;
 *   - a draft keeps its own value (it has no instance row yet);
 *   - no other column changes, a second run changes nothing, and both
 *     installs end on the same schema.
 */
#[Group('migration-backfill')]
final class BlockAppearanceMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_block_appearance_fresh';
    private const UPGRADED = 'mygdala_scratch_block_appearance_upgraded';

    /** The last migration before Extra vormgeving. */
    private const BEFORE = '20261007100000';

    private const MIGRATION = '20261008100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $sectionsBefore = [];

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
        $pdo->exec("INSERT INTO pages (content_key, slug, created_at, updated_at) VALUES ('zz-appearance', 'zz-appearance', NOW(), NOW())");
        $pageId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO item_galleries (page_slug, section_key, background, created_at, updated_at)
             VALUES ('zz-appearance', 'zz-soft', 'soft', NOW(), NOW()),
                    ('zz-appearance', 'zz-plain', 'default', NOW(), NOW()),
                    ('zz-appearance', 'zz-draft', 'soft', NOW(), NOW())"
        );
        $galleries = self::$upgraded->rows("SELECT id, section_key FROM item_galleries WHERE page_slug = 'zz-appearance' ORDER BY id");
        $ids = array_column($galleries, 'id', 'section_key');
        $pdo->exec(
            "INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES ({$pageId}, 'zz-appearance', 'rich_text', 'zz-text', 424242, 0, 1, NOW(), NOW()),
                    ({$pageId}, 'zz-appearance', 'item_gallery', 'zz-soft', {$ids['zz-soft']}, 1, 1, NOW(), NOW()),
                    ({$pageId}, 'zz-appearance', 'project_cards', 'zz-plain', {$ids['zz-plain']}, 2, 0, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO content_block_drafts (page_id, section_type, section_key, section_id, created_at)
             VALUES ({$pageId}, 'item_gallery', 'zz-draft', {$ids['zz-draft']}, NOW())"
        );

        self::$sectionsBefore = self::$upgraded->rows('SELECT * FROM page_sections ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::snapshot();
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::snapshot();
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

    public function testEveryExistingBlockGetsTheDefaultsExceptTheSoftGallery(): void
    {
        self::assertSame(
            [
                ['section_key' => 'zz-text', 'appearance_background' => 'default', 'appearance_border' => 'default', 'appearance_border_tone' => 'subtle', 'appearance_spacing' => 'default', 'appearance_decoration' => 'none'],
                ['section_key' => 'zz-soft', 'appearance_background' => 'subtle', 'appearance_border' => 'both', 'appearance_border_tone' => 'subtle', 'appearance_spacing' => 'default', 'appearance_decoration' => 'none'],
                ['section_key' => 'zz-plain', 'appearance_background' => 'default', 'appearance_border' => 'default', 'appearance_border_tone' => 'subtle', 'appearance_spacing' => 'default', 'appearance_decoration' => 'none'],
            ],
            self::$upgraded->rows(
                "SELECT section_key, appearance_background, appearance_border, appearance_border_tone, appearance_spacing, appearance_decoration
                   FROM page_sections WHERE page_slug = 'zz-appearance' ORDER BY sort_order"
            )
        );
    }

    public function testTheGallerysOwnColumnKeepsOnlyTheDraftsSoft(): void
    {
        self::assertSame(
            [['section_key' => 'zz-soft', 'background' => 'default'], ['section_key' => 'zz-plain', 'background' => 'default'], ['section_key' => 'zz-draft', 'background' => 'soft']],
            self::$upgraded->rows("SELECT section_key, background FROM item_galleries WHERE page_slug = 'zz-appearance' ORDER BY id")
        );
    }

    public function testNoColumnTheSectionsHadChanges(): void
    {
        $columns = array_keys(self::$sectionsBefore[0]);
        $after = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($columns)), self::$afterFirstRun['sections']);

        self::assertSame(self::$sectionsBefore, $after);
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
        self::assertContains(
            ['name' => 'appearance_decoration', 'type' => 'varchar(16)', 'nullable' => 'NO', 'default' => 'none'],
            self::shape(self::$fresh)
        );
    }

    /** @return array{sections: list<array<string, mixed>>, galleries: list<array<string, mixed>>} */
    private static function snapshot(): array
    {
        return [
            'sections' => self::$upgraded->rows('SELECT * FROM page_sections ORDER BY id'),
            'galleries' => self::$upgraded->rows('SELECT * FROM item_galleries ORDER BY id'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'page_sections' ORDER BY ordinal_position"
        );
    }
}
