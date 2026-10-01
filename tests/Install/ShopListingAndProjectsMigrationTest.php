<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The two migrations of v0.1.15 phase 9 on an upgraded installation that uses
 * the blocks as a real site would, and on a fresh one:
 *
 * db/migrations/20261015100000_give_the_shop_listing_blocks_a_row_of_their_own.php
 *   - every Productgrid and Collectie-tegels block gets exactly one row in
 *     shop_listing_blocks, and its page_sections row points at it;
 *   - page, position, visibility and Extra vormgeving of those rows stay as
 *     they were, and so does every other page section;
 *   - the historical storefront row (section_id 0, no key) is converted too.
 *
 * db/migrations/20261015110000_turn_portfolio_galleries_into_projects_blocks.php
 *   - a gallery on portfolio items (and one with no source) becomes Projecten
 *     in place: same page_sections row, same item_galleries row, settings,
 *     picked projects, words and Extra vormgeving; a draft of one too;
 *   - a gallery on a collection stays a gallery;
 *   - no row is added or removed anywhere.
 *
 * Both: running them again changes nothing; a fresh install ends on the same
 * schema with nothing to convert.
 */
#[Group('migration-backfill')]
final class ShopListingAndProjectsMigrationTest extends TestCase
{
    private const UPGRADED = 'mygdala_scratch_v0115_p9_upgraded';
    private const FRESH = 'mygdala_scratch_v0115_p9_fresh';

    private const BEFORE = '20261014100000';
    private const LISTING = '20261015100000';
    private const PROJECTS = '20261015110000';

    private static ?ScratchInstall $upgraded = null;
    private static ?ScratchInstall $fresh = null;

    /** @var array<string, int> fixture name => page_sections.id */
    private static array $sections = [];

    /** @var array<string, int> fixture name => item_galleries.id */
    private static array $galleries = [];

