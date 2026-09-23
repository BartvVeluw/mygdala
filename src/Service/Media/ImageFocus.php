<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Which part of a cropped picture stays in view: a closed list of nine
 * points on a 3×3 grid, and the one place that turns a point into CSS.
 *
 * A picture shown with object-fit: cover loses its edges whenever its shape
 * differs from its frame. Its focus point says which edges: 'top' keeps the
 * top in view, 'bottom-right' the lower right corner, 'center' (the default,
 * and what the browser does by itself) the middle. The same value drives the
 * website and the editor's preview (object-position), so what an editor
 * picks is what a visitor gets.
 *
 * Stored as the key, never as CSS: a value a request sends that is not a key
 * here becomes the default.
 *
 * First user: the Kaarten-carrousel cards (carousel_cards.image_focus).
 */
final class ImageFocus
{
    public const DEFAULT = 'center';

    /**
     * key => object-position, in reading order of the grid. The editor's
     * words for them are the CMS's own (media.focus.<key>).
     */
    private const POINTS = [
        'top-left' => '0% 0%',
        'top' => '50% 0%',
        'top-right' => '100% 0%',
        'left' => '0% 50%',
        'center' => '50% 50%',
        'right' => '100% 50%',
        'bottom-left' => '0% 100%',
        'bottom' => '50% 100%',
        'bottom-right' => '100% 100%',
    ];

    /** @return list<string> the nine keys, row by row */
    public static function keys(): array
    {
        return array_keys(self::POINTS);
    }

    /** A stored or posted value as a key: itself when known, else the default. */
    public static function normalise(mixed $value): string
    {
        return is_string($value) && isset(self::POINTS[$value]) ? $value : self::DEFAULT;
    }

    /** The CSS object-position of a key (the default's for anything else). */
    public static function objectPosition(mixed $value): string
    {
        return self::POINTS[self::normalise($value)];
    }
}
