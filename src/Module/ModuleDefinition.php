<?php

declare(strict_types=1);

namespace App\Module;

/**
 * What a first-party module IS: a key, a name, the modules it needs, and a
 * set of things it may contribute to the CMS.
 *
 * Only key() and label() are abstract. Every contribution below has an empty
 * default, so a module implements the two or three hooks it actually uses and
 * nothing else — there is no lifecycle to satisfy, no install/update/uninstall
 * API, and no interface to implement per capability. See MODULES.md.
 *
 * A contribution is CODE, never data: every list below is written in a module
 * class and read by Core. Nothing here is built from a request, a database
 * row or a directory scan, so a module can never turn user input into a class
 * name, a table name or a URL the browser loads — the same closed-list rule
 * App\Service\Blocks\BlockDefinitions and App\Service\PageAssets already
 * follow.
 *
 * ORDERING. Three of the contributions land in a list Core already renders in
 * a deliberate order (the admin sidebar, the permission form, the route
 * picker). Those entries therefore carry an integer `order`, and Core sorts
 * Core's own entries and the modules' together by it. That is the only
 * ordering mechanism; nothing depends on the order modules are registered in.
 */
abstract class ModuleDefinition
{
    /** The registration key, e.g. 'shop'. Matches App\Module\ModuleRegistry. */
    abstract public function key(): string;

    /** Admin-facing name, e.g. 'Shop'. */
    abstract public function label(): string;

    /**
     * One sentence an owner can decide by, for the few screens that offer a
     * module rather than assume it — today only the Setup Wizard's
     * "Onderdelen" step (SETUP.md).
     *
     * It lives on the module for the same reason its label does: Core must
     * not carry a sentence about what a webshop is, and a wizard that spelled
     * one out per key would be a second `if` on a module name. Empty is a
     * valid answer; a module with nothing to add renders only its name.
     */
    public function description(): string
    {
        return '';
    }

    /**
     * Whether an installation that has said nothing about this module gets
     * it — the LAST step of App\Module\ModuleConfig's precedence chain, after
     * the environment variable and the stored preference.
     *
     * ON is the default default, and every module that existed before this
     * method keeps it: a missing MODULE_<KEY>_ENABLED must never be able to
     * take a running webshop off the air.
     *
     * A module says false here when the site it is installed on is unlikely
     * to want it — the Blog, which most sites using this CMS do not run
     * (BLOG.md). That is a statement about a NEW installation only: a
     * deployment that switched the module on has a stored preference or an
     * environment variable, and both are read before this.
     */
    public function enabledByDefault(): bool
    {
        return true;
    }

    /**
     * Modules that must be enabled for this one to do anything. A module
     * whose dependency is disabled is itself treated as disabled — see
     * ModuleRegistry::enabled().
     *
     * @return list<string> module keys
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * Admin sidebar entries, in App\Service\AdminNavigation's own shape plus
     * `order` (see this class's docblock). An entry with a `menu` key is
     * shown inside that sidebar menu instead of on a line of its own, when
     * the menu exists (adminNavigationMenus()).
     *
     * @return list<array{key: string, label: string, url: string, icon: string, permission: string, order: int, scripts: list<string>, menu?: string}>
     */
    public function adminNavigationItems(): array
    {
        return [];
    }

    /**
     * Sidebar menus: one line with a name, an icon and a chevron that folds
     * open to show the entries naming it in their `menu` key. The menu sits
     * at its own `order` among the sidebar's lines; its entries keep theirs
     * among each other. A menu nobody can open an entry of is not shown at
     * all (App\Service\AdminNavigation::sidebar()).
     *
     * A module groups its own screens this way (the Shop's nine lines became
     * one "Shop"); Core names no module's menu.
     *
     * @return list<array{key: string, label: string, icon: string, order: int}>
     */
    public function adminNavigationMenus(): array
    {
        return [];
    }

    /**
     * Permission groups, in App\Service\AdminPermissions' own shape plus
     * `order`. The permission NAMES are this module's constants; their string
     * values are stable identifiers stored in `admin_user_permissions`, so a
     * module never renames one.
     *
     * @return list<array{label: string, order: int, permissions: array<string, array{label: string, description: string}>}>
     */
    public function permissionGroups(): array
    {
        return [];
    }

