<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The migration that gives the Shop and the Portfolio their system pages
 * (20260928150000, Shop Product & Ordering 2.0, App\Service\ModuleSystemPages),
 * on databases built from zero:
 *
 *   - an installation that already has its Shop page (seeded here the way an
 *     early installation got it, with its own words and SEO) keeps exactly
 *     that page, untouched, and gets the Portfolio page it lacked;
 *   - a legacy installation, which has both, gets neither: one page each,
 *     under the same ids, no module_default;
 *   - an installation where another page already holds a word gets NO page
 *     for it, and that other page is not changed or renamed;
 *   - running it again creates nothing;
 *   - no order, product or other page moves.
 *
 * The fresh install is Tests\Install\FreshInstallTest, and that the pages
 * change nothing on the website is Tests\Install\PortfolioRootInstallTest.
 */
#[Group('migration-backfill')]
final class ModuleSystemPagesMigrationTest extends TestCase
{
    private const EXISTING = 'mygdala_scratch_syspages_existing';
    private const CONFLICT = 'mygdala_scratch_syspages_conflict';
    private const LEGACY = 'mygdala_scratch_syspages_legacy';

    /** The migration before this one. */
    private const BEFORE = '20260928140000';
    private const MIGRATION = '20260928150000';

    private static ?ScratchInstall $existing = null;
    private static ?ScratchInstall $conflict = null;
    private static ?ScratchInstall $legacy = null;

    /** @var array<string, mixed> */
    private static array $shopBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $pagesBefore = [];

    /** @var array<string, mixed> */
    private static array $conflictPageBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $existingAfterFirstRun = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        // An installation from before, with its own Shop page.
        self::$existing = ScratchInstall::upTo(self::EXISTING, self::BEFORE);
        $pdo = self::$existing->pdo();
        $pdo->exec("INSERT INTO pages (parent_id, admin_group, content_key, slug, status, show_breadcrumb, is_system, route_path, sort_order, noindex, created_at, updated_at)
                    VALUES (NULL, 'website', 'shop', 'shop', 'draft', 0, 1, '/shop.php', 7, 1, '2026-01-01 10:00:00', '2026-01-02 10:00:00')");
        $shopId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO page_translations (page_id, language_code, title, meta_title, meta_description, created_at, updated_at)
                    VALUES ({$shopId}, 'nl', 'Onze winkel', 'Winkel | Eigen titel', 'Eigen omschrijving', '2026-01-01 10:00:00', '2026-01-01 10:00:00')");
        self::$shopBefore = self::$existing->rows('SELECT * FROM pages WHERE id = ?', [$shopId])[0];
        self::$pagesBefore = self::$existing->rows('SELECT * FROM pages ORDER BY id');
        self::$existing->catchUp(self::MIGRATION);
        self::$existingAfterFirstRun = self::$existing->rows('SELECT * FROM pages ORDER BY id');
        self::$existing->replay(self::MIGRATION, self::MIGRATION);

        // An installation where an ordinary page already holds "portfolio".
        self::$conflict = ScratchInstall::upTo(self::CONFLICT, self::BEFORE);
        self::$conflict->pdo()->exec("INSERT INTO pages (parent_id, admin_group, content_key, slug, status, show_breadcrumb, is_system, route_path, sort_order, noindex, created_at, updated_at)
                    VALUES (NULL, 'website', 'zz-onze-projecten', 'portfolio', 'published', 1, 0, NULL, 5, 0, '2026-01-01 10:00:00', '2026-01-01 10:00:00')");
        self::$conflictPageBefore = self::$conflict->rows("SELECT * FROM pages WHERE content_key = 'zz-onze-projecten'")[0];
        self::$conflict->catchUp(self::MIGRATION);

        self::$legacy = ScratchInstall::legacy(self::LEGACY);
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
        self::$conflict?->drop();
        self::$legacy?->drop();
        self::$existing = self::$conflict = self::$legacy = null;
    }

    public function testAnExistingShopPageIsTheSystemPageUntouched(): void
    {
        $shop = self::$existing->rows("SELECT * FROM pages WHERE content_key = 'shop'");
        self::assertCount(1, $shop, 'no second Shop page');

        $after = $shop[0];
        self::assertSame(0, (int) $after['module_default'], 'not a placeholder: it is the installation\'s own page');
        unset($after['module_default']);
        self::assertSame(self::$shopBefore, $after, 'status, address, breadcrumb, noindex and dates as they were');

        self::assertSame(
            [['title' => 'Onze winkel', 'meta_title' => 'Winkel | Eigen titel', 'meta_description' => 'Eigen omschrijving']],
            self::$existing->rows("SELECT title, meta_title, meta_description FROM page_translations WHERE page_id = ?", [(int) $shop[0]['id']]),
            'its words and SEO as they were'
        );
    }

    public function testTheMissingPortfolioPageIsCreatedEmptyOnce(): void
    {
        $portfolio = self::$existing->rows("SELECT * FROM pages WHERE content_key = 'portfolio'");
        self::assertCount(1, $portfolio);
        self::assertSame('portfolio', $portfolio[0]['slug']);
        self::assertSame('/portfolio', $portfolio[0]['route_path']);
        self::assertSame(1, (int) $portfolio[0]['is_system']);
        self::assertSame(1, (int) $portfolio[0]['module_default']);
        self::assertSame('published', $portfolio[0]['status']);
        self::assertNull($portfolio[0]['parent_id']);
        self::assertSame([], self::$existing->rows('SELECT id FROM page_sections WHERE page_id = ?', [(int) $portfolio[0]['id']]));
        self::assertSame(
            [['language_code' => 'nl', 'title' => 'Portfolio', 'slug' => null]],
            self::$existing->rows('SELECT language_code, title, slug FROM page_translations WHERE page_id = ?', [(int) $portfolio[0]['id']])
        );

        // Every page that was there is still there, unchanged but for the new column.
        $before = array_column(self::$pagesBefore, null, 'id');
        foreach (self::$existingAfterFirstRun as $row) {
            if (!isset($before[$row['id']])) {
                continue;
            }
            unset($row['module_default']);
            self::assertSame($before[$row['id']], $row, 'page #' . $row['id']);
        }
        self::assertCount(count(self::$pagesBefore) + 1, self::$existingAfterFirstRun, 'exactly one page more');
    }

    public function testARunAgainCreatesNothing(): void
    {
        self::assertSame(self::$existingAfterFirstRun, self::$existing->rows('SELECT * FROM pages ORDER BY id'));
    }

    public function testAPageHoldingTheWordIsLeftAloneAndNoSystemPageIsMade(): void
    {
        self::assertSame([], self::$conflict->rows("SELECT id FROM pages WHERE content_key = 'portfolio'"), 'no Portfolio page next to the one holding its word');

        $after = self::$conflict->rows("SELECT * FROM pages WHERE content_key = 'zz-onze-projecten'")[0];
        unset($after['module_default']);
        self::assertSame(self::$conflictPageBefore, $after, 'not renamed, not changed');

        self::assertCount(1, self::$conflict->rows("SELECT id FROM pages WHERE content_key = 'shop' AND module_default = 1"), 'the Shop, which nothing blocked, gets its page');
    }

    public function testALegacyInstallationKeepsItsOwnTwoPages(): void
    {
        foreach (['shop', 'portfolio'] as $contentKey) {
            $rows = self::$legacy->rows('SELECT id, module_default FROM pages WHERE content_key = ?', [$contentKey]);
            self::assertCount(1, $rows, $contentKey);
            self::assertSame(0, (int) $rows[0]['module_default'], $contentKey . ' is the installation\'s own');
        }
    }
}
