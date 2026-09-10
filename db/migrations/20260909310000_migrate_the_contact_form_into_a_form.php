<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Turns the quote form this site has always rendered into an ordinary Form
 * definition, and points every existing `contact_form` block at it.
 *
 * Until now the fields lived as markup in
 * partials/section-contact-form.php and as validation in api/contact.php.
 * The block now renders a Form (FORMS.md), so without this migration the
 * contact page would come up with its heading, its contact details and no
 * form at all. Everything below is therefore about PRESERVATION, not about
 * inventing a form.
 *
 * WHAT IS COPIED, verbatim and in both languages: the five fields, their
 * labels, which of them were required, the hint under the description, the
 * submit button's wording and the confirmation message. The field KEYS are
 * the names the old markup posted under (`naam`, `email`, `telefoon`,
 * `voor-wie`, `omschrijving`), so a page a visitor still has open from
 * before the change posts a body that is already in the right shape and the
 * shim at api/contact.php has nothing to translate.
 *
 * ONE DELIBERATE DIFFERENCE. The old "Voor wie is de aanvraag?" radio came
 * with "Particulier" pre-selected; a Forms V1 choice field has no default
 * value (FORMS.md lists per-option defaults as deliberately unsupported), so
 * it is a REQUIRED field the visitor picks instead. Nothing else about the
 * form changes.
 *
 * THE RECIPIENT IS PINNED, not assumed. The old endpoint mailed
 * SHOP_NOTIFICATION_EMAIL from .env; the new one falls back to the site's
 * own contact address in Site-instellingen
 * (App\Service\Forms\FormRecipient). Where those two differ, the .env value
 * is written onto the form so this installation keeps mailing exactly where
 * it did — the same "pin what is live before the code default becomes
 * generic" move as
 * 20260909210000_pin_branding_paths_before_generic_defaults.php. Where they
 * agree, the field is left empty so the owner keeps changing it in one
 * place.
 *
 * EVERY EXISTING BLOCK, NOT THE CONTACT PAGE. The link is made by selecting
 * on the DATA (`contact_form_sections` rows with no form yet), never on a
 * list of known page slugs — a `contact_form` block an editor put on a page
 * of their own is ordinary CMS data and must be migrated too
 * (CONTENT-BLOCKS.md).
 *
 * A FRESH INSTALL GETS NOTHING. No `contact_form` rows means there is no
 * form to preserve, and seeding one would put another company's wording and
 * fields into a brand-new site. Beheer → Formulieren simply opens empty.
 *
 * Forward-only and idempotent: re-running finds the form by its
 * `internal_key` and links only the rows that are still unlinked.
 */
final class MigrateTheContactFormIntoAForm extends AbstractMigration
{
    /** Must stay in sync with App\Service\ContactFormContent::MIGRATED_FORM_KEY. */
    private const FORM_KEY = 'contactformulier';

    /**
     * The five fields the hardcoded markup had, in the order it rendered
     * them. `key`, `type`, labels, requiredness, hint and options are copied
     * straight out of partials/section-contact-form.php as it was.
     */
    private const FIELDS = [
        [
            'key' => 'naam',
            'type' => 'text',
            'label_nl' => 'Naam',
            'label_en' => 'Name',
            'help_nl' => null,
            'help_en' => null,
            'required' => true,
            'options' => null,
        ],
        [
            'key' => 'email',
            'type' => 'email',
            'label_nl' => 'E-mail',
            'label_en' => 'Email',
            'help_nl' => null,
            'help_en' => null,
            'required' => true,
            'options' => null,
        ],
        [
            'key' => 'telefoon',
            'type' => 'tel',
            'label_nl' => 'Telefoonnummer',
            'label_en' => 'Phone number',
            'help_nl' => null,
            'help_en' => null,
            'required' => false,
            'options' => null,
        ],
        [
            'key' => 'voor-wie',
            'type' => 'radio',
            'label_nl' => 'Voor wie is de aanvraag?',
            'label_en' => 'Who is this request for?',
            'help_nl' => null,
            'help_en' => null,
            'required' => true,
            'options' => "Particulier|Personal\nZakelijk|Business",
        ],
        [
            'key' => 'omschrijving',
            'type' => 'textarea',
            'label_nl' => 'Omschrijving van je idee',
            'label_en' => 'Describe your idea',
            'help_nl' => 'Denk aan tekst, formaat, aantal en eventueel een link naar een voorbeeld.',
            'help_en' => 'Think text, size, quantity, and a link to an example if you have one.',
            'required' => true,
            'options' => null,
        ],
    ];

