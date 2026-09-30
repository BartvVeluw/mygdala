<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * ONE PLACE FOR A PICTURE on a content block's row, and what Responsive Media
 * 3.0 offers there (App\Service\Media\ResponsiveImage, MEDIA.md "Responsive
 * Media"): the card of a Kaarten-carrousel, an item of Tekst met afbeelding,
 * the picture of a Paginakop, and so on.
 *
 * A slot names two things about the row and two about the block:
 *
 *   prefix        what every presentation column of this picture starts with:
 *                 `image_` gives image_focus_x, image_mobile_media_id, …;
 *                 `background_` gives background_focus_x for the Oproep
 *   mediaColumn   where the row keeps the picture itself (media_id,
 *                 background_media_id), which this contract never writes
 *   fit           whether the picture sits in a frame of its own, so that
 *                 "the whole picture" (contain) can be a choice; a picture
 *                 behind text always fills its band
 *   mobileHeight  whether the block has a height a phone can choose
 *                 (compact, normal, large) instead of its own
 *
 * EVERY SLOT HAS A ZOOM. Each of them crops its picture with object-fit:
 * cover in a frame of the block's own shape (the audit of Responsive Media
 * 3.0, MEDIA.md), so "how far in" is as meaningful as "which point"; where an
 * editor chose contain, the editor hides the zoom and the page ignores it.
 *
 * THE COLUMNS ARE THE SAME EVERYWHERE, only the prefix differs, and a slot
 * without `fit` or `mobileHeight` simply has no such columns — so a block
 * never stores a setting its editor does not offer. The list lives here and
 * nowhere else in the application; the migrations that added the columns
 * spell them out once each (db/migrations/20260928220000, 20260928230000 and
 * 20261002100000 for the zoom).
 *
 * NOT HERE: which table the row is in (the repository's) and where the
 * editor puts the field (the block editor's). A slot is a value, made by the
 * block's Content class, never taken from a request.
 */
final class ResponsiveImageSlot
{
    public function __construct(
        public readonly string $prefix,
        public readonly string $mediaColumn,
        public readonly bool $fit = false,
        public readonly bool $mobileHeight = false,
    ) {
        if (preg_match('/^[a-z][a-z_]*_$/', $prefix) !== 1 || preg_match('/^[a-z][a-z_]*$/', $mediaColumn) !== 1) {
            throw new \InvalidArgumentException('A responsive image slot names its columns with lowercase words.');
        }
    }

    /** The full column (and form field) name of one part: focus_x → image_focus_x. */
    public function column(string $part): string
    {
        return $this->prefix . $part;
    }

    /**
     * Every column this slot stores, in a fixed order.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [
            $this->column('focus_x'),
            $this->column('focus_y'),
            $this->column('mobile_media_id'),
            $this->column('mobile_focus_x'),
            $this->column('mobile_focus_y'),
            $this->column('zoom'),
            $this->column('mobile_zoom'),
        ];

        if ($this->fit) {
            $columns[] = $this->column('fit');
            $columns[] = $this->column('mobile_fit');
        }

        if ($this->mobileHeight) {
            $columns[] = $this->column('mobile_height');
        }

        return $columns;
    }
}
