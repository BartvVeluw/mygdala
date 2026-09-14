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
 * is Core; the content behind it is not. So every source is contributed by the
 * ENABLED module that owns that content, through
 * App\Module\ModuleDefinition::itemGallerySources(): portfolio items by the
 * Portfolio, "a collection" by the Shop. Core owns no source of its own — it
 * owned the portfolio one until the Portfolio became a module.
 *
 * IT IS STILL A CLOSED LIST, and that is a SECURITY BOUNDARY, not a style
 * choice. Every source is written in code, in a module's definition, and a
 * source key arriving from a request can only hit or miss one of those keys,
 * at write time and at read time both. It never becomes a table name, a class
 * name or a query. Do not replace this with a query builder or an "entity +
 * filters" abstraction; see CONTENT-BLOCKS.md.
 *
 * A SOURCE IS SIX THINGS:
 *
 *   label             what the editor picks in the admin.
 *   order             where it sits in that choice. The first AVAILABLE source
 *                     is what a new gallery block starts with (defaultSource()).
 *   needs_collection  whether the block also has to store a collection id.
 *   needs_scope       optional: whether the block's scope setting ("all visible
 *                     items" or "only the ones for the homepage") applies.
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
 * renders empty instead of silently showing somebody else's content. With no
 * available source at all the block is not offered in the picker
 * (App\Service\Blocks\ItemGalleryBlock::meta()).
 */
final class ItemGallerySources
{
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
     * The sources an editor may choose right now, in their own `order`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function available(): array
    {
        return self::$available ??= self::ordered(ModuleRegistry::collectMap('itemGallerySources'));
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

        $known = [];

        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->itemGallerySources() as $key => $source) {
                $known[$key] ??= $source;
            }
        }

        return self::$known = self::ordered($known);
    }

    /**
     * What a new gallery block starts with: the first source an enabled module
     * offers, or '' when none does — and then the block is not offered at all.
     */
    public static function defaultSource(): string
    {
        $keys = array_keys(self::available());

        return $keys === [] ? '' : (string) $keys[0];
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

    /** Whether the block's scope setting means anything for this source. */
    public static function needsScope(string $source): bool
    {
        return (bool) (self::known()[$source]['needs_scope'] ?? false);
    }

    /** The module that owns a source, or null for a key nothing declares. */
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

    /**
     * Sources in their own `order`. A stable sort, so two sources with the same
     * number keep the order their modules are registered in.
     *
     * @param array<string, array<string, mixed>> $sources
     *
     * @return array<string, array<string, mixed>>
     */
    private static function ordered(array $sources): array
    {
        uasort(
            $sources,
            static fn (array $a, array $b): int => ((int) ($a['order'] ?? PHP_INT_MAX)) <=> ((int) ($b['order'] ?? PHP_INT_MAX))
        );

        return $sources;
    }
}
