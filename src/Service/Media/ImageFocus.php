<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * The nine points an editor can give a cropped picture with one click, on a
 * 3×3 grid: the presets of a focus point (Responsive Media 2.0,
 * App\Service\Media\ResponsiveImage).
 *
 * A focus point is stored as two whole percentages (image_focus_x and
 * image_focus_y), exactly what CSS object-position means; a preset is one of
 * the nine pairs below. Clicking one sets that pair, dragging the picture
 * sets any other, and a pair that equals a preset shows that preset as the
 * one chosen (keyFor()).
 *
 * Until 2026-09 a block stored the KEY of one of these points
 * (carousel_cards.image_focus, …). db/migrations/20260928220000 turned every
 * key into its pair with the same numbers as below — 0, 50 and 100 — so every
 * picture kept exactly the object-position it had.
 *
 * The editor's words for them are the CMS's own (media.focus.<key>).
 */
final class ImageFocus
{
    public const DEFAULT = 'center';

    /** key => [x, y] in percent, in reading order of the grid. */
    private const POINTS = [
        'top-left' => [0, 0],
        'top' => [50, 0],
        'top-right' => [100, 0],
        'left' => [0, 50],
        'center' => [50, 50],
        'right' => [100, 50],
        'bottom-left' => [0, 100],
        'bottom' => [50, 100],
        'bottom-right' => [100, 100],
    ];

    /** @return list<string> the nine keys, row by row */
    public static function keys(): array
    {
        return array_keys(self::POINTS);
    }

    /**
     * The pair of a key, the middle for anything that is not one.
     *
     * @return array{0: int, 1: int}
     */
    public static function point(mixed $key): array
    {
        return is_string($key) && isset(self::POINTS[$key]) ? self::POINTS[$key] : self::POINTS[self::DEFAULT];
    }

    /** The key of the preset at exactly this pair, or null for a point of its own. */
    public static function keyFor(int $x, int $y): ?string
    {
        foreach (self::POINTS as $key => [$presetX, $presetY]) {
            if ($presetX === $x && $presetY === $y) {
                return $key;
            }
        }

        return null;
    }
}
