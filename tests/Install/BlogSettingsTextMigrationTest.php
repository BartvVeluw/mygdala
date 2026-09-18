<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The closing migration of Multilingual 2.0 phase 5 on the kinds of database
 * it meets (docs/multilingual/ARCHITECTURE.md, BLOG.md):
 *
 *   20260918260000_move_blog_settings_text_into_site_setting_translations.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before it, with the four Blog
 *              word keys in every state they could be in, plus the settings
 *              that must not move
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte;
 * an empty or whitespace value gets no row; exactly the four word keys leave
 * `blog_settings` and every other Blog setting is left as it was; Core's own
 * localized settings in the same table are untouched; a second run changes
 * nothing; and words that cannot be moved stop the migration before anything
 * is removed.
 *
 * NOT ABOUT THE MODULE. The Blog is off by default and this migration runs
 * anyway: a module's rows are not a module's presence.
 */
#[Group('migration-backfill')]
final class BlogSettingsTextMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_blog_settings_fresh';
    private const UPGRADED = 'mygdala_scratch_blog_settings_upgraded';
    private const BROKEN = 'mygdala_scratch_blog_settings_broken';

    /** The last migration before this one. */
    private const BEFORE = '20260918250000';

    private const MOVE = '20260918260000';

    private const ODD = " <b>Één</b> & \"quotes\" &amp;\nregel twee";

    /** What the upgraded installation holds in `blog_settings` before the move. */
    private const SEEDED = [
        'blog_title' => 'Werkplaatslogboek',
        'blog_title_en' => 'Workshop log',
        'blog_intro' => self::ODD,
        'blog_intro_en' => " \t\n",
        'blog_posts_per_page' => '12',
        'blog_show_author' => '0',
        'blog_rss_enabled' => '1',
    ];

    /** A Core row in the same table, which this migration must not notice. */
    private const CORE_SLOGAN = 'Met zorg gemaakt';

    /** @var list<array<string, string>> every row of another catalogue, before the move */
    private static array $otherRowsBefore = [];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, string> */
    private static array $otherSettingsBefore = [];

    private static int $blogRowsAfterFirstRun = 0;
    private static int $rowsAfterFirstRun = 0;
    private static int $blogRowsAfterReplay = 0;
    private static int $rowsAfterReplay = 0;

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenKeysAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOVE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$otherSettingsBefore = self::blogSettings(self::$upgraded);
        foreach (self::movedKeys() as $key) {
            unset(self::$otherSettingsBefore[$key]);
        }
        self::$otherRowsBefore = self::otherCatalogueRows(self::$upgraded);
        self::$upgraded->catchUp(self::MOVE);
        self::$blogRowsAfterFirstRun = count(self::blogRows(self::$upgraded));
        self::$rowsAfterFirstRun = self::$upgraded->count('site_setting_translations');
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$blogRowsAfterReplay = count(self::blogRows(self::$upgraded));
        self::$rowsAfterReplay = self::$upgraded->count('site_setting_translations');

        $broken = ScratchInstall::upTo(self::BROKEN, self::BEFORE);
        self::seed($broken);
        // Every table that holds a language, asked of the schema rather than
        // listed here: by this point there are twenty-odd of them and a list
        // would go stale the moment one more is added.
        foreach ($broken->rows(
            "SELECT table_name AS t FROM information_schema.columns
              WHERE table_schema = DATABASE() AND column_name = 'language_code'"
        ) as $row) {
            $broken->pdo()->exec('DELETE FROM `' . (string) $row['t'] . "` WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenKeysAfterFailure = array_keys(self::blogSettings($broken));
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

    public function testEveryLanguageKeepsItsOwnWordsByteForByteAndEmptyGetsNoRow(): void
    {
        self::assertSame(
            [
                ['setting_key' => 'blog_intro', 'language_code' => 'nl', 'value' => self::ODD],
                ['setting_key' => 'blog_title', 'language_code' => 'en', 'value' => 'Workshop log'],
                ['setting_key' => 'blog_title', 'language_code' => 'nl', 'value' => 'Werkplaatslogboek'],
            ],
            self::blogRows(self::$upgraded)
        );
    }

    public function testExactlyTheFourWordKeysLeaveBlogSettings(): void
    {
        $after = self::blogSettings(self::$upgraded);

        foreach (self::movedKeys() as $key) {
            self::assertArrayNotHasKey($key, $after, $key . ' was moved');
        }

        self::assertSame(self::$otherSettingsBefore, $after, 'every other Blog setting is exactly as it was');
        self::assertSame('12', $after['blog_posts_per_page'] ?? null, 'a page size is the same in every language');
    }

    /**
     * ONE TABLE, SEVERAL CATALOGUES: the Blog's rows land BESIDE the rows of
     * every other catalogue, never in them. Those are the footer slogan this
     * test seeds and the shop-wide related-products heading every install has
     * had since 20260908170000 — both untouched, byte for byte.
     */
    public function testEveryOtherCatalogueInTheSameTableIsUntouched(): void
    {
        self::assertContains(
            ['setting_key' => 'footer_slogan', 'language_code' => 'nl', 'value' => self::CORE_SLOGAN],
            self::$otherRowsBefore,
            'the fixture must really have put a Core row in the table'
        );

        self::assertSame(self::$otherRowsBefore, self::otherCatalogueRows(self::$upgraded));
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame(3, self::$blogRowsAfterFirstRun, 'two Dutch texts and one English one');
        self::assertSame(self::$blogRowsAfterFirstRun, self::$blogRowsAfterReplay);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay, 'and nothing else moved either');
    }

    public function testWordsThatCannotBeMovedStopTheMigrationBeforeAnythingIsRemoved(): void
    {
        self::assertNotNull(self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);
        self::assertContains('blog_title_en', self::$brokenKeysAfterFailure);
        self::assertContains('blog_title', self::$brokenKeysAfterFailure, 'nothing at all was removed');
    }

    public function testAFreshInstallHasNoBlogWordsAndNoLegacyKeys(): void
    {
        self::assertSame([], self::blogRows(self::$fresh));

        foreach (self::movedKeys() as $key) {
            self::assertArrayNotHasKey($key, self::blogSettings(self::$fresh), $key);
        }
    }

    // --------------------------------------------------------------- helpers

    private static function seed(ScratchInstall $install): void
    {
        $statement = $install->pdo()->prepare(
            'INSERT INTO blog_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach (self::SEEDED as $key => $value) {
            $statement->execute([$key, $value]);
        }

        // One Core row in the shared table, so the test can see that the two
        // catalogues really do live side by side.
        $install->pdo()
            ->prepare(
                'INSERT INTO site_setting_translations (setting_key, language_code, value, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value)'
            )
            ->execute(['footer_slogan', 'nl', self::CORE_SLOGAN]);
    }

    /** @return list<array<string, string>> the Blog's own rows in the shared table */
    private static function blogRows(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT setting_key, language_code, value FROM site_setting_translations
              WHERE setting_key IN ('blog_title', 'blog_intro') ORDER BY setting_key, language_code"
        );
    }

    /** @return list<array<string, string>> every row the Blog does not own */
    private static function otherCatalogueRows(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT setting_key, language_code, value FROM site_setting_translations
              WHERE setting_key NOT IN ('blog_title', 'blog_intro') ORDER BY setting_key, language_code"
        );
    }

    /** @return list<string> */
    private static function movedKeys(): array
    {
        return ['blog_title', 'blog_title_en', 'blog_intro', 'blog_intro_en'];
    }

    /** @return array<string, string> */
    private static function blogSettings(ScratchInstall $install): array
    {
        $settings = [];
        foreach ($install->rows('SELECT setting_key, setting_value FROM blog_settings ORDER BY setting_key') as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }
}
