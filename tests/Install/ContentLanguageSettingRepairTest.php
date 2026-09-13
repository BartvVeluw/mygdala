<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * `site_settings.enabled_content_languages` ends up correct, whatever the
 * database looked like on the way there (MULTILINGUAL.md).
 *
 * 20260910140000_add_multilingual_language_settings decided what to store by
 * probing for English content, and two of the nine table names it probes do
 * not exist: it says `navigation_items` where the table is `nav_items`, and
 * `homepage_heroes` where the table is `homepage_hero`. Those are precisely
 * the two places the generic bootstrap puts its English, so a brand-new
 * installation stored `nl` and claimed it publishes Dutch only.
 *
 * 20260911200000_correct_the_stored_content_languages repairs that by
 * writing the full set this product publishes, which is what
 * App\Service\Language\ContentLanguages::normalise() writes whenever an
 * owner saves anything. These tests run the faulty migration exactly as it
 * ran in the world, look at the wrong answer it produced, then let the rest
 * of the migrations run and check the answer is right.
 *
 * Each case builds its own database from zero, stops one migration short of
 * the faulty one, shapes the content, and only then lets it continue: the
 * state being repaired has to be produced by the migration that produced it,
 * not written by hand.
 */
#[Group('migration-backfill')]
final class ContentLanguageSettingRepairTest extends TestCase
{
    /** The last migration BEFORE the faulty one: where a test shapes content. */
    private const BEFORE_THE_DEFECT = '20260910130000';

    /** The migration whose probe is misspelled. */
    private const THE_DEFECT = '20260910140000';

    private const SETTING = 'enabled_content_languages';
    private const PRIMARY = 'primary_content_language';

    /** What every installation of this build must end up storing. */
    private const CORRECT = 'nl,en';

