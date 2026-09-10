<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual V1 — what the CMS remembers about a translation.
 *
 * This table holds NO translated text. The translation itself stays exactly
 * where it has always been: in the `_en` column of the row it belongs to.
 * That is the whole compatibility promise of this step — not one existing
 * column moves, and a site that never presses a translate button never gets a
 * row here.
 *
 * What it holds instead is the four facts an editor needs and the columns
 * cannot express:
 *
 *   missing            no row, and the translation column is empty
 *   machine translated a row whose provider is set and is_manual is 0
 *   manually edited    is_manual = 1 — automatic translation must never
 *                      silently overwrite this
 *   possibly outdated  source_hash no longer matches the source text
 *
 * WHY A HASH AND NOT A TIMESTAMP COMPARISON. A row's updated_at moves when
 * anything on it changes — a colour, a sort order, an image. Comparing it to
 * a translation's timestamp would call a translation stale because somebody
 * dragged the block. The hash is of the SOURCE TEXT ONLY, so it changes when
 * and only when the words that were translated changed.
 *
 * DELIBERATELY NOT A TRANSLATION MANAGEMENT SYSTEM. No workflow, no
 * assignees, no review states, no per-language publication. Four facts, one
 * row per translated field.
 *
 * The identity is (entity_type, entity_key, field, language). `entity_type` is
 * the editor that owns the content and `entity_key` whatever that editor is
 * already addressed by — for a content block, the `<page_slug>:<section_key>`
 * pair this CMS uses everywhere else. `field` is the base column name without
 * its language suffix. All three are stored as DATA: nothing here is ever
 * concatenated into a query against the table it names.
 */
final class CreateTheTranslationStateTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('content_translation_state')) {
            return;
        }

        $this->table('content_translation_state', ['id' => true])
            // The content table this translation belongs to, e.g. 'cta_bands'.
            // A name, not a foreign key: the rows it points at live in ~30
            // different tables and a constraint per table would be a schema
            // change every time a block is added.
            ->addColumn('entity_type', 'string', ['limit' => 100])
            // WHICH row, as this CMS already addresses it. A string and not
            // an integer id, because the identity of a content block in this
            // project is "(page_slug, section_key)" and not a number
            // (CONTENT-BLOCKS.md) — an integer column would have forced every
            // block editor to look one up just to draw a badge. Whatever the
            // editor is already addressed by goes here verbatim.
            ->addColumn('entity_key', 'string', ['limit' => 191])
            // The base column name without its language suffix: 'title' for
            // title_nl/title_en.
            ->addColumn('field', 'string', ['limit' => 100])
            // The language this row describes the translation INTO. The
            // primary language never gets a row: it is the source.
            ->addColumn('language', 'string', ['limit' => 10])
            // Hash of the source text at the moment this translation was
            // produced. A mismatch is what "possibly outdated" means.
            ->addColumn('source_hash', 'string', ['limit' => 64])
            // Hash of what the PROVIDER returned. This is what makes "a
            // person edited this translation" detectable without asking all
            // ~77 write endpoints to report what an editor changed: if the
            // text in the column no longer hashes to this, somebody has been
            // at it, and automatic translation leaves it alone from then on.
            ->addColumn('translation_hash', 'string', ['limit' => 64, 'null' => true])
            // Which provider produced it, so an installation that switches
            // services can still explain where a translation came from.
            // NULL when a human wrote it without ever pressing translate.
            ->addColumn('provider', 'string', ['limit' => 40, 'null' => true])
            // An EXPLICIT "leave this alone" a person set, as opposed to the
            // implicit one the translation_hash comparison detects. Both mean
            // the same thing to App\Service\Translation\TranslationState;
            // this one survives even if the text is later reverted.
            ->addColumn('is_manual', 'boolean', ['default' => false])
            ->addColumn('translated_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['entity_type', 'entity_key', 'field', 'language'], [
                'unique' => true,
                'name' => 'uniq_translation_state_target',
            ])
            // Reading an editor screen asks for every field of one row at
            // once, which is what this index serves.
            ->addIndex(['entity_type', 'entity_key'], ['name' => 'idx_translation_state_entity'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('content_translation_state')) {
            $this->table('content_translation_state')->drop()->save();
        }
    }
}
