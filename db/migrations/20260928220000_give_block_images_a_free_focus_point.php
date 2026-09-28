<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A FREE FOCUS POINT for the pictures of content blocks (Responsive Media
 * 2.0, App\Service\Media\ResponsiveImage): which point of a cropped picture
 * stays in view is now any point, as two whole percentages, instead of one
 * of nine keys.
 *
 *   <prefix>focus_x   0..100, TINYINT UNSIGNED, default 50
 *   <prefix>focus_y   0..100, TINYINT UNSIGNED, default 50
 *
 * exactly what CSS object-position means: 0 0 keeps the top-left corner in
 * view, 50 50 the middle (what the browser does by itself), 100 100 the
 * bottom-right corner. The nine points stay as one-click presets in the
 * editor (App\Service\Media\ImageFocus).
 *
 * THE FIVE PLACES THAT HAD A KEY keep their picture exactly where it was:
 * every key becomes its pair with the numbers ImageFocus always turned it
 * into (0, 50 or 100 per axis), and anything that was not a key reads as the
 * middle — which is what ImageFocus::normalise() made of it. The key column
 * goes after that: one truth per picture, not two.
 *
 *   carousel_cards          image_focus       -> image_focus_x / _y
 *   text_image_split_items  image_focus       -> image_focus_x / _y
 *   page_heroes             image_focus       -> image_focus_x / _y
 *   cta_bands               background_focus  -> background_focus_x / _y
 *   media_banners           image_focus       -> image_focus_x / _y
 *
 * TWO PLACES GET THEIR FIRST FOCUS POINT, the middle for every existing row,
 * which is what they showed until now:
 *
 *   hover_card_grid_items   image_focus_x / _y   (its main picture)
 *   homepage_hero           image_focus_x / _y
 *
 * A failed update restores the whole database from the updater's dump
 * (docs/updates/ARCHITECTURE.md), so dropping the key after the copy leaves
 * no half state behind.
 *
 * Schema and a backfill of existing rows only, no fresh-install guard (a new
 * installation and an upgraded one end on the same tables), idempotent: every
 * column is added only when missing, and the copy runs only while the key
 * column still exists (db/migrations/CLAUDE.md). Forward-only.
 */
final class GiveBlockImagesAFreeFocusPoint extends AbstractMigration
{
    /** table => [the key column it had (or null), the prefix of its new columns, the column they follow] */
    private const TABLES = [
        'carousel_cards' => ['image_focus', 'image_', 'image_focus'],
        'text_image_split_items' => ['image_focus', 'image_', 'image_focus'],
        'page_heroes' => ['image_focus', 'image_', 'image_focus'],
        'cta_bands' => ['background_focus', 'background_', 'background_focus'],
        'media_banners' => ['image_focus', 'image_', 'image_focus'],
        'hover_card_grid_items' => [null, 'image_', 'hover_media_id'],
        'homepage_hero' => [null, 'image_', 'media_id'],
    ];

    /** The numbers ImageFocus always gave each key, per axis. */
    private const X = ['top-left' => 0, 'left' => 0, 'bottom-left' => 0, 'top-right' => 100, 'right' => 100, 'bottom-right' => 100];
    private const Y = ['top-left' => 0, 'top' => 0, 'top-right' => 0, 'bottom-left' => 100, 'bottom' => 100, 'bottom-right' => 100];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$keyColumn, $prefix, $after]) {
            $x = $prefix . 'focus_x';
            $y = $prefix . 'focus_y';

            // The key column may already be gone on a second run; the new
            // columns then follow whatever the table has at the end.
            $afterExisting = $this->table($table)->hasColumn($after) ? $after : null;

            foreach ([$x, $y] as $column) {
                if (!$this->table($table)->hasColumn($column)) {
                    $this->table($table)
                        ->addColumn($column, 'tinyinteger', [
                            'signed' => false,
                            'null' => false,
                            'default' => 50,
                            'comment' => 'App\Service\Media\ResponsiveImage: focus point, percent 0-100 (object-position)',
                        ] + ($afterExisting !== null ? ['after' => $afterExisting] : []))
                        ->update();
                }
                $afterExisting = $column;
            }

            if ($keyColumn !== null && $this->table($table)->hasColumn($keyColumn)) {
                $this->execute(sprintf(
                    'UPDATE `%s` SET `%s` = %s, `%s` = %s',
                    $table,
                    $x,
                    $this->caseFor($keyColumn, self::X),
                    $y,
                    $this->caseFor($keyColumn, self::Y)
                ));

                $this->table($table)->removeColumn($keyColumn)->update();
            }
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md).
    }

    /** CASE <key column> WHEN 'top-left' THEN 0 … ELSE 50 END, from a closed list of keys. */
    private function caseFor(string $keyColumn, array $numbers): string
    {
        $case = 'CASE `' . $keyColumn . '`';
        foreach ($numbers as $key => $number) {
            $case .= " WHEN '" . $key . "' THEN " . (int) $number;
        }

        return $case . ' ELSE 50 END';
    }
}