    /**
     * This module's permissions that only a Super Admin may hand out or take
     * away, next to Core's own (App\Service\AdminPermissions::superAdminGrantableOnly()).
     * For a permission whose holder could do the owner real harm — the Shop's
     * payments.manage decides which Mollie account the money goes to. Every
     * name must be one of this module's permissionGroups().
     *
     * @return list<string>
     */
    public function superAdminGrantablePermissions(): array
    {
        return [];
    }

    /**
     * Permissions that imply other permissions, e.g. "manage" implying
     * "view" — App\Service\AdminPermissions::IMPLIES for this module.
     *
     * @return array<string, list<string>>
     */
    public function permissionImplications(): array
    {
        return [];
    }

    /**
     * Application routes an administrator may point a menu or footer link at,
     * in App\Service\RouteRegistry's shape plus `order`.
     *
     * @return array<string, array{url: string, label: array<string, string>, order: int}>
     */
    public function routes(): array
    {
        return [];
    }

    /**
     * The module's SYSTEM PAGES (Shop Product & Ordering 2.0, MODULES.md
     * "Systeempagina's van modules"): pages that belong to this module and
     * are in Pagina's on every installation, whether the module is on or off
     * — the Shop's storefront page and the Portfolio overview. By content
     * key: the one identity of a page that never changes.
     *
     * Read for EVERY registered module, enabled or not
     * (App\Service\ModuleSystemPages): Pagina's shows the page, says the
     * module is off and refuses to delete it, and the word stays reserved
     * (reservedSlugs()), so nobody can take the address before the module is
     * switched on. A migration made the page where it was missing
     * (db/migrations/20260928150000); nothing here creates one.
     *
     * PAGES UNDER IT (Pages & Destinations 3.0, docs/pages/NESTING.md), both
     * optional:
     *
     *   child_prefix     the one URL segment every ordinary page under this
     *                    system page starts with — 'portfolio' makes
     *                    /portfolio/wolven. It must be one of this module's
     *                    reservedSlugs(), so no root page can hold it; without
     *                    it, nothing can be placed under the page.
     *   child_conflicts  what the module itself serves one level under that
     *                    prefix, as callable(list<string> $slugs):
     *                    list<array{slug: string, label: string}> — every slug
     *                    it already answers, with the admin's words for what
     *                    holds it. Core asks before a page is saved directly
     *                    under the system page; the module asks the other way
     *                    round, App\Service\ModuleSystemPages::childPageHolding(),
     *                    before it hands one of its own objects a slug.
     *
     * @return array<string, array{route_path: string, child_prefix?: string, child_conflicts?: callable(list<string>): list<array{slug: string, label: string}>}> content key => the page
     */
    public function systemPages(): array
    {
        return [];
    }

    /**
     * Single-segment URL words a CMS page may never claim as its slug.
     *
     * Read for EVERY registered module, enabled or not (see
     * App\Service\ReservedRoutes): the module's PHP files stay on disk when it
     * is switched off, and a page whose slug is shadowed by a real file would
     * be unreachable rather than merely unrouted.
     *
     * @return list<string>
     */
    public function reservedSlugs(): array
    {
        return [];
    }

    /**
     * The PUBLIC URL SHAPES this module serves, in
     * App\Service\Routing\RouteTable's shape: a key, a pattern, the
     * root-level template that answers it, and which captures that template
     * reads out of $_GET.
     *
     * Read for EVERY registered module, enabled or not, for the same reason
     * reservedSlugs() is: a disabled module's URLs must keep reaching its own
     * template, which answers the site's ordinary 404 through
     * App\Module\ModuleGuard. A URL that starts resolving differently the
     * moment a module is switched off is a URL nobody can reason about.
     *
     * @return list<array{key: string, pattern: string, template: string, query?: array<string, string>}>
     */
    public function publicRoutes(): array
    {
        return [];
    }

    /**
     * Fixed URL words of this module's routes that are a natural-language
     * word rather than a technical one, per language, in
     * App\Service\Routing\RouteSegments' shape.
     *
     * Read for every registered module, enabled or not: the words are
     * reserved against page slugs whatever the module's state, exactly like
     * reservedSlugs().
     *
     * @return array<string, array<string, string>> key => ['default' => word, '<code>' => word]
     */
    public function routeSegments(): array
    {
        return [];
    }

    /**
     * Fixed public paths this module's own root-level templates answer at,
     * beyond the menu destinations routes() offers — "/portfolio.php".
     *
     * Read by App\Module\ModuleRegistry::disabledModuleForRoutePath(), which is
     * how Core learns that a CMS page served from one of those templates (its
     * `pages.route_path`), a menu link to that page and a redirect aimed at it
     * stop resolving while the module is off. A path named here is NOT offered
     * in the link picker: a CMS content page is linked as a page, never as a
     * route (App\Service\RouteRegistry).
     *
     * @return list<string> root-relative paths
     */
    public function publicPaths(): array
    {
        return [];
    }

