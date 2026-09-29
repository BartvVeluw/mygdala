<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * A block that IS the page's anchor navigation where an editor placed it:
 * the Snelnavigatie (`quicknav`). A page that holds one gets no second
 * navigation from SectionRegistry::renderPage() (App\Service\Blocks\AnchorNavigation).
 */
interface RendersAnchorNavigation
{
}
