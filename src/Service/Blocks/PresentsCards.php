<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * A block whose cards an editor can give another look (Card Presentation 2.0,
 * App\Service\Blocks\CardPresentation): the capability a block definition
 * declares, so the editor offers only what this block can draw and the page
 * loads the shared stylesheet only where a block uses it.
 *
 * WHAT A BLOCK STILL DECIDES ITSELF: which items, their words, their links,
 * whether a picture zooms. A presentation is how a card looks, never what it
 * holds (CONTENT-BLOCKS.md, "Kaartweergave").
 */
interface PresentsCards
{
    /**
     * The presentations this block can draw, CardPresentation::DEFAULT first.
     *
     * @return list<string> a subset of CardPresentation::ALL
     */
    public function cardPresentations(): array;

    /**
     * The presentation one placed instance has, already read through
     * CardPresentation::stored(): what the page asks before its <head> is
     * written (CardPresentation::collectAssets()).
     *
     * @param array<string, mixed> $pageSection a page_sections row
     */
    public function cardPresentation(array $pageSection): string;
}
