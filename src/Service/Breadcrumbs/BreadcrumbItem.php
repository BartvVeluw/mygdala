<?php

declare(strict_types=1);

namespace App\Service\Breadcrumbs;


/**
 * One level of a breadcrumb trail: a label in the language of the request
 * and, optionally, the address it points at.
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
 * "Empty" is judged on what a visitor sees — the label in the language being
 * read, its fallback already applied — exactly as
 * partials/section-page-hero.php judges an empty eyebrow; BreadcrumbTrail
 * drops such a level.
 */
final class BreadcrumbItem
{
    private function __construct(
        public readonly string $label,
        public readonly ?string $href,
    ) {
    }

    /**
     * A level a visitor can click. A null or empty $href makes it an ordinary
     * named level instead of a dead link, so a caller may hand over
     * RouteRegistry::url() — which answers null while that module is off —
     * without checking first.
     */
    public static function link(string $label, ?string $href): self
    {
        $href = $href === null ? null : trim($href);

        return new self(trim($label), ($href === null || $href === '') ? null : $href);
    }

    /** A level with no address of its own: the page the visitor is on. */
    public static function current(string $label): self
    {
        return new self(trim($label), null);
    }

    /** Nothing a visitor would read — the trail leaves it out entirely. */
    public function isEmpty(): bool
    {
        return $this->label === '';
    }
}
