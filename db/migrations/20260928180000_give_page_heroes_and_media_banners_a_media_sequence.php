<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A MEDIA SEQUENCE for the Paginakop and the Mediabanner: more than one
 * picture (and for the banner, video) in one frame, one after the other
 * (App\Service\Media\MediaSequence, CONTENT-BLOCKS.md "Mediareeks").
 *
 * THE FIRST ITEM STAYS WHERE IT WAS. page_heroes.media_id and
 * media_banners.media_id keep their meaning — the picture, with the header's
 * own alt text and focus point, the banner's poster — so every existing header
 * and banner is exactly what it was, and every reader of those columns keeps
 * working. What is new is only what comes AFTER it, in a child table per
 * block, and the choices of the sequence:
 *
 *   page_heroes      + slide_transition  'fade' (the start), 'slide', 'none' —
 *                                        MediaSequence::TRANSITIONS
 *                    + slide_duration    1..10 whole seconds a picture stays, 5
 *   page_hero_images   the header's further pictures, in order: page_hero_id
 *                      (ON DELETE CASCADE), media_id (ON DELETE RESTRICT),
 *                      sort_order
 *
 *   media_banners    + slide_transition, slide_duration  as above
 *                    + slide_controls    'both' (the start), 'arrows', 'dots',
 *                                        'none' — MediaSequence::CONTROLS
 *   media_banner_items the banner's further pictures and videos, in order:
 *                      media_banner_id (ON DELETE CASCADE), media_id
 *                      (ON DELETE RESTRICT), sort_order
 *
 * A child row holds nothing but a library item and its place: no words (a
 * further picture uses the library's alt text), no settings of its own. Its
 * media_id is NULL-able like every child column but the link to the parent,
 * so a row can be written with its parent alone; a row without an item is
 * simply no item (the readers skip it), and the endpoints never write one. ON
 * DELETE RESTRICT for the reason 20260909260000 gives: an item that is still
 * used must not be deletable; App\Service\Media\Usage\ContentBlockMediaUsage
 * is the first line of defence.
 *
 * Nothing to take over: every existing row gets the defaults and no child
 * row, which is "one picture" — the header and the banner as they were.
 * Schema only, idempotent, no fresh-install guard (db/migrations/CLAUDE.md).
 */
final class GivePageHeroesAndMediaBannersAMediaSequence extends AbstractMigration
{
    public function up(): void
    {
        $this->addSequenceColumns('page_heroes', 'image_focus', false);
        $this->addSequenceColumns('media_banners', 'poster_media_id', true);

        $this->createItemsTable('page_hero_images', 'page_hero_id', 'page_heroes');
        $this->createItemsTable('media_banner_items', 'media_banner_id', 'media_banners');
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md).
    }

    private function addSequenceColumns(string $table, string $after, bool $withControls): void
    {
        $columns = [
            'slide_transition' => ['string', ['limit' => 10, 'null' => false, 'default' => 'fade', 'comment' => 'App\Service\Media\MediaSequence::TRANSITIONS']],
            'slide_duration' => ['integer', ['signed' => false, 'null' => false, 'default' => 5, 'comment' => 'Seconds a picture stays: App\Service\Media\MediaSequence::DURATIONS']],
        ];
        if ($withControls) {
            $columns['slide_controls'] = ['string', ['limit' => 10, 'null' => false, 'default' => 'both', 'comment' => 'App\Service\Media\MediaSequence::CONTROLS']];
        }

        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table($table)->hasColumn($column)) {
                $this->table($table)
                    ->addColumn($column, $type, $options + ['after' => $after])
                    ->update();
            }
            $after = $column;
        }
    }

    private function createItemsTable(string $table, string $parentColumn, string $parentTable): void
    {
        if ($this->hasTable($table)) {
            return;
        }

        $this->table($table, ['id' => true])
            ->addColumn($parentColumn, 'integer', ['signed' => false, 'null' => false])
            ->addColumn('media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference: a further item of the sequence'])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addIndex([$parentColumn, 'sort_order'])
            ->addForeignKey($parentColumn, $parentTable, 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();
    }
}
