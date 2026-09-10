<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Core Forms V1: a form definition, its fields, its optional stored
 * submissions, and the content table of the reusable "Formulier" block.
 *
 * Until now this CMS had exactly one form — the quote form hardcoded in
 * partials/section-contact-form.php with its fields, its validation and its
 * recipient written into api/contact.php. These tables turn that into an
 * ordinary CMS capability: an editor defines a form once and places it on as
 * many pages as they like. See FORMS.md.
 *
 * FIVE SMALL NORMALISED TABLES, NOT AN EAV STORE. A field is a row with real
 * columns; the only per-type configuration that exists is the option list of
 * a select/radio, and that is one TEXT column parsed by
 * App\Service\Forms\FormFieldOptions rather than a JSON blob or a third
 * level of tables. Nothing here is generic enough to describe a field type
 * that App\Service\Forms\FormFieldTypes does not already know by name.
 *
 * WHY A SUBMISSION SNAPSHOTS ITS LABELS. `form_submission_values` carries
 * the field's key, its LABEL and its TYPE as they were at the moment of
 * submitting, not a foreign key to `form_fields`. An editor who renames
 * "Omschrijving" to "Je vraag" six months from now must not make last
 * spring's submissions unreadable, and a deleted field must not take the
 * answers to it with it. The same reason `form_submissions.form_name` is a
 * copy rather than a join.
 *
 * `form_submissions.form_id` is ON DELETE SET NULL, so history survives even
 * if a form is removed — although App\Service\Forms\FormUsage refuses to
 * delete a form that still has submissions or is still placed on a page.
 *
 * Forward-only, idempotent and MySQL/Vimexx-compatible: plain CREATE TABLE
 * and ALTER, no CTEs, no window functions, no stored routines.
 */
final class CreateTheCoreFormsTables extends AbstractMigration
{
    public function up(): void
    {
        $this->createForms();
        $this->createFormFields();
        $this->createFormSubmissions();
        $this->createFormSubmissionValues();
        $this->createFormSubmissionAttachments();
        $this->createFormBlocks();
        $this->linkTheContactFormBlockToAForm();
    }

