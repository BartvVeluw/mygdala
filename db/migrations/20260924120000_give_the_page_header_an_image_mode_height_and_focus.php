<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives the Paginakop (`page_heroes`, 20260904200000 and 20260915120000) three
 * more choices, each a closed list stored as a short string, the same shape
 * as its `content_position`, `title_size` and `text_size`:
 *
 *   image_mode    where the picture goes: 'none', 'background' (behind the
 *                 text, as until now), 'left' or 'right' of the text
 *                 (App\Service\PageHeroContent::IMAGE_MODES)
 *   hero_height   how tall a header with a background picture is at least:
 *                 'small', 'medium' or 'large' (PageHeroContent::HEIGHTS)
 *   image_focus   which part of the cropped picture stays in view, one of the
 *                 nine points of App\Service\Media\ImageFocus
 *
 * EVERY EXISTING HEADER KEEPS ITS LOOK. Until now a header with a picture had
 * it behind the text, so a row with a `media_id` becomes 'background' and a
 * row without one 'none'; nothing is turned into a picture beside the text.
 * 'medium' is exactly the height a header with a picture had, and 'center'
 * the crop the browser made by itself.
 *
 * The backfill runs only in the run that adds `image_mode`. A second run finds
 * the column and leaves every row alone, so it can never undo a choice an
 * editor made in between. The defaults are written out here rather than read
 * from PageHeroContent, so this migration keeps meaning what it meant when
 * that class changes. No media reference and no word is touched.
 *
 * Schema plus a backfill of existing rows only, so there is no fresh-install
 * guard: a new installation has no rows to backfill (db/migrations/CLAUDE.md).
 */
final class GiveThePageHeaderAnImageModeHeightAndFocus extends AbstractMigration
{
    /** column => the default that reproduces the header as it was */
    private const CHOICES = [
        'image_mode' => 'none',
        'hero_height' => 'medium',
        'image_focus' => 'center',
    ];

    public function up(): void
    {
        $addsTheMode = !$this->table('page_heroes')->hasColumn('image_mode');
        $after = 'text_size';

        foreach (self::CHOICES as $column => $default) {
            if (!$this->table('page_heroes')->hasColumn($column)) {
                $this->table('page_heroes')
                    ->addColumn($column, 'string', [
                        'limit' => 20,
                        'null' => false,
                        'default' => $default,
                        'after' => $after,
                    ])
                    ->update();
            }

            $after = $column;
        }

        if ($addsTheMode) {
            // The only place a header's picture could be until now.
            $this->execute("UPDATE page_heroes SET image_mode = 'background' WHERE media_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::CHOICES)) as $column) {
            if ($this->table('page_heroes')->hasColumn($column)) {
                $this->table('page_heroes')->removeColumn($column)->update();
            }
        }
    }
}
