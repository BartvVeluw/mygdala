<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Forms 2.0 phase 2: a file upload is an ordinary form field (FORMS.md,
 * "Bestand uploaden").
 *
 *   form_fields.file_types          the accepted kinds, comma-separated keys of
 *                                   App\Service\Forms\FormFileTypes ('jpg,png,pdf');
 *                                   NULL for every field that takes no file
 *   form_fields.file_max_bytes      the largest file the field takes; NULL idem
 *   form_submission_attachments.field_key
 *                                   which upload field a stored file answers;
 *                                   NULL for the contact block's attachment
 *                                   from before this phase
 *   form_submission_attachments.sha256
 *                                   the file's hash, for the owner's records
 *
 * and the unique index on form_submission_attachments.submission_id becomes
 * one on (submission_id, field_key): a submission can now carry a file per
 * upload field, but still one file per field.
 *
 * THE CONTACT BLOCK'S ATTACHMENT BECOMES A FIELD. Until now a `contact_form`
 * block with `allow_attachment` switched on printed a fixed "Bijlage"
 * control after its form's fields, and the endpoint accepted a file for any
 * form such a block pointed at. That switch is retired. So that no site
 * loses what it offered, every form a block had the switch ON for gets one
 * explicit field of the new type, with exactly what the control accepted:
 *
 *   label        "Bijlage" (and "Attachment" where English is a site language)
 *   type         file, optional, full width, last in the form
 *   accepts      JPG, PNG, WEBP, GIF and PDF, at most 8 MB
 *   key          `bijlage`, or `bijlage-2` … when the form already has one
 *
 * A form that already has an upload field gets no second one. Then every
 * switch goes to 0, and the column's default with it; nothing reads it any
 * more, and it is kept rather than dropped because dropping it would only
 * make a rollback of the code harder. A block that pointed at no form had no
 * form to add a field to, and simply has its switch turned off.
 *
 * A fresh install has no contact block at all (20260909310000: "a fresh
 * install gets nothing"), so it gets no upload field anywhere: a new site
 * starts with the schema and without a single upload, which is what "not
 * added automatically" means. An editor adds one where a form wants it.
 *
 * Existing submissions are not touched: their attachment rows keep a NULL
 * `field_key` and stay downloadable exactly as before.
 *
 * Every step is safe to run again (db/migrations/CLAUDE.md): columns and
 * indexes are only added when missing, a form is only given a field while
 * it has no upload field, and the field with its words is one transaction,
 * so a run that stopped halfway finishes the rest the next time.
 */
final class MakeFileUploadAnOrdinaryFormField extends AbstractMigration
{
    /** What the contact block's control accepted (its old validator). */
    private const LEGACY_TYPES = 'jpg,png,webp,gif,pdf';
    private const LEGACY_MAX_BYTES = 8 * 1024 * 1024;

    /** The field's label per language this migration knows words for. */
    private const LABELS = ['nl' => 'Bijlage', 'en' => 'Attachment'];

    public function up(): void
    {
        $this->addFieldColumns();
        $this->reshapeAttachments();
        $this->turnSwitchesIntoFields();
    }

    public function down(): void
    {
        // Forward-only (CLAUDE.md): the fields this created are ordinary
        // fields by now, and the files they received are real submissions.
    }

    private function addFieldColumns(): void
    {
        $table = $this->table('form_fields');

        if (!$table->hasColumn('file_types')) {
            $table->addColumn('file_types', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'default_value',
                'comment' => 'App\Service\Forms\FormFileTypes keys an upload field accepts',
            ])->update();
        }

        $table = $this->table('form_fields');

        if (!$table->hasColumn('file_max_bytes')) {
            $table->addColumn('file_max_bytes', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'file_types',
                'comment' => 'The largest file an upload field accepts, in bytes',
            ])->update();
        }
    }

    private function reshapeAttachments(): void
    {
        $table = $this->table('form_submission_attachments');

        if (!$table->hasColumn('field_key')) {
            $table->addColumn('field_key', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'submission_id',
                'comment' => 'The upload field this file answers; NULL for a contact-block attachment from before Forms 2.0 phase 2',
            ])->update();
        }

        $table = $this->table('form_submission_attachments');

        if (!$table->hasColumn('sha256')) {
            $table->addColumn('sha256', 'char', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'file_size',
            ])->update();
        }

        // The new index first: the foreign key on submission_id needs an
        // index that starts with it at every moment, and this one does.
        $table = $this->table('form_submission_attachments');

        if (!$table->hasIndexByName('uq_form_submission_attachments_field')) {
            $table->addIndex(['submission_id', 'field_key'], [
                'unique' => true,
                'name' => 'uq_form_submission_attachments_field',
            ])->update();
        }

        $table = $this->table('form_submission_attachments');

        if ($table->hasIndexByName('submission_id')) {
            $table->removeIndexByName('submission_id')->update();
        }
    }

    private function turnSwitchesIntoFields(): void
    {
        if (!$this->hasTable('contact_form_sections')
            || !$this->table('contact_form_sections')->hasColumn('allow_attachment')
        ) {
            return;
        }

        $formIds = array_map(
            static fn (array $row): int => (int) $row['form_id'],
            $this->fetchAll(
                'SELECT DISTINCT c.form_id FROM contact_form_sections c
                   JOIN forms f ON f.id = c.form_id
                  WHERE c.allow_attachment = 1
                  ORDER BY c.form_id'
            )
        );

        $languages = $this->fetchAll('SELECT code, is_default FROM site_languages WHERE is_active = 1 ORDER BY sort_order, id');

        foreach ($formIds as $formId) {
            $this->giveFormAnUploadField($formId, $languages);
        }

        $this->execute('UPDATE contact_form_sections SET allow_attachment = 0 WHERE allow_attachment <> 0');

        $this->table('contact_form_sections')
            ->changeColumn('allow_attachment', 'boolean', [
                'default' => false,
                'comment' => 'Retired in Forms 2.0 phase 2 (20260925100000): a file upload is a form field',
            ])
            ->update();
    }

    /**
     * @param list<array<string, mixed>> $languages the active website languages
     */
    private function giveFormAnUploadField(int $formId, array $languages): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $hasOne = $pdo->prepare("SELECT 1 FROM form_fields WHERE form_id = :form_id AND field_type = 'file' LIMIT 1");
        $hasOne->execute(['form_id' => $formId]);
        if ($hasOne->fetchColumn() !== false) {
            return;
        }

        $keys = $pdo->prepare('SELECT field_key FROM form_fields WHERE form_id = :form_id');
        $keys->execute(['form_id' => $formId]);
        $taken = array_map('strval', $keys->fetchAll(\PDO::FETCH_COLUMN));

        $key = 'bijlage';
        for ($suffix = 2; in_array($key, $taken, true); $suffix++) {
            $key = 'bijlage-' . $suffix;
        }

        $last = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM form_fields WHERE form_id = :form_id');
        $last->execute(['form_id' => $formId]);
        $sortOrder = (int) $last->fetchColumn() + 1;

        // A transaction of its own, unless Phinx already runs this migration
        // inside one (it may, on a replay with no schema change left to
        // make); then that one covers it. The pattern of 20260924100000.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->prepare(
                "INSERT INTO form_fields
                    (form_id, field_key, field_type, is_required, layout_width, sort_order, default_value,
                     file_types, file_max_bytes, created_at, updated_at)
                 VALUES (:form_id, :field_key, 'file', 0, 'full', :sort_order, NULL,
                     :file_types, :file_max_bytes, NOW(), NOW())"
            )->execute([
                'form_id' => $formId,
                'field_key' => $key,
                'sort_order' => $sortOrder,
                'file_types' => self::LEGACY_TYPES,
                'file_max_bytes' => self::LEGACY_MAX_BYTES,
            ]);

            $fieldId = (int) $pdo->lastInsertId();

            $words = $pdo->prepare(
                'INSERT INTO form_field_translations (form_field_id, language_code, label, placeholder, help_text, created_at, updated_at)
                 VALUES (:form_field_id, :language_code, :label, NULL, NULL, NOW(), NOW())'
            );

            foreach ($languages as $language) {
                $code = (string) $language['code'];
                $label = self::LABELS[$code] ?? null;

                // The default language always gets a label, the one every
                // other language falls back to; another language only when
                // this migration has words for it.
                if ($label === null && (int) ($language['is_default'] ?? 0) === 1) {
                    $label = self::LABELS['nl'];
                }

                if ($label !== null) {
                    $words->execute(['form_field_id' => $fieldId, 'language_code' => $code, 'label' => $label]);
                }
            }

            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
