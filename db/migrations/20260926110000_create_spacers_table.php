<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Witruimte block (`spacer`, App\Service\Blocks\SpacerBlock): deliberate
 * vertical room between two blocks, one row per instance.
 *
 *   size   'small', 'medium' (a new spacer's start), 'large' or 'xlarge'
 *          — App\Service\SpacerContent::SIZES; the heights are steps of the
 *          spacing scale in assets/css/blocks/spacer.css, never a number here
 *
 * A spacer has no words, so nothing of it goes into block_translations; the
 * room is the same in every language. A new table with nothing to take over:
 * no backfill, no fresh-install guard, idempotent (db/migrations/CLAUDE.md).
 */
final class CreateSpacersTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('spacers')) {
            return;
        }

        $this->table('spacers', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('size', 'string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'comment' => 'App\Service\SpacerContent::SIZES'])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('spacers')) {
            $this->table('spacers')->drop()->save();
        }
    }
}
