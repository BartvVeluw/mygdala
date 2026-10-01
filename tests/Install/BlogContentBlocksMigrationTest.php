<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Blog 2.0's migration (20261011100000) on a database built from zero and on
 * one upgraded from the migration before it, seeded first the way a live
 * blog looks: posts in every state (published, draft, scheduled in the
 * future and in the past), two languages with their own slugs and SEO text,
 * a classic rich-text body, a featured and a social image, categories and
 * tags, and a stored slug redirect.
 *
 *   - every existing post is 'legacy', so it keeps showing its body;
 *   - no column of any row it had changes — posts, translations, links,
 *     taxonomy, media, redirects;
 *   - the link table exists with real RESTRICT keys and is empty;
 *   - both installs end on the same schema, and running it again changes
 *     nothing.
 */
#[Group('migration-backfill')]
final class BlogContentBlocksMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_blog_blocks_fresh';
    private const UPGRADED = 'mygdala_scratch_blog_blocks_upgraded';

    /** The last migration before Blog 2.0. */
    private const BEFORE = '20261010100000';

    private const MIGRATION = '20261011100000';

    /** Every table the seeded blog lives in, and its stable order. */
    private const TABLES = [
        'blog_posts' => 'id',
        'blog_post_translations' => 'id',
        'blog_categories' => 'id',
        'blog_category_translations' => 'id',
        'blog_tags' => 'id',
        'blog_tag_translations' => 'id',
        'blog_post_categories' => 'post_id, category_id',
        'blog_post_tags' => 'post_id, tag_id',
        'media' => 'id',
        'redirects' => 'id',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterFirstRun = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded->pdo());
        self::$before = self::snapshot(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::snapshot(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
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

    public function testEveryExistingPostKeepsItsClassicBody(): void
    {
        $modes = self::$upgraded->rows('SELECT slug, content_mode FROM blog_posts ORDER BY id');

        self::assertCount(5, $modes);
        foreach ($modes as $row) {
            self::assertSame('legacy', $row['content_mode'], $row['slug']);
        }
    }

    public function testNoRowOfTheBlogChanges(): void
    {
        foreach (self::TABLES as $table => $order) {
            self::assertNotSame([], self::$before[$table], $table . ' was seeded');

            // Compared on the columns the rows had before: a new column is not a change.
            $columns = array_keys(self::$before[$table][0]);
            $after = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($columns)), self::$afterFirstRun[$table]);

            self::assertSame(self::$before[$table], $after, $table);
        }
    }

    public function testTheLinkTableHasRealKeysAndNoRows(): void
    {
        self::assertSame([['total' => 0]], self::$upgraded->rows('SELECT COUNT(*) AS total FROM blog_post_content_pages'));

        $keys = self::$upgraded->rows(
            "SELECT k.column_name AS col, k.referenced_table_name AS ref, r.delete_rule AS on_delete
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name = 'blog_post_content_pages'
              ORDER BY k.column_name"
        );

        self::assertSame([
            ['col' => 'blog_post_id', 'ref' => 'blog_posts', 'on_delete' => 'RESTRICT'],
            ['col' => 'page_id', 'ref' => 'pages', 'on_delete' => 'RESTRICT'],
        ], $keys);
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        foreach (['blog_posts', 'blog_post_content_pages'] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        self::assertContains(
            ['name' => 'content_mode', 'type' => 'varchar(10)', 'nullable' => 'NO', 'default' => 'legacy'],
            self::shape(self::$fresh, 'blog_posts')
        );
    }

    private static function seed(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO media (path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
                    VALUES ('/assets/media/zz-blog-hero.jpg', 'hero.jpg', 'Hero', 'image/jpeg', 1600, 900, 1000, 'Een werkplaats', NOW(), NOW()),
                           ('/assets/media/zz-blog-share.jpg', 'share.jpg', 'Deelbeeld', 'image/jpeg', 1200, 630, 900, 'Deelbeeld', NOW(), NOW())");
        $hero = (int) $pdo->query("SELECT id FROM media WHERE path = '/assets/media/zz-blog-hero.jpg'")->fetchColumn();
        $share = (int) $pdo->query("SELECT id FROM media WHERE path = '/assets/media/zz-blog-share.jpg'")->fetchColumn();

        $posts = [
            ['zz-gepubliceerd', 'published', '2026-01-10 09:00:00', $hero, $share, 0],
            ['zz-concept', 'draft', null, null, null, 0],
            ['zz-ingepland-later', 'scheduled', '2099-01-01 08:00:00', null, null, 0],
            ['zz-ingepland-voorbij', 'scheduled', '2026-02-01 08:00:00', $hero, null, 1],
            ['zz-oud-adres', 'published', '2025-12-24 18:30:00', null, null, 0],
        ];

        $insert = $pdo->prepare('INSERT INTO blog_posts (slug, featured_media_id, status, published_at, author_name, noindex, og_media_id, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, \'2026-01-01 10:00:00\', \'2026-03-01 10:00:00\')');
        $words = $pdo->prepare('INSERT INTO blog_post_translations (blog_post_id, language_code, slug, title, excerpt, body, meta_title, meta_description, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $ids = [];

        foreach ($posts as [$slug, $status, $at, $image, $og, $noindex]) {
            $insert->execute([$slug, $image, $status, $at, 'Inge', $noindex, $og]);
            $id = (int) $pdo->lastInsertId();
            $ids[] = $id;
            $words->execute([$id, 'nl', $slug, 'Titel ' . $slug, 'Samenvatting', '<p>Klassieke <strong>tekst</strong> met <a href="/contact">een link</a>.</p><figure><img src="/assets/media/zz-blog-hero.jpg" alt="Werkplaats"></figure>', 'SEO ' . $slug, 'Beschrijving']);
            $words->execute([$id, 'en', $slug . '-en', 'Title ' . $slug, 'Summary', '<p>Classic <em>text</em>.</p>', '', 'Description']);
        }

        $pdo->exec("INSERT INTO blog_categories (slug, is_active, sort_order, created_at, updated_at) VALUES ('zz-hout', 1, 1, NOW(), NOW())");
        $category = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO blog_category_translations (blog_category_id, language_code, slug, name, description, created_at, updated_at)
                    VALUES ({$category}, 'nl', 'zz-hout', 'Hout', 'Over hout', NOW(), NOW()), ({$category}, 'en', 'zz-wood', 'Wood', '', NOW(), NOW())");
        $pdo->exec("INSERT INTO blog_tags (slug, created_at, updated_at) VALUES ('zz-eiken', NOW(), NOW())");
        $tag = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO blog_tag_translations (blog_tag_id, language_code, slug, name, created_at, updated_at)
                    VALUES ({$tag}, 'nl', 'zz-eiken', 'eiken', NOW(), NOW())");

        foreach ($ids as $id) {
            $pdo->exec("INSERT INTO blog_post_categories (post_id, category_id) VALUES ({$id}, {$category})");
            $pdo->exec("INSERT INTO blog_post_tags (post_id, tag_id) VALUES ({$id}, {$tag})");
        }

        $pdo->exec("INSERT INTO redirects (source_path, target_type, target_value, status_code, is_active, origin, created_at, updated_at)
                    VALUES ('/blog/zz-heel-oud-adres', 'internal', '/blog/zz-oud-adres', 301, 1, 'slug_change', NOW(), NOW())");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function snapshot(ScratchInstall $install): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table => $order) {
            $snapshot[$table] = $install->rows('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
        }

        return $snapshot;
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = '" . $table . "' ORDER BY ordinal_position"
        );
    }
}
