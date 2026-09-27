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
 */
final class ModuleSystemPages
{
    /**
     * Every module's system pages, on or off.
     *
     * @return array<string, array{module: string, module_label: string, route_path: string}> content key => page
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
                ];
            }
        }

        return $pages;
    }

    /**
     * The module a page belongs to, or null for an ordinary page.
     *
     * @param array<string, mixed> $page a `pages` row
     * @return array{module: string, module_label: string, route_path: string, enabled: bool}|null
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

    /** @return array<string, array{route_path: string}> */
    private static function declared(ModuleDefinition $module): array
    {
        $pages = [];
        foreach ($module->systemPages() as $contentKey => $page) {
            if (is_string($contentKey) && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $contentKey) === 1 && is_array($page) && isset($page['route_path'])) {
                $pages[$contentKey] = ['route_path' => (string) $page['route_path']];
            }
        }

        return $pages;
    }
}
