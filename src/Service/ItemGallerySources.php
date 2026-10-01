<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleRegistry;

/**
 * The closed list of content sources the blocks built on item_galleries
 * (App\Service\ItemGalleryContent) can show, and the one place that turns a
 * chosen source into items: the Shop's Collectiegalerij (`item_gallery`, a
 * collection's products) and the Portfolio's Projecten (`project_cards`,
 * portfolio items).
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
 * EVERY SOURCE BELONGS TO ONE BLOCK TYPE (`block`). A source is offered,
 * stored and rendered only by that block: a collection is the Shop's
 * Collectiegalerij, portfolio items are the Portfolio's Projecten. So the
 * Shop's gallery can never be set to show projects, and Projecten never
 * shows products — enforced here, at write time and at read time, not by an
 * `if` in each editor. Until v0.1.15 the gallery offered both sources and
 * the picker showed it twice (Collectiegalerij, Portfoliogalerij);
 * db/migrations/20261015110000 turned every portfolio gallery into a
 * Projecten block.
 *
 * A SOURCE IS UP TO ELEVEN THINGS:
 *
 *   block             the one block type that shows it (`item_gallery`,
 *                     `project_cards`).
 *   label             what the editor picks in the admin.
 *   order             where it sits in that choice. The first AVAILABLE source
 *                     of a block is what a new one starts with
 *                     (defaultSourceFor()).
 *   needs_collection  whether the block also has to store a collection id.
 *   needs_scope       optional: whether the block's choice of items applies —
 *                     all of them, one category, or picked by hand, in an
 *                     order of its own (ItemGalleryContent::SCOPES and SORTS).
 *   items             callable(array $settings): list<array> — the items, in
 *                     the ONE normalised shape the partial renders (see
 *                     ItemGalleryContent). $settings carries the block row's
 *                     already-validated `gallery_id`, `portfolio_scope`,
 *                     `category_id`, `collection_id`, `sort` and `max_items`;
 *                     a source applies what it reads and ignores the rest.
 *   filter_categories callable(): list<array> — optional. Only a source with
 *                     a taxonomy can offer a filter bar; a collection has
 *                     none, so a collection-backed block simply has no bar.
 *
 * and, for a source whose items an editor may choose (needs_scope), what the
 * block editors need to let them (admin/_gallery_selection.php), all four or
 * none:
 *
 *   category_choices  callable(): list<{id, name, count}> — its categories.
 *   item_choices      callable(): list<{id, title, thumbnail, categories,
 *                     visible}> — every item that may be picked, hidden ones
 *                     marked.
 *   selected_items    callable(int $galleryId): list<int> — a block's picked
 *                     items, in order.
 *   save_selection    callable(int $galleryId, list<int> $ids): void — store
 *                     them, inside the endpoint's transaction. The endpoint
 *                     passes only ids item_choices named.
 *
 * The picked items are the source's own relation: Core stores no item id of a
 * source anywhere.
 *
 * KNOWN vs AVAILABLE, the same distinction App\Service\AdminPermissions makes.
 * A source whose module is switched off is still KNOWN — a stored block keeps
 * naming it, and nothing rewrites that row — but it is not AVAILABLE: it
 * cannot be picked for a new block and it yields no items, so the block
 * renders empty instead of silently showing somebody else's content. A block
 * of a module that is off is not registered at all, so it is neither offered
 * nor rendered.
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
     * The sources one block type may show right now, in their own `order`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function availableFor(string $blockType): array
    {
        return array_filter(
            self::available(),
            static fn (array $source): bool => (string) ($source['block'] ?? '') === $blockType
        );
    }

    /**
     * What a new block of this type starts with: its first available source,
     * or '' when none is on.
     */
    public static function defaultSourceFor(string $blockType): string
    {
        $keys = array_keys(self::availableFor($blockType));

        return $keys === [] ? '' : (string) $keys[0];
    }

    /** Whether an editor of this block type may pick this source right now. */
    public static function isAvailableFor(string $blockType, string $source): bool
    {
        return array_key_exists($source, self::availableFor($blockType));
    }

    /**
     * Whether a stored source is one of this block type's own — known, its
     * module on or off. What a block checks before it renders a row: a row
     * whose source belongs to another block was not written by its editor,
     * and shows nothing rather than somebody else's content.
     */
    public static function belongsTo(string $source, string $blockType): bool
    {
        return (string) (self::known()[$source]['block'] ?? '') === $blockType;
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

    /** Whether an editor may choose this source's items (a category, a hand-picked list). */
    public static function supportsSelection(string $source): bool
    {
        $definition = self::available()[$source] ?? null;

        return $definition !== null
            && isset($definition['category_choices'], $definition['item_choices'], $definition['selected_items'], $definition['save_selection']);
    }

    /**
     * A source's categories for the block editors, or none.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    public static function categoryChoices(string $source): array
    {
        return self::supportsSelection($source) ? (self::available()[$source]['category_choices'])() : [];
    }

    /**
     * Every item of a source an editor may pick, or none.
     *
     * @return list<array{id: int, title: string, thumbnail: string, categories: string, visible: bool}>
     */
    public static function itemChoices(string $source): array
    {
        return self::supportsSelection($source) ? (self::available()[$source]['item_choices'])() : [];
    }

    /**
     * The items picked for one block, in order, or none.
     *
     * @return list<int>
     */
    public static function selectedItems(string $source, int $galleryId): array
    {
        return self::supportsSelection($source) && $galleryId > 0
            ? array_map('intval', (self::available()[$source]['selected_items'])($galleryId))
            : [];
    }

    /**
     * Store the items picked for one block. Does nothing for a source that
     * has no choice of items, so a save can never write a list nobody reads.
     *
     * @param list<int> $itemIds
     */
    public static function saveSelection(string $source, int $galleryId, array $itemIds): void
    {
        if (self::supportsSelection($source) && $galleryId > 0) {
            (self::available()[$source]['save_selection'])($galleryId, array_values(array_map('intval', $itemIds)));
        }
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
