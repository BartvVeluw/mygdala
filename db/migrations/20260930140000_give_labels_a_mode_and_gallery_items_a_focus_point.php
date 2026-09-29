<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * v0.1.13 corrections (CONTENT-BLOCKS.md "Kaarten-carrousel", "Detailsectie";
 * MEDIA.md "Responsive Media"):
 *
 * 1. A CARD'S LABEL IS A CHOICE (Kaarten-carrousel).
 *      carousel_cards.label_mode           none | padded (01, 02) | plain (1, 2)
 *                                          | icon | custom; App\Service\Blocks\LabelMode
 *      carousel_cards.label_icon_media_id  the icon of `icon`: a Media Library
 *                                          item, RESTRICT (a used icon cannot be
 *                                          deleted), like feature_grid_items.icon_media_id
 *    The number of padded and plain is the card's place, worked out at every
 *    render; it is never stored. The words of `custom` stay where they were:
 *    the translated field `number_label` in block_translations.
 *    Existing cards keep exactly what they show: a card with a label in any
 *    language becomes `custom`, with its text untouched; a card without one
 *    `none`. A stored "01" stays text on purpose: it may have been typed.
 *
 * 2. A DETAILSECTIE'S NUMBER IS A CHOICE.
 *      detail_sections.label_mode          none | padded | plain | custom
 *    Every existing section keeps its automatic 01, 02: `padded`, which is
 *    also where a new one starts. The words of `custom` are a new translated
 *    field `label` (block_translations): no column.
 *
 * 3. A DETAILSECTIE GALLERY ITEM HAS A FOCUS POINT. Its picture is cropped to
 *    a square, so where its middle is is a choice of this item: the columns
 *    of a App\Service\Media\ResponsiveImageSlot with prefix `image_`, as on
 *    every other place (the phone columns included, NULL: the editor offers
 *    no phone part here, and a phone follows the desktop point). Stored on the
 *    gallery row, never on the Media Library item, the product, the project
 *    or the blog post whose picture it shows. Every existing item: 50 / 50,
 *    the middle, which is what object-fit: cover showed.
 *
 * Idempotent: each column is added once, and the backfill of (1) runs only
 * in the run that adds label_mode, so a later choice is never undone.
 * Forward-only.
 */
final class GiveLabelsAModeAndGalleryItemsAFocusPoint extends AbstractMigration
{
    public function up(): void
    {
        $this->cardLabels();
        $this->detailSectionLabels();
        $this->galleryFocus();
    }

    private function cardLabels(): void
    {
        $cards = $this->table('carousel_cards');

        if (!$cards->hasColumn('label_mode')) {
            $cards->addColumn('label_mode', 'string', [
                'limit' => 10,
                'null' => false,
                'default' => 'none',
                'comment' => 'App\Service\Blocks\LabelMode: none, padded, plain, icon or custom',
            ])->update();

            // What each card showed: its text, in any language, or nothing.
            $this->execute(
                "UPDATE carousel_cards c
                    SET c.label_mode = 'custom'
                  WHERE EXISTS (
                        SELECT 1 FROM block_translations t
                         WHERE t.owner_table = 'carousel_cards'
                           AND t.owner_id = c.id
                           AND t.field = 'number_label'
                           AND TRIM(t.value) <> ''
                  )"
            );
        }

        if (!$this->table('carousel_cards')->hasColumn('label_icon_media_id')) {
            $this->table('carousel_cards')
                ->addColumn('label_icon_media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'label_mode',
                    'comment' => 'App\Service\Media reference: the icon of label_mode = icon',
                ])
                ->update();
        }

        if (!$this->table('carousel_cards')->hasForeignKey('label_icon_media_id')) {
            $this->table('carousel_cards')
                ->addForeignKey('label_icon_media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->update();
        }
    }

    private function detailSectionLabels(): void
    {
        if (!$this->table('detail_sections')->hasColumn('label_mode')) {
            $this->table('detail_sections')
                ->addColumn('label_mode', 'string', [
                    'limit' => 10,
                    'null' => false,
                    'default' => 'padded',
                    'after' => 'image_position',
                    'comment' => 'App\Service\Blocks\LabelMode: none, padded, plain or custom',
                ])
                ->update();
        }
    }

    private function galleryFocus(): void
    {
        $after = 'source_id';
        $columns = [
            'image_focus_x' => ['tinyinteger', ['signed' => false, 'null' => false, 'default' => 50, 'comment' => 'App\Service\Media\ResponsiveImage: focus point, percent 0-100 (object-position)']],
            'image_focus_y' => ['tinyinteger', ['signed' => false, 'null' => false, 'default' => 50, 'comment' => 'App\Service\Media\ResponsiveImage: focus point, percent 0-100 (object-position)']],
            'image_mobile_media_id' => ['integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own picture; NULL = the desktop picture']],
            'image_mobile_focus_x' => ['tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own focus point, percent; NULL = the desktop point']],
            'image_mobile_focus_y' => ['tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own focus point, percent; NULL = the desktop point']],
        ];

        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table('detail_section_images')->hasColumn($column)) {
                $this->table('detail_section_images')
                    ->addColumn($column, $type, $options + ($this->table('detail_section_images')->hasColumn($after) ? ['after' => $after] : []))
                    ->update();
            }
            $after = $column;
        }

        if (!$this->table('detail_section_images')->hasForeignKey('image_mobile_media_id')) {
            $this->table('detail_section_images')
                ->addForeignKey('image_mobile_media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->update();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