    /**
     * Fixed public paths this module owns but does not answer RIGHT NOW,
     * while it is switched on: a CMS page served from one of its templates
     * whose URL the module currently sends elsewhere or answers with a 404,
     * because of a choice made in the module's own settings.
     *
     * Read by App\Module\ModuleRegistry::isPausedRoutePath(), so Core treats
     * such a page like one of a switched-off module — no sitemap entry, no
     * link, no breadcrumb level — without knowing why. Empty for almost every
     * module; see the Shop's product overview (MODULES.md) for the one that
     * uses it.
     *
     * @return list<string> root-relative paths
     */
    public function pausedPublicPaths(): array
    {
        return [];
    }

    /**
     * Sitemap collectors, keyed by the label App\Service\Sitemap logs when one
     * fails. Each returns entries in Sitemap's own shape.
     *
     * @return array<string, callable(): list<array{loc: string, lastmod: ?string}>>
     */
    public function sitemapCollectors(): array
    {
        return [];
    }

    /**
     * Site-search providers, keyed by result type ("product", "project",
     * "post"), in App\Service\Search\SearchService's order. Read only while
     * the module is enabled, so a module that is off can never put its
     * content in the search results (SEARCH.md).
     *
     * @return array<string, \App\Service\Search\SearchProvider>
     */
    public function searchProviders(): array
    {
        return [];
    }

    /**
     * Content-block types this module owns, in App\Service\Blocks\BlockDefinitions'
     * shape: type key => definition class.
     *
     * @return array<string, class-string<\App\Service\Blocks\BlockDefinition>>
     */
    public function blockDefinitions(): array
    {
        return [];
    }

    /**
     * Item-gallery content sources, in App\Service\ItemGallerySources' shape.
     * Every source the gallery block can show comes from a module — Core owns
     * none — and the lowest `order` among the available ones is the source a
     * new block starts with.
     *
     * @return array<string, array{label: string, order: int, needs_collection: bool, needs_scope?: bool, items: callable(array<string, mixed>): list<array<string, mixed>>, filter_categories?: callable(): list<array<string, mixed>>}>
     */
    public function itemGallerySources(): array
    {
        return [];
    }

    /**
     * The DESTINATIONS of this module a link can point at by id (the
     * Destination Picker, App\Service\Routing\LinkTargets — read that class's
     * docblock for the shape): a label for the editor (Dutch, or per CMS
     * language), an `order` among Core's 'page' (10) and the other modules'
     * kinds, the editor's choices (with a `note` for what a visitor cannot
     * open yet and, for the searchable list, a `thumbnail`), the href of one
     * item in the language being read (null when a visitor cannot open it),
     * optionally how it is picked (`picker`: 'search', the default for a
     * module) and its visible `title` in one language.
     *
     * Only a kind with a public address of its own belongs here: a
     * destination nobody can open is no destination.
     *
     * @return array<string, array{label: string|array<string, string>, order: int, choices: callable(): list<array{id: int, label: string, note?: string, thumbnail?: string}>, href: callable(int): ?string, picker?: string, title?: callable(int, string): ?string}>
     */
    public function linkTargets(): array
    {
        return [];
    }

    /**
     * Frontend files EVERY public page needs because of this module — the
     * site shell (App\Service\PageAssets). Keep this empty unless the module
     * really renders something on every page; per-route and per-block assets
     * belong to the route or the block definition.
     *
     * @return list<string> project-relative paths under assets/
     */
    public function shellStyles(): array
    {
        return [];
    }

    /** @return list<string> project-relative paths under assets/ */
    public function shellScripts(): array
    {
        return [];
    }

    /**
     * Partials rendered inside the shared public header's action area
     * (partials/header.php), in order.
     *
     * @return list<string> absolute filesystem paths
     */
    public function headerPartials(): array
    {
        return [];
    }

    /**
     * Panels rendered on the CMS dashboard (admin/index.php), in order. Each
     * partial does its own permission checks and its own queries, so a
     * disabled module's tables are never touched.
     *
     * @return list<string> absolute filesystem paths
     */
    public function dashboardPanels(): array
    {
        return [];
    }

