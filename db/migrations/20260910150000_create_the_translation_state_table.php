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
 * The identity is (entity_type, entity_id, field, language). `entity_type` is
 * the content table's own name and `field` the base column name without its
 * language suffix — both written in code by the caller, never taken from a
 * request. App\Service\Translation\TranslationState is the only writer.
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
            ->addColumn('entity_id', 'integer', ['signed' => false])
            // The base column name without its language suffix: 'title' for
            // title_nl/title_en.
            ->addColumn('field', 'string', ['limit' => 100])
            // The language this row describes the translation INTO. The
            // primary language never gets a row: it is the source.
            ->addColumn('language', 'string', ['limit' => 10])
            // Hash of the source text at the moment this translation was
            // produced. A mismatch is what "possibly outdated" means.
            ->addColumn('source_hash', 'string', ['limit' => 64])
            // Which provider produced it, so an installation that switches
            // services can still explain where a translation came from.
            // NULL when a human wrote it without ever pressing translate.
            ->addColumn('provider', 'string', ['limit' => 40, 'null' => true])
            // Has a person edited this translation since the machine wrote
            // it? The flag that makes "manual edits win" enforceable.
            ->addColumn('is_manual', 'boolean', ['default' => false])
            ->addColumn('translated_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['entity_type', 'entity_id', 'field', 'language'], [
                'unique' => true,
                'name' => 'uniq_translation_state_target',
            ])
            // Reading an editor screen asks for every field of one row at
            // once, which is what this index serves.
            ->addIndex(['entity_type', 'entity_id'], ['name' => 'idx_translation_state_entity'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('content_translation_state')) {
            $this->table('content_translation_state')->drop()->save();
        }
    }
}
