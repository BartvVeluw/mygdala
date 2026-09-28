<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A PICTURE ON A PHONE for the content blocks that crop one (Responsive Media
 * 2.0, App\Service\Media\ResponsiveImage, MEDIA.md "Responsive Media"). Next to
 * each picture's focus point (db/migrations/20260928220000) come the same
 * columns everywhere, only the prefix differs (App\Service\Media\ResponsiveImageSlot):
 *
 *   <prefix>mobile_media_id  INT UNSIGNED NULL, FK media ON DELETE RESTRICT:
 *                            another Media Library picture for a phone;
 *                            NULL is "the desktop picture"
 *   <prefix>mobile_focus_x   TINYINT UNSIGNED NULL
 *   <prefix>mobile_focus_y   TINYINT UNSIGNED NULL: the phone's own point;
 *                            NULL is "follow the desktop point"
 *
 * and where the picture sits in a frame of its own:
 *
 *   <prefix>fit              'cover' (fill, crop; the default, and what every
 *                            block did) or 'contain' (the whole picture)
 *   <prefix>mobile_fit       NULL ("as on a large screen"), 'cover', 'contain'
 *
 * and where the block has a height a phone can choose:
 *
 *   <prefix>mobile_height    NULL ("automatic": the block's own), 'compact',
 *                            'normal', 'large'
 *
 *   table                   prefix        fit  phone height
 *   carousel_cards          image_        yes  no (the carousel's own ratio)
 *   text_image_split_items  image_        yes  yes
 *   page_heroes             image_        yes  yes
 *   cta_bands               background_   no   no (a picture behind text)
 *   media_banners           image_        yes  yes
 *   hover_card_grid_items   image_        yes  no (the grid's own shape)
 *   homepage_hero           image_        no   no
 *
 * and for the Kaarten-carrousel as a whole, the shape of its pictures in a
 * flat row of cards (side by side, and every carousel on a phone):
 *
 *   card_carousels.flat_image_ratio  'auto' (the fixed height it always had),
 *                                    '1-1', '4-3', '3-4', '16-9'
 *
 * RESTRICT for the reason 20260909260000 gives: a picture still used must not
 * be deletable; App\Service\Media\Usage\ContentBlockMediaUsage names every
 * one of these columns, and is the first line of defence.
 *
 * Every existing row gets NULL or the default, which is "as it was": no
 * phone picture, the desktop point, cover, the block's own height. Schema
 * only, idempotent, no fresh-install guard (db/migrations/CLAUDE.md).
 * Forward-only.
 */
final class GiveBlockImagesAMobilePresentation extends AbstractMigration
{
    /** table => [prefix, fit, phone height] */
    private const SLOTS = [
        'carousel_cards' => ['image_', true, false],
        'text_image_split_items' => ['image_', true, true],
        'page_heroes' => ['image_', true, true],
        'cta_bands' => ['background_', false, false],
        'media_banners' => ['image_', true, true],
        'hover_card_grid_items' => ['image_', true, false],
        'homepage_hero' => ['image_', false, false],
    ];

    public function up(): void
    {
        foreach (self::SLOTS as $table => [$prefix, $fit, $mobileHeight]) {
            $after = $prefix . 'focus_y';

            $columns = [
                $prefix . 'mobile_media_id' => ['integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own picture; NULL = the desktop picture']],
                $prefix . 'mobile_focus_x' => ['tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own focus point, percent; NULL = the desktop point']],
                $prefix . 'mobile_focus_y' => ['tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own focus point, percent; NULL = the desktop point']],
            ];
            if ($fit) {
                $columns[$prefix . 'fit'] = ['string', ['limit' => 10, 'null' => false, 'default' => 'cover', 'comment' => 'App\Service\Media\ResponsiveImage::FITS']];
                $columns[$prefix . 'mobile_fit'] = ['string', ['limit' => 10, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage::FITS; NULL = as on a large screen']];
            }
            if ($mobileHeight) {
                $columns[$prefix . 'mobile_height'] = ['string', ['limit' => 10, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage::MOBILE_HEIGHTS; NULL = automatic']];
            }

            foreach ($columns as $column => [$type, $options]) {
                if (!$this->table($table)->hasColumn($column)) {
                    $this->table($table)
                        ->addColumn($column, $type, $options + ['after' => $after])
                        ->update();
                }
                $after = $column;
            }

            $mobileMedia = $prefix . 'mobile_media_id';
            if (!$this->table($table)->hasForeignKey($mobileMedia)) {
                $this->table($table)
                    ->addForeignKey($mobileMedia, 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                    ->update();
            }
        }

        if (!$this->table('card_carousels')->hasColumn('flat_image_ratio')) {
            $this->table('card_carousels')
                ->addColumn('flat_image_ratio', 'string', [
                    'limit' => 10,
                    'null' => false,
                    'default' => 'auto',
                    'after' => 'image_height',
                    'comment' => 'App\Service\Media\ResponsiveImage::FLAT_RATIOS: the pictures in a flat row of cards',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md).
    }
}
