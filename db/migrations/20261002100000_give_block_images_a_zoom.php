<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A ZOOM for every cropped picture of a content block (Responsive Media 3.0,
 * App\Service\Media\ResponsiveImage, MEDIA.md "Responsive Media"). Next to
 * each picture's focus point and its phone point come two columns, the same
 * everywhere, only the prefix differs (App\Service\Media\ResponsiveImageSlot):
 *
 *   <prefix>zoom         TINYINT UNSIGNED NOT NULL DEFAULT 100: how far the
 *                        picture is enlarged in its frame, percent 100-200;
 *                        100 is the frame filled exactly as object-fit: cover
 *                        fills it, what every picture showed until now
 *   <prefix>mobile_zoom  TINYINT UNSIGNED NULL: the zoom of the phone's own
 *                        point, stored with <prefix>mobile_focus_x/y and NULL
 *                        whenever they are ("follow the desktop point and
 *                        zoom"); a phone point without one reads as 100
 *
 *   table                   prefix
 *   carousel_cards          image_
 *   text_image_split_items  image_
 *   page_heroes             image_
 *   cta_bands               background_
 *   media_banners           image_
 *   hover_card_grid_items   image_
 *   homepage_hero           image_
 *   detail_section_images   image_
 *
 * Every existing row gets 100 and NULL, which is "as it was": nothing on any
 * page changes. Zoom is presentation of the block's row, never of the Media
 * Library item or of the product, project or post whose picture a row shows,
 * so no media relation is added or changed. Schema only, idempotent, no
 * fresh-install guard (db/migrations/CLAUDE.md). Forward-only.
 */
final class GiveBlockImagesAZoom extends AbstractMigration
{
    /** table => prefix */
    private const SLOTS = [
        'carousel_cards' => 'image_',
        'text_image_split_items' => 'image_',
        'page_heroes' => 'image_',
        'cta_bands' => 'background_',
        'media_banners' => 'image_',
        'hover_card_grid_items' => 'image_',
        'homepage_hero' => 'image_',
        'detail_section_images' => 'image_',
    ];

    public function up(): void
    {
        foreach (self::SLOTS as $table => $prefix) {
            $after = $prefix . 'mobile_focus_y';

            $columns = [
                $prefix . 'zoom' => ['null' => false, 'default' => 100, 'comment' => 'App\Service\Media\ResponsiveImage: zoom, percent 100-200 (100 = cover as it is)'],
                $prefix . 'mobile_zoom' => ['null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: the phone point\'s own zoom, percent; NULL = no phone point of its own'],
            ];

            foreach ($columns as $column => $options) {
                if (!$this->table($table)->hasColumn($column)) {
                    $this->table($table)
                        ->addColumn($column, 'tinyinteger', $options + ['signed' => false] + ($this->table($table)->hasColumn($after) ? ['after' => $after] : []))
                        ->update();
                }
                $after = $column;
            }
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
