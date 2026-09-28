<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Hover kaarten grid block (`hover_card_grid`,
 * App\Service\Blocks\HoverCardGridBlock): a grid of picture cards that come
 * alive when a pointer or the keyboard reaches them — the words appear, the
 * picture zooms or turns into a second one, and an organic card changes its
 * shape (CONTENT-BLOCKS.md, "Hover kaarten grid"). Two tables, the shape of
 * every block with a repeater.
 *
 * hover_card_grids, one row per instance, the choices of the whole grid:
 *
 *   layout        'overlay' (the start: the words over the picture) or 'open'
 *                 (the words under it) — App\Service\HoverCardGridContent::LAYOUTS
 *   shape         'rounded' (the start), 'square', 'circle' or 'organic' — ::SHAPES
 *   columns       '3' (the start), '2' or '4', on a wide screen — ::COLUMNS
 *   overlay       'medium' (the start), 'light' or 'dark': how much of the
 *                 picture the veil behind the words of an overlay card covers — ::OVERLAYS
 *   effect        'normal' (the start) or 'subtle': how much a card moves — ::EFFECTS
 *   header_align  'left' (the start), 'center' or 'right': the heading above
 *                 the grid — ::HEADER_ALIGNMENTS
 *
 * hover_card_grid_items, the cards in their order:
 *
 *   media_id        the picture, a Media Library item. A card is a picture
 *                   first: the editor refuses a card without one, and a row
 *                   without one (written outside the editor) shows nothing.
 *                   NULL-able like every child column but the link to the
 *                   parent, so a row can be written with its parent alone
 *                   (Tests\Service\BlockTranslationIntegrityTest does)
 *   hover_media_id  an optional second picture, shown instead of the first on
 *                   hover and keyboard focus
 *   link_type, link_target_id, link_url
 *                   where the card goes: App\Service\Routing\LinkChoice's
 *                   three values, NULL type for a card that goes nowhere
 *
 * Every word — the grid's eyebrow, title and lead, and each card's badge,
 * title, text and link label — is in block_translations
 * (HoverCardGridBlock::translatableFields()), never a column here. There is no
 * alt-text column either: a table made after the Media Library uses the
 * library's alt text (MEDIA.md, "Een tabel die na de bibliotheek is gemaakt").
 *
 * ON DELETE RESTRICT on both media columns, for the reason 20260909260000
 * gives: an item that is still used must not be deletable.
 * App\Service\Media\Usage\ContentBlockMediaUsage is the first line of
 * defence, these constraints the second. The cards cascade from their grid.
 *
 * New tables with nothing to take over: no backfill, no fresh-install guard,
 * idempotent (db/migrations/CLAUDE.md).
 */
final class CreateHoverCardGridTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('hover_card_grids')) {
            $this->table('hover_card_grids', ['id' => true])
                ->addColumn('page_slug', 'string', ['limit' => 100])
                ->addColumn('section_key', 'string', ['limit' => 100])
                ->addColumn('layout', 'string', ['limit' => 10, 'null' => false, 'default' => 'overlay', 'comment' => 'App\Service\HoverCardGridContent::LAYOUTS'])
                ->addColumn('shape', 'string', ['limit' => 10, 'null' => false, 'default' => 'rounded', 'comment' => 'App\Service\HoverCardGridContent::SHAPES'])
                ->addColumn('columns', 'string', ['limit' => 2, 'null' => false, 'default' => '3', 'comment' => 'App\Service\HoverCardGridContent::COLUMNS'])
                ->addColumn('overlay', 'string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'comment' => 'App\Service\HoverCardGridContent::OVERLAYS'])
                ->addColumn('effect', 'string', ['limit' => 10, 'null' => false, 'default' => 'normal', 'comment' => 'App\Service\HoverCardGridContent::EFFECTS'])
                ->addColumn('header_align', 'string', ['limit' => 10, 'null' => false, 'default' => 'left', 'comment' => 'App\Service\HoverCardGridContent::HEADER_ALIGNMENTS'])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('hover_card_grid_items')) {
            $this->table('hover_card_grid_items', ['id' => true])
                ->addColumn('hover_card_grid_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference: the picture (the editor requires it)'])
                ->addColumn('hover_media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference: the picture on hover, optional'])
                ->addColumn('link_type', 'string', ['limit' => 20, 'null' => true, 'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = no link'])
                ->addColumn('link_target_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('link_url', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['hover_card_grid_id', 'sort_order'])
                ->addForeignKey('hover_card_grid_id', 'hover_card_grids', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('hover_media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['hover_card_grid_items', 'hover_card_grids'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }
}
