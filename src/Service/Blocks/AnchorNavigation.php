<?php

declare(strict_types=1);

namespace App\Service\Blocks;

require_once dirname(__DIR__, 3) . '/partials/section-quicknav.php';

/**
 * THE anchor navigation of a page (Detailsectie 2.0; docs/content-blocks/DECISIONS.md,
 * "Anker en navigatielabel horen bij de sectie"): a link to every section on
 * the page that carries an anchor, in the page's own block order, labelled
 * with the section's navigation label, else its title, in the language of
 * the request. It is the Snelnavigatie (partials/section-quicknav.php) — one
 * navigation, never a second.
 *
 * WHERE IT APPEARS. Until Detailsectie 2.0 only as the fixed `quicknav`
 * block, and that block is allowed on one page only (content key `diensten`,
 * a page most sites do not have), so on every other page an anchor never got
 * its label although the editor promised it would. Now SectionRegistry::renderPage()
 * prints it by itself, directly under the page's head (a block of category
 * HERO: the Paginakop, the Homepage Hero) or at the top when the page has no
 * head — on every page with anchored sections that does not place the
 * navigation itself (a block implementing RendersAnchorNavigation).
 *
 * The links come from the blocks (ContributesAnchor), so the registry names
 * no block type; this class only draws them.
 */
final class AnchorNavigation
{
    /**
     * @param list<array{anchor: string, label: string}> $items
     */
    public static function render(array $items): void
    {
        render_section_quicknav($items);
    }
}
