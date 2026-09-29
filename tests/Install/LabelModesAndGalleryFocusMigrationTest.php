<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20260930140000_give_labels_a_mode_and_gallery_items_a_focus_point.php
 * (v0.1.13 corrections), on a fresh installation and on an upgraded one with
 * a carousel and a Detailsectie in it:
 *
 *   - a card with label words in any language becomes `custom`, its words
 *     untouched — also a stored "01", which may have been typed on purpose;
 *     a card without words becomes `none`;
 *   - every existing Detailsectie keeps its automatic number: `padded`;
 *   - every existing gallery item sits in the middle (50 / 50) without phone
 *     settings;
 *   - the icon and the phone picture are real foreign keys to media,
 *     RESTRICT;
 *   - fresh and upgraded end on the same columns, and running it again
 *     changes nothing, a later choice included.
 */
#[Group('migration-backfill')]
final class LabelModesAndGalleryFocusMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_lmf_fresh';
    private const UPGRADED = 'mygdala_scratch_lmf_upgraded';

    private const BEFORE = '20260930120000';
    private const MIGRATION = '20260930140000';

    private const TABLES = ['carousel_cards', 'detail_sections', 'detail_section_images', 'block_translations'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$before = self::rows(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::rows(self::$upgraded);

        // A choice made after the upgrade survives a replay.
        self::$upgraded->pdo()->exec("UPDATE carousel_cards SET label_mode = 'none' WHERE sort_order = 0");
        $chosen = self::rows(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::rows(self::$upgraded);
        self::$after['__chosen'] = $chosen['carousel_cards'];
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

    public function testACardKeepsWhatItShowed(): void
    {
        $modes = array_column(self::$after['carousel_cards'], 'label_mode', 'sort_order');

        self::assertSame(['custom', 'custom', 'none', 'none'], [$modes[0], $modes[1], $modes[2], $modes[3]], '"01" and an English-only label are text; empty and blank are none');
        foreach (self::$after['carousel_cards'] as $card) {
            self::assertNull($card['label_icon_media_id']);
        }

        self::assertSame(self::$before['block_translations'], self::$after['block_translations'], 'no word changed');
        self::assertSame(
            self::without(self::$before['carousel_cards'], []),
            self::without(self::$after['carousel_cards'], ['label_mode', 'label_icon_media_id'])
        );
    }

    public function testASectionKeepsItsNumberAndAGalleryItemItsMiddle(): void
    {
        self::assertSame('padded', self::$after['detail_sections'][0]['label_mode']);
        self::assertSame(self::$before['detail_sections'], self::without(self::$after['detail_sections'], ['label_mode']));

        $image = self::$after['detail_section_images'][0];
        self::assertSame([50, 50, null, null, null], [
            (int) $image['image_focus_x'], (int) $image['image_focus_y'],
            $image['image_mobile_media_id'], $image['image_mobile_focus_x'], $image['image_mobile_focus_y'],
        ]);
        self::assertSame(
            self::$before['detail_section_images'],
            self::without(self::$after['detail_section_images'], ['image_focus_x', 'image_focus_y', 'image_mobile_media_id', 'image_mobile_focus_x', 'image_mobile_focus_y'])
        );
    }

    public function testTheNewReferencesAreRealKeysThatKeepAUsedItem(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            $rules = $install->rows(
                "SELECT k.table_name AS tbl, k.column_name AS col, k.referenced_table_name AS target, r.delete_rule AS rule
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                  WHERE k.table_schema = DATABASE()
                    AND ((k.table_name = 'carousel_cards' AND k.column_name = 'label_icon_media_id')
                      OR (k.table_name = 'detail_section_images' AND k.column_name = 'image_mobile_media_id'))
                  ORDER BY k.table_name"
            );
            self::assertSame([
                ['tbl' => 'carousel_cards', 'col' => 'label_icon_media_id', 'target' => 'media', 'rule' => 'RESTRICT'],
                ['tbl' => 'detail_section_images', 'col' => 'image_mobile_media_id', 'target' => 'media', 'rule' => 'RESTRICT'],
            ], $rules);
        }
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        foreach (['carousel_cards', 'detail_sections', 'detail_section_images'] as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
        }

        $defaults = array_column(self::columns(self::$fresh, 'detail_sections'), 'default_value', 'name');
        self::assertSame('padded', $defaults['label_mode'], 'a new section starts numbered, like every section before');
        $defaults = array_column(self::columns(self::$fresh, 'carousel_cards'), 'default_value', 'name');
        self::assertSame('none', $defaults['label_mode']);
    }

    public function testRunningItAgainChangesNothingAndKeepsALaterChoice(): void
    {
        self::assertSame('none', array_column(self::$afterReplay['carousel_cards'], 'label_mode', 'sort_order')[0], 'the backfill ran once');
        self::assertSame(self::$after['__chosen'], self::$afterReplay['carousel_cards']);
        foreach (['detail_sections', 'detail_section_images', 'block_translations'] as $table) {
            self::assertSame(self::$after[$table], self::$afterReplay[$table], $table);
        }
    }

    /* ------------------------------------------------------------------ */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $at = "'2026-09-29 10:00:00'";

        $pdo->exec("INSERT INTO card_carousels (page_slug, section_key, is_active, created_at, updated_at)
                    VALUES ('zz-lmf', 'custom-zzlmf01', 1, {$at}, {$at})");
        $carousel = (int) $pdo->lastInsertId();

        // 0: "01" in Dutch; 1: English only; 2: nothing; 3: only blanks.
        $labels = [['nl' => '01'], ['en' => 'New'], [], ['nl' => '   ']];
        foreach ($labels as $position => $words) {
            $pdo->exec("INSERT INTO carousel_cards (carousel_id, sort_order, is_active, created_at, updated_at)
                        VALUES ({$carousel}, {$position}, 1, {$at}, {$at})");
            $card = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO block_translations (owner_table, owner_id, language_code, field, value)
                        VALUES ('carousel_cards', {$card}, 'nl', 'title', 'Kaart {$position}')");
            foreach ($words as $language => $label) {
                $pdo->prepare("INSERT INTO block_translations (owner_table, owner_id, language_code, field, value)
                               VALUES ('carousel_cards', ?, ?, 'number_label', ?)")->execute([$card, $language, $label]);
            }
        }

        $pdo->exec("INSERT INTO detail_sections (page_slug, section_key, image_position, is_active, created_at, updated_at)
                    VALUES ('zz-lmf', 'custom-zzlmf02', 'image_left', 1, {$at}, {$at})");
        $section = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO detail_section_images (section_id, image_path, sort_order, created_at, updated_at)
                    VALUES ({$section}, 'assets/images/sections/zz-lmf.jpg', 0, {$at}, {$at})");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function rows(ScratchInstall $install): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $install->rows('SELECT * FROM `' . $table . '` ORDER BY 1');
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     *
     * @return list<array<string, mixed>>
     */
    private static function without(array $rows, array $columns): array
    {
        return array_map(static fn (array $row): array => array_diff_key($row, array_flip($columns)), $rows);
    }

    /** @return list<array<string, mixed>> */
    private static function columns(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS default_value
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY ordinal_position',
            [$table]
        );
    }
}
