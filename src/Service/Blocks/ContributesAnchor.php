<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * A block an instance of which can carry an anchor and so belongs in its
 * page's anchor navigation (App\Service\Blocks\AnchorNavigation): the
 * Detailsectie. SectionRegistry asks every visible block that implements
 * this, in the page's order, and never names a type.
 */
interface ContributesAnchor
{
    /**
     * This instance's link in the page's navigation — its anchor
     * (AnchorName) and its label in the language of the request — or null
     * when it has none: no anchor, hidden, or no label in the default
     * language.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     *
     * @return array{anchor: string, label: string}|null
     */
    public function anchorFor(array $pageSection): ?array;
}
