<?php

declare(strict_types=1);

namespace App\Service\Breadcrumbs;

use App\Service\Language\LanguageRegistry;
use App\Service\PageContent;
use App\Service\RouteRegistry;

/**
 * The ordered levels of one breadcrumb, from the homepage down to the page a
 * visitor is on.
 *
 * WHO BUILDS ONE. The route does — that is the whole split this class exists
 * for. A domain route knows what its levels are (Shop knows it sits under the
 * storefront, the Blog knows it sits under its index); Core knows how to write
 * them down. So there is no repository of any module in here, and there never
 * may be: MODULES.md's boundary applies to this file as it does to any other
 * Core service. partials/breadcrumb.php turns the result into markup, and is
 * the only place that knows what a breadcrumb looks like.
 *
 * IMMUTABLE. Every step returns a new trail, so a half-built one can be shared
 * or reused without a later step changing it.
 *
 * WHERE HOME COMES FROM. The address is the site root's own, resolved through
 * PageContent::publicUrl() — the single page-URL resolution this project has,
 * the one the menu, the canonical tag and the sitemap already use. Fourteen
 * templates used to write it by hand, in three different spellings
 * ("index.php", "/index.php", "/"), and this is what replaces all three. There
 * is deliberately no second resolver here: when the row cannot be read the
 * fallback is the bare "/", which is exactly what publicUrl() returns for the
 * site root anyway.
 *
 * THE WORD "Home" is held here and nowhere else. It is not the site root's CMS
 * title — that is "Homepage", the name an administrator sees in the Pages
 * list, never a word a visitor reads — and it is the same in both languages,
 * as it has been in every template since the beginning.
 * App\Service\RouteRegistry names the same route for the admin's link picker;
 * that list answers a different question (which routes may a menu item point
 * at) and carries the route's own "/index.php", not the canonical address.
 */
final class BreadcrumbTrail
{
    /** What the first level is called; the same word in both languages. */
    private const HOME_LABEL_NL = 'Home';
    private const HOME_LABEL_EN = 'Home';

    /** The site root's address when its `pages` row cannot be read. */
    private const HOME_FALLBACK_URL = '/';

    /** The content key of the site root, as index.php addresses it. */
    private const HOME_CONTENT_KEY = 'index';

    /** @param list<BreadcrumbItem> $items */
    private function __construct(private readonly array $items)
    {
    }

    /** A trail that starts at the homepage — every trail on this site does. */
    public static function home(): self
    {
        return new self([BreadcrumbItem::link(self::HOME_LABEL_NL, self::HOME_LABEL_EN, self::homeUrl())]);
    }

    /** The same trail with one more level below it. */
    public function to(BreadcrumbItem $item): self
    {
        return new self([...$this->items, $item]);
    }

    /**
     * One more level that is an ordinary CMS page, addressed by its immutable
     * content key: its own current title, at its own current address. A page
     * renamed in the CMS therefore moves every trail that passes through it,
     * without anything being copied anywhere.
     *
     * Core only ever reads `pages` here, so a module route may use this for
     * its own page (the Shop's storefront, the Portfolio's overview) without
     * this class learning that those modules exist.
     *
     * A page that is not published, or one served from a switched-off module's
     * template, keeps its name but loses its link — see BreadcrumbItem.
     *
     * $fallbackRoute is for the one case where the SAME level exists without a
     * page behind it: an installation whose storefront is the Shop module's
     * own overview rather than a `pages` row (INSTALL-BOOTSTRAP.md). Naming it
     * keeps the level instead of silently shortening the trail there. Without
     * one, a page that is not there adds no level at all: inventing a name
     * would point at a place that does not exist.
     */
    public function toPage(string $contentKey, ?string $fallbackRoute = null): self
    {
        $page = PageContent::forContentKey($contentKey);

        if ($page === null) {
            return $fallbackRoute === null ? $this : $this->toRoute($fallbackRoute);
        }

        $reachable = PageContent::isPublished($page) && PageContent::isServedByAnEnabledModule($page);

        // Both halves exactly as stored; the renderer decides which one is
        // visible. PageContent::titleValue() is the one place that knows a
        // page name is `title` + `title_en`.
        $title = PageContent::titleValue($page);

        return $this->to(BreadcrumbItem::link(
            $title->raw(LanguageRegistry::DUTCH),
            $title->raw(LanguageRegistry::ENGLISH),
            $reachable ? PageContent::publicUrl($page) : null
        ));
    }

    /**
     * One more level that is a real APPLICATION route rather than a page: the
     * storefront, the cart, the checkout, the two legal pages. Its name and
     * its address both come from App\Service\RouteRegistry — the closed list
     * that already names them in both languages for the admin's link picker,
     * so a route is named in exactly one place.
     *
     * A key the registry does not know adds no level. That is not a
     * programming error: a module's routes disappear from the list while it is
     * switched off, and a trail must not then point at a URL that answers 404.
     */
    public function toRoute(string $key): self
    {
        $route = RouteRegistry::all()[$key] ?? null;

        if ($route === null) {
            return $this;
        }

        return $this->to(BreadcrumbItem::link($route['label_nl'], $route['label_en'], $route['url']));
    }

    /**
     * The levels, in reading order, without the ones a visitor would see as
     * blank. @return list<BreadcrumbItem>
     */
    public function items(): array
    {
        return array_values(array_filter($this->items, static fn (BreadcrumbItem $item): bool => !$item->isEmpty()));
    }

    /**
     * Is there a trail worth printing?
     *
     * Two levels is the minimum, because "Home" on its own says nothing about
     * where a visitor is — it is a link to the homepage dressed up as
     * navigation. A route whose own level resolved to nothing therefore
     * renders no breadcrumb at all rather than a lonely first level.
     */
    public function isRenderable(): bool
    {
        return count($this->items()) > 1;
    }

    private static function homeUrl(): string
    {
        $home = PageContent::forContentKey(self::HOME_CONTENT_KEY);

        return $home === null ? self::HOME_FALLBACK_URL : PageContent::publicUrl($home);
    }
}
