<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Tekst met afbeelding 2.0 (20260924100000) on the kinds of database it
 * meets:
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before it, with a block in
 *              every shape the old one-text-many-pictures block could have
 *
 * What must hold: every block becomes its own first item and shows the same
 * words — eyebrow, title and button label byte for byte in every language,
 * the paragraphs as `<p>`s of escaped text, the ones the website skipped
 * left out and a translation's missing paragraph falling back per paragraph
 * as it did — with its first picture and that picture's alt text; every
 * further picture becomes an image-only item after it, in order; a block that
 * showed nothing gets no item; the block row itself, which pages point at,
 * is untouched; the old places of the words and rows are empty afterwards,
 * so nothing is stored twice; and a second run changes nothing.
 */
#[Group('migration-backfill')]
final class TextImageItemsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_text_image_items_fresh';
    private const UPGRADED = 'mygdala_scratch_text_image_items_upgraded';

    /** The last migration before this one. */
    private const BEFORE = '20260923180000';

    private const ITEMS = '20260924100000';

    private const PAGE = 'zz-eigen-pagina-van-een-redacteur';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, int> media name => id */
    private static array $media = [];

    /** @var list<array<string, mixed>> the block rows before the migration */
    private static array $blocksBefore = [];

    /** @var array<string, mixed> */
    private static array $afterFirstRun = [];

    /** @var array<string, mixed> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$blocksBefore = self::$upgraded->rows('SELECT * FROM text_image_splits ORDER BY id');
        self::$upgraded->catchUp(self::ITEMS);
        self::$afterFirstRun = self::state(self::$upgraded);
        self::$upgraded->replay(self::ITEMS, self::ITEMS);
        self::$afterReplay = self::state(self::$upgraded);
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

    public function testAFullBlockBecomesItsFirstItemWithTheSameWordsAndItsFurtherPicturesFollow(): void
    {
        $items = self::items('zz-full');
        self::assertCount(3, $items);

        [$first, $second, $third] = $items;
        self::assertSame(
            ['media_id' => self::$media['bench'], 'image_path' => '/assets/media/bench.webp', 'image_side' => 'left', 'image_column' => '50', 'image_height' => 'large', 'image_focus' => 'center', 'button_url' => '/over', 'sort_order' => 0],
            $first['row']
        );
        self::assertSame(
            [
                'en' => [
                    'alt' => "A 'bench' & tools",
                    'body' => "<p>First paragraph</p>\n<p>Tweede\nalinea</p>",
                    'button_label' => 'Read more',
                    'eyebrow' => 'About',
                ],
                'nl' => [
                    'alt' => 'Een "werkbank" <met> gereedschap',
                    'body' => "<p>Eerste &lt;alinea&gt; &amp; \"zo\"</p>\n<p>Tweede\nalinea</p>",
                    'button_label' => 'Lees meer',
                    'eyebrow' => 'Over ons',
                    'title' => 'Het idee',
                ],
            ],
            $first['words'],
            'words byte for byte; paragraphs as escaped <p>s; the English body takes the Dutch paragraph it lacked, the paragraph without Dutch is left out'
        );

        self::assertSame(
            ['media_id' => null, 'image_path' => '/assets/images/legacy.jpg', 'image_side' => 'left', 'image_column' => '50', 'image_height' => 'medium', 'image_focus' => 'center', 'button_url' => null, 'sort_order' => 1],
            $second['row'],
            'a picture from before the library keeps its path'
        );
        self::assertSame(['nl' => ['alt' => 'Oude foto']], $second['words']);

        self::assertSame(self::$media['plank'], $third['row']['media_id']);
        self::assertSame(2, $third['row']['sort_order']);
        self::assertSame([], $third['words'], 'a picture without alt text gets no words');
    }

    public function testABlockThatShowedNothingGetsNoItemAndHalfBlocksGetOne(): void
    {
        self::assertSame([], self::items('zz-empty'));

        $imageOnly = self::items('zz-image-only');
        self::assertCount(1, $imageOnly);
        self::assertSame('right', $imageOnly[0]['row']['image_side']);
        self::assertSame(self::$media['plank'], $imageOnly[0]['row']['media_id']);
        self::assertSame(['nl' => ['alt' => 'Een plank']], $imageOnly[0]['words']);

        $textOnly = self::items('zz-text-only');
        self::assertCount(1, $textOnly);
        self::assertNull($textOnly[0]['row']['media_id']);
        self::assertNull($textOnly[0]['row']['image_path']);
        self::assertSame(['nl' => ['title' => 'Alleen een titel']], $textOnly[0]['words']);
    }

    public function testTheBlockRowsStayAndTheOldPlacesAreEmpty(): void
    {
        self::assertSame(self::$blocksBefore, self::$upgraded->rows('SELECT * FROM text_image_splits ORDER BY id'), 'what pages point at is untouched');
        self::assertSame(0, self::$upgraded->count('text_image_split_paragraphs'));
        self::assertSame(0, self::$upgraded->count('text_image_split_images'));
        self::assertSame(
            [],
            self::$upgraded->rows("SELECT owner_table, owner_id FROM block_translations WHERE owner_table IN ('text_image_splits', 'text_image_split_paragraphs', 'text_image_split_images')"),
            'no word is stored twice'
        );
        self::assertSame(
            [],
            self::$upgraded->rows("SELECT t.owner_id FROM block_translations t LEFT JOIN text_image_split_items i ON i.id = t.owner_id WHERE t.owner_table = 'text_image_split_items' AND i.id IS NULL"),
            'every moved word has its item'
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertNotSame([], self::$afterFirstRun['items']);
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testBothDatabasesHaveTheItemsTableWithItsKeys(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertTrue($install->hasTable('text_image_split_items'));
            self::assertSame(
                [
                    ['column_name' => 'media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
                    ['column_name' => 'text_image_split_id', 'referenced_table_name' => 'text_image_splits', 'delete_rule' => 'CASCADE'],
                ],
                $install->rows(
                    "SELECT k.column_name AS column_name, k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.table_name = 'text_image_split_items'
                      ORDER BY k.column_name"
                )
            );
            self::assertTrue($install->hasTable('text_image_split_paragraphs'), 'forward-only: the old tables stay');
            self::assertTrue($install->hasTable('text_image_split_images'));
        }
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $media = $pdo->prepare("INSERT INTO media (path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at) VALUES (?, ?, ?, 'image/webp', 1600, 1000, 100, '', NOW(), NOW())");
        foreach (['bench', 'plank'] as $name) {
            $media->execute(['assets/media/' . $name . '.webp', $name . '.webp', $name]);
            self::$media[$name] = (int) $pdo->lastInsertId();
        }

        $block = $pdo->prepare('INSERT INTO text_image_splits (page_slug, section_key, layout, button_url, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())');
        $paragraph = $pdo->prepare('INSERT INTO text_image_split_paragraphs (text_image_split_id, sort_order, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $image = $pdo->prepare('INSERT INTO text_image_split_images (text_image_split_id, media_id, image_path, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $word = $pdo->prepare('INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $words = static function (string $table, int $id, array $translations) use ($word): void {
            foreach ($translations as $language => $fields) {
                foreach ($fields as $field => $value) {
                    $word->execute([$table, $id, $language, $field, $value]);
                }
            }
        };

        // Everything at once, the paragraphs and pictures stored out of order.
        $block->execute([self::PAGE, 'zz-full', 'image_left', '/over']);
        $full = (int) $pdo->lastInsertId();
        $words('text_image_splits', $full, [
            'nl' => ['eyebrow' => 'Over ons', 'title' => 'Het idee', 'button_label' => 'Lees meer'],
            'en' => ['eyebrow' => 'About', 'button_label' => 'Read more'],
        ]);
        $paragraph->execute([$full, 2]);
        $words('text_image_split_paragraphs', (int) $pdo->lastInsertId(), ['en' => ['content' => 'Only in English, so never shown']]);
        $paragraph->execute([$full, 0]);
        $words('text_image_split_paragraphs', (int) $pdo->lastInsertId(), ['nl' => ['content' => 'Eerste <alinea> & "zo"'], 'en' => ['content' => 'First paragraph']]);
        $paragraph->execute([$full, 1]);
        $words('text_image_split_paragraphs', (int) $pdo->lastInsertId(), ['nl' => ['content' => "Tweede\nalinea"], 'en' => ['content' => "  \n"]]);
        $image->execute([$full, self::$media['plank'], '/assets/media/plank.webp', 2]);
        $image->execute([$full, self::$media['bench'], '/assets/media/bench.webp', 0]);
        $words('text_image_split_images', (int) $pdo->lastInsertId(), ['nl' => ['alt' => 'Een "werkbank" <met> gereedschap'], 'en' => ['alt' => "A 'bench' & tools"]]);
        $image->execute([$full, null, '/assets/images/legacy.jpg', 1]);
        $words('text_image_split_images', (int) $pdo->lastInsertId(), ['nl' => ['alt' => 'Oude foto']]);

        // Nothing but a layout: it showed nothing.
        $block->execute([self::PAGE, 'zz-empty', 'image_left', null]);

        // One picture, no words.
        $block->execute([self::PAGE, 'zz-image-only', 'image_right', null]);
        $imageOnly = (int) $pdo->lastInsertId();
        $image->execute([$imageOnly, self::$media['plank'], '/assets/media/plank.webp', 0]);
        $words('text_image_split_images', (int) $pdo->lastInsertId(), ['nl' => ['alt' => 'Een plank']]);

        // A title and nothing else.
        $block->execute([self::PAGE, 'zz-text-only', 'image_right', null]);
        $words('text_image_splits', (int) $pdo->lastInsertId(), ['nl' => ['title' => 'Alleen een titel']]);
    }

    /**
     * The items of one block, in order: the language-neutral columns and the
     * words per language.
     *
     * @return list<array{row: array<string, mixed>, words: array<string, array<string, string>>}>
     */
    private static function items(string $sectionKey): array
    {
        $items = [];
        foreach (self::$upgraded->rows(
            'SELECT i.id AS id, i.media_id AS media_id, i.image_path AS image_path, i.image_side AS image_side, i.image_column AS image_column,
                    i.image_height AS image_height, i.image_focus AS image_focus, i.button_url AS button_url, i.sort_order AS sort_order
               FROM text_image_split_items i JOIN text_image_splits s ON s.id = i.text_image_split_id
              WHERE s.page_slug = ? AND s.section_key = ? ORDER BY i.sort_order, i.id',
            [self::PAGE, $sectionKey]
        ) as $row) {
            $id = (int) $row['id'];
            unset($row['id']);
            $row['media_id'] = $row['media_id'] === null ? null : (int) $row['media_id'];
            $row['sort_order'] = (int) $row['sort_order'];

            $words = [];
            foreach (self::$upgraded->rows(
                "SELECT language_code, field, value FROM block_translations WHERE owner_table = 'text_image_split_items' AND owner_id = ? ORDER BY language_code, field",
                [$id]
            ) as $word) {
                $words[$word['language_code']][$word['field']] = $word['value'];
            }

            $items[] = ['row' => $row, 'words' => $words];
        }

        return $items;
    }

    /** @return array<string, mixed> every item and every item word */
    private static function state(ScratchInstall $install): array
    {
        return [
            'items' => $install->rows('SELECT id, text_image_split_id, media_id, image_path, image_side, image_column, image_height, image_focus, button_url, sort_order FROM text_image_split_items ORDER BY id'),
            'words' => $install->rows("SELECT owner_id, language_code, field, value FROM block_translations WHERE owner_table = 'text_image_split_items' ORDER BY id"),
        ];
    }
}
