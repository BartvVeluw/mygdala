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

    public function testEveryCardKeepsTheNumberItShowed(): void
    {
        $labels = self::labelsByCard(self::$upgraded);

        // First carousel: first (01), hidden (skipped), own label (counts as
        // 02 but keeps "Nieuw"), untitled (skipped), third shown (03, and its
        // English label stays), and the second carousel counts on its own.
        self::assertSame('01', $labels['first']['nl'] ?? null);
        self::assertArrayNotHasKey('hidden', $labels, 'a hidden card showed nothing and gets nothing');
        self::assertSame('Nieuw', $labels['own']['nl'] ?? null, 'an own label is left as it was');
        self::assertArrayNotHasKey('untitled', $labels, 'a card without a title showed nothing and gets nothing');
        self::assertSame('03', $labels['third']['nl'] ?? null, 'counted among the cards that showed, as the website counted');
        self::assertSame('Third', $labels['third']['en'] ?? null, 'a translation keeps its own label');
        self::assertSame('01', $labels['other']['nl'] ?? null, 'each carousel counts from one');
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

        self::$cards['first'] = self::card($pdo, $carousel, 0, true, ['nl' => ['title' => 'Eerste']]);
        self::$cards['hidden'] = self::card($pdo, $carousel, 1, false, ['nl' => ['title' => 'Verborgen']]);
        self::$cards['own'] = self::card($pdo, $carousel, 2, true, ['nl' => ['title' => 'Eigen', 'number_label' => 'Nieuw']]);
        self::$cards['untitled'] = self::card($pdo, $carousel, 3, true, ['nl' => ['body' => 'Geen titel']]);
        self::$cards['third'] = self::card($pdo, $carousel, 4, true, ['nl' => ['title' => 'Derde'], 'en' => ['title' => 'Third one', 'number_label' => 'Third']]);
        self::$cards['other'] = self::card($pdo, $other, 0, true, ['nl' => ['title' => 'Ander']]);

        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-polish', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO feature_grids (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', 'zz-polish', 1, NOW(), NOW())");
        $pdo->prepare("INSERT INTO feature_grid_items (feature_grid_id, icon_key, sort_order, is_active, created_at, updated_at) VALUES (?, 'heart', 0, 1, NOW(), NOW())")
            ->execute([(int) $pdo->lastInsertId()]);
    }

    private static function carousel(\PDO $pdo, string $key): int
    {
        $pdo->prepare("INSERT INTO card_carousels (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz', ?, 1, NOW(), NOW())")
            ->execute([$key]);

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
