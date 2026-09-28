<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleDefinition;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;

/**
 * The pages that belong to a MODULE (Shop Product & Ordering 2.0, MODULES.md
 * "Systeempagina's van modules"): each module names its own in
 * ModuleDefinition::systemPages() — the Shop its storefront page, the
 * Portfolio its overview — by content key. Core knows no module by name; it
 * asks every REGISTERED module, on or off, because the page is in Pagina's
 * either way.
 *
 * What Core does with it:
 *
 *   - Pagina's and the page editor say whose page it is and whether that
 *     module is on; with the module off the page answers nothing on the
 *     website (its address is the module's, and ModuleGuard answers 404),
 *     and its word stays reserved (ModuleDefinition::reservedSlugs()).
 *   - it cannot be deleted (App\Service\PageService::delete()): it is the
 *     module's page, and the module would come back to a missing one.
 *   - a page a migration made for the module (`pages.module_default`) with
 *     no block of its own is NOT a page of its own on the website: the
 *     module shows its own overview on that address, as it did before the
 *     page existed (isPlaceholder()). The moment an editor gives it a block,
 *     it is the page.
 *   - conflicts(): a module page that is missing because another page held
 *     its word when the migration ran. Nothing renames that page; Pagina's
 *     says so, and the owner decides.
 *
 * PAGES UNDER A SYSTEM PAGE (Pages & Destinations 3.0, docs/pages/NESTING.md).
 * A module that names a `child_prefix` lets ordinary CMS pages sit under its
 * system page: /shop/zakelijk, /portfolio/wolven. The prefix is one of the
 * module's own reserved words, so no root page can ever hold it, and it is
 * the first segment of every path in that subtree (App\Service\PagePath) —
 * never the system page's route, which may be a file (/shop.php). What the
 * module itself serves one level under that prefix (a Portfolio project at
 * /portfolio/<slug>) is ONE namespace with those pages: Core asks the module
 * before a page is saved there (childSlugConflicts()), and the module asks
 * Core before it saves an object's slug (childPageHolding()). Whoever saves
 * second is refused; nothing is ever renamed, and no route silently wins.
 *
 * With the module off its whole subtree is off the website
 * (inDisabledModuleSubtree(), App\Service\PageContent::isServedByAnEnabledModule()),
 * and every row stays exactly as it was.
 */
final class ModuleSystemPages
{
    /**
     * Every module's system pages, on or off.
     *
     * @return array<string, array{module: string, module_label: string, route_path: string, child_prefix: ?string, child_conflicts: ?callable}> content key => page
     */
    public static function all(): array
    {
        $pages = [];
        foreach (ModuleRegistry::all() as $key => $module) {
            foreach (self::declared($module) as $contentKey => $page) {
                $pages[$contentKey] = [
                    'module' => $key,
                    'module_label' => $module->label(),
                    'route_path' => $page['route_path'],
                    'child_prefix' => $page['child_prefix'],
                    'child_conflicts' => $page['child_conflicts'],
                ];
            }
        }

        return $pages;
    }

    /**
     * The module a page belongs to, or null for an ordinary page.
     *
     * @param array<string, mixed> $page a `pages` row
     * @return array{module: string, module_label: string, route_path: string, child_prefix: ?string, child_conflicts: ?callable, enabled: bool}|null
     */
    public static function forPage(array $page): ?array
    {
        $contentKey = (string) ($page['content_key'] ?? '');
        $system = self::all()[$contentKey] ?? null;
        if ($system === null) {
            return null;
        }

        return $system + ['enabled' => ModuleRegistry::isEnabled($system['module'])];
    }

    /**
     * The first path segment of every page under this system page, or null
     * when it is not a system page or its module keeps no pages under it.
     * What makes a page with a fixed URL a possible parent at all
     * (App\Service\PageService::validateParent()).
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function childPrefix(array $page): ?string
    {
        return self::forPage($page)['child_prefix'] ?? null;
    }

    /**
     * The system page at the top of this page's tree, with its module's state,
     * or null for a page in an ordinary tree (or with a broken chain). The
     * system page itself counts as its own tree.
     *
     * @return array{page_id: int, module: string, module_label: string, route_path: string, child_prefix: ?string, child_conflicts: ?callable, enabled: bool}|null
     */
    public static function forSubtreeOf(int $pageId): ?array
    {
        $rootId = PagePath::rootId($pageId);
        $root = $rootId === null ? null : PagePath::node($rootId);
        if ($root === null) {
            return null;
        }

        $system = self::forPage($root);

        return $system === null ? null : ['page_id' => (int) $rootId] + $system;
    }

    /**
     * Is this page BELOW a system page whose module is switched off? Then it
     * answers nothing on the website, like the system page itself, whatever
     * its own status says. The system page itself is not "below" anything.
     */
    public static function inDisabledModuleSubtree(int $pageId): bool
    {
        $system = self::forSubtreeOf($pageId);

        return $system !== null && $system['page_id'] !== $pageId && !$system['enabled'];
    }

