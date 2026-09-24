<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * pages.parent_id and pages.admin_group, however the database came to be:
 * db/migrations/20260924140000_give_pages_a_parent_and_an_admin_group.php
 * (docs/pages/NESTING.md).
 *
 * Two throwaway databases (Tests\Support\ScratchInstall): one built from zero,
 * and one standing where a deployed site stood the migration before, holding
 * pages — an ordinary one, a draft and one with its own English address. That
 * one catches up, and then runs the migration a second time, as
 * `phinx migrate` would if its log lost the line.
 *
 * What it proves: one nullable `int unsigned` parent column with one index and
 * one RESTRICT key to pages.id, and a NOT NULL admin group defaulting to
 * 'website', identical on both databases and after the replay; every existing
 * page a root page in the website group, with its id, slug, status and
 * sort_order — and therefore its URL — unchanged.
 */
#[Group('migration-backfill')]
final class PageNestingMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_page_nesting_fresh';
    private const DEPLOYED = 'mygdala_scratch_page_nesting_deployed';

    /** The migration before this one: where a deployed site stood. */
    private const BEFORE = '20260924100000';

    private const NESTING = '20260924140000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $deployed = null;

    /** @var list<array<string, mixed>> */
    private static array $pagesBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $slugsBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$deployed = ScratchInstall::upTo(self::DEPLOYED, self::BEFORE);
        $pdo = self::$deployed->pdo();

        foreach ([['zz-over', 'published', 900], ['zz-concept', 'draft', 910], ['zz-tweetalig', 'published', 920]] as [$slug, $status, $order]) {
            $pdo->prepare(
                "INSERT INTO pages (content_key, slug, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, '2026-09-01 10:00:00', '2026-09-01 10:00:00')"
            )->execute([$slug, $slug, $status, $order]);
            $pageId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO page_translations (page_id, language_code, slug, title) VALUES (?, 'nl', ?, ?)")
                ->execute([$pageId, $slug, 'Titel ' . $slug]);
        }

        self::$pagesBefore = self::pages(self::$deployed);
        self::$slugsBefore = self::$deployed->rows('SELECT page_id, language_code, slug FROM page_translations ORDER BY page_id, language_code');

        self::$deployed->catchUp();
        self::$deployed->replay(self::NESTING);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$fresh, self::$deployed] as $install) {
            $install?->drop();
        }

        self::$fresh = null;
        self::$deployed = null;
    }

    public function testAFreshInstallAndAnUpgradedOneEndWithTheSameColumns(): void
    {
        $fresh = $this->schema($this->install(self::$fresh));

        $this->assertSame(
            [
                'parent_id' => 'int unsigned NULL DEFAULT NULL',
                'admin_group' => "varchar(20) NOT NULL DEFAULT 'website'",
                'indexes' => 1,
                'keys' => [['ref_table' => 'pages', 'ref_column' => 'id', 'delete_rule' => 'RESTRICT', 'update_rule' => 'CASCADE']],
            ],
            $fresh
        );

        $this->assertSame($fresh, $this->schema($this->install(self::$deployed)), 'a deployed site, after a second run');
    }

    /**
     * Nothing about an existing page changes but the two new columns: the
     * same ids, slugs, statuses and order, so the same URLs.
     */
    public function testEveryExistingPageStaysWhereItWasAsARootWebsitePage(): void
    {
        $after = self::pages($this->install(self::$deployed));

        $this->assertNotSame([], self::$pagesBefore);
        $this->assertSame(
            self::$pagesBefore,
            array_map(static fn (array $row): array => array_diff_key($row, ['parent_id' => true, 'admin_group' => true]), $after)
        );
        $this->assertSame(array_fill(0, count($after), null), array_column($after, 'parent_id'));
        $this->assertSame(array_fill(0, count($after), 'website'), array_column($after, 'admin_group'));
        $this->assertSame(
            self::$slugsBefore,
            $this->install(self::$deployed)->rows('SELECT page_id, language_code, slug FROM page_translations ORDER BY page_id, language_code')
        );
    }

    // --------------------------------------------------------------- helpers

    private function install(?ScratchInstall $install): ScratchInstall
    {
        if ($install === null) {
            $this->markTestSkipped(
                'Building installations from zero needs the MySQL root account (DB_ROOT_PASSWORD in .env).'
            );
        }

        return $install;
    }

    /** @return list<array<string, mixed>> */
    private static function pages(ScratchInstall $install): array
    {
        $columns = array_column($install->rows(
            "SELECT COLUMN_NAME AS name FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'pages' AND COLUMN_NAME IN ('id', 'slug', 'status', 'sort_order', 'content_key', 'route_path', 'parent_id', 'admin_group')
             ORDER BY ORDINAL_POSITION",
            [$install->database]
        ), 'name');

        return $install->rows('SELECT ' . implode(', ', $columns) . ' FROM pages ORDER BY id');
    }

    /** @return array<string, mixed> */
    private function schema(ScratchInstall $install): array
    {
        $columns = [];
        foreach ($install->rows(
            "SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'pages' AND COLUMN_NAME IN ('parent_id', 'admin_group')",
            [$install->database]
        ) as $row) {
            $default = $row['dflt'] === null ? 'NULL' : "'" . trim((string) $row['dflt'], "'") . "'";
            $columns[(string) $row['name']] = $row['type'] . ($row['nullable'] === 'YES' ? ' NULL' : ' NOT NULL') . ' DEFAULT ' . $default;
        }

        $indexes = $install->rows(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = 'pages' AND COLUMN_NAME = 'parent_id'",
            [$install->database]
        );

        $keys = $install->rows(
            "SELECT k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_column, r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = 'pages' AND k.COLUMN_NAME = 'parent_id'",
            [$install->database]
        );

        return [
            'parent_id' => $columns['parent_id'] ?? null,
            'admin_group' => $columns['admin_group'] ?? null,
            'indexes' => count($indexes),
            'keys' => $keys,
        ];
    }
}
