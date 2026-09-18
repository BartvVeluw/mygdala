<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 5 wave B on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md, BLOG.md):
 *
 *   20260918180000_create_the_blog_translation_tables.php
 *   20260918190000_move_blog_words_into_translation_tables.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before wave B, with posts,
 *              categories and tags in every state their columns could be in
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte,
 * the rich body included; an empty or whitespace value gets no row; several
 * fields of one post share one row per language; and above all EVERY SLUG IS
 * UNTOUCHED, because /blog/<slug>, /blog/categorie/<slug> and /blog/tag/<slug>
 * must answer exactly what they answered before. Ids, status, publication
 * dates, authors, images, noindex, is_active, sort orders and every taxonomy
 * link are not touched either. A second run changes nothing, and words that
 * cannot be moved stop the migration before any column is dropped.
 *
 * NOTHING HERE ASKS WHETHER THE BLOG IS ENABLED. A scratch install runs with
 * no MODULE_* variable set at all, so the module is off, and both databases
 * must still end on the same schema with every word moved.
 */
#[Group('migration-backfill')]
final class BlogWordsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_blog_words_fresh';
    private const UPGRADED = 'mygdala_scratch_blog_words_upgraded';
    private const BROKEN = 'mygdala_scratch_blog_words_broken';

    /** The last migration before wave B. */
    private const BEFORE = '20260918170000';

    private const SCHEMA = '20260918180000';
    private const MOVE = '20260918190000';

    private const NEUTRAL_POST_COLUMNS = 'id, slug, featured_media_id, status, published_at, author_name, noindex, og_media_id';
    private const NEUTRAL_CATEGORY_COLUMNS = 'id, slug, is_active, sort_order';
    private const NEUTRAL_TAG_COLUMNS = 'id, slug';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $rowsAfterFirstRun = 0;
    private static int $rowsAfterReplay = 0;

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenColumnsAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOVE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$ids = self::seed(self::$upgraded);
        self::$neutralBefore['blog_posts'] = self::$upgraded->rows('SELECT ' . self::NEUTRAL_POST_COLUMNS . ' FROM blog_posts ORDER BY id');
        self::$neutralBefore['blog_categories'] = self::$upgraded->rows('SELECT ' . self::NEUTRAL_CATEGORY_COLUMNS . ' FROM blog_categories ORDER BY id');
        self::$neutralBefore['blog_tags'] = self::$upgraded->rows('SELECT ' . self::NEUTRAL_TAG_COLUMNS . ' FROM blog_tags ORDER BY id');
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::rowCount(self::$upgraded);
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::rowCount(self::$upgraded);

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        foreach ([
            'page_translations',
            'block_translations',
            'nav_item_translations',
            'footer_column_translations',
            'footer_link_translations',
            'site_setting_translations',
            'form_translations',
            'form_field_translations',
            'form_field_option_translations',
            'portfolio_category_translations',
            'portfolio_item_translations',
            'portfolio_item_image_translations',
        ] as $table) {
            $broken->pdo()->exec("DELETE FROM {$table} WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column($broken->rows('SHOW COLUMNS FROM blog_posts'), 'Field');
        $broken->drop();
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

    // ------------------------------------------------------------ the schema

    public function testBothDatabasesEndWithTheSameSchemaAndNoWordColumnsLeft(): void
    {
        foreach ([
            'blog_posts',
            'blog_categories',
            'blog_tags',
            'blog_post_translations',
            'blog_category_translations',
            'blog_tag_translations',
        ] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        // The bare Dutch column is gone as well as the _en one, so the only
        // way to ask "what is this post called" is the words store.
        foreach ([
            'blog_posts' => ['title', 'title_en', 'excerpt', 'excerpt_en', 'body', 'body_en', 'meta_title', 'meta_title_en', 'meta_description', 'meta_description_en'],
            'blog_categories' => ['name', 'name_en', 'description', 'description_en'],
            'blog_tags' => ['name', 'name_en'],
        ] as $table => $gone) {
            $columns = array_keys(self::shape(self::$fresh, $table));

            foreach ($gone as $column) {
                self::assertNotContains($column, $columns, $table . '.' . $column . ' should be gone');
            }
        }
    }

    /** THE SLUG STAYED, on all three tables, exactly where a route can find it. */
    public function testEveryTableKeptItsOneLanguageNeutralSlug(): void
    {
        foreach (['blog_posts', 'blog_categories', 'blog_tags'] as $table) {
            self::assertArrayHasKey('slug', self::shape(self::$fresh, $table), $table);
        }

        foreach (['blog_post_translations', 'blog_category_translations', 'blog_tag_translations'] as $table) {
            self::assertArrayNotHasKey('slug', self::shape(self::$fresh, $table), $table . ' must not carry a slug');
        }
    }

    public function testEachTableHasItsOwnerLanguageUniqueAndItsTwoForeignKeys(): void
    {
        foreach ([
            'blog_post_translations' => ['blog_post_id', 'blog_posts'],
            'blog_category_translations' => ['blog_category_id', 'blog_categories'],
            'blog_tag_translations' => ['blog_tag_id', 'blog_tags'],
        ] as $table => [$ownerColumn, $ownerTable]) {
            $unique = self::$fresh->rows(
                "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
                   FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 AND index_name <> 'PRIMARY'
                  GROUP BY index_name",
                [$table]
            );
            self::assertSame([['columns_in_index' => $ownerColumn . ',language_code']], $unique, $table);

            $keys = self::$fresh->rows(
                "SELECT k.column_name AS own, k.referenced_table_name AS points_at, r.delete_rule AS on_delete
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_name = k.constraint_name AND r.constraint_schema = k.constraint_schema
                  WHERE k.table_schema = DATABASE() AND k.table_name = ?
                  ORDER BY k.column_name",
                [$table]
            );

            self::assertEqualsCanonicalizing(
                [
                    ['own' => 'language_code', 'points_at' => 'site_languages', 'on_delete' => 'RESTRICT'],
                    ['own' => $ownerColumn, 'points_at' => $ownerTable, 'on_delete' => 'CASCADE'],
                ],
                $keys,
                $table . ': the owner cascades, the language is refused'
            );
        }
    }

    // ------------------------------------------------------------- the words

    public function testAPostKeepsEveryFieldPerLanguageInOneRow(): void
    {
        self::assertSame(
            [
                [
                    'language_code' => 'en',
                    'title' => 'About wood',
                    'excerpt' => null,
                    'body' => '<p>Made of oak</p>',
                    'meta_title' => 'About wood | Blog',
                    'meta_description' => null,
                ],
                [
                    'language_code' => 'nl',
                    'title' => 'Over hout',
                    'excerpt' => 'Een korte inleiding.',
                    'body' => '<p>Van eiken hout</p>',
                    'meta_title' => 'Over hout | Blog',
                    'meta_description' => 'Waarom eiken.',
                ],
            ],
            self::$upgraded->rows(
                'SELECT language_code, title, excerpt, body, meta_title, meta_description
                   FROM blog_post_translations WHERE blog_post_id = ? ORDER BY language_code',
                [self::$ids['post']]
            ),
            'five fields of one post share one row per language, whitespace excluded'
        );
    }

    /** A post with a Dutch title and nothing else has one row with one value. */
    public function testAPostWithOnlyATitleHasOneValue(): void
    {
        self::assertSame(
            [['language_code' => 'nl', 'title' => 'Alleen een titel', 'excerpt' => null, 'body' => null]],
            self::$upgraded->rows(
                'SELECT language_code, title, excerpt, body FROM blog_post_translations
                  WHERE blog_post_id = ? ORDER BY language_code',
                [self::$ids['bare_post']]
            )
        );
    }

    public function testACategoryAndATagKeepTheirNamesPerLanguage(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'name' => 'Materials', 'description' => null],
                ['language_code' => 'nl', 'name' => 'Materialen', 'description' => 'Waar we mee werken.'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, name, description FROM blog_category_translations
                  WHERE blog_category_id = ? ORDER BY language_code',
                [self::$ids['category']]
            )
        );

        self::assertSame(
            [
                ['language_code' => 'en', 'name' => 'laser cutting'],
                ['language_code' => 'nl', 'name' => 'lasersnijden'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, name FROM blog_tag_translations WHERE blog_tag_id = ? ORDER BY language_code',
                [self::$ids['tag']]
            )
        );

        self::assertSame(
            [['language_code' => 'nl', 'name' => 'hout']],
            self::$upgraded->rows(
                'SELECT language_code, name FROM blog_tag_translations WHERE blog_tag_id = ? ORDER BY language_code',
                [self::$ids['untranslated_tag']]
            ),
            'whitespace is no translation'
        );
    }

    /** A value is copied, never trimmed, sanitized or re-encoded on the way. */
    public function testWordsAreCopiedByteForByte(): void
    {
        self::assertSame(
            [['title' => " Ruimte  eromheen \u{00a0}", 'body' => '<p>Ampersand &amp; "quotes"</p>']],
            self::$upgraded->rows(
                "SELECT title, body FROM blog_post_translations
                  WHERE blog_post_id = ? AND language_code = 'nl'",
                [self::$ids['exotic_post']]
            )
        );
    }

    // ------------------------------------------------- what must not change

    public function testNothingButTheWordsMoved(): void
    {
        foreach ([
            'blog_posts' => self::NEUTRAL_POST_COLUMNS,
            'blog_categories' => self::NEUTRAL_CATEGORY_COLUMNS,
            'blog_tags' => self::NEUTRAL_TAG_COLUMNS,
        ] as $table => $columns) {
            self::assertSame(
                self::$neutralBefore[$table],
                self::$upgraded->rows('SELECT ' . $columns . ' FROM ' . $table . ' ORDER BY id'),
                $table . ': ids, slugs, status, dates, images, flags and orders are untouched'
            );
        }
    }

    /** The links between posts, categories and tags are language-neutral and stay. */
    public function testTheTaxonomyLinksAreUntouched(): void
    {
        self::assertSame(
            [['post_id' => self::$ids['post'], 'category_id' => self::$ids['category']]],
            self::$upgraded->rows('SELECT post_id, category_id FROM blog_post_categories ORDER BY post_id, category_id')
        );

        self::assertSame(
            [['post_id' => self::$ids['post'], 'tag_id' => self::$ids['tag']]],
            self::$upgraded->rows('SELECT post_id, tag_id FROM blog_post_tags ORDER BY post_id, tag_id')
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertGreaterThan(0, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    // ------------------------------------------------------- refuses to lose

    public function testWordsInALanguageTheRegistryDoesNotHaveStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration should have refused');
        self::assertStringContainsString('blog_posts', (string) self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);

        foreach (['title', 'title_en', 'body', 'body_en', 'meta_description_en'] as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure, $column . ' must still be there');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Posts, categories and tags in every state their columns could be in:
     * both languages, Dutch only, whitespace, empty, and one with characters
     * a careless copy would change. Plus one link of each kind, so the
     * taxonomy can be checked afterwards.
     *
     * @return array<string, int>
     */
    private static function seed(ScratchInstall $install): array
    {
        $ids = [];
        $pdo = $install->pdo();

        $post = $pdo->prepare(
            'INSERT INTO blog_posts
                (title, title_en, slug, excerpt, excerpt_en, body, body_en, status, published_at,
                 author_name, meta_title, meta_title_en, meta_description, meta_description_en, noindex, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $post->execute([
            'Over hout', 'About wood', 'zz-over-hout',
            'Een korte inleiding.', "  \n ",
            '<p>Van eiken hout</p>', '<p>Made of oak</p>',
            'published', '2026-01-02 03:04:05',
            'ZZ Auteur', 'Over hout | Blog', 'About wood | Blog', 'Waarom eiken.', '',
            0, '2026-01-02 03:04:05', '2026-02-03 04:05:06',
        ]);
        $ids['post'] = (int) $pdo->lastInsertId();

        $post->execute([
            'Alleen een titel', '   ', 'zz-alleen-een-titel',
            '', null, null, "\t",
            'draft', null,
            null, null, null, null, null,
            0, '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['bare_post'] = (int) $pdo->lastInsertId();

        $post->execute([
            " Ruimte  eromheen \u{00a0}", null, 'zz-exotisch',
            null, null, '<p>Ampersand &amp; "quotes"</p>', null,
            'draft', null,
            null, null, null, null, null,
            1, '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['exotic_post'] = (int) $pdo->lastInsertId();

        $category = $pdo->prepare(
            'INSERT INTO blog_categories (name, name_en, slug, description, description_en, is_active, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $category->execute([
            'Materialen', 'Materials', 'zz-materialen', 'Waar we mee werken.', "   ",
            1, 10, '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['category'] = (int) $pdo->lastInsertId();

        $tag = $pdo->prepare(
            'INSERT INTO blog_tags (name, name_en, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $tag->execute(['lasersnijden', 'laser cutting', 'zz-lasersnijden', '2026-01-02 03:04:05', '2026-01-02 03:04:05']);
        $ids['tag'] = (int) $pdo->lastInsertId();

        $tag->execute(['hout', "\r\n", 'zz-hout', '2026-01-02 03:04:05', '2026-01-02 03:04:05']);
        $ids['untranslated_tag'] = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?, ?)')
            ->execute([$ids['post'], $ids['category']]);
        $pdo->prepare('INSERT INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)')
            ->execute([$ids['post'], $ids['tag']]);

        return $ids;
    }

    private static function rowCount(ScratchInstall $install): int
    {
        return $install->count('blog_post_translations')
            + $install->count('blog_category_translations')
            + $install->count('blog_tag_translations');
    }

    /** @return array<string, string> column => type */
    private static function shape(ScratchInstall $install, string $table): array
    {
        $shape = [];
        foreach ($install->rows('SHOW COLUMNS FROM ' . $table) as $column) {
            $shape[(string) $column['Field']] = (string) $column['Type'];
        }

        return $shape;
    }
}
