<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 2 of the content-block refactor (docs/content-blocks/ROADMAP.md):
 * the Contact page's one FIXED `contact_form` block becomes two ordinary,
 * reusable, configurable blocks.
 *
 * Until now the quote form, the "Direct contact" details card and the
 * "Liever direct mailen?" card were one hardcoded template block
 * (partials/section-contact-form.php, section_id = 0, not addable, not
 * deletable — see 20260908250000_flatten_page_sections_into_one_list.php).
 * That made the quote form unusable on any other page and made the mail card
 * uneditable. This migration gives each its own content table:
 *
 *   contact_form_sections  the quote form block. The form itself is theme
 *                          behaviour (this project builds no form builder),
 *                          so the only CMS content is the heading it
 *                          already had. The "Direct contact" card stays part
 *                          of this block: it shares the block's
 *                          `.contact-grid` two-column layout, and its values
 *                          come from Site-instellingen, not page content.
 *   contact_cards          the "Liever direct mailen?" card, generalised to
 *                          a repeatable "Contactkaart": heading, text and
 *                          one button. An empty button_url keeps today's
 *                          behaviour — a mailto: link to the e-mail address
 *                          in Site-instellingen — so changing that address
 *                          keeps updating the card, exactly as before.
 *
 * Content preservation: the existing copy of both is inserted verbatim
 * (NL and EN), the Contact page's existing `contact_form` page_sections row
 * is repointed at its new content row instead of being recreated (so its
 * position and is_active survive), and the new Contactkaart is attached
 * directly after it — the order the page renders today.
 *
 * Idempotent and forward-only; safe on a fresh install (a missing Contact
 * page or attachment is simply skipped). MySQL/Vimexx-compatible: CREATE
 * TABLE plus plain INSERT/UPDATE, no CTEs and no window functions.
 */
final class TurnTheContactBlockIntoReusableBlocks extends AbstractMigration
{
    /** Must stay in sync with the *Content classes' MIGRATED_SECTION_KEY. */
    private const MIGRATED_SECTION_KEY = 'main';

    private const CONTACT_PAGE = 'contact';

    public function up(): void
    {
        $this->createContactFormTable();
        $this->createContactCardTable();

        $page = $this->oneRow(
            'SELECT id FROM pages WHERE content_key = ' . $this->sqlQuote(self::CONTACT_PAGE) . ' LIMIT 1'
        );
        if ($page === null) {
            return;
        }

        $pageId = (int) $page['id'];

        $formSectionId = $this->seedContactForm();
        $this->repointContactFormAttachment($pageId, $formSectionId);

        $cardSectionId = $this->seedContactCard();
        $this->attachContactCardAfterTheForm($pageId, $cardSectionId);
    }

