<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleRegistry;

/**
 * The closed list of content sources the "Portfolio-/collectiegalerij" block
 * (App\Service\ItemGalleryContent) can show, and the one place that turns a
 * chosen source into items.
 *
 * WHY IT IS ITS OWN CLASS. The list used to be a constant on
 * ItemGalleryContent with a `switch` underneath it, which meant a Core content
 * class named a shop collection and reached into ProductRepository. The block
 * is Core; "a collection of products" is the Shop's. So Core registers what it
 * owns (Portfolio items) and each ENABLED module contributes its own through
 * App\Module\ModuleDefinition::itemGallerySources().
 *
 * IT IS STILL A CLOSED LIST, and that is a SECURITY BOUNDARY, not a style
 * choice. Every source is written in code — Core's below, a module's in its
 * definition — and a source key arriving from a request can only hit or miss
 * one of those keys, at write time and at read time both. It never becomes a
 * table name, a class name or a query. Do not replace this with a query
 * builder or an "entity + filters" abstraction; see CONTENT-BLOCKS.md.
 *
 * A SOURCE IS FOUR THINGS:
 *
 *   label             what the editor picks in the admin.
 *   needs_collection  whether the block also has to store a collection id.
 *   items             callable(array $settings): list<array> — the items, in
 *                     the ONE normalised shape the partial renders (see
 *                     ItemGalleryContent). $settings carries the block row's
 *                     already-validated `portfolio_scope` and `collection_id`.
 *   filter_categories callable(): list<array> — optional. Only a source with
 *                     a taxonomy can offer a filter bar; a collection has
 *                     none, so a collection-backed block simply has no bar.
 *
 * KNOWN vs AVAILABLE, the same distinction App\Service\AdminPermissions makes.
 * A source whose module is switched off is still KNOWN — a stored block keeps
 * naming it, and nothing rewrites that row — but it is not AVAILABLE: it
 * cannot be picked for a new block and it yields no items, so the block
 * renders empty instead of silently showing somebody else's content.
 */
final class ItemGallerySources
{
    /** Portfolio items, from the Portfolio catalogue. Core's own source. */
    public const PORTFOLIO = 'portfolio';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $available = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $known = null;

    /** Forgets the merged lists; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$available = null;
        self::$known = null;
    }

    /**
     * Core's own sources. Portfolio is not a module: it has no dependency on
     * anything optional and is always present, so its source lives here
     * rather than in a one-contribution module that could never be off.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function coreSources(): array
    {
        return [
            self::PORTFOLIO => [
                'label' => 'Portfolio-items',
                'needs_collection' => false,
                'items' => static fn (array $settings): array => PortfolioGalleryContent::catalogueItems(
                    ($settings['portfolio_scope'] ?? '') === ItemGalleryContent::SCOPE_FEATURED
                ),
                'filter_categories' => static fn (): array => PortfolioGalleryContent::filterCategories(),
            ],
        ];
    }

    /**
     * The sources an editor may choose right now.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function available(): array
    {
        // "+" rather than array_merge(): a module cannot replace Core's
        // portfolio source by reusing its key.
        return self::$available ??= self::coreSources() + ModuleRegistry::collectMap('itemGallerySources');
    }

    /**
     * Every source any registered module declares, enabled or not — what an
     * already-stored `source_type` is checked against before it is called
     * "unrecognised".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function known(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }

        $known = self::coreSources();

        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->itemGallerySources() as $key => $source) {
                $known[$key] ??= $source;
            }
        }

        return self::$known = $known;
    }

    /** Whether an editor may pick this source for a block right now. */
    public static function isAvailable(string $source): bool
    {
        return array_key_exists($source, self::available());
    }

    /** Whether this source exists at all — a disabled module's included. */
    public static function isKnown(string $source): bool
    {
        return array_key_exists($source, self::known());
    }

    public static function label(string $source): string
    {
        return (string) (self::known()[$source]['label'] ?? $source);
    }

    public static function needsCollection(string $source): bool
    {
        return (bool) (self::known()[$source]['needs_collection'] ?? false);
    }

    /**
     * The module that owns a source, or null for a Core source and for a key
     * nothing declares.
     */
    public static function moduleOwnerOf(string $source): ?string
    {
        return ModuleRegistry::ownerOf('itemGallerySources', $source);
    }

    /**
     * The items for one block, or an empty list when the source is not
     * available (unknown, or owned by a module that is switched off). An
     * empty list is the safe answer: the block keeps its stored settings and
     * renders nothing, rather than falling back to a different source's
     * content.
     *
     * @param array<string, mixed> $settings the block's validated portfolio_scope / collection_id
     *
     * @return list<array<string, mixed>>
     */
    public static function items(string $source, array $settings): array
    {
        $definition = self::available()[$source] ?? null;

        if ($definition === null) {
            return [];
        }

        try {
            return ($definition['items'])($settings);
        } catch (\Throwable $e) {
            error_log('[ItemGallerySources] source "' . $source . '" could not produce items: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The filter categories this source offers, or an empty list when it has
     * no taxonomy (or is not available).
     *
     * @return list<array<string, mixed>>
     */
    public static function filterCategories(string $source): array
    {
        $definition = self::available()[$source] ?? null;

        if ($definition === null || !isset($definition['filter_categories'])) {
            return [];
        }

        try {
            return ($definition['filter_categories'])();
        } catch (\Throwable $e) {
            error_log('[ItemGallerySources] source "' . $source . '" could not list categories: ' . $e->getMessage());

            return [];
        }
    }
}
