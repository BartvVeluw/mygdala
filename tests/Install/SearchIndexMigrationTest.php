<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Search 2.0's migration (20261013100000) on a database built from zero and
 * on one upgraded from Articles 1.0 (the migration before it), with a page
 * and a block seeded first:
 *
 *   - the two tables exist with their keys: one row per block and language,
 *     CASCADE to the block and to the page, the fingerprint keyed by index;
 *   - the migration reads no block: an upgraded site starts with an empty
 *     index and no fingerprint, which makes its first search rebuild it
 *     (BlockSearchIndex::ensureCurrent());
 *   - both installs end on the same schema;
 *   - running it again changes nothing, index rows written in between
 *     included, and a deleted block or page takes its rows along.
 */
final class SearchIndexMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_search_index_fresh';
    private const UPGRADED = 'mygdala_scratch_search_index_upgraded';

    /** Articles 1.0, the migration before this one. */
    private const BEFORE = '20261012100000';

    private const MIGRATION = '20261013100000';

    private const TABLES = ['search_block_texts', 'search_index_state'];

    private static ?ScratchInstall $fresh = null;

    private static ?ScratchInstall $upgraded = null;

    private static int $pageId = 0;

    private static int $sectionId = 0;

    /** @var list<array<string, mixed>> */
    private static array $sectionsBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);
        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);

        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO pages (content_key, slug, status, created_at, updated_at) VALUES ('zz-search-index', 'zz-search-index', 'published', NOW(), NOW())");
        self::$pageId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-search-index', 'tekst', 1, NOW(), NOW())");
        $textId = (int) $pdo->lastInsertId();
        $pdo->exec('INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at) VALUES (' . self::$pageId . ", 'zz-search-index', 'rich_text', 'tekst', {$textId}, 10, 1, NOW(), NOW())");
        self::$sectionId = (int) $pdo->lastInsertId();
        self::$sectionsBefore = self::$upgraded->rows('SELECT * FROM page_sections ORDER BY id');

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

    public function testTheTablesExistWithTheirKeys(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach (self::TABLES as $table) {
                self::assertTrue($install->hasTable($table), $table);
            }

            self::assertSame([
                ['tab' => 'search_block_texts', 'col' => 'page_id', 'ref' => 'pages', 'on_delete' => 'CASCADE'],
                ['tab' => 'search_block_texts', 'col' => 'page_section_id', 'ref' => 'page_sections', 'on_delete' => 'CASCADE'],
            ], $install->rows(
                "SELECT k.table_name AS tab, k.column_name AS col, k.referenced_table_name AS ref, r.delete_rule AS on_delete
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                  WHERE k.table_schema = DATABASE() AND k.table_name LIKE 'search%'
                  ORDER BY k.table_name, k.column_name"
            ));

            $indexes = $install->rows(
                "SELECT table_name AS tab, index_name AS name, non_unique AS non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name LIKE 'search%'
                  GROUP BY table_name, index_name, non_unique ORDER BY table_name, index_name"
            );
            self::assertContains(['tab' => 'search_block_texts', 'name' => 'uq_search_block_texts_section_language', 'non_unique' => 0, 'cols' => 'page_section_id,language_code'], $indexes);
            self::assertContains(['tab' => 'search_block_texts', 'name' => 'idx_search_block_texts_page_language', 'non_unique' => 1, 'cols' => 'page_id,language_code'], $indexes);
            self::assertContains(['tab' => 'search_index_state', 'name' => 'PRIMARY', 'non_unique' => 0, 'cols' => 'index_name'], $indexes);
        }
    }

    public function testAnUpgradedSiteStartsEmptySoItsFirstSearchRebuilds(): void
    {
        self::assertSame(0, self::$upgraded->count('search_block_texts'), 'the migration reads no block');
        self::assertSame(0, self::$upgraded->count('search_index_state'), 'no fingerprint: the first search rebuilds');
        self::assertSame(self::$sectionsBefore, self::$upgraded->rows('SELECT * FROM page_sections ORDER BY id'), 'and changes no block');
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        self::assertContains(['name' => 'body_text', 'type' => 'mediumtext', 'nullable' => 'NO', 'default' => null], self::shape(self::$fresh, 'search_block_texts'));
    }

    public function testASecondRunKeepsTheIndexAndDeletesCascade(): void
    {
        $pdo = self::$upgraded->pdo();
        $pdo->exec('INSERT INTO search_block_texts (page_section_id, page_id, section_type, language_code, heading_text, body_text, updated_at) VALUES ('
            . self::$sectionId . ', ' . self::$pageId . ", 'rich_text', 'nl', '', 'Blijft staan', NOW())");
        $pdo->exec("INSERT INTO search_index_state (index_name, fingerprint, built_at) VALUES ('blocks', 'abc', NOW())");
        $before = [self::$upgraded->rows('SELECT * FROM search_block_texts ORDER BY id'), self::$upgraded->rows('SELECT * FROM search_index_state')];

        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);

        self::assertSame($before, [self::$upgraded->rows('SELECT * FROM search_block_texts ORDER BY id'), self::$upgraded->rows('SELECT * FROM search_index_state')]);

        // One row per block and language.
        try {
            $pdo->exec('INSERT INTO search_block_texts (page_section_id, page_id, section_type, language_code, heading_text, body_text) VALUES ('
                . self::$sectionId . ', ' . self::$pageId . ", 'rich_text', 'nl', '', 'Dubbel')");
            self::fail('a second row for the same block and language was accepted');
        } catch (\PDOException) {
        }

        $pdo->exec('DELETE FROM page_sections WHERE id = ' . self::$sectionId);
        self::assertSame(0, self::$upgraded->count('search_block_texts'), 'a deleted block takes its words along');
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
