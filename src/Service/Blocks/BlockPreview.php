<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * THE closed vocabulary of shapes a block's schematic preview is drawn from.
 *
 * WHY A VOCABULARY AND NOT A PICTURE. The block picker and the Contentblokken
 * catalogue both have to answer one question — "wat krijg ik als ik dit blok
 * toevoeg?" — and the tempting answer is a screenshot. A screenshot is wrong
 * for this project twice over: it goes stale the first time the theme's
 * colours, spacing or a block's markup change, and nothing fails when it
 * does, so an editor ends up trusting a picture of a site that no longer
 * exists. The frontend is also themeable (THEMING.md), so there is no single
 * true picture to take.
 *
 * So a definition does not supply an image. It names a couple of SHAPES from
 * the list below — "a heading, then three columns" — and admin.css draws them
 * as plain boxes in the admin's own colours. That is enough to tell a text
 * block from a carousel at a glance, which is the entire job; it deliberately
 * is not, and must never grow into, a rendering of the real frontend. That
 * rendering exists, and it is the real block rather than a drawing: the
 * Contentblokken library's preview (App\Service\Blocks\BlockSamples,
 * admin/block-preview.php).
 *
 * The list is CLOSED for the same reason BlockDefinitions is: a part name
 * from a definition becomes a CSS class name in admin/page.php and
 * admin/content-blocks.php. It may only ever hit or miss a key here, and
 * Tests\Service\BlockPresentationTest fails the build on a definition naming
 * a part this file does not list.
 *
 * Every part is decorative: the screens that draw it mark it aria-hidden and
 * carry the real meaning in the block's label and description, so nothing is
 * lost to a screen reader or to a browser that never loads the stylesheet.
 */
final class BlockPreview
{
    /** A tall media panel with a title and a button on it — the homepage opener. */
    public const HERO = 'hero';

    /** A tinted band with an eyebrow, a big page title and one lead line. */
    public const PAGE_TITLE = 'page_title';

    /** A centred section heading with a short subtitle under it. */
    public const HEADING = 'heading';

    /** A few lines of running text. */
    public const TEXT = 'text';

    /** A picture on the left, text on the right. */
    public const IMAGE_LEFT = 'image_left';

    /** Three cards side by side, each with an icon and two lines. */
    public const COLUMNS = 'columns';

    /** Stacked rows, each with a chevron — a question list that folds open. */
    public const ROWS = 'rows';

    /** Stacked rows, each with a numbered circle — a series of steps. */
    public const NUMBERS = 'numbers';

    /** A dark band with big figures and small captions under them. */
    public const FIGURES = 'figures';

    /** A bordered card with a title, one line and a button — the call to action. */
    public const BAND = 'band';

    /** A thin band with short words scrolling past. */
    public const TICKER = 'ticker';

    /** A card in the middle with two peeking neighbours and page dots. */
    public const CAROUSEL = 'carousel';

    /** A grid of six equal picture squares. */
    public const GALLERY = 'gallery';

    /** Three wide picture tiles with a caption each. */
    public const TILES = 'tiles';

    /** A grid of picture cards with a name and a price under each. */
    public const PRODUCTS = 'products';

    /** Stacked input boxes with a send button — a form. */
    public const FIELDS = 'fields';

    /** Two cards side by side: a wide one and a narrow one beside it. */
    public const TWO_CARDS = 'two_cards';

    /** A row of small pills — jump links. */
    public const CHIPS = 'chips';

    /**
     * Every part, in no meaningful order — this is a set, not a sequence.
     *
     * @var list<string>
     */
    private const PARTS = [
        self::HERO,
        self::PAGE_TITLE,
        self::HEADING,
        self::TEXT,
        self::IMAGE_LEFT,
        self::COLUMNS,
        self::ROWS,
        self::NUMBERS,
        self::FIGURES,
        self::BAND,
        self::TICKER,
        self::CAROUSEL,
        self::GALLERY,
        self::TILES,
        self::PRODUCTS,
        self::FIELDS,
        self::TWO_CARDS,
        self::CHIPS,
    ];

    /**
     * @return list<string>
     */
    public static function parts(): array
    {
        return self::PARTS;
    }

    public static function has(string $part): bool
    {
        return in_array($part, self::PARTS, true);
    }

    /**
     * The parts of $preview this vocabulary actually knows, in the order they
     * were declared. A screen calls this rather than trusting the list it was
     * handed, so an unknown name draws nothing instead of emitting a CSS
     * class nobody wrote.
     *
     * @param list<string> $preview
     *
     * @return list<string>
     */
    public static function filter(array $preview): array
    {
        return array_values(array_filter($preview, static fn (string $part): bool => self::has($part)));
    }
}