    /**
     * "Waar wil je aan werken?" cards on the dashboard, in admin/index.php's
     * own shape plus `order`.
     *
     * @return list<array{icon: string, title: string, desc: string, href: string, cta: string, permission: string, order: int}>
     */
    public function dashboardCards(): array
    {
        return [];
    }

    /**
     * How this module answers "do you use this media item, and where?" —
     * read by App\Service\Media\MediaUsageRegistry.
     *
     * A module that stores a `media_id` contributes one provider per group of
     * tables it owns; a module that owns no library media contributes
     * nothing, which is the default and is true of every module today. This
     * is what keeps Core's Media Library from ever naming a product, a
     * variant or a collection: the Shop answers for its own tables.
     *
     * The contract deliberately asks about a BATCH of ids at once — see
     * App\Service\Media\MediaUsageProvider for why.
     *
     * @return list<\App\Service\Media\MediaUsageProvider>
     */
    public function mediaUsageProviders(): array
    {
        return [];
    }

    /**
     * What this module has that can carry content blocks besides a page — a
     * product, a project (Product & Portfolio Content Pages 1.0): one
     * App\Service\ContentOwners\ContentOwner each, answering for its own
     * tables and its own editor. Core then gives each owner a content page
     * through the ordinary block engine (App\Service\ContentOwners\ContentPages)
     * without ever naming it. Asked for every registered module, on or off
     * (App\Service\ContentOwners\ContentOwners::all()): a switched-off
     * module's owners keep their blocks and their names.
     *
     * @return list<\App\Service\ContentOwners\ContentOwner>
     */
    public function contentOwners(): array
    {
        return [];
    }

    /**
     * The picture that stands for an item of this module wherever a block
     * shows the item AS a picture that links to it — a gallery item of a
     * Detailsectie (Detailsectie 2.0, App\Service\Media\LinkedImages). Keyed
     * by the item's App\Service\Routing\LinkTargets type, which already
     * gives the name, the choices and the public address: this adds only the
     * picture, the one the module's own cards and storefront show, with the
     * Media Library's alt text. A kind offers itself as a gallery source only
     * with both, so a new module (Articles) that contributes a destination
     * and a picture needs no change to any block.
     *
     * @return array<string, callable(int): ?array{image_path: string, alt?: string, width?: int|null, height?: int|null}>
     */
    public function linkedImages(): array
    {
        return [];
    }

    /**
     * Whether this module lets the website publish MORE than its default
     * language: every active language of the website language registry, with
     * its prefix, its place in the language switch, the sitemap and hreflang.
     *
     * False for every module but App\Module\MultilingualModule. Core never
     * asks a module by key: App\Service\Language\SiteLanguages asks
     * ModuleRegistry::publishesTranslations() once, and every public route,
     * editor and endpoint asks SiteLanguages.
     */
    public function publishesTranslations(): bool
    {
        return false;
    }

    /**
     * How this CMS page should look when it is not to look like the rest of
     * the site: an App\Service\Theme\PageAppearance, or null for "the site
     * theme" (Page Themes 1.0, THEMING.md "Paginathema's").
     *
     * Asked only of ENABLED modules, and only for an ordinary page � never
     * for the content page of a product or project � through
     * ModuleRegistry::pageAppearance(), which App\Service\Theme\PageThemeCss
     * calls once per request from partials/page-head.php. The first module
     * with an answer decides. Null for every module but
     * App\Module\PageThemesModule. Core prints the result and never names
     * the module that gave it.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public function pageAppearance(array $page): ?\App\Service\Theme\PageAppearance
    {
        return null;
    }

    /**
     * Extra settings on the Pagina tab of the page editor (admin/page.php),
     * saved with the rest of the page by api/admin/update-page.php: one
     * App\Service\PageSettingsSection each. Read from ENABLED modules only,
     * so a switched-off module's field is not on the screen and its posted
     * value is not read � and what it stored stays untouched. Pages only:
     * the product and project editors never render these.
     *
     * @return list<\App\Service\PageSettingsSection>
     */
    public function pageSettingsSections(): array
    {
        return [];
    }

    /**
     * Whether the Vormgeving screen (admin/theme.php) offers this module an
     * on/off switch after installation � for a module that is part of how
     * the site looks (Paginathema's). The switch writes the same stored
     * preference the Setup Wizard writes (App\Module\ModuleSettings), and an
     * environment variable still has the last word (MODULES.md). Asked of
     * every registered module, on or off: a switch must be there to switch
     * a module back on.
     */
    public function switchableFromAppearance(): bool
    {
        return false;
    }
}
