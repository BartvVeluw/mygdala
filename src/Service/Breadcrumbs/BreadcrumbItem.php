<?php

declare(strict_types=1);

namespace App\Service\Breadcrumbs;

use App\Service\Language\SiteText;

/**
 * One level of a breadcrumb trail: a label in both content languages and,
 * optionally, the address it points at.
 *
 * A VALUE, not a renderer. It holds no markup and no escaping — that is
 * partials/breadcrumb.php's job, and keeping it in exactly one place is the
 * point of this class existing at all. Before this, fourteen templates each
 * wrote their own `<div class="breadcrumb">` by hand.
 *
 * IMMUTABLE. Both factories return a finished item; there is no setter, so a
 * trail that was handed an item cannot have it changed underneath it.
 *
 * A NULL href is a level that is named but not linked — the current page, and
 * also a parent whose own URL does not currently answer (an unpublished page,
 * a route of a switched-off module). Naming the level keeps the hierarchy
 * honest; dropping the link keeps a visitor out of a dead end.
 *
 * "Empty" is judged on what a visitor sees first — the primary language's own
 * text (SiteText::visible()) — exactly as partials/section-page-hero.php
 * judges an empty eyebrow. An item with a translation but no primary-language
 * label is an empty level for everyone reading the primary language, and
 * BreadcrumbTrail drops it.
 */
final class BreadcrumbItem
{
    private function __construct(
        public readonly string $labelNl,
        public readonly string $labelEn,
        public readonly ?string $href,
    ) {
    }

    /**
     * A level a visitor can click. A null or empty $href makes it an ordinary
     * named level instead of a dead link, so a caller may hand over
     * RouteRegistry::url() — which answers null while that module is off —
     * without checking first.
     */
    public static function link(string $labelNl, string $labelEn, ?string $href): self
    {
        $href = $href === null ? null : trim($href);

        return new self(trim($labelNl), trim($labelEn), ($href === null || $href === '') ? null : $href);
    }

    /** A level with no address of its own: the page the visitor is on. */
    public static function current(string $labelNl, string $labelEn): self
    {
        return new self(trim($labelNl), trim($labelEn), null);
    }

    /** Nothing a visitor would read — the trail leaves it out entirely. */
    public function isEmpty(): bool
    {
        return SiteText::visible($this->labelNl, $this->labelEn) === '';
    }
}
