<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The English page title and the one value it inherits:
 * db/migrations/20260916140000_add_an_english_title_to_pages.php.
 *
 * WHAT IT HAS TO GET RIGHT. Before the breadcrumb moved out of the Paginakop,
 * an editor's English name for a page could only live in
 * `page_heroes.breadcrumb_label_en`. Nothing reads that column any more, so
 * without this migration an English visitor would start seeing the Dutch page
 * name where they used to read an English one. That is a regression, and this
 * is the migration that closes it.
 *
 * Two throwaway databases (Tests\Support\ScratchInstall): one built from zero,
 * and one standing where a site with a Paginakop stood before this migration —
 * three pages, each with a header: one with an English label, one whose label
 * only repeats the Dutch title, and one with no label at all. That one catches
 * up and then runs the migration a second time, as `phinx migrate` would if
 * its log lost the line.
 *
 * What it proves: one nullable column on both databases; the English label
 * moved exactly once and only where it says something the Dutch title does
 * not; a translation an editor already typed is never overwritten; and
 * `page_heroes` keeps every value it had, because dropping those columns is a
 * separate decision.
 */
#[Group('migration-backfill')]
final class PageEnglishTitleMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_page_title_en_fresh';
    private const DEPLOYED = 'mygdala_scratch_page_title_en_deployed';

    /** The migration before this one: where a site on the 5B branch stood. */
    private const BEFORE = '20260916120000';

    private const TITLE_EN = '20260916140000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $deployed = null;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$deployed = ScratchInstall::upTo(self::DEPLOYED, self::BEFORE);
        $pdo = self::$deployed->pdo();

        $pages = $pdo->prepare(
            'INSERT INTO pages (content_key, slug, title, status, is_system, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, NOW(), NOW())'
        );
        $heroes = $pdo->prepare(
            'INSERT INTO page_heroes (page_slug, eyebrow_nl, title_nl, breadcrumb_label_nl, breadcrumb_label_en, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
        );

        // An editor typed a real English name for this page.
        $pages->execute(['zz-title-en-translated', 'zz-title-en-translated', 'Over ons', 'published', 900]);
        $heroes->execute(['zz-title-en-translated', '', 'Over ons', 'Over ons', 'About us']);

        // The English label only repeats the Dutch title: nothing to inherit,
        // and copying it would turn "not translated" into "translated".
        $pages->execute(['zz-title-en-echo', 'zz-title-en-echo', 'Contact', 'published', 901]);
        $heroes->execute(['zz-title-en-echo', '', 'Contact', 'Contact', 'Contact']);

        // A header that was never given an English label.
        $pages->execute(['zz-title-en-empty', 'zz-title-en-empty', 'Diensten', 'published', 902]);
        $heroes->execute(['zz-title-en-empty', '', 'Diensten', 'Diensten', null]);

        self::$deployed->catchUp();
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('no root database credentials for a throwaway installation');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$deployed?->drop();
        self::$fresh = null;
        self::$deployed = null;
    }

    public function testBothDatabasesEndWithTheSameNullableColumn(): void
    {
        foreach ([self::FRESH => self::$fresh, self::DEPLOYED => self::$deployed] as $name => $install) {
            $this->assertNotNull($install);

            $column = $install->pdo()
                ->query("SHOW COLUMNS FROM pages LIKE 'title_en'")
                ->fetch();

            $this->assertIsArray($column, "{$name} has the column");
            $this->assertSame('YES', $column['Null'], "{$name}: NULL is what \"not translated\" means");
            $this->assertNull($column['Default'], "{$name}: no default, so nothing is written for anybody");
        }
    }

    public function testAnEnglishBreadcrumbLabelBecomesTheEnglishTitle(): void
    {
        $this->assertSame('About us', $this->titleEn('zz-title-en-translated'));
    }

    public function testALabelThatOnlyRepeatsTheDutchTitleIsNotCopiedIn(): void
    {
        $this->assertNull(
            $this->titleEn('zz-title-en-echo'),
            'the same words in both columns is not a translation, and storing it would claim it is'
        );
    }

    public function testAHeaderWithoutAnEnglishLabelLeavesThePageUntranslated(): void
    {
        $this->assertNull($this->titleEn('zz-title-en-empty'));
    }

    public function testTheLegacyColumnsAreLeftExactlyAsTheyWere(): void
    {
        $this->assertNotNull(self::$deployed);

        $rows = self::$deployed->pdo()
            ->query("SELECT page_slug, breadcrumb_label_nl, breadcrumb_label_en FROM page_heroes WHERE page_slug LIKE 'zz-title-en-%' ORDER BY page_slug")
            ->fetchAll();

        $this->assertSame(
            [
                ['page_slug' => 'zz-title-en-echo', 'breadcrumb_label_nl' => 'Contact', 'breadcrumb_label_en' => 'Contact'],
                ['page_slug' => 'zz-title-en-empty', 'breadcrumb_label_nl' => 'Diensten', 'breadcrumb_label_en' => null],
                ['page_slug' => 'zz-title-en-translated', 'breadcrumb_label_nl' => 'Over ons', 'breadcrumb_label_en' => 'About us'],
            ],
            $rows,
            'nothing is moved out of page_heroes; the value is copied and the columns stay as legacy data'
        );
    }

    public function testRunningItAgainChangesNothingAndOverwritesNoTranslation(): void
    {
        $this->assertNotNull(self::$deployed);
        $pdo = self::$deployed->pdo();

        // What an editor typed after the first run. A replay must not touch it.
        $pdo->prepare('UPDATE pages SET title_en = ? WHERE content_key = ?')
            ->execute(['About our workshop', 'zz-title-en-translated']);

        self::$deployed->replay(self::TITLE_EN);

        $this->assertSame('About our workshop', $this->titleEn('zz-title-en-translated'));
        $this->assertNull($this->titleEn('zz-title-en-echo'));
        $this->assertNull($this->titleEn('zz-title-en-empty'));
    }

    private function titleEn(string $contentKey): ?string
    {
        $this->assertNotNull(self::$deployed);

        $statement = self::$deployed->pdo()->prepare('SELECT title_en FROM pages WHERE content_key = ?');
        $statement->execute([$contentKey]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
