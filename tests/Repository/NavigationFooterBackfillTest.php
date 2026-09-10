<?php

namespace Tests\Repository;

use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Service\LinkResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the nav_items/footer_columns/footer_links backfill migrations
 * (db/migrations/20260907210000_create_nav_items_table.php and
 * .../20260907220000_create_footer_tables.php) reproduce the previously
 * hardcoded partials/nav-config.php menu and footer columns — i.e. that
 * migrating an existing database did not lose, duplicate or reorder the
 * navigation/footer that existed at the time. Requires the migrations to
 * have been run against the database this test suite connects to (run
 * `phinx migrate` first if this fails with "no rows").
 *
 * These assertions are deliberately about the rows the MIGRATION owns, and
 * never about how many rows the menu or the footer has today. Navigation and
 * footer are editable content: an administrator may add a seventh menu item
 * or a fifth footer column at any moment, and a test pinning today's totals
 * would only assert that nobody has used the CMS yet — which is exactly how
 * this test broke the first time a fifth footer column was created from the
 * admin (same lesson as Tests\Repository\PageSectionsBackfillTest, which
 * broke the first time the homepage's blocks were reordered).
 *
 * So, per migrated row: it is still there, exactly once, with the label and
 * the destination the migration gave it, and in the same order relative to
 * the other migrated rows. "Exactly once" is also this test's idempotency
 * check — running either backfill a second time is what would produce a
 * duplicate, and both migrations guard against that with a COUNT check
 * before they seed.
 */
#[Group('migration-backfill')]
class NavigationFooterBackfillTest extends TestCase
{
    /**
     * The six partials/nav-config.php entries, in their original order, by
     * the URL each one resolves to.
     *
     * Asserted by DESTINATION rather than by target_route: Diensten,
     * Portfolio, Over mij and Contact are linked by pages.id since
     * 20260908260000_link_content_pages_as_pages_not_routes.php (they are
     * content pages, not application routes). The menu itself — same items,
     * same order, same URLs — is unchanged, which is what this backfill test
     * is about.
     *
     * @var array<string, string>
     */
    private const MIGRATED_MENU = [
        'Home' => '/index.php',
        'Diensten' => '/diensten.php',
        'Portfolio' => '/portfolio.php',
        'Shop' => '/shop.php',
        'Over mij' => '/over-mij.php',
        'Contact' => '/contact.php',
    ];

    /** The four hardcoded partials/footer.php columns: title_nl => title_en. */
    private const MIGRATED_COLUMNS = [
        'Navigatie' => 'Navigation',
        'Materialen' => 'Materials',
        'Informatie' => 'Information',
        'Contact' => 'Contact',
    ];

    /**
     * Every link the footer backfill created, per column, by the URL it
     * resolves to — the anchors, the route links and the information pages
     * the old template built by hand.
     *
     * @var array<string, array<string, string>>
     */
    private const MIGRATED_LINKS = [
        'Navigatie' => [
            'Home' => '/index.php',
            'Diensten' => '/diensten.php',
            'Portfolio' => '/portfolio.php',
            'Shop' => '/shop.php',
            'Over mij' => '/over-mij.php',
        ],
        'Materialen' => [
            'Hout graveren' => '/diensten.php#hout',
            'Metaal graveren' => '/diensten.php#metaal',
            'Acryl & glas' => '/diensten.php#acryl-glas',
            'Zakelijk' => '/diensten.php#zakelijk',
        ],
        'Informatie' => [
            'Verzenden & retourneren' => '/verzenden-retourneren',
            'Algemene voorwaarden' => '/algemene-voorwaarden',
            'Privacyverklaring' => '/privacyverklaring',
            'Herroepingsrecht' => '/herroeping.php',
        ],
        'Contact' => [
            'Offerte aanvragen' => '/contact.php',
        ],
    ];

    private FooterRepository $footer;

    /** @var list<int> columns this test created and must clean up */
    private array $createdColumnIds = [];

    protected function setUp(): void
    {
        $this->footer = new FooterRepository();
    }

    /**
     * Runs whether the assertions passed or not, so a failing run never
     * leaves an extra column behind for the next one.
     */
    protected function tearDown(): void
    {
        foreach ($this->createdColumnIds as $id) {
            $this->footer->deleteColumn($id);
        }
        $this->createdColumnIds = [];
    }

    // ------------------------------------------------------------ navigation

    public function testEveryMigratedMenuItemIsStillThereExactlyOnceAndInOrder(): void
    {
        $this->assertSame(
            array_keys(self::MIGRATED_MENU),
            array_column($this->migratedMenuItems(), 'label_nl'),
            'the backfilled menu items must all still be top-level, once each, in their original order — run phinx migrate'
        );
    }

    public function testEveryMigratedMenuItemStillPointsAtTheSameUrl(): void
    {
        foreach ($this->migratedMenuItems() as $item) {
            $label = (string) $item['label_nl'];

            $this->assertSame(
                self::MIGRATED_MENU[$label],
                LinkResolver::resolve($item)['href'] ?? null,
                "\"{$label}\" must still link where partials/nav-config.php linked it"
            );
        }
    }

    public function testContentPagesInTheMenuAreLinkedByPageIdNotByAHardcodedRoute(): void
    {
        $byLabel = array_column($this->migratedMenuItems(), null, 'label_nl');

        foreach (['Diensten', 'Portfolio', 'Over mij', 'Contact'] as $label) {
            $this->assertArrayHasKey($label, $byLabel);
            $this->assertSame('page', (string) $byLabel[$label]['link_type'], "\"{$label}\" must link to its CMS page");
            $this->assertNotNull($byLabel[$label]['target_page_id']);
            $this->assertNull($byLabel[$label]['target_route']);
        }
    }

    // ---------------------------------------------------------------- footer

    public function testEveryMigratedFooterColumnIsStillThereExactlyOnceAndInOrder(): void
    {
        $this->assertMigratedFooterColumnsAreIntact();
    }

    public function testEveryMigratedFooterLinkIsStillThereExactlyOnceAndInOrder(): void
    {
        $this->assertMigratedFooterLinksAreIntact();
    }

    /**
     * The regression this case exists for: a fifth footer column created by
     * an administrator is ordinary application data, and the historical
     * guarantee has to keep holding around it. Without this, the only proof
     * that the test tolerates later editor content would be whatever happens
     * to be in the copied snapshot on the day it runs.
     */
    public function testAColumnAddedInTheCmsDoesNotBreakTheMigrationGuarantee(): void
    {
        $this->makeEditorColumn('Zz testkolom');

        $titles = array_map(
            static fn (array $c): string => (string) $c['title_nl'],
            $this->footer->findAllColumnsForAdmin()
        );
        $this->assertContains('Zz testkolom', $titles, 'the extra column must really be in the footer while we assert');
        $this->assertGreaterThan(count(self::MIGRATED_COLUMNS), count($titles));

        $this->assertMigratedFooterColumnsAreIntact();
        $this->assertMigratedFooterLinksAreIntact();
    }

    // --------------------------------------------------------------- helpers

    private function assertMigratedFooterColumnsAreIntact(): void
    {
        $migrated = $this->migratedFooterColumns();

        $this->assertSame(
            array_keys(self::MIGRATED_COLUMNS),
            array_column($migrated, 'title_nl'),
            'the backfilled footer columns must all still be there, once each, in their original order — run phinx migrate'
        );

        foreach ($migrated as $column) {
            $this->assertSame(
                self::MIGRATED_COLUMNS[(string) $column['title_nl']],
                (string) $column['title_en'],
                'the English column title the migration wrote must have survived'
            );
        }
    }

    private function assertMigratedFooterLinksAreIntact(): void
    {
        foreach ($this->migratedFooterColumns() as $column) {
            $title = (string) $column['title_nl'];
            $expected = self::MIGRATED_LINKS[$title];

            $migrated = array_values(array_filter(
                $this->footer->findLinksForColumn((int) $column['id']),
                static fn (array $l): bool => isset($expected[(string) $l['label_nl']])
            ));

            $this->assertSame(
                array_keys($expected),
                array_column($migrated, 'label_nl'),
                "the links the migration put in \"{$title}\" must all still be there, once each, in their original order"
            );

            foreach ($migrated as $link) {
                $label = (string) $link['label_nl'];
                $this->assertSame(
                    $expected[$label],
                    LinkResolver::resolve($link)['href'] ?? null,
                    "\"{$title} / {$label}\" must still link where partials/footer.php linked it"
                );
            }
        }
    }

    /**
     * The migrated top-level menu items, in the order the header renders
     * them, with every later editor-created item filtered out.
     *
     * @return list<array<string, mixed>>
     */
    private function migratedMenuItems(): array
    {
        $migrated = array_values(array_filter(
            (new NavigationRepository())->findAllForAdmin(),
            static fn (array $r): bool => $r['parent_id'] === null
                && isset(self::MIGRATED_MENU[(string) $r['label_nl']])
        ));

        $this->assertNotEmpty($migrated, 'expected the backfill migration to have seeded nav_items — run phinx migrate');

        return $migrated;
    }

    /**
     * The migrated columns, in the order the footer renders them, with every
     * later editor-created column filtered out.
     *
     * @return list<array<string, mixed>>
     */
    private function migratedFooterColumns(): array
    {
        $columns = array_values(array_filter(
            $this->footer->findAllColumnsForAdmin(),
            static fn (array $c): bool => isset(self::MIGRATED_COLUMNS[(string) $c['title_nl']])
        ));

        $this->assertNotEmpty($columns, 'expected the backfill migration to have seeded footer_columns — run phinx migrate');

        return $columns;
    }

    private function makeEditorColumn(string $title): int
    {
        $id = $this->footer->createColumn(['title_nl' => $title, 'title_en' => $title, 'is_visible' => true]);
        $this->createdColumnIds[] = $id;

        return $id;
    }
}
