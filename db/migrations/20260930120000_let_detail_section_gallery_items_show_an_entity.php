<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Detailsectie 2.0: a gallery item of a Detailsectie is a picture from the
 * Media Library — as every existing one is — OR a product, a Portfolio
 * project or a blog post, shown with that item's own picture and linked to
 * its own page (App\Service\Media\LinkedImages, CONTENT-BLOCKS.md
 * "Detailsectie").
 *
 *   detail_section_images.source_type  NULL = a Media Library picture (every
 *                                      existing row); else the kind of item
 *                                      (a App\Service\Routing\LinkTargets
 *                                      type: product, portfolio_project,
 *                                      blog_post, ...)
 *   detail_section_images.source_id    the item's id, for that kind
 *
 * ONLY THE KIND AND THE ID. The item's name, address and picture are read
 * live at every render, so a new slug or a new picture shows at once and
 * nothing goes stale; nothing of them is copied here. No foreign key on
 * source_id, deliberately and the way link_target_id is stored everywhere
 * else: which table it points into depends on the kind, and an item that is
 * gone (or a module that is off) is an answer the render gives — the item is
 * left out — not a row the database refuses. The editor keeps such a choice
 * and says so (link_choice.*).
 *
 * TWO NULLABLE COLUMNS, nothing rewritten: every existing gallery image reads
 * exactly as before. Idempotent.
 */
final class LetDetailSectionGalleryItemsShowAnEntity extends AbstractMigration
{
    public function up(): void
    {
        $images = $this->table('detail_section_images');

        if (!$images->hasColumn('source_type')) {
            $images->addColumn('source_type', 'string', [
                'limit' => 30,
                'null' => true,
                'default' => null,
                'after' => 'media_id',
                'comment' => 'NULL = a Media Library picture; else a LinkTargets type (App\\Service\\Media\\LinkedImages)',
            ])->update();
        }

        if (!$images->hasColumn('source_id')) {
            $images->addColumn('source_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'source_type',
                'comment' => 'the item of source_type; read live, never a copy',
            ])->update();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
