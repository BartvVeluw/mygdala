<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20260924120000: the Paginakop's image_mode, hero_height and
 * image_focus, on the kinds of database they meet.
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before it, with a header
 *              without a picture, one with a picture behind its text (the
 *              only place there was) and one with a picture that had every
 *              other choice changed, each with words in block_translations
 *
 * What must hold: a header with a picture keeps it behind the text
 * ('background'), never beside it; a header without one is 'none'; every
 * header gets 'medium' (the height a header with a picture had) and 'center'
 * (the crop the browser made by itself); nothing else in the row and none of
 * its words changes; and a second run changes nothing, not even a choice an
 * editor made in between.
 */
#[Group('migration-backfill')]
final class PageHeaderImageModeMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_page_header_2_fresh';
    private const UPGRADED = 'mygdala_scratch_page_header_2_upgraded';

    /** The last migration before this one. */
    private const BEFORE = '20260923180000';

    private const THIS = '20260924120000';

    /** The columns a page_heroes row had before this migration. */
    private const OLD_COLUMNS = 'id, page_slug, media_id, content_position, title_size, text_size, is_active';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, array<string, mixed>> page_slug => row, before */
    private static array $before = [];

    /** @var list<array<string, mixed>> */
    private static array $wordsBefore = [];

    /** @var array<string, array<string, mixed>> page_slug => row, after the first run */
    private static array $afterFirstRun = [];

    /** @var array<string, array<string, mixed>> page_slug => the old columns, after the first run */
    private static array $oldColumnsAfterFirstRun = [];

    /** @var list<array<string, mixed>> */
    private static array $wordsAfterFirstRun = [];

    /** @var array<string, array<string, mixed>> page_slug => row, after the replay */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::THIS);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$before = self::rowsBySlug(self::$upgraded, self::OLD_COLUMNS);
        self::$wordsBefore = self::words(self::$upgraded);

        self::$upgraded->catchUp(self::THIS);
        self::$afterFirstRun = self::rowsBySlug(self::$upgraded, '*');
        self::$oldColumnsAfterFirstRun = self::rowsBySlug(self::$upgraded, self::OLD_COLUMNS);
        self::$wordsAfterFirstRun = self::words(self::$upgraded);

        // An editor turns the picture of one header into a picture beside the
        // text, and removes the other one's picture altogether, between the
        // two runs: the replay must leave both choices alone.
        $pdo = self::$upgraded->pdo();
        $pdo->exec("UPDATE page_heroes SET image_mode = 'right', hero_height = 'large', image_focus = 'top' WHERE page_slug = 'zz-picture'");
        $pdo->exec("UPDATE page_heroes SET image_mode = 'none', media_id = NULL WHERE page_slug = 'zz-chosen'");
        self::$upgraded->replay(self::THIS, self::THIS);
        self::$afterReplay = self::rowsBySlug(self::$upgraded, '*');
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

    public function testAHeaderWithAPictureKeepsItBehindTheText(): void
    {
        self::assertSame('background', self::$afterFirstRun['zz-picture']['image_mode'] ?? null);
        self::assertSame('background', self::$afterFirstRun['zz-chosen']['image_mode'] ?? null, 'every other choice changed, still behind the text');
    }

    public function testAHeaderWithoutAPictureStaysWithoutOne(): void
    {
        self::assertSame('none', self::$afterFirstRun['zz-plain']['image_mode'] ?? null);
    }

    public function testEveryHeaderGetsTheHeightAndTheCropItHad(): void
    {
        foreach (['zz-plain', 'zz-picture', 'zz-chosen'] as $slug) {
            self::assertSame('medium', self::$afterFirstRun[$slug]['hero_height'] ?? null, $slug);
            self::assertSame('center', self::$afterFirstRun[$slug]['image_focus'] ?? null, $slug);
        }
    }

    public function testNothingElseInTheRowAndNoneOfItsWordsChanges(): void
    {
        self::assertSame(self::$before, self::$oldColumnsAfterFirstRun, 'compared on the columns the rows had before');
        self::assertCount(3, self::$before);
        self::assertSame(self::$wordsBefore, self::$wordsAfterFirstRun, 'eyebrow, title, lead: byte for byte');
        self::assertNotNull(self::$before['zz-picture']['media_id'], 'the picture reference is still there');
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame('right', self::$afterReplay['zz-picture']['image_mode']);
        self::assertSame('large', self::$afterReplay['zz-picture']['hero_height']);
        self::assertSame('top', self::$afterReplay['zz-picture']['image_focus']);
        self::assertSame('none', self::$afterReplay['zz-chosen']['image_mode']);
        self::assertNull(self::$afterReplay['zz-chosen']['media_id']);
        self::assertSame(self::$afterFirstRun['zz-plain'], self::$afterReplay['zz-plain']);
    }

    public function testAFreshInstallHasTheColumnsWithTheirDefaults(): void
    {
        $columns = self::$fresh->rows(
            "SELECT column_name AS name, column_default AS def, is_nullable AS nullable, character_maximum_length AS len
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'page_heroes'
                AND column_name IN ('image_mode', 'hero_height', 'image_focus')
              ORDER BY column_name"
        );

        $byName = [];
        foreach ($columns as $column) {
            $byName[$column['name']] = [trim((string) $column['def'], "'"), $column['nullable'], (int) $column['len']];
        }

        self::assertSame([
            'hero_height' => ['medium', 'NO', 20],
            'image_focus' => ['center', 'NO', 20],
            'image_mode' => ['none', 'NO', 20],
        ], $byName);
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $pdo->exec(
            "INSERT INTO media (path, original_filename, mime_type, width, height, alt_text, created_at, updated_at)
             VALUES ('/assets/media/zz-header.webp', 'zz-header.webp', 'image/webp', 1600, 900, 'Een werkplaats', NOW(), NOW())"
        );
        $media = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO page_heroes (page_slug, media_id, content_position, title_size, text_size, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $insert->execute(['zz-plain', null, 'left', 'normal', 'normal', 1]);
        $insert->execute(['zz-picture', $media, 'left', 'normal', 'normal', 1]);
        $insert->execute(['zz-chosen', $media, 'right', 'large', 'small', 0]);

        $words = $pdo->prepare(
            "INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
             SELECT 'page_heroes', id, ?, ?, ?, NOW(), NOW() FROM page_heroes WHERE page_slug = ?"
        );
        foreach (['zz-plain', 'zz-picture', 'zz-chosen'] as $slug) {
            $words->execute(['nl', 'title', 'Kop van ' . $slug, $slug]);
            $words->execute(['nl', 'eyebrow', 'Bovenschrift', $slug]);
            $words->execute(['en', 'lead', 'An introduction.', $slug]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function rowsBySlug(ScratchInstall $install, string $columns): array
    {
        $rows = [];

        foreach ($install->rows('SELECT ' . $columns . ' FROM page_heroes ORDER BY page_slug') as $row) {
            unset($row['created_at'], $row['updated_at']);
            $rows[(string) $row['page_slug']] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function words(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT owner_id, language_code, field, value FROM block_translations WHERE owner_table = 'page_heroes' ORDER BY owner_id, language_code, field"
        );
    }
}
