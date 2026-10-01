<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * ONE MEANING FOR COMPACT, NORMAL AND LARGE (Responsive Media 3.1, MEDIA.md
 * "Responsive Media", "Compact, Normaal, Groot"): the size steps a picture of
 * a content block can take, and the three screens the CMS previews it on.
 *
 * THE STEPS. A block that lets an editor choose how large its picture is
 * offers three steps, smallest first. Its own stored words may differ (Tekst
 * met afbeelding and the Mediabanner store small, medium, large; a phone's own
 * height is compact, normal, large, ResponsiveImage::MOBILE_HEIGHTS), but they
 * mean the same three steps, mapped once in STEP_OF. A step keeps its meaning
 * on every screen: on a tablet large is still the largest of the three, never
 * "one step smaller" — the pixels shrink with the screen, the order and the
 * shape do not.
 *
 * THE VIEWS. The CMS previews a picture as it stands on three reference
 * screens, VIEWPORTS: a large screen (1280 x 900, the window every block's
 * frame was measured in since Responsive Media 2.0), a tablet (768 x 1024,
 * portrait) and a phone (375 x 812). These are reference sizes for a
 * preview, not breakpoints: each block keeps its own breakpoints in its own
 * stylesheet, and the one breakpoint of the contract stays
 * ResponsiveImage::MOBILE_MAX_WIDTH.
 *
 * ONE SOURCE FOR THE PAGE AND THE PREVIEW. A block keeps the lengths of its
 * steps as the literal CSS values its stylesheet uses ("clamp(14rem, 24vw,
 * 20rem)", "4 / 3"); the stylesheet prints them and a contract test pins the
 * two together (Tests\Service\ImagePresentationContractTest). The CMS works
 * out a frame from those same values with length() at a view's reference
 * size, so a change to a step changes the page and its preview together, and
 * nothing in admin/ keeps a table of its own.
 *
 * NOT HERE: which block offers which steps, its tokens and its layout (the
 * block's Content class), the editor that shows the frames
 * (admin/_responsive_image_field.php).
 */
final class ImagePresentation
{
    public const COMPACT = 'compact';

    public const NORMAL = 'normal';

    public const LARGE = 'large';

    /** @var list<string> smallest first */
    public const STEPS = [self::COMPACT, self::NORMAL, self::LARGE];

    /**
     * Every stored word for a step, mapped to that step: the block heights'
     * small / medium / large and a phone's own compact / normal / large.
     *
     * @var array<string, string>
     */
    public const STEP_OF = [
        'small' => self::COMPACT,
        'medium' => self::NORMAL,
        'large' => self::LARGE,
        'compact' => self::COMPACT,
        'normal' => self::NORMAL,
    ];

    public const DESKTOP = 'desktop';

    public const TABLET = 'tablet';

    public const MOBILE = 'mobile';

    /** @var list<string> */
    public const VIEWS = [self::DESKTOP, self::TABLET, self::MOBILE];

    /** @var array<string, array{0: int, 1: int}> each view's reference window, width x height */
    public const VIEWPORTS = [
        self::DESKTOP => [1280, 900],
        self::TABLET => [768, 1024],
        self::MOBILE => [375, 812],
    ];

    /** The core .container (core.css): at most this wide, with its side padding inside. */
    public const CONTAINER_MAX = 1200;

    /** The container's side padding, in px: var(--sp-4), var(--sp-3) on a phone. */
    public const CONTAINER_PADDING = 32;

    public const CONTAINER_PADDING_PHONE = 24;

    /** The root font size the rem values of the stylesheets resolve against. */
    public const ROOT_FONT = 16;

    /** A preview frame is this fraction of the picture's size on its reference screen. */
    public const FRAME_SCALE = 0.5;

    /** A phone shows the step chosen for a large screen. */
    public const AUTOMATIC = 'automatic';

    /** A phone shows a step of its own (ResponsiveImage::MOBILE_HEIGHTS). */
    public const OWN = 'own';

    /** The step a stored word means, or null for a word that is no step ('xlarge', '', none). */
    public static function step(?string $stored): ?string
    {
        return $stored !== null ? (self::STEP_OF[$stored] ?? null) : null;
    }

    /**
     * What a phone shows: the step and who chose it. A phone's own height
     * wins when it is a step; anything else (none, '', a forged word) is
     * AUTOMATIC, the step of the block's own stored height. The two sources
     * stay apart because a block may give them different lengths: a phone's
     * own Groot of Tekst met afbeelding, the Mediabanner and the Paginakop is
     * deliberately taller than the automatic one, as it was before
     * Responsive Media 3.1. A step on a phone is never a step on a tablet:
     * a phone's own height stops at ResponsiveImage::MOBILE_MAX_WIDTH.
     *
     * @return array{0: string|null, 1: string} the step (null when the block's word is no step), AUTOMATIC or OWN
     */
    public static function onPhone(?string $stored, ?string $phoneStored): array
    {
        $own = self::step($phoneStored);

        return $own !== null && $phoneStored !== null && in_array($phoneStored, ResponsiveImage::MOBILE_HEIGHTS, true)
            ? [$own, self::OWN]
            : [self::step($stored), self::AUTOMATIC];
    }

    /** The width of the core container's content on a view's reference screen. */
    public static function contentWidth(string $view): int
    {
        [$width] = self::viewport($view);
        $padding = $width <= ResponsiveImage::MOBILE_MAX_WIDTH ? self::CONTAINER_PADDING_PHONE : self::CONTAINER_PADDING;

        return min(self::CONTAINER_MAX, $width) - 2 * $padding;
    }

    /** @return array{0: int, 1: int} */
    public static function viewport(string $view): array
    {
        if (!isset(self::VIEWPORTS[$view])) {
            throw new \InvalidArgumentException('A view is one of ImagePresentation::VIEWS.');
        }

        return self::VIEWPORTS[$view];
    }

    /**
     * A CSS length of a block's stylesheet in px on a view's reference
     * screen: Npx, Nrem, Nvw, Nvh and clamp(), min() and max() of those —
     * exactly what the size steps are written with. Anything else is a
     * mistake in a block's tokens, and says so.
     */
    public static function length(string $css, string $view): float
    {
        [$width, $height] = self::viewport($view);
        $css = trim($css);

        if (preg_match('/^(clamp|min|max)\((.*)\)$/s', $css, $call) === 1) {
            $values = array_map(static fn (string $part): float => self::length($part, $view), self::arguments($call[2]));

            return match (true) {
                $call[1] === 'clamp' && count($values) === 3 => max($values[0], min($values[1], $values[2])),
                $call[1] === 'min' && $values !== [] => min($values),
                $call[1] === 'max' && $values !== [] => max($values),
                default => throw new \InvalidArgumentException("Not a length: {$css}"),
            };
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(px|rem|vw|vh)$/', $css, $unit) === 1) {
            $number = (float) $unit[1];

            return match ($unit[2]) {
                'px' => $number,
                'rem' => $number * self::ROOT_FONT,
                'vw' => $number * $width / 100,
                'vh' => $number * $height / 100,
            };
        }

        throw new \InvalidArgumentException("Not a length: {$css}");
    }

    /** A CSS ratio of a stylesheet ("4 / 3", "1 / 1") as a number, width over height. */
    public static function ratio(string $css): float
    {
        if (preg_match('#^\s*(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)\s*$#', $css, $parts) !== 1 || (float) $parts[2] <= 0.0) {
            throw new \InvalidArgumentException("Not a ratio: {$css}");
        }

        return (float) $parts[1] / (float) $parts[2];
    }

    /**
     * The preview frame of a picture that is $width x $height px on a view's
     * reference screen: the custom properties admin/assets/admin.css sizes
     * the frame with (its shape, and its size at FRAME_SCALE, so that large
     * looks larger than normal and a phone smaller than a large screen).
     *
     * @return array<string, string>
     */
    public static function frame(string $view, float $width, float $height): array
    {
        self::viewport($view);
        if ($width <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException('A frame has a size.');
        }

        return [
            '--admin-rm-' . $view . '-ratio' => (string) round($width) . ' / ' . (string) round($height),
            '--admin-rm-' . $view . '-width' => (string) round($width * self::FRAME_SCALE) . 'px',
        ];
    }

    /**
     * Custom properties as an inline style.
     *
     * @param array<string, string> $properties
     */
    public static function style(array $properties): string
    {
        $declarations = [];
        foreach ($properties as $name => $value) {
            if (preg_match('/^--admin-rm-[a-z]+-(ratio|width)$/', $name) === 1
                && preg_match('#^(\d+(\.\d+)? / \d+(\.\d+)?|\d+px)$#', $value) === 1) {
                $declarations[] = $name . ': ' . $value . ';';
            }
        }

        return implode(' ', $declarations);
    }

    /** @return list<string> the top-level arguments of a CSS function call */
    private static function arguments(string $inside): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($inside) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }
            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $parts[] = $current;

        return $parts;
    }
}
