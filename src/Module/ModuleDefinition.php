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
     * `order` (see this class's docblock).
     *
     * @return list<array{key: string, label: string, url: string, icon: string, permission: string, order: int, scripts: list<string>}>
     */
    public function adminNavigationItems(): array
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
     * The items of this module a block's button can point at by id, in
     * App\Service\Routing\LinkTargets' shape: a label for the editor, an
     * `order` among Core's 'page' (10) and the other modules' types, the
     * editor's choices, and the href of one item in the language being read
     * (null when a visitor cannot open it).
     *
     * @return array<string, array{label: string, order: int, choices: callable(): list<array{id: int, label: string, note?: string}>, href: callable(int): ?string}>
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
}
