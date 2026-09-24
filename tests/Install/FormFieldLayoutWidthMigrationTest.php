<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Forms 2.0 phase 1 on the kinds of database it meets (FORMS.md, "Breedte
 * van een veld"):
 *
 *   20260924160000_give_form_fields_a_layout_width.php
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before it, with one field of
 *              every type
 *   halfway    the same, where an earlier run stopped after adding the
 *              column (empty) and one row had been given a width since
 *
 * What must hold: every existing field gets the width the renderer used to
 * give it (text, e-mail and telephone half a row, everything else the whole
 * row), so no stored form looks different; nothing else about a field
 * changes; a new field is full width; the column never holds NULL; a second
 * run changes nothing, and a run that stopped halfway finishes without
 * touching a width that was already there.
 */
#[Group('migration-backfill')]
final class FormFieldLayoutWidthMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_form_width_fresh';
    private const UPGRADED = 'mygdala_scratch_form_width_upgraded';
    private const HALFWAY = 'mygdala_scratch_form_width_halfway';

    /** The last migration before this one. */
    private const BEFORE = '20260924140000';

    private const WIDTH = '20260924160000';

    private const OTHER_COLUMNS = 'id, form_id, field_key, field_type, is_required, sort_order, default_value, created_at, updated_at';

    /** Field type => the width it must end on. */
    private const EXPECTED = [
        'text' => 'half',
        'email' => 'half',
        'tel' => 'half',
        'textarea' => 'full',
        'select' => 'full',
        'radio' => 'full',
        'checkbox' => 'full',
        'consent' => 'full',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;
    private static ?ScratchInstall $halfway = null;

    /** @var list<array<string, mixed>> */
    private static array $othersBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $widthsAfterFirstRun = [];

    /** @var list<array<string, mixed>> */
    private static array $widthsAfterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::WIDTH);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$othersBefore = self::$upgraded->rows('SELECT ' . self::OTHER_COLUMNS . ' FROM form_fields ORDER BY id');
        self::$upgraded->catchUp(self::WIDTH);
        self::$widthsAfterFirstRun = self::widths(self::$upgraded);

        // What an editor may have chosen since, then the same file again.
        self::$upgraded->pdo()->exec("UPDATE form_fields SET layout_width = 'third' WHERE field_key = 'zz-textarea'");
        self::$upgraded->replay(self::WIDTH, self::WIDTH);
        self::$widthsAfterReplay = self::widths(self::$upgraded);

        self::$halfway = ScratchInstall::upTo(self::HALFWAY, self::BEFORE);
        self::seed(self::$halfway);
        self::$halfway->pdo()->exec('ALTER TABLE form_fields ADD COLUMN layout_width VARCHAR(20) NULL DEFAULT NULL AFTER is_required');
        self::$halfway->pdo()->exec("UPDATE form_fields SET layout_width = 'quarter' WHERE field_key = 'zz-email'");
        self::$halfway->catchUp(self::WIDTH);
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
        self::$halfway?->drop();
        self::$fresh = null;
        self::$upgraded = null;
        self::$halfway = null;
    }

    public function testEveryExistingFieldKeepsTheWidthItWasShownAt(): void
    {
        self::assertSame(self::expected(), self::$widthsAfterFirstRun);
    }

    public function testNothingElseAboutAFieldChanged(): void
    {
        self::assertSame(self::$othersBefore, self::$upgraded->rows('SELECT ' . self::OTHER_COLUMNS . ' FROM form_fields ORDER BY id'));
    }

    public function testASecondRunChangesNothingAnEditorChose(): void
    {
        $expected = self::expected();
        foreach ($expected as $index => $row) {
            if ($row['field_key'] === 'zz-textarea') {
                $expected[$index]['layout_width'] = 'third';
            }
        }

        self::assertSame($expected, self::$widthsAfterReplay);
    }

    public function testARunThatStoppedHalfwayFinishesWithoutOverwriting(): void
    {
        $expected = self::expected();
        foreach ($expected as $index => $row) {
            if ($row['field_key'] === 'zz-email') {
                $expected[$index]['layout_width'] = 'quarter';
            }
        }

        self::assertSame($expected, self::widths(self::$halfway));
        self::assertSame(['NO', 'full'], self::columnShape(self::$halfway));
    }

    /**
     * The same column on a fresh install as on an upgraded one: never NULL,
     * `full` by default, so a field created without a width is a whole row.
     */
    public function testANewFieldIsFullWidthAndTheColumnIsNeverEmpty(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(['NO', 'full'], self::columnShape($install));

            $forms = $install->rows('SELECT id FROM forms ORDER BY id LIMIT 1');
            $form = $forms === [] ? self::form($install) : (int) $forms[0]['id'];
            $install->pdo()->prepare(
                "INSERT INTO form_fields (form_id, field_key, field_type, is_required, sort_order, created_at, updated_at)
                 VALUES (?, 'zz-nieuw', 'text', 0, 99, NOW(), NOW())"
            )->execute([$form]);

            self::assertSame('full', $install->rows("SELECT layout_width FROM form_fields WHERE field_key = 'zz-nieuw'")[0]['layout_width']);
            self::assertSame(0, (int) $install->rows('SELECT COUNT(*) AS n FROM form_fields WHERE layout_width IS NULL')[0]['n']);
        }
    }

    // --------------------------------------------------------------- helpers

    /** One form with one field of every type, in the order of EXPECTED. */
    private static function seed(ScratchInstall $install): void
    {
        $form = self::form($install);
        $field = $install->pdo()->prepare(
            'INSERT INTO form_fields (form_id, field_key, field_type, is_required, sort_order, default_value, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $position = 0;
        foreach (array_keys(self::EXPECTED) as $type) {
            $field->execute([$form, 'zz-' . $type, $type, $position % 2, $position, null, '2026-01-02 03:04:05', '2026-02-03 04:05:06']);
            $position++;
        }
    }

    private static function form(ScratchInstall $install): int
    {
        $install->pdo()->exec(
            "INSERT INTO forms (name, internal_key, is_active, store_submissions, created_at, updated_at)
             VALUES ('zz Breedtes', CONCAT('zz-breedtes-', FLOOR(RAND() * 1000000)), 1, 0, NOW(), NOW())"
        );

        return (int) $install->pdo()->lastInsertId();
    }

    /** @return list<array{field_key: string, layout_width: string}> */
    private static function expected(): array
    {
        $rows = [];
        foreach (self::EXPECTED as $type => $width) {
            $rows[] = ['field_key' => 'zz-' . $type, 'layout_width' => $width];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function widths(ScratchInstall $install): array
    {
        return $install->rows("SELECT field_key, layout_width FROM form_fields WHERE field_key LIKE 'zz-%' ORDER BY id");
    }

    /** @return array{0: string, 1: string|null} IS_NULLABLE and COLUMN_DEFAULT */
    private static function columnShape(ScratchInstall $install): array
    {
        $row = $install->rows(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'form_fields' AND column_name = 'layout_width'"
        )[0] ?? [];

        return [(string) ($row['IS_NULLABLE'] ?? ''), isset($row['COLUMN_DEFAULT']) ? trim((string) $row['COLUMN_DEFAULT'], "'") : null];
    }
}
