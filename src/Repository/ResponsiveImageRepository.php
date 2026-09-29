<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;

/**
 * The one UPDATE of a picture's presentation columns (Responsive Media 2.0,
 * App\Service\Media\ResponsiveImage): its focus point, its phone picture and
 * point, and — where the block offers them — its fit and its phone height.
 *
 * One method for every block, because the columns are the same everywhere
 * and only their prefix differs (App\Service\Media\ResponsiveImageSlot). The
 * table is a name from the closed list below, never one taken from a request;
 * the column names come from the slot, whose constructor only accepts
 * lowercase words. The values are bound.
 *
 * NOT HERE: the rest of a block's row (its own repository writes that, in
 * the same transaction) and inserting a row: a new row starts with the
 * columns' defaults, which are "the middle, no phone settings".
 */
final class ResponsiveImageRepository extends Repository
{
    /** @var list<string> the tables whose rows carry a responsive picture */
    private const TABLES = [
        'carousel_cards',
        'text_image_split_items',
        'page_heroes',
        'cta_bands',
        'media_banners',
        'hover_card_grid_items',
        'homepage_hero',
        'detail_section_images',
    ];

    public function save(string $table, int $rowId, ResponsiveImageSlot $slot, ResponsiveImage $image): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('No responsive picture is stored in ' . $table . '.');
        }

        $sets = [];
        $params = ['row_id' => $rowId];

        foreach ($image->toRow($slot) as $column => $value) {
            $sets[] = '`' . $column . '` = :' . $column;
            $params[$column] = $value;
        }

        $this->db->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE id = :row_id')->execute($params);
    }
}