    /**
     * Which of these slugs a module already serves one level under its system
     * page — what a page saved directly under $parent would collide with. Each
     * with the module's own words for the object that holds it ("het
     * portfolioproject ‘Wolven’"). Empty for an ordinary parent and for a
     * module that serves nothing there (the Shop).
     *
     * A module whose check fails is treated as a clash: refusing a save the
     * editor can retry is the safe direction; handing out an address that turns
     * out to be a project's is not.
     *
     * @param array<string, mixed> $parent a `pages` row
     * @param list<string> $slugs
     * @return list<array{slug: string, label: string}>
     */
    public static function childSlugConflicts(array $parent, array $slugs): array
    {
        $system = self::forPage($parent);
        $check = $system['child_conflicts'] ?? null;
        $slugs = array_values(array_unique(array_filter($slugs, static fn (string $slug): bool => $slug !== '')));

        if ($check === null || ($system['child_prefix'] ?? null) === null || $slugs === []) {
            return [];
        }

        try {
            $conflicts = [];
            foreach (($check)($slugs) as $conflict) {
                if (is_array($conflict) && isset($conflict['slug'], $conflict['label'])) {
                    $conflicts[] = ['slug' => (string) $conflict['slug'], 'label' => (string) $conflict['label']];
                }
            }

            return $conflicts;
        } catch (\Throwable $e) {
            error_log('[ModuleSystemPages] the ' . $system['module'] . ' module could not check ' . implode(', ', $slugs) . ': ' . $e->getMessage());

            return [['slug' => $slugs[0], 'label' => \App\Service\Language\AdminTranslator::trans('validation.page_slug_module_unchecked', ['module' => $system['module_label']])]];
        }
    }

    /**
     * The CMS page directly under this module's system page that has $slug as
     * its address in any language, or null — what a module asks before it
     * gives one of its own objects that slug (App\Service\PortfolioSlug). Its
     * id and name, for the message the module shows. Only a DIRECT child
     * shares the namespace: /portfolio/wolven/detail is two levels down, where
     * the module serves nothing.
     *
     * @return array{id: int, name: string}|null
     */
    public static function childPageHolding(string $contentKey, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $system = PageContent::forContentKey($contentKey);
        if ($system === null || self::childPrefix($system) === null) {
            return null;
        }

        $childIds = PagePath::childIds((int) $system['id']);
        PageLocalization::preload($childIds);

        foreach ($childIds as $childId) {
            $child = PagePath::node($childId);
            if ($child !== null && in_array($slug, self::slugsOf($child), true)) {
                return ['id' => $childId, 'name' => PageLocalization::name($childId)];
            }
        }

        return null;
    }

    /**
     * Every address a page has, in every registered language — the default
     * language's own, or the neutral column that stands for it, included.
     *
     * @param array<string, mixed> $page a `pages` row
     * @return array<string, string> language code => slug
     */
    public static function slugsOf(array $page): array
    {
        $slugs = [];
        foreach (\App\Service\Language\SiteLanguages::all() as $language) {
            $slug = PageContent::localizedSlug($page, $language->code);
            if ($slug !== null) {
                $slugs[$language->code] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * A page a migration made for its module, published, that shows no block
     * of its own yet (a hidden block does not count): on the website the
     * module's own overview stands on its address, and nothing lists the page
     * itself (no sitemap entry, no link, no breadcrumb of its own). A page an
     * installation already had is never a placeholder, whatever it holds, and
     * neither is one an editor set to Concept: that is the editor's choice,
     * and the address answers 404 like any concept page's.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function isPlaceholder(array $page): bool
    {
        if ((int) ($page['module_default'] ?? 0) !== 1 || !PageContent::isPublished($page)) {
            return false;
        }

        try {
            return !(new PageSectionRepository())->hasActiveBlocks((int) ($page['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[ModuleSystemPages] block lookup failed: ' . $e->getMessage());

            // The reading from before the page existed: the module's own.
            return true;
        }
    }

    /**
     * Module pages that are missing because another page holds their word.
     *
     * @return list<array{content_key: string, module_label: string, page_id: int}>
     */
    public static function conflicts(): array
    {
        $conflicts = [];
        $repository = new PageRepository();

        foreach (self::all() as $contentKey => $system) {
            if ($repository->findByContentKey($contentKey) !== null) {
                continue;
            }

            $holder = $repository->idHoldingSlug($contentKey) ?? PageLocalization::pageIdHoldingSlug($contentKey);
            if ($holder !== null) {
                $conflicts[] = ['content_key' => $contentKey, 'module_label' => $system['module_label'], 'page_id' => $holder];
            }
        }

        return $conflicts;
    }

    /**
     * A module's declaration, checked: a child prefix counts only when it is
     * one path segment AND one of the module's own reserved words — the one
     * thing that keeps every root page, in every language, off it.
     *
     * @return array<string, array{route_path: string, child_prefix: ?string, child_conflicts: ?callable}>
     */
    private static function declared(ModuleDefinition $module): array
    {
        $pages = [];
        foreach ($module->systemPages() as $contentKey => $page) {
            if (!is_string($contentKey) || preg_match('/^[a-z][a-z0-9-]{0,63}$/', $contentKey) !== 1 || !is_array($page) || !isset($page['route_path'])) {
                continue;
            }

            $prefix = $page['child_prefix'] ?? null;
            if ($prefix !== null && (!is_string($prefix) || preg_match('/^[a-z0-9-]+$/', $prefix) !== 1 || !in_array($prefix, $module->reservedSlugs(), true))) {
                error_log('[ModuleSystemPages] ignoring child prefix of "' . $contentKey . '": it must be one of the module\'s own reserved words');
                $prefix = null;
            }

            $conflicts = $page['child_conflicts'] ?? null;

            $pages[$contentKey] = [
                'route_path' => (string) $page['route_path'],
                'child_prefix' => $prefix,
                'child_conflicts' => is_callable($conflicts) ? $conflicts : null,
            ];
        }

        return $pages;
    }
}
