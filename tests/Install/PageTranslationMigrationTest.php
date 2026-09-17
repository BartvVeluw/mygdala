<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20260917140000_create_the_page_translations_table.php
 * (Multilingual 2.0 phase 2, docs/multilingual/ARCHITECTURE.md), on the two
 * kinds of database it meets:
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before this phase, with pages
 *
 * What must hold on both: the same table, one row per page per language in
 * the schema, the page as a cascading foreign key, the language as a
 * restricting foreign key on the registry, and a second run that changes
 * nothing.
 */
#[Group('migration-backfill')]
final class PageTranslationMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_page_translations_fresh';
    private const UPGRADED = 'mygdala_scratch_page_translations_upgraded';

    /** The last migration before this phase. */
    private const BEFORE = '20260917120000';

    private const SCHEMA = '20260917140000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$upgraded->catchUp();
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

    public function testBothDatabasesEndWithTheSameTable(): void
    {
        $fresh = self::shape(self::$fresh);

        self::assertSame($fresh, self::shape(self::$upgraded));
        self::assertSame(
            ['id', 'page_id', 'language_code', 'title', 'meta_title', 'meta_description', 'created_at', 'updated_at'],
            array_keys($fresh)
        );
        self::assertSame('int unsigned', $fresh['page_id']['column_type']);
        self::assertSame('NO', $fresh['page_id']['is_nullable']);
        self::assertSame('varchar(12)', $fresh['language_code']['column_type']);
        self::assertSame('ascii_bin', $fresh['language_code']['collation_name']);
        self::assertSame('NO', $fresh['language_code']['is_nullable']);

        foreach (['title' => 'varchar(200)', 'meta_title' => 'varchar(255)', 'meta_description' => 'varchar(500)'] as $column => $type) {
            self::assertSame($type, $fresh[$column]['column_type'], $column);
            self::assertSame('YES', $fresh[$column]['is_nullable'], $column . ' is NULL when a language has no words');
        }
    }

    public function testTheLanguageColumnMatchesTheRegistryItPointsAt(): void
    {
        $registry = self::$fresh->rows(
            'SELECT column_type AS column_type, character_set_name AS charset, collation_name AS collation_name '
            . 'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [self::FRESH, 'site_languages', 'code']
        );
        $translations = self::$fresh->rows(
            'SELECT column_type AS column_type, character_set_name AS charset, collation_name AS collation_name '
            . 'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [self::FRESH, 'page_translations', 'language_code']
        );

        self::assertSame($registry, $translations);
    }

    public function testOnePageHasAtMostOneRowPerLanguage(): void
    {
        $unique = array_column(self::$fresh->rows(
            'SELECT index_name AS index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns '
            . 'FROM information_schema.statistics '
            . 'WHERE table_schema = ? AND table_name = ? AND non_unique = 0 GROUP BY index_name ORDER BY index_name',
            [self::FRESH, 'page_translations']
        ), 'columns', 'index_name');

        self::assertSame(['PRIMARY' => 'id', 'uq_page_translations_page_language' => 'page_id,language_code'], $unique);
    }

    public function testThePageCascadesAndTheLanguageRestricts(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            $keys = [];
            foreach ($install->rows(
                'SELECT rc.constraint_name AS name, rc.referenced_table_name AS referenced_table, '
                . 'rc.delete_rule AS delete_rule, kcu.column_name AS column_name, kcu.referenced_column_name AS referenced_column '
                . 'FROM information_schema.referential_constraints rc '
                . 'JOIN information_schema.key_column_usage kcu '
                . '  ON kcu.constraint_schema = rc.constraint_schema AND kcu.constraint_name = rc.constraint_name '
                . 'WHERE rc.constraint_schema = ? AND rc.table_name = ? ORDER BY rc.constraint_name',
                [$install->database, 'page_translations']
            ) as $row) {
                $keys[$row['name']] = [$row['column_name'], $row['referenced_table'], $row['referenced_column'], $row['delete_rule']];
            }

            self::assertSame([
                'fk_page_translations_language' => ['language_code', 'site_languages', 'code', 'RESTRICT'],
                'fk_page_translations_page' => ['page_id', 'pages', 'id', 'CASCADE'],
            ], $keys, $install->database);
        }
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $before = self::shape(self::$upgraded);

        self::$upgraded->replay(self::SCHEMA);

        self::assertSame($before, self::shape(self::$upgraded));
    }

    /** @return array<string, array{column_type: string, is_nullable: string, collation_name: ?string}> */
    private static function shape(ScratchInstall $install): array
    {
        $columns = [];
        foreach ($install->rows(
            'SELECT column_name AS column_name, column_type AS column_type, is_nullable AS is_nullable, '
            . 'collation_name AS collation_name FROM information_schema.columns '
            . 'WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$install->database, 'page_translations']
        ) as $row) {
            $columns[$row['column_name']] = [
                'column_type' => $row['column_type'],
                'is_nullable' => $row['is_nullable'],
                'collation_name' => $row['collation_name'],
            ];
        }

        return $columns;
    }
}