    public function down(): void
    {
        foreach (['contact_form_sections', 'contact_cards'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    private function createContactFormTable(): void
    {
        if ($this->hasTable('contact_form_sections')) {
            return;
        }

        $this->table('contact_form_sections', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->create();
    }

    private function createContactCardTable(): void
    {
        if ($this->hasTable('contact_cards')) {
            return;
        }

        $this->table('contact_cards', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('body_nl', 'text', ['null' => true])
            ->addColumn('body_en', 'text', ['null' => true])
            ->addColumn('button_label_nl', 'string', ['limit' => 150])
            ->addColumn('button_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('button_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->create();
    }

    /**
     * @return int the contact_form_sections row id for the Contact page
     */
    private function seedContactForm(): int
    {
        $existing = $this->oneRow(
            'SELECT id FROM contact_form_sections
              WHERE page_slug = ' . $this->sqlQuote(self::CONTACT_PAGE)
            . ' AND section_key = ' . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ' LIMIT 1'
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO contact_form_sections
                (page_slug, section_key, title_nl, title_en, is_active, created_at, updated_at)
             VALUES ('
            . $this->sqlQuote(self::CONTACT_PAGE) . ', '
            . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ', '
            // The heading contact.php has always rendered above the form.
            . $this->sqlQuote('Offerte aanvragen') . ', '
            . $this->sqlQuote('Request a quote') . ', '
            . '1, ' . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
        );

        return (int) $this->oneRow(
            'SELECT id FROM contact_form_sections
              WHERE page_slug = ' . $this->sqlQuote(self::CONTACT_PAGE)
            . ' AND section_key = ' . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ' LIMIT 1'
        )['id'];
    }

    /**
     * @return int the contact_cards row id for the Contact page
     */
    private function seedContactCard(): int
    {
        $existing = $this->oneRow(
            'SELECT id FROM contact_cards
              WHERE page_slug = ' . $this->sqlQuote(self::CONTACT_PAGE)
            . ' AND section_key = ' . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ' LIMIT 1'
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');

        // Copied verbatim from partials/section-contact-form.php's mail card
        // (both languages). button_url stays empty on purpose: that means
        // "mailto: the address in Site-instellingen", which is what the
        // hardcoded card did.
        $bodyNl = 'Stuur je vraag met een korte omschrijving, eventueel materiaal en, indien van toepassing, een foto of voorbeeld. Dan reageer ik zo snel mogelijk persoonlijk.';
        $bodyEn = "Send your question with a short description, the material if known, and a photo or example if relevant. I'll reply personally as soon as I can.";

        $this->execute(
            'INSERT INTO contact_cards
                (page_slug, section_key, title_nl, title_en, body_nl, body_en,
                 button_label_nl, button_label_en, button_url, is_active, created_at, updated_at)
             VALUES ('
            . $this->sqlQuote(self::CONTACT_PAGE) . ', '
            . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ', '
            . $this->sqlQuote('Liever direct mailen?') . ', '
            . $this->sqlQuote('Prefer to email directly?') . ', '
            . $this->sqlQuote($bodyNl) . ', '
            . $this->sqlQuote($bodyEn) . ', '
            . $this->sqlQuote('Mail direct') . ', '
            . $this->sqlQuote('Email directly') . ', '
            . 'NULL, 1, ' . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
        );

        return (int) $this->oneRow(
            'SELECT id FROM contact_cards
              WHERE page_slug = ' . $this->sqlQuote(self::CONTACT_PAGE)
            . ' AND section_key = ' . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ' LIMIT 1'
        )['id'];
    }

    /**
     * Repoints the Contact page's existing `contact_form` attachment from
     * the fixed-block placeholder (section_id = 0, section_key NULL) at its
     * new content row — keeping its position, its is_active flag and its id.
     */
    private function repointContactFormAttachment(int $pageId, int $formSectionId): void
    {
        $attachment = $this->oneRow(
            'SELECT id, section_id FROM page_sections
              WHERE page_id = ' . $pageId . " AND section_type = 'contact_form' LIMIT 1"
        );

        if ($attachment === null) {
            // No attachment (fresh install) — attach one at the bottom.
            $this->attachAtEnd($pageId, 'contact_form', $formSectionId);

            return;
        }

        if ((int) $attachment['section_id'] === $formSectionId) {
            return;
        }

        $this->execute(
            'UPDATE page_sections
                SET section_id = ' . $formSectionId . ', '
            . 'section_key = ' . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ', updated_at = NOW()'
            . ' WHERE id = ' . (int) $attachment['id']
        );
    }

    /**
     * Attaches the new Contactkaart directly after the quote form, which is
     * where the mail card renders today (inside the form block's second
     * column). Everything below it shifts down one place.
     */
    private function attachContactCardAfterTheForm(int $pageId, int $cardSectionId): void
    {
        $existing = $this->oneRow(
            'SELECT id FROM page_sections
              WHERE section_type = ' . $this->sqlQuote('contact_card')
            . ' AND section_id = ' . $cardSectionId . ' LIMIT 1'
        );
        if ($existing !== null) {
            return;
        }

        $form = $this->oneRow(
            'SELECT sort_order FROM page_sections
              WHERE page_id = ' . $pageId . " AND section_type = 'contact_form' LIMIT 1"
        );

        if ($form === null) {
            $this->attachAtEnd($pageId, 'contact_card', $cardSectionId);

            return;
        }

        $position = (int) $form['sort_order'] + 1;

        $this->execute(
            'UPDATE page_sections SET sort_order = sort_order + 1, updated_at = NOW()
              WHERE page_id = ' . $pageId . ' AND sort_order >= ' . $position
        );

        $this->insertAttachment($pageId, 'contact_card', $cardSectionId, $position);
    }

    private function attachAtEnd(int $pageId, string $sectionType, int $sectionId): void
    {
        $max = $this->oneRow(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM page_sections WHERE page_id = ' . $pageId
        );

        $this->insertAttachment($pageId, $sectionType, $sectionId, (int) $max['next']);
    }

    private function insertAttachment(int $pageId, string $sectionType, int $sectionId, int $sortOrder): void
    {
        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES ('
            . $pageId . ', '
            . $this->sqlQuote(self::CONTACT_PAGE) . ', '
            . $this->sqlQuote($sectionType) . ', '
            . $this->sqlQuote(self::MIGRATED_SECTION_KEY) . ', '
            . $sectionId . ', ' . $sortOrder . ', 1, '
            . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
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
