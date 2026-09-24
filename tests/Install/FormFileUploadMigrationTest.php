<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Forms 2.0 phase 2 on the kinds of database it meets (FORMS.md, "Bestand
 * uploaden", and "Het contactformulier"):
 *
 *   20260925100000_make_file_upload_an_ordinary_form_field.php
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before it (v0.1.5 plus Forms
 *              2.0 phase 1), with contact blocks of every kind and a
 *              submission that carries the old contact-block attachment
 *   halfway    the same, where an earlier run stopped after one form had
 *              its field but before any switch was turned off
 *
 * What must hold: every form a contact block had the attachment switch ON
 * for gets exactly one optional upload field accepting what the old control
 * accepted, labelled in the site's languages, last in the form, under a key
 * that collides with nothing; a form whose switch was off, or that already
 * had an upload field, gets none; every switch ends at 0 with a default of
 * 0; the old attachment stays, with no field; one file per field per
 * submission is what the index allows; and a second run changes nothing.
 */
#[Group('migration-backfill')]
final class FormFileUploadMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_form_upload_fresh';
    private const UPGRADED = 'mygdala_scratch_form_upload_upgraded';
    private const HALFWAY = 'mygdala_scratch_form_upload_halfway';

    /** Forms 2.0 phase 1, the last migration before this one. */
    private const BEFORE = '20260924160000';

    private const UPLOAD = '20260925100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;
    private static ?ScratchInstall $halfway = null;

    /** @var array<string, int> seed name => form id */
    private static array $forms = [];

    /** @var array<string, int> */
    private static array $halfwayForms = [];

    /** @var list<int> forms with the switch on in the fresh install before the migration */
    private static array $freshSwitchedOn = [];

    /** @var list<array<string, mixed>> */
    private static array $fieldsAfterFirstRun = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::BEFORE);
        self::$freshSwitchedOn = array_map(
            static fn (array $row): int => (int) $row['form_id'],
            self::$fresh->rows('SELECT DISTINCT form_id FROM contact_form_sections WHERE allow_attachment = 1 AND form_id IS NOT NULL ORDER BY form_id')
        );
        self::$fresh->catchUp(self::UPLOAD);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$forms = self::seed(self::$upgraded);
        self::$upgraded->catchUp(self::UPLOAD);
        self::$fieldsAfterFirstRun = self::fileFields(self::$upgraded);
        self::$upgraded->replay(self::UPLOAD, self::UPLOAD);

        self::$halfway = ScratchInstall::upTo(self::HALFWAY, self::BEFORE);
        self::$halfwayForms = self::seed(self::$halfway);
        // What a first run that stopped after one form would have left: the
        // columns, and that form's field with its words, but every switch
        // still on.
        $pdo = self::$halfway->pdo();
        $pdo->exec('ALTER TABLE form_fields ADD COLUMN file_types VARCHAR(100) NULL DEFAULT NULL AFTER default_value');
        $pdo->exec('ALTER TABLE form_fields ADD COLUMN file_max_bytes INT UNSIGNED NULL DEFAULT NULL AFTER file_types');
        $pdo->prepare(
            "INSERT INTO form_fields (form_id, field_key, field_type, is_required, layout_width, sort_order, file_types, file_max_bytes, created_at, updated_at)
             VALUES (?, 'bijlage', 'file', 0, 'full', 50, 'jpg,png,webp,gif,pdf', 8388608, NOW(), NOW())"
        )->execute([self::$halfwayForms['twice']]);
        $pdo->prepare("INSERT INTO form_field_translations (form_field_id, language_code, label) VALUES (?, 'nl', 'Bijlage')")
            ->execute([(int) $pdo->lastInsertId()]);
        self::$halfway->catchUp(self::UPLOAD);
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

    public function testEveryFormWithTheSwitchOnGetsExactlyOneUploadField(): void
    {
        $byForm = [];
        foreach (self::$fieldsAfterFirstRun as $row) {
            $byForm[(int) $row['form_id']][] = $row;
        }

        // `quote` already had a field called bijlage (a text field).
        self::assertSame('bijlage-2', $byForm[self::$forms['quote']][0]['field_key']);
        self::assertSame('bijlage', $byForm[self::$forms['twice']][0]['field_key']);
        self::assertCount(1, $byForm[self::$forms['twice']], 'two blocks, one form, one field');
        self::assertArrayNotHasKey(self::$forms['off'], $byForm, 'the switch was off');
        self::assertCount(1, $byForm[self::$forms['had-one']], 'a form with an upload field gets no second one');
        self::assertSame('eigen-upload', $byForm[self::$forms['had-one']][0]['field_key']);

        foreach ([self::$forms['quote'], self::$forms['twice']] as $formId) {
            $field = $byForm[$formId][0];
            self::assertSame('jpg,png,webp,gif,pdf', $field['file_types']);
            self::assertSame(8 * 1024 * 1024, (int) $field['file_max_bytes']);
            self::assertSame(0, (int) $field['is_required']);
            self::assertSame('full', $field['layout_width']);

            $last = self::$upgraded->rows('SELECT MAX(sort_order) AS m FROM form_fields WHERE form_id = ?', [$formId])[0]['m'];
            self::assertSame((int) $last, (int) $field['sort_order'], 'last in the form, where the control stood');

            $words = self::$upgraded->rows('SELECT language_code, label FROM form_field_translations WHERE form_field_id = ? ORDER BY language_code', [(int) $field['id']]);
            self::assertSame([['language_code' => 'en', 'label' => 'Attachment'], ['language_code' => 'nl', 'label' => 'Bijlage']], $words);
        }
    }

    public function testEverySwitchIsOffAndStaysOff(): void
    {
        foreach ([self::$upgraded, self::$halfway, self::$fresh] as $install) {
            self::assertSame(0, (int) $install->rows('SELECT COUNT(*) AS n FROM contact_form_sections WHERE allow_attachment <> 0')[0]['n']);

            $default = $install->rows(
                "SELECT COLUMN_DEFAULT FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'contact_form_sections' AND column_name = 'allow_attachment'"
            )[0]['COLUMN_DEFAULT'];
            self::assertSame('0', trim((string) $default, "'"));
        }
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame(self::$fieldsAfterFirstRun, self::fileFields(self::$upgraded));
    }

    public function testARunThatStoppedHalfwayFinishesWithoutADuplicate(): void
    {
        $fields = self::fileFields(self::$halfway);
        $twice = array_values(array_filter($fields, static fn (array $row): bool => (int) $row['form_id'] === self::$halfwayForms['twice']));
        $quote = array_values(array_filter($fields, static fn (array $row): bool => (int) $row['form_id'] === self::$halfwayForms['quote']));

        self::assertCount(1, $twice);
        self::assertSame(50, (int) $twice[0]['sort_order'], 'the field the first run wrote is the one that stays');
        self::assertCount(1, $quote, 'the forms the first run did not reach are finished');
    }

    /**
     * A fresh install places no contact block (20260909310000), so no form
     * gets an upload field: nothing is added automatically. It does get the
     * same schema as an upgraded installation.
     */
    public function testAFreshInstallGetsTheSchemaAndNoUploadField(): void
    {
        self::assertSame([], self::$freshSwitchedOn);
        self::assertSame(0, (int) self::$fresh->rows("SELECT COUNT(*) AS n FROM form_fields WHERE field_type = 'file'")[0]['n']);

        foreach ([self::$fresh, self::$upgraded] as $install) {
            $columns = array_column($install->rows(
                "SELECT CONCAT(table_name, '.', column_name) AS c FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND ((table_name = 'form_fields' AND column_name IN ('file_types', 'file_max_bytes'))
                      OR (table_name = 'form_submission_attachments' AND column_name IN ('field_key', 'sha256')))
                  ORDER BY c"
            ), 'c');
            self::assertSame([
                'form_fields.file_max_bytes',
                'form_fields.file_types',
                'form_submission_attachments.field_key',
                'form_submission_attachments.sha256',
            ], $columns);
        }
    }

    /**
     * The old attachment keeps its row, without a field; a submission may
     * now have one file per upload field and still only one per field.
     */
    public function testTheOldAttachmentStaysAndTheIndexAllowsOneFilePerField(): void
    {
        $rows = self::$upgraded->rows('SELECT submission_id, field_key, stored_filename, sha256 FROM form_submission_attachments');
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['field_key']);
        self::assertNull($rows[0]['sha256']);
        self::assertSame('0123456789abcdef0123456789abcdef.pdf', $rows[0]['stored_filename']);

        foreach ([self::$fresh, self::$upgraded, self::$halfway] as $install) {
            $indexes = [];
            foreach ($install->rows("SHOW INDEX FROM form_submission_attachments WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'") as $row) {
                $indexes[$row['Key_name']][] = $row['Column_name'];
            }
            self::assertSame(['uq_form_submission_attachments_field' => ['submission_id', 'field_key']], $indexes);
        }

        $pdo = self::$upgraded->pdo();
        $submission = (int) $rows[0]['submission_id'];
        $insert = $pdo->prepare(
            "INSERT INTO form_submission_attachments (submission_id, field_key, stored_filename, original_filename, mime_type, file_size, created_at)
             VALUES (?, ?, ?, 'x.pdf', 'application/pdf', 1, NOW())"
        );
        $insert->execute([$submission, 'a', 'a.pdf']);
        $insert->execute([$submission, 'b', 'b.pdf']);

        $this->expectException(\PDOException::class);
        $insert->execute([$submission, 'a', 'c.pdf']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, int> name => form id
     */
    private static function seed(ScratchInstall $install): array
    {
        $pdo = $install->pdo();
        $forms = [];

        foreach (['quote', 'twice', 'off', 'had-one'] as $name) {
            $pdo->prepare(
                "INSERT INTO forms (name, internal_key, is_active, store_submissions, created_at, updated_at)
                 VALUES (?, ?, 1, 1, NOW(), NOW())"
            )->execute(['zz ' . $name, 'zz-upload-' . $name . '-' . bin2hex(random_bytes(3))]);
            $forms[$name] = (int) $pdo->lastInsertId();
        }

        $field = $pdo->prepare(
            "INSERT INTO form_fields (form_id, field_key, field_type, is_required, layout_width, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, 1, 'half', ?, NOW(), NOW())"
        );
        $field->execute([$forms['quote'], 'naam', 'text', 0]);
        $field->execute([$forms['quote'], 'bijlage', 'text', 1]);
        $field->execute([$forms['twice'], 'naam', 'text', 0]);
        $field->execute([$forms['off'], 'naam', 'text', 0]);
        $field->execute([$forms['had-one'], 'eigen-upload', 'file', 0]);

        $section = $pdo->prepare(
            "INSERT INTO contact_form_sections (page_slug, section_key, form_id, allow_attachment, is_active, created_at, updated_at)
             VALUES ('zz-upload', ?, ?, ?, ?, NOW(), NOW())"
        );
        $section->execute(['quote', $forms['quote'], 1, 1]);
        $section->execute(['twice-a', $forms['twice'], 1, 1]);
        $section->execute(['twice-b', $forms['twice'], 1, 0]);
        $section->execute(['off', $forms['off'], 0, 1]);
        $section->execute(['had-one', $forms['had-one'], 1, 1]);
        $section->execute(['no-form', null, 1, 1]);

        $pdo->prepare(
            "INSERT INTO form_submissions (form_id, form_name, is_read, created_at, updated_at) VALUES (?, 'zz quote', 0, NOW(), NOW())"
        )->execute([$forms['quote']]);
        $pdo->prepare(
            "INSERT INTO form_submission_attachments (submission_id, stored_filename, original_filename, mime_type, file_size, created_at)
             VALUES (?, '0123456789abcdef0123456789abcdef.pdf', 'offerte.pdf', 'application/pdf', 1234, NOW())"
        )->execute([(int) $pdo->lastInsertId()]);

        return $forms;
    }

    /** @return list<array<string, mixed>> */
    private static function fileFields(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT id, form_id, field_key, is_required, layout_width, sort_order, file_types, file_max_bytes
               FROM form_fields WHERE field_type = 'file' AND form_id IN (SELECT id FROM forms WHERE name LIKE 'zz %')
              ORDER BY form_id, id"
        );
    }
}
