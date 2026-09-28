<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * THE HEADING LEVEL OF A CARD'S TITLE (CONTENT-BLOCKS.md, "Koppen in
 * kaarten").
 *
 * A block of cards may show a title of its own, and that title is an h2.
 * Under it every card title is an h3. Without it the cards are the first
 * headings of their section, so every card title is an h2: a page's h1 is
 * never followed by an h3 with no h2 in between. Nothing is added to make an
 * h3 fit — no hidden or empty h2 — and a card without a title has no heading
 * at all.
 *
 * Only the tag changes. A card title looks the same either way, because the
 * card's own class carries its type (font size and all), never the element.
 *
 * The level is decided here and nowhere else, from a closed list of two tags:
 * a partial prints what under() returns, and a script that draws cards in the
 * browser reads the level from the data-card-heading attribute its container
 * got from under() and accepts only these two. No tag ever comes from content
 * or a request.
 */
final class CardHeading
{
    /** A card title under the block's own title. */
    public const UNDER_BLOCK_TITLE = 'h3';

    /** A card title in a block that shows no title of its own. */
    public const WITHOUT_BLOCK_TITLE = 'h2';

    /** Every tag a card title may have. */
    public const LEVELS = [self::WITHOUT_BLOCK_TITLE, self::UNDER_BLOCK_TITLE];

    /**
     * The tag of every card title in a block that does, or does not, show a
     * title of its own. Pass the same condition the partial uses to print the
     * block's h2, so the two can never disagree.
     */
    public static function under(bool $blockShowsTitle): string
    {
        return $blockShowsTitle ? self::UNDER_BLOCK_TITLE : self::WITHOUT_BLOCK_TITLE;
    }
}
