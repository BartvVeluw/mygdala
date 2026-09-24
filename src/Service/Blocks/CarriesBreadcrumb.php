<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\Breadcrumbs\BreadcrumbTrail;

/**
 * A block that can print its page's breadcrumb inside itself, when it is the
 * first block on the page.
 *
 * The trail is the page's own navigation (App\Service\Breadcrumbs\PageBreadcrumb)
 * and normally stands on its own, just before the first block. A header whose
 * picture fills the band is the exception: a trail above it would sit on the
 * bare page ground, with the header's whole clearance for the fixed site
 * header above it and the picture starting below it. So such a header takes
 * the trail in and prints it over its picture, and nothing else prints it.
 *
 * SectionRegistry::renderPage() is the only caller, and it asks only the first
 * block that renders. That keeps the trail exactly once on a page: inside
 * that block when it says yes, else before it, and on a page without blocks
 * after nothing.
 *
 * First and only user: the Paginakop (PageHeroBlock), for a picture behind or
 * beside its text. Its header without a picture says no and keeps the trail
 * where it always was.
 */
interface CarriesBreadcrumb
{
    /**
     * Would this instance, rendered first on its page, print the trail
     * inside itself? Only when it really renders something to put it in: a
     * hidden or empty instance says no, so the trail is printed on its own.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public function carriesBreadcrumb(array $pageSection): bool;

    /**
     * render(), with the page's trail inside. Called instead of render(),
     * and only after carriesBreadcrumb() said yes for the same row.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public function renderWithBreadcrumb(array $pageSection, bool $tightTop, string $revealGroup, BreadcrumbTrail $trail): void;
}
