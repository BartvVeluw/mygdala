<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Articles 1.0's migration (20261012100000) on a database built from zero
 * and on one upgraded from Blog 2.0 (the v0.1.15 baseline before it), with a
 * live blog seeded first:
 *
 *   - the five tables exist with real keys: featured image RESTRICT, topic
 *     SET NULL, translations CASCADE to their owner and RESTRICT to the
 *     language, content page RESTRICT both ways, an address unique per
 *     language;
 *   - nothing of the Blog changes;
 *   - both installs end on the same schema;
 *   - running it again changes nothing, articles written in between included
 *     (module state is never the migration's business: rows stay, whatever
 *     the switch says).
 */
final class ArticlesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_articles_fresh';
    private const UPGRADED = 'mygdala_scratch_articles_upgraded';

    /** Blog 2.0, the migration before this one. */
    private const BEFORE = '20261011100000';
    private const MIGRATION = '20261012100000';

    private const TABLES = ['article_topics', 'article_topic_translations', 'articles', 'article_translations', 'article_content_pages'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $blogBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);
        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);

        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO blog_posts (slug, status, published_at, content_mode, created_at, updated_at) VALUES ('zz-blijft', 'published', '2026-01-01 10:00:00', 'blocks', NOW(), NOW())");
        $pdo->exec("INSERT INTO blog_post_translations (blog_post_id, language_code, slug, title, created_at, updated_at) VALUES (LAST_INSERT_ID(), 'nl', 'zz-blijft', 'Blijft', NOW(), NOW())");
        self::$blogBefore = self::$upgraded->rows('SELECT * FROM blog_posts ORDER BY id');

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

    public function testTheTablesExistWithRealKeys(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach (self::TABLES as $table) {
                self::assertTrue($install->hasTable($table), $table);
            }

            self::assertSame([
                ['tab' => 'article_content_pages', 'col' => 'article_id', 'ref' => 'articles', 'on_delete' => 'RESTRICT'],
                ['tab' => 'article_content_pages', 'col' => 'page_id', 'ref' => 'pages', 'on_delete' => 'RESTRICT'],
                ['tab' => 'article_topic_translations', 'col' => 'article_topic_id', 'ref' => 'article_topics', 'on_delete' => 'CASCADE'],
                ['tab' => 'article_topic_translations', 'col' => 'language_code', 'ref' => 'site_languages', 'on_delete' => 'RESTRICT'],
                ['tab' => 'article_translations', 'col' => 'article_id', 'ref' => 'articles', 'on_delete' => 'CASCADE'],
                ['tab' => 'article_translations', 'col' => 'language_code', 'ref' => 'site_languages', 'on_delete' => 'RESTRICT'],
                ['tab' => 'articles', 'col' => 'featured_media_id', 'ref' => 'media', 'on_delete' => 'RESTRICT'],
                ['tab' => 'articles', 'col' => 'topic_id', 'ref' => 'article_topics', 'on_delete' => 'SET NULL'],
            ], $install->rows(
                "SELECT k.table_name AS tab, k.column_name AS col, k.referenced_table_name AS ref, r.delete_rule AS on_delete
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                  WHERE k.table_schema = DATABASE() AND k.table_name LIKE 'article%'
                  ORDER BY k.table_name, k.column_name"
            ));

            $unique = $install->rows(
                "SELECT index_name AS name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'article_translations' AND non_unique = 0
                  GROUP BY index_name ORDER BY index_name"
            );
            self::assertContains(['name' => 'uq_article_translations_language_slug', 'cols' => 'language_code,slug'], $unique);
            self::assertContains(['name' => 'uq_article_translations_owner_language', 'cols' => 'article_id,language_code'], $unique);
        }
    }

    public function testTheBlogIsUntouchedAndArticlesStartEmpty(): void
    {
        self::assertSame(self::$blogBefore, self::$upgraded->rows('SELECT * FROM blog_posts ORDER BY id'));
        foreach (self::TABLES as $table) {
            self::assertSame(0, self::$upgraded->count($table), $table);
        }
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        self::assertContains(['name' => 'status', 'type' => 'varchar(20)', 'nullable' => 'NO', 'default' => 'draft'], self::shape(self::$fresh, 'articles'));
    }

    public function testASecondRunKeepsEveryArticle(): void
    {
        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO article_topics (sort_order, created_at, updated_at) VALUES (1, NOW(), NOW())");
        $topic = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO articles (status, published_at, topic_id, created_at, updated_at) VALUES ('archived', '2026-01-01 10:00:00', {$topic}, NOW(), NOW())");
        $article = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO article_translations (article_id, language_code, slug, title, created_at, updated_at) VALUES ({$article}, 'nl', 'zz-blijft-staan', 'Blijft staan', NOW(), NOW())");

        $before = self::snapshot(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);

        self::assertSame($before, self::snapshot(self::$upgraded));

        // Deleting a topic never deletes an article: it loses its topic.
        $pdo->exec("DELETE FROM article_topics WHERE id = {$topic}");
        self::assertSame([['topic_id' => null]], self::$upgraded->rows("SELECT topic_id FROM articles WHERE id = {$article}"));
        $pdo->exec("DELETE FROM articles WHERE id = {$article}");
        self::assertSame(0, self::$upgraded->count('article_translations'), 'translations go with their article');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function snapshot(ScratchInstall $install): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = $install->rows('SELECT * FROM ' . $table . ($table === 'article_content_pages' ? ' ORDER BY article_id' : ' ORDER BY id'));
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