    public function up(): void
    {
        if (!$this->hasTable('forms') || !$this->hasTable('contact_form_sections')) {
            return;
        }

        // "Does this install have the old contact form at all." Asked of the
        // data, not of a page slug.
        $existing = $this->oneRow('SELECT COUNT(*) AS total FROM contact_form_sections');
        $hasContactBlocks = $existing !== null && (int) $existing['total'] > 0;

        $formId = $this->findForm();

        if ($formId === null) {
            if (!$hasContactBlocks) {
                // Fresh install: nothing to preserve, and nothing invented.
                return;
            }

            $formId = $this->createForm();
            $this->createFields($formId);
        }

        $this->linkUnlinkedContactBlocks($formId);
    }

    public function down(): void
    {
        $formId = $this->findForm();

        if ($formId === null) {
            return;
        }

        $this->execute('UPDATE contact_form_sections SET form_id = NULL WHERE form_id = ' . $formId);
        // form_fields cascades from forms.
        $this->execute('DELETE FROM forms WHERE id = ' . $formId);
    }

    private function findForm(): ?int
    {
        $row = $this->oneRow(
            'SELECT id FROM forms WHERE internal_key = ' . $this->sqlQuote(self::FORM_KEY) . ' LIMIT 1'
        );

        return $row === null ? null : (int) $row['id'];
    }

    private function createForm(): int
    {
        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO forms
                (name, internal_key, is_active, submit_label_nl, submit_label_en,
                 success_message_nl, success_message_en, notification_email,
                 reply_to_field_key, store_submissions, created_at, updated_at)
             VALUES ('
            . $this->sqlQuote('Contactformulier') . ', '
            . $this->sqlQuote(self::FORM_KEY) . ', '
            . '1, '
            // The button and the confirmation the site already showed.
            . $this->sqlQuote('Verstuur aanvraag') . ', '
            . $this->sqlQuote('Send request') . ', '
            . $this->sqlQuote('Bedankt — je aanvraag is verstuurd. Ik reageer binnen twee werkdagen.') . ', '
            . $this->sqlQuote("Thanks — your request has been sent. I'll get back to you within two working days.") . ', '
            . $this->pinnedRecipient() . ', '
            // The old endpoint always set the visitor's address as Reply-To;
            // this is the field it took it from.
            . $this->sqlQuote('email') . ', '
            // The old endpoint stored every request, so this one does too.
            . '1, '
            . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
        );

        return (int) $this->oneRow(
            'SELECT id FROM forms WHERE internal_key = ' . $this->sqlQuote(self::FORM_KEY) . ' LIMIT 1'
        )['id'];
    }

    /**
     * The SQL literal for `notification_email`: the .env address this
     * installation actually mails to when it differs from the site's own
     * contact address, and NULL when it does not.
     */
    private function pinnedRecipient(): string
    {
        $configured = trim((string) ($_ENV['SHOP_NOTIFICATION_EMAIL'] ?? getenv('SHOP_NOTIFICATION_EMAIL') ?: ''));

        if ($configured === '' || filter_var($configured, FILTER_VALIDATE_EMAIL) === false) {
            return 'NULL';
        }

        $siteEmail = $this->oneRow(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'email' LIMIT 1"
        );
        $siteAddress = $siteEmail === null ? '' : trim((string) $siteEmail['setting_value']);

        if (strcasecmp($configured, $siteAddress) === 0) {
            // They already agree: leave it empty so the owner keeps editing
            // one address in Site-instellingen rather than two.
            return 'NULL';
        }

        return $this->sqlQuote($configured);
    }

    private function createFields(int $formId): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::FIELDS as $position => $field) {
            $this->execute(
                'INSERT INTO form_fields
                    (form_id, field_key, field_type, label_nl, label_en,
                     placeholder_nl, placeholder_en, help_text_nl, help_text_en,
                     is_required, sort_order, options, created_at, updated_at)
                 VALUES ('
                . $formId . ', '
                . $this->sqlQuote($field['key']) . ', '
                . $this->sqlQuote($field['type']) . ', '
                . $this->sqlQuote($field['label_nl']) . ', '
                . $this->sqlQuote($field['label_en']) . ', '
                . 'NULL, NULL, '
                . ($field['help_nl'] === null ? 'NULL' : $this->sqlQuote($field['help_nl'])) . ', '
                . ($field['help_en'] === null ? 'NULL' : $this->sqlQuote($field['help_en'])) . ', '
                . ($field['required'] ? '1' : '0') . ', '
                . $position . ', '
                . ($field['options'] === null ? 'NULL' : $this->sqlQuote($field['options'])) . ', '
                . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
            );
        }
    }

    /**
     * Every `contact_form` block that has no form yet gets this one — the
     * contact page's, and any an editor put on a page of their own. A row
     * that already points somewhere is left alone, which is what makes
     * re-running this harmless.
     */
    private function linkUnlinkedContactBlocks(int $formId): void
    {
        $this->execute(
            'UPDATE contact_form_sections
                SET form_id = ' . $formId . ', updated_at = NOW()
              WHERE form_id IS NULL'
        );
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