    public function down(): void
    {
        // Reverse creation order: children before the rows they point at.
        foreach ([
            'form_submission_attachments',
            'form_submission_values',
            'form_submissions',
            'form_blocks',
            'form_fields',
            'forms',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }

        if ($this->hasTable('contact_form_sections')) {
            $section = $this->table('contact_form_sections');
            foreach (['form_id', 'allow_attachment'] as $column) {
                if ($section->hasColumn($column)) {
                    $section->removeColumn($column)->update();
                }
            }
        }
    }

    private function createForms(): void
    {
        if ($this->hasTable('forms')) {
            return;
        }

        $this->table('forms', ['id' => true])
            // The editor's own label for this form, shown in the CMS and
            // copied onto every submission. Never rendered publicly.
            ->addColumn('name', 'string', ['limit' => 150])
            // A stable, generated identifier. It exists so a form can be
            // referred to in a migration, a test or a log line without
            // depending on its numeric id, and it is deliberately NOT part
            // of any public URL: a form is addressed by the block that
            // renders it, never by a key a visitor could type.
            ->addColumn('internal_key', 'string', ['limit' => 100])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('submit_label_nl', 'string', ['limit' => 150])
            ->addColumn('submit_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('success_message_nl', 'text')
            ->addColumn('success_message_en', 'text', ['null' => true])
            // EMPTY MEANS "use the site's own contact address"
            // (App\Service\SiteSettings::get('email')), which is what
            // App\Service\Forms\FormRecipient resolves. No company's address
            // is written into the code or into a default row.
            ->addColumn('notification_email', 'string', ['limit' => 254, 'null' => true])
            // Which of this form's own email fields may be used as the
            // notification's Reply-To. NULL = send without one. The visitor
            // never controls From; see App\Mail\FormSubmissionBuilder.
            ->addColumn('reply_to_field_key', 'string', ['limit' => 64, 'null' => true])
            // Conservative by default: a new form emails the owner and keeps
            // nothing. Retaining personal data is a decision the site owner
            // makes per form (FORMS.md, "Privacy").
            ->addColumn('store_submissions', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['internal_key'], ['unique' => true])
            ->create();
    }

    private function createFormFields(): void
    {
        if ($this->hasTable('form_fields')) {
            return;
        }

        $this->table('form_fields', ['id' => true])
            ->addColumn('form_id', 'integer', ['signed' => false])
            // The name this field posts under, and the key a stored value is
            // recorded against. Lowercase a-z, 0-9 and '-' only
            // (App\Service\Forms\FormFieldKey), unique within the form.
            ->addColumn('field_key', 'string', ['limit' => 64])
            // One of App\Service\Forms\FormFieldTypes' registered keys. A
            // row holding anything else is ignored everywhere rather than
            // rendered: the registry is a closed list, not a class lookup.
            ->addColumn('field_type', 'string', ['limit' => 32])
            ->addColumn('label_nl', 'string', ['limit' => 200])
            ->addColumn('label_en', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('placeholder_nl', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('placeholder_en', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('help_text_nl', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('help_text_en', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('is_required', 'boolean', ['default' => false])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            // The choices of a select/radio, one per line, "NL|EN". Empty
            // for every other type. See App\Service\Forms\FormFieldOptions.
            ->addColumn('options', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['form_id', 'field_key'], ['unique' => true])
            ->addIndex(['form_id', 'sort_order'])
            ->addForeignKey('form_id', 'forms', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    private function createFormSubmissions(): void
    {
        if ($this->hasTable('form_submissions')) {
            return;
        }

        $this->table('form_submissions', ['id' => true])
            ->addColumn('form_id', 'integer', ['signed' => false, 'null' => true])
            // A copy, not a join: see this migration's docblock.
            ->addColumn('form_name', 'string', ['limit' => 150, 'default' => ''])
            // The public path the form was submitted from, so the owner can
            // tell a landing page's enquiries from the contact page's. A
            // validated, same-site, root-relative path or NULL — never a
            // full URL and never anything a visitor typed
            // (App\Service\Forms\FormSourcePath).
            ->addColumn('source_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_read', 'boolean', ['default' => false])
            // NULL means the notification e-mail has not (yet) gone out, so
            // a submission is never silently lost to an SMTP failure — the
            // admin list shows it as "niet gemaild".
            ->addColumn('notification_sent_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['form_id', 'created_at'])
            ->addIndex(['created_at'])
            ->addForeignKey('form_id', 'forms', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    private function createFormSubmissionValues(): void
    {
        if ($this->hasTable('form_submission_values')) {
            return;
        }

        $this->table('form_submission_values', ['id' => true])
            ->addColumn('submission_id', 'integer', ['signed' => false])
            ->addColumn('field_key', 'string', ['limit' => 64])
            ->addColumn('field_label', 'string', ['limit' => 200, 'default' => ''])
            ->addColumn('field_type', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addIndex(['submission_id', 'sort_order'])
            ->addForeignKey('submission_id', 'form_submissions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    /**
     * Attachments are NOT a Forms V1 field type and there is no upload field
     * in the form builder — FORMS.md says so plainly. This table exists for
     * one reason: the contact form this site already runs has always
     * accepted a photo or a PDF, and the generic engine must not quietly
     * take a working feature away. The `contact_form` block owns that
     * control (see contact_form_sections.allow_attachment); everything else
     * about the submission is ordinary Forms data.
     */
    private function createFormSubmissionAttachments(): void
    {
        if ($this->hasTable('form_submission_attachments')) {
            return;
        }

        $this->table('form_submission_attachments', ['id' => true])
            ->addColumn('submission_id', 'integer', ['signed' => false])
            // Random name inside the non-public storage directory, exactly
            // as App\Service\ContactAttachmentStorage has always written it.
            ->addColumn('stored_filename', 'string', ['limit' => 100])
            ->addColumn('original_filename', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('file_size', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addIndex(['submission_id'], ['unique' => true])
            ->addForeignKey('submission_id', 'form_submissions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    /**
     * The content row of the reusable "Formulier" block: which form to show,
     * plus the block's own heading and introduction. The FIELDS are not here
     * — they belong to the form, which is the whole point (FORMS.md, "Eén
     * definitie, meerdere plaatsingen").
     */
    private function createFormBlocks(): void
    {
        if ($this->hasTable('form_blocks')) {
            return;
        }

        $this->table('form_blocks', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            // NULL means the editor has not chosen a form yet. The block
            // then renders nothing publicly and says so in the page builder.
            ->addColumn('form_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('intro_nl', 'text', ['null' => true])
            ->addColumn('intro_en', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->addIndex(['form_id'])
            ->addForeignKey('form_id', 'forms', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    /**
     * The existing `contact_form` block keeps its type key and every row it
     * has (CONTENT-BLOCKS.md: retiring a block type is a data migration, and
     * this one has no reason to be retired). It becomes a thin wrapper: the
     * form half is now a Form definition like any other, and the "Direct
     * contact" card beside it stays exactly where it was.
     */
    private function linkTheContactFormBlockToAForm(): void
    {
        if (!$this->hasTable('contact_form_sections')) {
            return;
        }

        $table = $this->table('contact_form_sections');

        if (!$table->hasColumn('form_id')) {
            $table->addColumn('form_id', 'integer', ['signed' => false, 'null' => true])
                ->addIndex(['form_id'])
                ->addForeignKey('form_id', 'forms', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                ])
                ->update();
        }

        if (!$table->hasColumn('allow_attachment')) {
            // TRUE, because every existing instance of this block renders
            // the optional "Foto, logo of ontwerp" field today and must keep
            // doing so. A brand-new instance can turn it off in the editor.
            $table->addColumn('allow_attachment', 'boolean', ['default' => true])
                ->update();
        }
    }
}
