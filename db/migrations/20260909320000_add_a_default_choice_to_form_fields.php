<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Lets a choice field start out with one of its options already picked, and
 * gives the site's own "Voor wie is de aanvraag?" its "Particulier" back.
 *
 * WHY THIS EXISTS. The quote form's audience radio had `checked` written into
 * the markup for as long as the form was hardcoded. Core Forms replaced that
 * markup with a generic renderer, and a generic renderer has no business
 * knowing that one particular option of one particular field should start out
 * selected — so the behaviour was lost, and the visitor had to pick. This
 * adds the missing PROPERTY, and then sets it as DATA on the field that used
 * to have it. No option name appears in any renderer or controller.
 *
 * ONLY A CHOICE FIELD HAS ONE. A default for a text field is what the
 * placeholder is for, and a pre-filled text box that the visitor did not type
 * is an answer nobody gave; App\Service\Forms\FieldTypes\FormFieldType
 * decides which types accept one, and everything else leaves the column NULL.
 * The value is validated against the field's own option list, on the way in
 * and again on the way out, so it can never name a choice that is not
 * offered.
 *
 * IDENTIFYING THE FIELD SAFELY. Not by id, not by position and not by a page
 * slug: the form is found by the `internal_key` that
 * 20260909310000_migrate_the_contact_form_into_a_form.php gave it, the field
 * by its key AND its type, and the value only written when that field really
 * offers it and has no default yet. An install that never had the old contact
 * form — or whose editor has since renamed, retyped or removed the field —
 * simply gets nothing, and a fresh form is never given a default it did not
 * ask for.
 *
 * Forward-only, idempotent and MySQL/Vimexx-compatible.
 */
final class AddADefaultChoiceToFormFields extends AbstractMigration
{
    /** Must stay in sync with App\Service\ContactFormContent::MIGRATED_FORM_KEY. */
    private const FORM_KEY = 'contactformulier';

    /** The audience radio, as 20260909310000 created it. */
    private const FIELD_KEY = 'voor-wie';
    private const FIELD_TYPE = 'radio';

    /** The option the hardcoded markup had `checked` on. */
    private const DEFAULT_OPTION = 'Particulier';

    public function up(): void
    {
        if (!$this->hasTable('form_fields')) {
            return;
        }

        $this->addTheColumn();
        $this->restoreTheContactFormsDefault();
    }

    public function down(): void
    {
        if ($this->hasTable('form_fields') && $this->table('form_fields')->hasColumn('default_value')) {
            $this->table('form_fields')->removeColumn('default_value')->update();
        }
    }

    private function addTheColumn(): void
    {
        $table = $this->table('form_fields');

        if ($table->hasColumn('default_value')) {
            return;
        }

        // As wide as an option label can be, and NULL by default: "no
        // default" is the answer for every field that exists today and for
        // every field created afterwards.
        $table->addColumn('default_value', 'string', [
            'limit' => 200,
            'null' => true,
            'after' => 'options',
            'comment' => 'Pre-selected option of a choice field; must be one of `options`',
        ])->update();
    }

    private function restoreTheContactFormsDefault(): void
    {
        $field = $this->oneRow(
            'SELECT f.id, f.options, f.default_value
               FROM form_fields f
               JOIN forms fo ON fo.id = f.form_id
              WHERE fo.internal_key = ' . $this->sqlQuote(self::FORM_KEY)
            . ' AND f.field_key = ' . $this->sqlQuote(self::FIELD_KEY)
            . ' AND f.field_type = ' . $this->sqlQuote(self::FIELD_TYPE)
            . ' LIMIT 1'
        );

        if ($field === null) {
            // No such install, or the editor has since changed the field.
            return;
        }

        if (($field['default_value'] ?? null) !== null) {
            // Already answered — by an earlier run of this migration, or by
            // the editor. Either way it is not this migration's to overwrite.
            return;
        }

        if (!$this->offersTheOption((string) ($field['options'] ?? ''))) {
            // The option was renamed or removed. Writing it anyway would
            // store a default that names a choice the field does not offer.
            return;
        }

        $this->execute(
            'UPDATE form_fields
                SET default_value = ' . $this->sqlQuote(self::DEFAULT_OPTION) . ', updated_at = NOW()
              WHERE id = ' . (int) $field['id']
        );
    }

    /**
     * Whether the stored option list really contains the option, read the
     * same way App\Service\Forms\FormFieldOptions reads it: one per line,
     * "NL|EN" with the English half optional.
     */
    private function offersTheOption(string $stored): bool
    {
        foreach (preg_split('/\R/', $stored) ?: [] as $line) {
            $dutch = trim(explode('|', $line, 2)[0]);

            if ($dutch === self::DEFAULT_OPTION) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function oneRow(string $sql): ?array
    {
        $row = $this->query($sql)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function sqlQuote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
