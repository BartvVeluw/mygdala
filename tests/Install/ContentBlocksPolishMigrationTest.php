<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The four migrations of the Content Blocks UX polish phase, on the kinds of
 * database they meet:
 *
 *   20260923150000  feature_grid_items.icon_media_id      (a card's own SVG icon)
 *   20260923160000  rich_text_sections: text_align + button (the Tekstblok)
 *   20260923170000  the carousel numbers every card shows, written down
 *   20260923180000  carousel_cards.image_focus             (the crop's focus point)
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before them, with carousel
 *              cards in every state the old automatic numbering knew
 *
 * What must hold: a card that showed "01", "02", ... because its label was
 * empty now has that number stored in the default language, so the site
 * looks the same; a card with its own label, a hidden card and a card
 * without a title are left alone, the count skipping exactly the cards the
 * website skipped; a translation keeps its own label; every existing row gets
 * the defaults that look like before (no icon media, left, no button,
 * centre); and a second run changes nothing.
 */
#[Group('migration-backfill')]
final class ContentBlocksPolishMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_cb_polish_fresh';
    private const UPGRADED = 'mygdala_scratch_cb_polish_upgraded';

    /** The last migration before this phase. */
    private const BEFORE = '20260923140000';

    private const LAST = '20260923180000';

    private const NUMBERS = '20260923170000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, int> card name => id */
    private static array $cards = [];

    /** @var list<array<string, mixed>> */
    private static array $labelsAfterFirstRun = [];

    /** @var list<array<string, mixed>> */
    private static array $labelsAfterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::LAST);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$upgraded->catchUp(self::LAST);
        self::$labelsAfterFirstRun = self::labels(self::$upgraded);
        self::$upgraded->replay(self::NUMBERS, self::LAST);
        self::$labelsAfterReplay = self::labels(self::$upgraded);
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

    /**
     * What CardCarouselContent rendered up to f715193, card by card, against
     * what the backfill stored. The old rules: the carousel's active cards in
     * sort_order, then id; a card counts only with a default-language title
     * after trim() (BlockLocalization::raw() inside hasRequiredWords()); it
     * printed its own label when that, trimmed, was not empty (the request's
     * language first, then the default language), else its place among the
     * counted cards.
     */
    public function testEveryCardKeepsTheNumberItShowed(): void
    {
        $labels = self::labelsByCard(self::$upgraded);

        // Carousel A: first (01), hidden (not counted), own label (counted as
        // 02, keeps "Nieuw"), untitled and blank-titled (not counted), third
        // (03, its English label stays), blank label (04: "\t" printed the
        // place), English words only in the title (05).
        self::assertSame(['nl' => '01'], $labels['first'] ?? null);
        self::assertArrayNotHasKey('hidden', $labels, 'an inactive card showed nothing and gets nothing');
        self::assertSame(['nl' => 'Nieuw'], $labels['own'] ?? null, 'an own label is left as it was');
        self::assertArrayNotHasKey('untitled', $labels, 'a card without a title showed nothing and gets nothing');
        self::assertArrayNotHasKey('blankTitle', $labels, 'a title of only whitespace is no title, as raw() trims');
        self::assertSame(['en' => 'Third', 'nl' => '03'], $labels['third'] ?? null, 'a translation keeps its own label; the default language gets the place');
        self::assertSame(['nl' => '04'], $labels['blankLabel'] ?? null, 'a label of only whitespace printed the place; its one row now holds it');
        self::assertSame(['nl' => '05'], $labels['englishTitle'] ?? null, 'English falls back to the stored Dutch number, as it fell back to the place');

        // Each carousel counts from one.
        self::assertSame(['nl' => '01'], $labels['other'] ?? null);

        // Reordered before the migration: the order on the website was
        // sort_order, then id, not the order the cards were made in.
        self::assertSame(['nl' => '03'], $labels['madeFirstSortedLast'] ?? null);
        self::assertSame(['nl' => '01'], $labels['sortTieLowerId'] ?? null);
        self::assertSame(['nl' => '02'], $labels['sortTieHigherId'] ?? null);

        // A hidden carousel rendered nothing, but these same numbers before
        // it was hidden and again once it is switched back on.
        self::assertSame(['nl' => '01'], $labels['inHiddenCarousel'] ?? null);

        // Exactly one row per card and language: nothing duplicated.
        $rows = self::labels(self::$upgraded);
        $keys = array_map(static fn (array $row): string => $row['owner_id'] . '/' . $row['language_code'], $rows);
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertNotSame([], self::$labelsAfterFirstRun);
        self::assertSame(self::$labelsAfterFirstRun, self::$labelsAfterReplay);
    }

    public function testExistingRowsGetDefaultsThatLookLikeBefore(): void
    {
        foreach (self::$upgraded->rows('SELECT image_focus FROM carousel_cards') as $row) {
            self::assertSame('center', $row['image_focus']);
        }

        self::assertSame(
            [['text_align' => 'left', 'button_link_type' => null, 'button_link_target_id' => null, 'button_url' => null]],
            self::$upgraded->rows('SELECT text_align, button_link_type, button_link_target_id, button_url FROM rich_text_sections WHERE section_key = ?', ['zz-polish'])
        );

        self::assertSame(
            [['icon_key' => 'heart', 'icon_media_id' => null]],
            self::$upgraded->rows('SELECT i.icon_key, i.icon_media_id FROM feature_grid_items i JOIN feature_grids g ON g.id = i.feature_grid_id WHERE g.section_key = ?', ['zz-polish'])
        );
    }

    public function testAFreshInstallHasTheNewColumns(): void
    {
        $columns = array_map(
            static fn (array $row): string => $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'],
            self::$fresh->rows(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND ((TABLE_NAME = 'feature_grid_items' AND COLUMN_NAME = 'icon_media_id')
                      OR (TABLE_NAME = 'rich_text_sections' AND COLUMN_NAME IN ('text_align', 'button_link_type', 'button_link_target_id', 'button_url'))
                      OR (TABLE_NAME = 'carousel_cards' AND COLUMN_NAME = 'image_focus'))
                  ORDER BY TABLE_NAME, COLUMN_NAME"
            )
        );

        self::assertSame([
            'carousel_cards.image_focus',
            'feature_grid_items.icon_media_id',
            'rich_text_sections.button_link_target_id',
            'rich_text_sections.button_link_type',
            'rich_text_sections.button_url',
            'rich_text_sections.text_align',
        ], $columns);
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $carousel = self::carousel($pdo, 'zz-polish-a');
        $other = self::carousel($pdo, 'zz-polish-b');
        $reordered = self::carousel($pdo, 'zz-polish-c');
        $hiddenCarousel = self::carousel($pdo, 'zz-polish-d', false);

        self::$cards['first'] = self::card($pdo, $carousel, 0, true, ['nl' => ['title' => 'Eerste']]);
        self::$cards['hidden'] = self::card($pdo, $carousel, 1, false, ['nl' => ['title' => 'Verborgen']]);
        self::$cards['own'] = self::card($pdo, $carousel, 2, true, ['nl' => ['title' => 'Eigen', 'number_label' => 'Nieuw']]);
        self::$cards['untitled'] = self::card($pdo, $carousel, 3, true, ['nl' => ['body' => 'Geen titel']]);
        self::$cards['blankTitle'] = self::card($pdo, $carousel, 4, true, ['nl' => ['title' => " \t\n "]]);
        self::$cards['third'] = self::card($pdo, $carousel, 5, true, ['nl' => ['title' => 'Derde'], 'en' => ['title' => 'Third one', 'number_label' => 'Third']]);
        self::$cards['blankLabel'] = self::card($pdo, $carousel, 6, true, ['nl' => ['title' => 'Leeg label', 'number_label' => "\t"]]);
        self::$cards['englishTitle'] = self::card($pdo, $carousel, 7, true, ['nl' => ['title' => 'Vierde'], 'en' => ['title' => 'Fourth']]);
        self::$cards['other'] = self::card($pdo, $other, 0, true, ['nl' => ['title' => 'Ander']]);

        // Made first, moved to the end; and two cards on the same place,
        // which the website ordered by id.
        self::$cards['madeFirstSortedLast'] = self::card($pdo, $reordered, 5, true, ['nl' => ['title' => 'Eerst gemaakt']]);
        self::$cards['sortTieLowerId'] = self::card($pdo, $reordered, 1, true, ['nl' => ['title' => 'Gelijk een']]);
        self::$cards['sortTieHigherId'] = self::card($pdo, $reordered, 1, true, ['nl' => ['title' => 'Gelijk twee']]);

        self::$cards['inHiddenCarousel'] = self::card($pdo, $hiddenCarousel, 0, true, ['nl' => ['title' => 'In een verborgen carrousel']]);

        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-polish', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO feature_grids (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-polish', 1, NOW(), NOW())");
        $pdo->prepare("INSERT INTO feature_grid_items (feature_grid_id, icon_key, sort_order, is_active, created_at, updated_at) VALUES (?, 'heart', 0, 1, NOW(), NOW())")
            ->execute([(int) $pdo->lastInsertId()]);
    }

    private static function carousel(\PDO $pdo, string $key, bool $active = true): int
    {
        $pdo->prepare("INSERT INTO card_carousels (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', ?, ?, NOW(), NOW())")
            ->execute([$key, $active ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, array<string, string>> $words language => field => value */
    private static function card(\PDO $pdo, int $carousel, int $sort, bool $active, array $words): int
    {
        $pdo->prepare('INSERT INTO carousel_cards (carousel_id, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
            ->execute([$carousel, $sort, $active ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            "INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
             VALUES ('carousel_cards', ?, ?, ?, ?, NOW(), NOW())"
        );
        foreach ($words as $language => $fields) {
            foreach ($fields as $field => $value) {
                $insert->execute([$id, $language, $field, $value]);
            }
        }

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private static function labels(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT owner_id, language_code, value FROM block_translations
              WHERE owner_table = 'carousel_cards' AND field = 'number_label'
              ORDER BY owner_id, language_code"
        );
    }

    /** @return array<string, array<string, string>> card name => language => label */
    private static function labelsByCard(ScratchInstall $install): array
    {
        $names = array_flip(self::$cards);
        $labels = [];
        foreach (self::labels($install) as $row) {
            $labels[$names[(int) $row['owner_id']]][(string) $row['language_code']] = (string) $row['value'];
        }

        return $labels;
    }
}