    private static int $draftGallery = 0;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$before = self::state(self::$upgraded);
        self::$upgraded->catchUp(self::PROJECTS);
        self::$after = self::state(self::$upgraded);
        self::$upgraded->replay(self::LISTING, self::PROJECTS);
        self::$upgraded->replay(self::PROJECTS, self::PROJECTS);
        self::$afterReplay = self::state(self::$upgraded);

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
        self::$upgraded?->drop();
        self::$fresh?->drop();
        self::$upgraded = null;
        self::$fresh = null;
    }

    /* ------------------------------------------------ Shop listings */

    public function testEveryListingBlockGetsExactlyOneRowAndPointsAtIt(): void
    {
        $listings = self::$upgraded->rows(
            "SELECT s.id, s.section_type, s.section_key, s.section_id, s.page_slug, l.id AS row_id, l.page_slug AS row_slug, l.section_key AS row_key, l.is_active
               FROM page_sections s
               LEFT JOIN shop_listing_blocks l ON l.id = s.section_id
              WHERE s.section_type IN ('product_grid', 'shop_collections')
              ORDER BY s.id"
        );

        $this->assertCount(4, $listings, 'the storefront grid and tiles, and a grid and tiles on another page');
        foreach ($listings as $listing) {
            $this->assertNotNull($listing['row_id'], 'page section #' . $listing['id'] . ' points at its own row');
            $this->assertSame($listing['page_slug'], $listing['row_slug']);
            $this->assertSame($listing['section_key'], $listing['row_key']);
            $this->assertNotSame('', (string) $listing['section_key']);
            $this->assertSame(1, (int) $listing['is_active'], 'shown, as it was');
        }

        $this->assertSame(4, self::$upgraded->count('shop_listing_blocks'), 'one row each, nothing more');
        $this->assertCount(4, array_unique(array_column($listings, 'row_id')));
    }

    public function testAListingKeepsItsPagePositionVisibilityAndLook(): void
    {
        foreach (['storefront-grid', 'storefront-tiles', 'page-grid', 'page-tiles'] as $name) {
            $before = self::sectionIn(self::$before, self::$sections[$name]);
            $after = self::sectionIn(self::$after, self::$sections[$name]);

            foreach (['page_id', 'section_type', 'sort_order', 'is_active', 'appearance_background', 'appearance_border', 'appearance_spacing', 'appearance_decoration'] as $column) {
                $this->assertSame($before[$column], $after[$column], "{$name}: {$column}");
            }
        }

        $this->assertSame('0', (string) self::sectionIn(self::$after, self::$sections['page-grid'])['is_active'], 'a hidden grid stays hidden');
        $this->assertSame('secondary', self::sectionIn(self::$after, self::$sections['page-tiles'])['appearance_background']);
    }

    /* ------------------------------------------------ Portfoliogalerij -> Projecten */

    public function testAPortfolioGalleryBecomesProjectenInPlace(): void
    {
        foreach (['portfolio-gallery', 'sorted-gallery', 'no-source-gallery'] as $name) {
            $after = self::sectionIn(self::$after, self::$sections[$name]);
            $before = self::sectionIn(self::$before, self::$sections[$name]);

            $this->assertSame('project_cards', $after['section_type'], $name);
            foreach (['id', 'page_id', 'section_key', 'section_id', 'sort_order', 'is_active', 'appearance_background', 'appearance_border', 'appearance_border_tone', 'appearance_spacing', 'appearance_decoration'] as $column) {
                $this->assertSame($before[$column], $after[$column], "{$name}: {$column}");
            }
        }

        $this->assertSame('0', (string) self::sectionIn(self::$after, self::$sections['sorted-gallery'])['is_active'], 'a hidden gallery stays hidden');
    }

    public function testItsSettingsPickedProjectsAndWordsStayExactlyAsTheyWere(): void
    {
        foreach (['portfolio-gallery', 'sorted-gallery'] as $name) {
            $this->assertSame(
                self::galleryIn(self::$before, self::$galleries[$name]),
                self::galleryIn(self::$after, self::$galleries[$name]),
                "{$name}: every column of its item_galleries row"
            );
        }
        $this->assertSame(self::$before['picked'], self::$after['picked']);
        $this->assertSame(self::$before['words'], self::$after['words']);

        $sorted = self::galleryIn(self::$after, self::$galleries['sorted-gallery']);
        $this->assertSame('manual', $sorted['portfolio_scope']);
        $this->assertSame('compact', $sorted['card_presentation']);
        $this->assertSame('/portfolio', $sorted['button_url']);
        $this->assertSame('1', (string) $sorted['tight_top']);
    }

    public function testAGalleryWithoutASourceIsGivenThePortfoliosOnBecomingProjecten(): void
    {
        $this->assertSame('portfolio', self::galleryIn(self::$after, self::$galleries['no-source-gallery'])['source_type']);
    }

    public function testACollectionGalleryStaysAGallery(): void
    {
        $this->assertSame('item_gallery', self::sectionIn(self::$after, self::$sections['collection-gallery'])['section_type']);
        $this->assertSame(
            self::galleryIn(self::$before, self::$galleries['collection-gallery']),
            self::galleryIn(self::$after, self::$galleries['collection-gallery'])
        );
    }

    public function testTheSearchIndexNamesTheNewTypeWithTheSameWords(): void
    {
        $rows = self::$upgraded->rows('SELECT section_type, heading_text, body_text FROM search_block_texts WHERE page_section_id = ?', [self::$sections['sorted-gallery']]);
        $this->assertSame([['section_type' => 'project_cards', 'heading_text' => 'Uitgelicht', 'body_text' => 'Eerder werk En meer']], $rows);
    }

    public function testADraftOfAPortfolioGalleryBecomesADraftOfProjecten(): void
    {
        $drafts = self::$upgraded->rows('SELECT section_type FROM content_block_drafts WHERE section_id = ?', [self::$draftGallery]);
        $this->assertSame([['section_type' => 'project_cards']], $drafts);
    }

    public function testNoRowIsAddedOrRemovedAndThePageOrderIsTheSame(): void
    {
        $this->assertSame(count(self::$before['sections']), count(self::$after['sections']));
        $this->assertSame(count(self::$before['galleries']), count(self::$after['galleries']));
        $this->assertSame(
            array_map(static fn (array $row): array => [$row['id'], $row['page_id'], $row['sort_order']], self::$before['sections']),
            array_map(static fn (array $row): array => [$row['id'], $row['page_id'], $row['sort_order']], self::$after['sections']),
            'every block where it stood'
        );
        $this->assertSame([], self::$upgraded->rows("SELECT id FROM page_sections WHERE section_type = 'item_gallery' AND section_id IN (SELECT id FROM item_galleries WHERE source_type = 'portfolio')"));
    }

    /* ------------------------------------------------ both */

    public function testRunningThemAgainChangesNothing(): void
    {
        $this->assertSame(self::$after, self::$afterReplay);
    }

    public function testAFreshInstallHasTheTableAndNothingToConvert(): void
    {
        $this->assertTrue(self::$fresh->hasTable('shop_listing_blocks'));
        $this->assertSame([], self::$fresh->rows("SELECT id FROM page_sections WHERE section_type = 'item_gallery' AND section_id IN (SELECT id FROM item_galleries WHERE source_type = 'portfolio')"));

        $columns = static fn (ScratchInstall $install): array => $install->rows(
            "SELECT column_name, column_type, is_nullable, column_default FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'shop_listing_blocks' ORDER BY ordinal_position"
        );
        $this->assertSame($columns(self::$upgraded), $columns(self::$fresh), 'upgrade and fresh end on the same table');
    }

    /* ------------------------------------------------ fixtures */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $storefront = self::page($install, 'zz-p9-shop');
        $other = self::page($install, 'zz-p9-page');

        // The historical storefront: no key, section_id 0, as the old
        // install bootstrap attached them.
        self::$sections['storefront-grid'] = self::section($install, $storefront, 'product_grid', null, 0, 10, 1);
        self::$sections['storefront-tiles'] = self::section($install, $storefront, 'shop_collections', null, 0, 5, 1);
        // Placed by hand: the page id as section_id.
        self::$sections['page-grid'] = self::section($install, $other, 'product_grid', null, $other['id'], 30, 0);
        self::$sections['page-tiles'] = self::section($install, $other, 'shop_collections', null, $other['id'], 40, 1);
        $pdo->exec("UPDATE page_sections SET appearance_background = 'secondary', appearance_spacing = 'spacious', appearance_decoration = 'glow' WHERE id = " . self::$sections['page-tiles']);

        // Galleries on portfolio items, one sorted by hand, hidden, styled,
        // with a button and words; one with no source at all; one on a
        // collection.
        $item = self::portfolioItem($install);
        self::$galleries['portfolio-gallery'] = self::gallery($install, $other, 'zz-p9-portfolio', ['source_type' => 'portfolio', 'show_filter_bar' => 1, 'enable_lightbox' => 1]);
        self::$sections['portfolio-gallery'] = self::section($install, $other, 'item_gallery', 'zz-p9-portfolio', self::$galleries['portfolio-gallery'], 50, 1);

        self::$galleries['sorted-gallery'] = self::gallery($install, $other, 'zz-p9-sorted', [
            'source_type' => 'portfolio', 'portfolio_scope' => 'manual', 'item_sort' => 'random', 'max_items' => 6,
            'card_presentation' => 'compact', 'button_url' => '/portfolio', 'tight_top' => 1, 'fallback_link_url' => '/elders',
        ]);
        self::$sections['sorted-gallery'] = self::section($install, $other, 'item_gallery', 'zz-p9-sorted', self::$galleries['sorted-gallery'], 60, 0);
        $pdo->exec("UPDATE page_sections SET appearance_border = 'both', appearance_border_tone = 'accent' WHERE id = " . self::$sections['sorted-gallery']);
        $pdo->exec('INSERT INTO item_gallery_portfolio_items (item_gallery_id, portfolio_item_id, sort_order, created_at) VALUES (' . self::$galleries['sorted-gallery'] . ", {$item}, 1, NOW())");
        foreach (['eyebrow' => 'Eerder werk', 'title' => 'Uitgelicht', 'footer_note' => 'En meer', 'button_label' => 'Alles'] as $field => $words) {
            $pdo->exec("INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
                        SELECT 'item_galleries', " . self::$galleries['sorted-gallery'] . ", code, '{$field}', '{$words}', NOW(), NOW() FROM site_languages WHERE is_default = 1");
        }

        // The site search's copy of its words, as Search 2.0 wrote it.
        $pdo->exec("INSERT INTO search_block_texts (page_section_id, page_id, section_type, language_code, heading_text, body_text, updated_at)
                    SELECT " . self::$sections['sorted-gallery'] . ", {$other['id']}, 'item_gallery', code, 'Uitgelicht', 'Eerder werk En meer', NOW() FROM site_languages WHERE is_default = 1");

        self::$galleries['no-source-gallery'] = self::gallery($install, $other, 'zz-p9-nosource', ['source_type' => '']);
        self::$sections['no-source-gallery'] = self::section($install, $other, 'item_gallery', 'zz-p9-nosource', self::$galleries['no-source-gallery'], 70, 1);

        self::$galleries['collection-gallery'] = self::gallery($install, $other, 'zz-p9-collection', ['source_type' => 'collection']);
        self::$sections['collection-gallery'] = self::section($install, $other, 'item_gallery', 'zz-p9-collection', self::$galleries['collection-gallery'], 80, 1);

        // A portfolio gallery still a draft.
        self::$draftGallery = self::gallery($install, $other, 'zz-p9-draft', ['source_type' => 'portfolio']);
        $pdo->exec("INSERT INTO content_block_drafts (page_id, section_type, section_key, section_id, created_at)
                    VALUES ({$other['id']}, 'item_gallery', 'zz-p9-draft', " . self::$draftGallery . ', NOW())');
    }

    /** @return array{id: int, content_key: string} */
    private static function page(ScratchInstall $install, string $key): array
    {
        $pdo = $install->pdo();
        $pdo->exec("INSERT INTO pages (parent_id, admin_group, content_key, slug, status, is_system, route_path, owner_type, sort_order, created_at, updated_at)
                    VALUES (NULL, 'website', '{$key}', '{$key}', 'published', 0, NULL, NULL, 0, NOW(), NOW())");

        return ['id' => (int) $pdo->lastInsertId(), 'content_key' => $key];
    }

    /** @param array{id: int, content_key: string} $page */
    private static function section(ScratchInstall $install, array $page, string $type, ?string $key, int $sectionId, int $sort, int $active): int
    {
        $pdo = $install->pdo();
        $stmt = $pdo->prepare('INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$page['id'], $page['content_key'], $type, $key, $sectionId, $sort, $active]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{id: int, content_key: string} $page
     * @param array<string, int|string> $values
     */
    private static function gallery(ScratchInstall $install, array $page, string $key, array $values): int
    {
        $pdo = $install->pdo();
        $columns = array_merge(['page_slug' => $page['content_key'], 'section_key' => $key, 'is_active' => 1], $values);
        $stmt = $pdo->prepare(
            'INSERT INTO item_galleries (' . implode(', ', array_keys($columns)) . ', created_at, updated_at)
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ', NOW(), NOW())'
        );
        $stmt->execute(array_values($columns));

        return (int) $pdo->lastInsertId();
    }

    private static function portfolioItem(ScratchInstall $install): int
    {
        $pdo = $install->pdo();
        $gallery = (int) ($install->rows('SELECT id FROM portfolio_galleries ORDER BY id LIMIT 1')[0]['id'] ?? 0);
        if ($gallery === 0) {
            $pdo->exec('INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())');
            $gallery = (int) $pdo->lastInsertId();
        }
        $pdo->exec("INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, sort_order, is_active, has_detail_page, slug, created_at, updated_at)
                    VALUES ({$gallery}, 'assets/images/portfolio/zz-p9.jpg', 0, 1, 0, 'zz-p9-project', NOW(), NOW())");

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function state(ScratchInstall $install): array
    {
        return [
            'sections' => $install->rows('SELECT * FROM page_sections ORDER BY id'),
            'galleries' => $install->rows('SELECT * FROM item_galleries ORDER BY id'),
            'picked' => $install->rows('SELECT item_gallery_id, portfolio_item_id, sort_order FROM item_gallery_portfolio_items ORDER BY item_gallery_id, sort_order'),
            'words' => $install->rows("SELECT owner_id, language_code, field, value FROM block_translations WHERE owner_table = 'item_galleries' ORDER BY owner_id, language_code, field"),
            'listings' => $install->hasTable('shop_listing_blocks') ? $install->rows('SELECT id, page_slug, section_key, is_active FROM shop_listing_blocks ORDER BY id') : [],
            'drafts' => $install->rows('SELECT * FROM content_block_drafts ORDER BY id'),
            'index' => $install->rows('SELECT page_section_id, section_type, language_code, heading_text, body_text FROM search_block_texts ORDER BY id'),
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $state
     * @return array<string, mixed>
     */
    private static function sectionIn(array $state, int $id): array
    {
        foreach ($state['sections'] as $row) {
            if ((int) $row['id'] === $id) {
                unset($row['updated_at']);

                return $row;
            }
        }

        self::fail("page section #{$id} is gone");
    }

    /**
     * @param array<string, list<array<string, mixed>>> $state
     * @return array<string, mixed>
     */
    private static function galleryIn(array $state, int $id): array
    {
        foreach ($state['galleries'] as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        self::fail("item_galleries #{$id} is gone");
    }
}