    private ?ScratchInstall $install = null;

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped(
                'A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env), like scripts/test-db.php.'
            );
        }
    }

    protected function tearDown(): void
    {
        $this->install?->drop();
        $this->install = null;
    }

    // ------------------------------------------------------- the plain case

    public function testAFreshInstallFromZeroStoresBothContentLanguages(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_fresh', static function (): void {
        });

        $this->assertSame(
            'nl',
            $this->stored($install, self::SETTING),
            'the defect itself: a fresh install keeps its English in nav_items and homepage_hero, '
            . 'which the probe misspells, so it concluded there is no English content'
        );

        $install->catchUp();

        $this->assertSame(
            self::CORRECT,
            $this->stored($install, self::SETTING),
            'after the corrective migration a fresh install says it publishes both languages'
        );
    }

    // --------------------------------------------- the shapes of the content

    public function testAnInstallationWhoseOnlyEnglishIsInTheMenuIsRepaired(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_nav', static function (ScratchInstall $i): void {
            $i->pdo()->exec(
                "UPDATE homepage_hero SET eyebrow_en = '', title_en = '', lead_en = '', primary_label_en = ''"
            );
        });

        $this->assertGreaterThan(0, $this->countEnglish($install, 'nav_items', 'label_en'), 'the menu keeps its English');
        $this->assertSame(0, $this->countEnglish($install, 'homepage_hero', 'title_en'), 'the hero has none left');
        $this->assertSame('nl', $this->stored($install, self::SETTING), 'nav_items is the name the probe misspells');

        $install->catchUp();

        $this->assertSame(self::CORRECT, $this->stored($install, self::SETTING));
    }

    public function testAnInstallationWhoseOnlyEnglishIsInTheHomepageHeroIsRepaired(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_hero', static function (ScratchInstall $i): void {
            $i->pdo()->exec("UPDATE nav_items SET label_en = ''");
        });

        $this->assertSame(0, $this->countEnglish($install, 'nav_items', 'label_en'), 'the menu has none left');
        $this->assertGreaterThan(
            0,
            $this->countEnglish($install, 'homepage_hero', 'title_en'),
            'the hero keeps its English'
        );
        $this->assertSame('nl', $this->stored($install, self::SETTING), 'homepage_hero is the other misspelled name');

        $install->catchUp();

        $this->assertSame(self::CORRECT, $this->stored($install, self::SETTING));
    }

    public function testADutchOnlyInstallationAlsoStoresBothBecauseThisProductIsBilingual(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_dutch', static function (ScratchInstall $i): void {
            $i->pdo()->exec("UPDATE nav_items SET label_en = ''");
            $i->pdo()->exec(
                "UPDATE homepage_hero SET eyebrow_en = '', title_en = '', lead_en = '', primary_label_en = ''"
            );
        });

        $this->assertSame(0, $this->countEnglish($install, 'nav_items', 'label_en'));
        $this->assertSame(0, $this->countEnglish($install, 'homepage_hero', 'title_en'));

        $install->catchUp();

        // Not a judgement about this site's content: the row says which
        // languages this BUILD publishes, and English is one of them whether
        // or not anybody has written any yet. An editor has to be able to start.
        $this->assertSame(
            self::CORRECT,
            $this->stored($install, self::SETTING),
            'a site with no English written yet still publishes English; nothing may take the language away'
        );
    }

    public function testAnInstallationWithNoContentAtAllStoresBoth(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_empty', static function (ScratchInstall $i): void {
            $pdo = $i->pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['page_sections', 'pages', 'nav_items', 'homepage_hero', 'footer_links', 'footer_columns'] as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->assertSame(0, $install->count('pages'), 'nothing is left to probe');

        $install->catchUp();

        $this->assertSame(self::CORRECT, $this->stored($install, self::SETTING));
    }

    // ------------------------------------------------------ the upgrade path

    public function testTheRepairReachesADatabaseWhereTheFaultyMigrationAlreadyRan(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_upgrade', static function (): void {
        });

        // Exactly the state a real installation was left in by the rollout.
        $this->assertSame('nl', $this->stored($install, self::SETTING));
        $english = $this->countEnglish($install, 'nav_items', 'label_en');

        $install->catchUp();

        $this->assertSame(
            self::CORRECT,
            $this->stored($install, self::SETTING),
            'the stored set is corrected in place'
        );
        $this->assertSame(
            'nl',
            $this->stored($install, self::PRIMARY),
            'the primary language is read and never rewritten'
        );
        $this->assertSame(
            $english,
            $this->countEnglish($install, 'nav_items', 'label_en'),
            'not one word of content is touched'
        );
    }

    public function testTheStoredPrimaryLanguageDecidesTheOrder(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_primary', static function (ScratchInstall $i): void {
            // An owner whose site is written in English. Written before the
            // faulty migration, whose INSERT IGNORE then leaves it alone.
            $i->pdo()->exec(
                'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) '
                . "VALUES ('primary_content_language', 'en', NOW(), NOW())"
            );
        });

        $install->catchUp();

        $this->assertSame('en', $this->stored($install, self::PRIMARY), 'the choice is left alone');
        $this->assertSame(
            'en,nl',
            $this->stored($install, self::SETTING),
            'the primary language comes first, because that is what first means everywhere else'
        );
    }

    public function testTheCorrectionWritesOneRowAndWritingItAgainChangesNothing(): void
    {
        $install = $this->buildUpToTheDefect('mygdala_scratch_lang_twice', static function (): void {
        });

        $install->catchUp();
        $once = $this->stored($install, self::SETTING);

        // Phinx will not re-run a recorded migration, so "idempotent" can
        // only mean the statement itself, applied a second time.
        $install->pdo()->exec(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) '
            . "VALUES ('" . self::SETTING . "', '" . self::CORRECT . "', NOW(), NOW()) "
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        $this->assertSame(self::CORRECT, $once);
        $this->assertSame($once, $this->stored($install, self::SETTING));

        $rows = $install->rows(
            'SELECT COUNT(*) AS total FROM site_settings WHERE setting_key = ?',
            [self::SETTING]
        );
        $this->assertSame(1, (int) $rows[0]['total'], 'the unique index means one row, never a second');
    }

    // ------------------------------------------------------------ internals

    /**
     * A database migrated to just before the defect, shaped by $seed, and
     * then taken exactly one migration further so the faulty probe runs
     * against that shape.
     */
    private function buildUpToTheDefect(string $database, callable $seed): ScratchInstall
    {
        $install = ScratchInstall::upTo($database, self::BEFORE_THE_DEFECT);
        $this->install = $install;

        $seed($install);
        $install->catchUp(self::THE_DEFECT);

        return $install;
    }

    private function stored(ScratchInstall $install, string $key): ?string
    {
        $rows = $install->rows('SELECT setting_value FROM site_settings WHERE setting_key = ?', [$key]);

        return isset($rows[0]) ? (string) $rows[0]['setting_value'] : null;
    }

    private function countEnglish(ScratchInstall $install, string $table, string $column): int
    {
        $rows = $install->rows(
            'SELECT COUNT(*) AS total FROM ' . $table
            . ' WHERE ' . $column . " IS NOT NULL AND TRIM(" . $column . ") <> ''"
        );

        return (int) ($rows[0]['total'] ?? 0);
    }
}
