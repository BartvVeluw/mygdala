<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The three Portfolio migrations of this content-blocks series, on a database
 * built from zero and on one upgraded from the migration before them, with an
 * installation's own projects and galleries in it:
 *
 *   20260928190000  related projects: the settings on portfolio_gallery_items
 *                   (off), two words in portfolio_item_translations, and
 *                   portfolio_related_items;
 *   20260928200000  Projecten 2.0: item_galleries.portfolio_category_id
 *                   (SET NULL) and item_sort ('source'), and
 *                   item_gallery_portfolio_items; every featured gallery
 *                   becomes a hand-picked list of exactly the projects it
 *                   showed, in exactly that order;
 *   20260928210000  "Toon op homepage" goes: is_featured and
 *                   featured_sort_order are dropped.
 *
 *   - a fresh install and an upgrade end on the same schema, with no flag;
 *   - every project keeps its row, its words and its categories, and gets
 *     its related projects switched off;
 *   - a featured gallery shows the same projects afterwards; a gallery on
 *     "all" never looked at the flag and is untouched;
 *   - running the three again changes nothing, rows written in between
 *     included;
 *   - the references cascade and let go as they should, and a project cannot
 *     be picked twice.
 */
#[Group('migration-backfill')]
final class PortfolioSelectionMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_portfolio_selection_fresh';
    private const UPGRADED = 'mygdala_scratch_portfolio_selection_upgraded';

    /** The last migration before these three (the media sequence). */
    private const BEFORE = '20260928180000';

    private const RELATED = '20260928190000';

    private const SELECTION = '20260928200000';

    private const FLAG = '20260928210000';

    private const TABLES = ['portfolio_gallery_items', 'portfolio_item_translations', 'portfolio_related_items', 'item_galleries', 'item_gallery_portfolio_items'];

    /** Every column of an item that is neither the flag nor new. */
    private const ITEM_COLUMNS = 'id, portfolio_gallery_id, page_id, media_id, image_path, thumbnail_path, categories, sort_order, is_active, has_detail_page, slug, created_at, updated_at';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var array<string, bool> */
    private static array $existedBefore = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $beforeReplay = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::FLAG);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        $pdo = self::$upgraded->pdo();
        foreach (['portfolio_related_items', 'item_gallery_portfolio_items'] as $table) {
            self::$existedBefore[$table] = self::$upgraded->hasTable($table);
        }

        // An installation's own catalogue: three projects on the homepage in
        // their own order — B, then the hidden C, then A — and one that is not.
        $pdo->exec('INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())');
        $catalogue = (int) $pdo->lastInsertId();
        foreach (
            [
                'a' => ['sort' => 0, 'active' => 1, 'featured' => 1, 'order' => 2],
                'b' => ['sort' => 1, 'active' => 1, 'featured' => 1, 'order' => 0],
                'c' => ['sort' => 2, 'active' => 0, 'featured' => 1, 'order' => 1],
                'd' => ['sort' => 3, 'active' => 1, 'featured' => 0, 'order' => null],
            ] as $name => $item
        ) {
            $pdo->prepare('INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, sort_order, is_active, is_featured, featured_sort_order, has_detail_page, slug, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())')
                ->execute([$catalogue, 'assets/images/sections/zz-' . $name . '.jpg', $item['sort'], $item['active'], $item['featured'], $item['order'], 'zz-' . $name]);
            self::$ids[$name] = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO portfolio_item_translations (portfolio_item_id, language_code, title, subtitle, created_at, updated_at) VALUES (?, 'nl', ?, 'Kort', NOW(), NOW())")
                ->execute([self::$ids[$name], 'Project ' . strtoupper($name)]);
        }

        $pdo->exec("INSERT INTO portfolio_categories (slug, sort_order, created_at, updated_at) VALUES ('zz-wolven', 0, NOW(), NOW())");
        self::$ids['category'] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO portfolio_item_categories (portfolio_item_id, portfolio_category_id) VALUES (?, ?)')->execute([self::$ids['a'], self::$ids['category']]);

        // The homepage teaser on "featured", a Projecten block on "featured",
        // and an overview on "all".
        $gallery = $pdo->prepare('INSERT INTO item_galleries (page_slug, section_key, source_type, portfolio_scope, max_items, show_filter_bar, enable_lightbox, button_url, background, tight_top, is_active, created_at, updated_at)
                                  VALUES (?, ?, \'portfolio\', ?, ?, 0, 1, ?, \'soft\', 1, 1, NOW(), NOW())');
        foreach (
            [
                'home' => ['homepage', 'custom-home', 'featured', 3, '/portfolio'],
                'projects' => ['zz-over', 'custom-projects', 'featured', null, null],
                'all' => ['portfolio', 'custom-all', 'all', null, null],
            ] as $name => $values
        ) {
            $gallery->execute($values);
            self::$ids[$name] = (int) $pdo->lastInsertId();
        }

        self::$before = self::neutral(self::$upgraded);

        self::$upgraded->catchUp(self::FLAG);

        self::$after = self::neutral(self::$upgraded);

        // Rows written in between, then all three once more.
        $pdo->prepare('INSERT INTO portfolio_related_items (portfolio_item_id, related_item_id, sort_order) VALUES (?, ?, 0)')->execute([self::$ids['a'], self::$ids['d']]);
        $pdo->prepare("UPDATE portfolio_gallery_items SET related_enabled = 1, related_mode = 'hybrid' WHERE id = ?")->execute([self::$ids['a']]);
        $pdo->prepare("UPDATE item_galleries SET portfolio_scope = 'category', portfolio_category_id = ?, item_sort = 'random' WHERE id = ?")->execute([self::$ids['category'], self::$ids['all']]);

        self::$beforeReplay = self::contentOf(self::$upgraded);
        self::$upgraded->replay(self::RELATED, self::FLAG);
        self::$upgraded->replay(self::SELECTION, self::FLAG);
        self::$upgraded->replay(self::FLAG, self::FLAG);
        self::$afterReplay = self::contentOf(self::$upgraded);
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

    public function testTheNewTablesDidNotExistBefore(): void
    {
        self::assertSame(['portfolio_related_items' => false, 'item_gallery_portfolio_items' => false], self::$existedBefore);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchemaWithoutTheFlag(): void
    {
        foreach (self::TABLES as $table) {
            self::assertNotSame([], self::shape(self::$fresh, $table), $table);
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        foreach ([self::$fresh, self::$upgraded] as $install) {
            $columns = array_column(self::shape($install, 'portfolio_gallery_items'), 'name');
            self::assertNotContains('is_featured', $columns);
            self::assertNotContains('featured_sort_order', $columns);
        }
    }

    public function testAFeaturedGalleryShowsTheSameProjectsInTheSameOrderAsAHandPickedList(): void
    {
        foreach (['home', 'projects'] as $gallery) {
            $row = self::$upgraded->rows('SELECT portfolio_scope, item_sort FROM item_galleries WHERE id = ?', [self::$ids[$gallery]])[0];
            self::assertSame(['portfolio_scope' => 'manual', 'item_sort' => 'source'], $row, $gallery);
        }

        // The state right after the migration, before the rows written for the replay.
        self::assertSame(
            [self::$ids['b'], self::$ids['c'], self::$ids['a']],
            array_map('intval', array_column(self::picked(self::$after, self::$ids['home']), 'portfolio_item_id')),
            'featured order; the hidden one too, so it comes back when it is shown again'
        );
        self::assertSame(
            array_column(self::picked(self::$after, self::$ids['home']), 'portfolio_item_id'),
            array_column(self::picked(self::$after, self::$ids['projects']), 'portfolio_item_id')
        );
        self::assertSame([], self::picked(self::$after, self::$ids['all']), 'a gallery on "all" never looked at the flag');
    }

    public function testEveryOtherGallerySettingAndEveryProjectStaysAsItWas(): void
    {
        self::assertSame(self::$before['galleries'], self::$after['galleries'], 'max, button, background, lightbox, top spacing, active: unchanged');
        self::assertSame(self::$before['items'], self::$after['items'], 'every project row, minus the flag');
        self::assertSame(self::$before['words'], self::$after['words']);
        self::assertSame(self::$before['categories'], self::$after['categories']);

        foreach (self::$upgraded->rows('SELECT related_enabled, related_mode, related_max, related_sort, related_fallback, related_layout, related_show_text FROM portfolio_gallery_items WHERE id IN (?, ?)', [self::$ids['b'], self::$ids['d']]) as $row) {
            self::assertSame(
                ['0', 'automatic', '3', 'relevance', 'available', 'normal', '1'],
                array_map('strval', array_values($row)),
                'related projects off, every other setting at its start'
            );
        }
    }

    public function testRunningTheThreeAgainChangesNothing(): void
    {
        self::assertCount(1, self::$beforeReplay['portfolio_related_items']);
        self::assertSame(self::$beforeReplay, self::$afterReplay);
    }

    public function testTheReferencesCascadeAndLetGoAsTheyShould(): void
    {
        $expected = [
            'item_galleries' => [
                ['column_name' => 'collection_id', 'referenced_table_name' => 'collections', 'delete_rule' => 'SET NULL'],
                ['column_name' => 'portfolio_category_id', 'referenced_table_name' => 'portfolio_categories', 'delete_rule' => 'SET NULL'],
            ],
            'item_gallery_portfolio_items' => [
                ['column_name' => 'item_gallery_id', 'referenced_table_name' => 'item_galleries', 'delete_rule' => 'CASCADE'],
                ['column_name' => 'portfolio_item_id', 'referenced_table_name' => 'portfolio_gallery_items', 'delete_rule' => 'CASCADE'],
            ],
            'portfolio_related_items' => [
                ['column_name' => 'portfolio_item_id', 'referenced_table_name' => 'portfolio_gallery_items', 'delete_rule' => 'CASCADE'],
                ['column_name' => 'related_item_id', 'referenced_table_name' => 'portfolio_gallery_items', 'delete_rule' => 'CASCADE'],
            ],
        ];

        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach ($expected as $table => $keys) {
                self::assertSame($keys, $install->rows(
                    "SELECT k.column_name AS column_name, k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL
                      ORDER BY k.column_name",
                    [$table]
                ), $table);
            }
        }
    }

    public function testAProjectCannotBePickedTwiceAndDeletingLetsGo(): void
    {
        $pdo = self::$upgraded->pdo();

        try {
            $pdo->prepare('INSERT INTO item_gallery_portfolio_items (item_gallery_id, portfolio_item_id, sort_order) VALUES (?, ?, 9)')->execute([self::$ids['home'], self::$ids['b']]);
            self::fail('a project picked twice for one block');
        } catch (\PDOException) {
        }

        $pdo->prepare('DELETE FROM portfolio_gallery_items WHERE id = ?')->execute([self::$ids['b']]);
        self::assertSame(0, (int) self::$upgraded->rows('SELECT COUNT(*) AS n FROM item_gallery_portfolio_items WHERE portfolio_item_id = ?', [self::$ids['b']])[0]['n']);

        $pdo->prepare('DELETE FROM portfolio_gallery_items WHERE id = ?')->execute([self::$ids['d']]);
        self::assertSame(0, self::$upgraded->count('portfolio_related_items'), 'a deleted project leaves every related list');

        $pdo->prepare('DELETE FROM portfolio_item_categories WHERE portfolio_category_id = ?')->execute([self::$ids['category']]);
        $pdo->prepare('DELETE FROM portfolio_categories WHERE id = ?')->execute([self::$ids['category']]);
        $row = self::$upgraded->rows('SELECT portfolio_scope, portfolio_category_id, item_sort FROM item_galleries WHERE id = ?', [self::$ids['all']])[0];
        self::assertSame(['portfolio_scope' => 'category', 'portfolio_category_id' => null, 'item_sort' => 'random'], $row, 'the block lets go of a deleted category and keeps the rest');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function neutral(ScratchInstall $install): array
    {
        return [
            'items' => $install->rows('SELECT ' . self::ITEM_COLUMNS . ' FROM portfolio_gallery_items ORDER BY id'),
            'words' => $install->rows('SELECT portfolio_item_id, language_code, title, subtitle, alt, intro, description FROM portfolio_item_translations ORDER BY id'),
            'categories' => $install->rows('SELECT * FROM portfolio_item_categories ORDER BY portfolio_item_id, portfolio_category_id'),
            'galleries' => $install->rows('SELECT id, page_slug, section_key, source_type, collection_id, max_items, show_filter_bar, enable_lightbox, fallback_link_url, button_url, background, tight_top, is_active FROM item_galleries ORDER BY id'),
            'picked' => $install->hasTable('item_gallery_portfolio_items')
                ? $install->rows('SELECT item_gallery_id, portfolio_item_id, sort_order FROM item_gallery_portfolio_items ORDER BY item_gallery_id, sort_order')
                : [],
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $state from neutral()
     *
     * @return list<array<string, mixed>>
     */
    private static function picked(array $state, int $galleryId): array
    {
        return array_values(array_filter($state['picked'], static fn (array $row): bool => (int) $row['item_gallery_id'] === $galleryId));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function contentOf(ScratchInstall $install): array
    {
        return [
            'portfolio_gallery_items' => $install->rows('SELECT * FROM portfolio_gallery_items ORDER BY id'),
            'portfolio_item_translations' => $install->rows('SELECT * FROM portfolio_item_translations ORDER BY id'),
            'portfolio_related_items' => $install->rows('SELECT * FROM portfolio_related_items ORDER BY portfolio_item_id, sort_order'),
            'item_galleries' => $install->rows('SELECT * FROM item_galleries ORDER BY id'),
            'item_gallery_portfolio_items' => $install->rows('SELECT * FROM item_gallery_portfolio_items ORDER BY item_gallery_id, sort_order'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table]
        );
    }
}
