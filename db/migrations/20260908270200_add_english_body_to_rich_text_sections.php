<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives the Rich text block an optional English body.
 *
 * 20260908100100_create_rich_text_sections_table.php deliberately stored a
 * single body instead of the *_nl/*_en pair every other block type uses,
 * because the information pages it migrated had no English text at all, and
 * it noted: "A future EN column can be added additively if that changes."
 * Phase 2 is when it changes — the Shop's "Iets specifieks?" paragraph moves
 * into a Rich text block (see the next migration) and that paragraph IS
 * bilingual, so without this column the migration would silently drop its
 * English copy.
 *
 * Additive and nullable: every existing row keeps exactly the body it has,
 * NULL means "no separate English text" and the block then renders the Dutch
 * body in both languages — the same "leeg = zelfde als NL" rule as every
 * other block. The three legal/information pages therefore render
 * byte-identically after this runs (partials/section-rich-text.php only
 * emits data-nl/data-en attributes when an English body actually exists).
 *
 * Forward-only, idempotent, MySQL/Vimexx-compatible.
 */
final class AddEnglishBodyToRichTextSections extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('rich_text_sections');

        if ($table->hasColumn('content_html_en')) {
            return;
        }

        $table
            ->addColumn('content_html_en', 'text', ['null' => true, 'after' => 'content_html'])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('rich_text_sections');

        if ($table->hasColumn('content_html_en')) {
            $table->removeColumn('content_html_en')->update();
        }
    }
}
