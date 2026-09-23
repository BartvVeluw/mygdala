<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 6: what the two slug migrations do to a FRESH
 * installation and to an EXISTING one (docs/multilingual/MIGRATIONS.md,
 * docs/multilingual/ROUTING.md).
 *
 *   20260920100000   page_translations.slug
 *   20260920110000   the same for blog posts, categories, tags and collections
 *
 * The promise being defended is COMPATIBILITY: not one existing URL moves, not
 * one id changes, not one word is lost — and every language that already had
 * words ends up with an address of its own, while a language that had none
 * ends up with no route at all.
 *
 * Both databases are built from zero through Phinx itself
 * (Tests\Support\ScratchInstall), and the upgraded one is stopped at the last
 * migration BEFORE this phase, filled the way a real installation would have
 * been, and then allowed to catch up. Nothing here touches the development
 * database, the test database or any other installation.
 */
#[Group('migration-backfill')]
final class LocalizedSlugMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_localized_slugs_fresh';
    private const UPGRADED = 'mygdala_scratch_localized_slugs_upgraded';

    /** The last migration before this phase. */
    private const BEFORE = '20260918260000';

    private const PAGES = '20260920100000';
    private const MODULES = '20260920110000';

    /** table => owner column, for the five tables that gained an address. */
    private const SLUG_TABLES = [
        'page_translations' => 'page_id',
        'blog_post_translations' => 'blog_post_id',
        'blog_category_translations' => 'blog_category_id',
        'blog_tag_translations' => 'blog_tag_id',
        'collection_translations' => 'collection_id',
    ];

    /** The neutral tables whose rows must come through byte-identical. */
    private const NEUTRAL_TABLES = ['pages', 'blog_posts', 'blog_categories', 'blog_tags', 'collections', 'nav_items'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

    /** @var array<string, list<array<string, mixed>>> the translated WORDS before the upgrade */
    private static array $wordsBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);

        foreach (self::NEUTRAL_TABLES as $table) {
            self::$neutralBefore[$table] = self::$upgraded->rows('SELECT * FROM `' . $table . '` ORDER BY id');
        }
        self::$wordsBefore['page_translations'] = self::$upgraded->rows(
            'SELECT page_id, language_code, title, meta_title, meta_description FROM page_translations ORDER BY page_id, language_code'
        );
        self::$wordsBefore['blog_post_translations'] = self::$upgraded->rows(
            'SELECT blog_post_id, language_code, title, excerpt, body FROM blog_post_translations ORDER BY blog_post_id, language_code'
        );

        self::$upgraded->catchUp();
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
        self::$fresh = null;
        self::$upgraded = null;
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available() || self::$fresh === null || self::$upgraded === null) {
            $this->markTestSkipped('no database server to build a scratch installation on');
        }
    }

    /**
     * What an installation looked like the day before this phase: pages, blog
     * posts, a category, a tag and a collection, some in both languages, some
     * in one, plus the awkward ones a real site accumulates.
     */
    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $page = static function (string $key, ?string $nl, ?string $en, ?string $routePath = null) use ($pdo): void {
            $pdo->prepare(
                'INSERT INTO pages (content_key, slug, status, is_system, route_path, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 50, NOW(), NOW())'
            )->execute([$key, $key, 'published', $routePath === null ? 0 : 1, $routePath]);

            $id = (int) $pdo->lastInsertId();

            foreach (['nl' => $nl, 'en' => $en] as $code => $title) {
                if ($title !== null) {
                    $pdo->prepare(
                        'INSERT INTO page_translations (page_id, language_code, title, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())'
                    )->execute([$id, $code, $title]);
                }
            }
        };

        $page('zz-both', 'Over ons', 'About us');
        $page('zz-dutch-only', 'Alleen Nederlands', null);
        $page('zz-english-only', null, 'Only English');
        // Two pages whose English title is the same: the second one's
        // generated address has to take the CMS's own "-2".
        $page('zz-twin-a', 'Tweeling A', 'The same title');
        $page('zz-twin-b', 'Tweeling B', 'The same title');
        // A title the slugger reduces to nothing: no address, so no route.
        $page('zz-unsluggable', 'Symbolen', '***');
        // Served at a fixed route: its address is its route, in every language.
        $page('zz-route-bound', 'Vaste route', 'Fixed route', '/zz-fixed.php');
        // The one slug this phase turns into a reserved word.
        $page('en', 'Botsing', null);
        // English titles whose slug is a word the router owns: a module
        // namespace and a language code.
        $page('zz-reserved-route', 'Weblog', 'Blog');
        $page('zz-reserved-code', 'Taalkeuze', 'NL');

        $pdo->exec(
            "INSERT INTO blog_posts (slug, status, published_at, created_at, updated_at)
             VALUES ('zz-bericht', 'published', NOW() - INTERVAL 1 DAY, NOW(), NOW()),
                    ('zz-alleen-nl', 'published', NOW() - INTERVAL 1 DAY, NOW(), NOW()),
                    ('zz-gereserveerd', 'published', NOW() - INTERVAL 1 DAY, NOW(), NOW())"
        );
        $posts = array_column($install->rows("SELECT id, slug FROM blog_posts WHERE slug LIKE 'zz-%'"), 'id', 'slug');
        $pdo->prepare(
            "INSERT INTO blog_post_translations (blog_post_id, language_code, title, excerpt, body, created_at, updated_at) VALUES
             (?, 'nl', 'Mijn bericht', 'Samenvatting.', '<p>Inhoud.</p>', NOW(), NOW()),
             (?, 'en', 'My post', 'Summary.', '<p>Content.</p>', NOW(), NOW()),
             (?, 'nl', 'Alleen Nederlands', NULL, NULL, NOW(), NOW()),
             (?, 'nl', 'Etiket', NULL, NULL, NOW(), NOW()),
             (?, 'en', 'Tag', NULL, NULL, NOW(), NOW())"
        )->execute([
            $posts['zz-bericht'],
            $posts['zz-bericht'],
            $posts['zz-alleen-nl'],
            $posts['zz-gereserveerd'],
            $posts['zz-gereserveerd'],
        ]);

        $pdo->exec("INSERT INTO blog_categories (slug, is_active, sort_order, created_at, updated_at) VALUES ('zz-hout', 1, 1, NOW(), NOW())");
        $categoryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO blog_category_translations (blog_category_id, language_code, name, created_at, updated_at) VALUES
             (?, 'nl', 'Hout', NOW(), NOW()), (?, 'en', 'Wood', NOW(), NOW())"
        )->execute([$categoryId, $categoryId]);

        $pdo->exec("INSERT INTO blog_tags (slug, created_at, updated_at) VALUES ('zz-eiken', NOW(), NOW())");
        $tagId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO blog_tag_translations (blog_tag_id, language_code, name, created_at, updated_at) VALUES
             (?, 'nl', 'Eiken', NOW(), NOW()), (?, 'en', 'Oak', NOW(), NOW())"
        )->execute([$tagId, $tagId]);

        $pdo->exec("INSERT INTO collections (slug, is_active, sort_order, created_at, updated_at) VALUES ('zz-cadeaus', 1, 1, NOW(), NOW())");
        $collectionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO collection_translations (collection_id, language_code, name, created_at, updated_at) VALUES
             (?, 'nl', 'Cadeaus', NOW(), NOW()), (?, 'en', 'Gifts', NOW(), NOW())"
        )->execute([$collectionId, $collectionId]);
    }

    /** @return array<string, ?string> language code => slug */
    private static function slugs(ScratchInstall $install, string $table, string $ownerTable, string $neutralSlug): array
    {
        $owner = self::SLUG_TABLES[$table];

        $slugs = [];
        foreach ($install->rows(
            "SELECT t.language_code AS language_code, t.slug AS slug
               FROM `{$table}` t JOIN `{$ownerTable}` o ON o.id = t.`{$owner}`
              WHERE o.slug = ? ORDER BY t.language_code",
            [$neutralSlug]
        ) as $row) {
            $slugs[(string) $row['language_code']] = $row['slug'] === null ? null : (string) $row['slug'];
        }

        return $slugs;
    }

    /* ------------------------------------------------------------------ */
    /* Schema: a fresh install and an upgrade end in the same place        */
    /* ------------------------------------------------------------------ */

    public function testEveryRoutableTableGainedANullableAddressAndAUniqueIndexPerLanguage(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach (array_keys(self::SLUG_TABLES) as $table) {
                $column = $install->rows(
                    'SELECT is_nullable AS is_nullable FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                    [$table, 'slug']
                );
                self::assertCount(1, $column, $table . ' has a slug column');
                self::assertSame('YES', $column[0]['is_nullable'], $table . ': NULL is "no public route in this language"');

                $index = $install->rows(
                    'SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
                       FROM information_schema.statistics
                      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0',
                    [$table, 'uq_' . $table . '_language_slug']
                );
                self::assertSame('language_code,slug', $index[0]['columns'] ?? null, $table);
            }
        }
    }

    public function testAProductGetsNoAddressBecauseItHasNoSlugUrl(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame([], $install->rows(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'product_translations' AND column_name = 'slug'"
            ), 'a product is one page at /product.php?id=…, so a slug there would be a column nothing reads');
        }
    }

    public function testTheNeutralSlugColumnsAreStillThere(): void
    {
        foreach (['pages', 'blog_posts', 'blog_categories', 'blog_tags', 'collections'] as $table) {
            self::assertCount(1, self::$upgraded->rows(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'slug'",
                [$table]
            ), $table . '.slug is the neutral key every existing URL was built from');
        }
    }

    public function testAFreshInstallsHomepageIsRouteBoundAndHasNoSlug(): void
    {
        $rows = self::$fresh->rows(
            "SELECT t.slug AS slug FROM page_translations t JOIN pages p ON p.id = t.page_id WHERE p.route_path = '/'"
        );

        self::assertNotSame([], $rows, 'a fresh install has a homepage with words');
        foreach ($rows as $row) {
            self::assertNull($row['slug'], 'the site root is addressed by its route in every language');
        }
    }

    /* ------------------------------------------------------------------ */
    /* The upgrade: nothing moves, nothing is lost                          */
    /* ------------------------------------------------------------------ */

    public function testNotOneNeutralRowChanged(): void
    {
        foreach (self::NEUTRAL_TABLES as $table) {
            // Compared on the columns the rows had before: catchUp() runs every
            // later migration too, and one that ADDS a column to a neutral table
            // (collections.media_id, 20260923120000) changes no value that was
            // there.
            $before = self::$neutralBefore[$table];
            $columns = $before === [] ? null : array_flip(array_keys($before[0]));
            $after = array_map(
                static fn (array $row): array => $columns === null ? $row : array_intersect_key($row, $columns),
                self::$upgraded->rows('SELECT * FROM `' . $table . '` ORDER BY id')
            );

            self::assertSame(
                $before,
                $after,
                $table . ': same ids, same slugs, same everything — the upgrade only ADDS addresses'
            );
        }
    }

    public function testNotOneWordWasLost(): void
    {
        self::assertSame(
            self::$wordsBefore['blog_post_translations'],
            self::$upgraded->rows(
                'SELECT blog_post_id, language_code, title, excerpt, body FROM blog_post_translations ORDER BY blog_post_id, language_code'
            )
        );

        // Pages gain address-only rows (see below), so the words are compared
        // on the rows that had words.
        self::assertSame(
            self::$wordsBefore['page_translations'],
            self::$upgraded->rows(
                'SELECT page_id, language_code, title, meta_title, meta_description FROM page_translations
                  WHERE title IS NOT NULL OR meta_title IS NOT NULL OR meta_description IS NOT NULL
                  ORDER BY page_id, language_code'
            )
        );
    }

    public function testTheDefaultLanguagesAddressIsTheNeutralSlugByteForByte(): void
    {
        self::assertSame('zz-both', self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-both')['nl']);
        self::assertSame('zz-bericht', self::slugs(self::$upgraded, 'blog_post_translations', 'blog_posts', 'zz-bericht')['nl']);
        self::assertSame('zz-hout', self::slugs(self::$upgraded, 'blog_category_translations', 'blog_categories', 'zz-hout')['nl']);
        self::assertSame('zz-eiken', self::slugs(self::$upgraded, 'blog_tag_translations', 'blog_tags', 'zz-eiken')['nl']);
        self::assertSame('zz-cadeaus', self::slugs(self::$upgraded, 'collection_translations', 'collections', 'zz-cadeaus')['nl']);
    }

    public function testALanguageThatHadWordsGetsAnAddressMadeFromThoseWords(): void
    {
        self::assertSame('about-us', self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-both')['en']);
        self::assertSame('my-post', self::slugs(self::$upgraded, 'blog_post_translations', 'blog_posts', 'zz-bericht')['en']);
        self::assertSame('wood', self::slugs(self::$upgraded, 'blog_category_translations', 'blog_categories', 'zz-hout')['en']);
        self::assertSame('oak', self::slugs(self::$upgraded, 'blog_tag_translations', 'blog_tags', 'zz-eiken')['en']);
        self::assertSame('gifts', self::slugs(self::$upgraded, 'collection_translations', 'collections', 'zz-cadeaus')['en']);
    }

    public function testALanguageWithoutWordsGetsNoAddressAndThereforeNoRoute(): void
    {
        self::assertSame(
            ['nl' => 'zz-dutch-only'],
            self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-dutch-only'),
            'no English words, so no English row and no English URL'
        );
        self::assertSame(
            ['nl' => 'zz-alleen-nl'],
            self::slugs(self::$upgraded, 'blog_post_translations', 'blog_posts', 'zz-alleen-nl')
        );
    }

    public function testAPageWithOnlyEnglishWordsStillKeepsItsOwnUrl(): void
    {
        // Its URL was /zz-english-only, and that is the DEFAULT language's URL
        // whatever language its words are in: it gains a Dutch row carrying
        // an address and nothing else.
        self::assertSame(
            ['en' => 'only-english', 'nl' => 'zz-english-only'],
            self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-english-only')
        );
    }

    public function testACollisionInsideOneLanguageTakesTheCmsOwnSuffix(): void
    {
        $a = self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-twin-a')['en'];
        $b = self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-twin-b')['en'];

        self::assertSame(['the-same-title', 'the-same-title-2'], [$a, $b]);
    }

    /**
     * REGRESSION. A generated address is a NEW URL, published the moment the
     * migration writes it, so it has to obey the same reserved words the CMS
     * enforces when it makes a first address itself: /en/blog would otherwise
     * be a page hidden behind the Blog's own index, /en/nl a page that can
     * never be reached, and the next save of either refused. The way out is
     * the CMS's own: "-2" for a page (App\Service\PageService::generateSlug()),
     * "bericht" for a blog entity (App\Service\Blog\BlogSlug::unique()).
     */
    public function testAGeneratedAddressNeverClaimsAWordTheRouterOwns(): void
    {
        self::assertSame('blog-2', self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-reserved-route')['en']);
        self::assertSame('nl-2', self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-reserved-code')['en']);
        self::assertSame('bericht', self::slugs(self::$upgraded, 'blog_post_translations', 'blog_posts', 'zz-gereserveerd')['en']);

        // The default language's addresses are the existing URLs, and those
        // are never renamed (see the next test for the one that clashes).
        self::assertSame('zz-reserved-route', self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-reserved-route')['nl']);
    }

    public function testWordsThatYieldNoSlugLeaveTheLanguageWithoutARoute(): void
    {
        $slugs = self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-unsluggable');

        self::assertSame('zz-unsluggable', $slugs['nl']);
        self::assertNull($slugs['en'], 'an invented "pagina-7" would be a URL nobody asked for');
    }

    public function testARouteBoundPageHasNoSlugInAnyLanguage(): void
    {
        foreach (self::slugs(self::$upgraded, 'page_translations', 'pages', 'zz-route-bound') as $code => $slug) {
            self::assertNull($slug, $code . ': its address is its route');
        }
    }

    public function testAPageWhoseSlugBecameALanguageWordIsReportedAndLeftAlone(): void
    {
        // Renaming somebody's live URL is not a migration's decision. The row
        // is untouched; the CMS refuses the next save of that slug instead.
        self::assertSame(
            [['slug' => 'en', 'status' => 'published']],
            self::$upgraded->rows("SELECT slug, status FROM pages WHERE content_key = 'en'")
        );
    }

    /* ------------------------------------------------------------------ */
    /* Idempotent                                                           */
    /* ------------------------------------------------------------------ */

    public function testRunningBothMigrationsAgainChangesNothing(): void
    {
        $snapshot = static function (ScratchInstall $install): array {
            $state = [];
            foreach (self::SLUG_TABLES as $table => $owner) {
                $state[$table] = $install->rows(
                    "SELECT `{$owner}` AS owner_id, language_code, slug FROM `{$table}` ORDER BY `{$owner}`, language_code"
                );
            }

            return $state;
        };

        $before = $snapshot(self::$upgraded);

        self::$upgraded->replay(self::PAGES);
        self::$upgraded->replay(self::MODULES);

        self::assertSame($before, $snapshot(self::$upgraded), 'the file that ships, run again on the state its own first run produced');
    }
}
