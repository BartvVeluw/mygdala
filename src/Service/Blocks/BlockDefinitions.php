<?php

namespace App\Service\Blocks;

use App\Module\ModuleRegistry;

/**
 * THE list of content-block types this CMS knows — the ONE place a new block
 * is registered, and the only shared file adding one has to touch. Everything
 * else about a block lives in its own definition class next to this one.
 *
 * The list has two halves and one shape:
 *
 *     Core blocks   +   the blocks each ENABLED module contributes
 *
 * A Core block is registered in CORE_MAP below. A module's block is
 * registered in that module's own blockDefinitions() (see
 * App\Module\ModuleDefinition), so the Shop's `product_grid` and
 * `shop_collections` no longer appear in a Core file at all, and a new module
 * can bring blocks without this file changing. Both halves are still ONE
 * registration list with one lookup in front of it: there is no second
 * dispatch table, and App\Service\SectionRegistry still knows no block type by
 * name.
 *
 * The order is the order the CMS shows: the page builder's block picker
 * follows it, within each block's category
 * (App\Service\SectionRegistry::availableDefinitionsForPage(), grouped by
 * App\Service\Blocks\BlockCategories). Core's blocks come first, then the
 * modules' in registration order.
 *
 * Registration is EXPLICIT and closed on purpose. There is no directory
 * scanning, no reflection over class names and no database-defined class:
 * `section_type` arrives from requests, and the only thing it may ever do is
 * hit or miss a key of the merged array. A miss is rejected — it can never
 * become a class name or a table name.
 *
 * A DISABLED MODULE'S BLOCK IS NOT AN UNKNOWN BLOCK. Its rows stay in
 * `page_sections` and nothing deletes them, but the type is not registered
 * while the module is off, so has() says no and every write path refuses it.
 * moduleOwnerOf() is how the page builder and the render path tell that case
 * apart from a genuinely unrecognised type and say so in the right words —
 * "belongs to a module that is switched off", not "this data is broken".
 */
final class BlockDefinitions
{
    /** @var array<string, class-string<BlockDefinition>> */
    private const CORE_MAP = [
        'homepage_hero' => HomepageHeroBlock::class,
        'page_hero' => PageHeroBlock::class,
        'rich_text' => RichTextBlock::class,
        'cta_band' => CtaBandBlock::class,
        'feature_grid' => FeatureGridBlock::class,
        'faq' => FaqBlock::class,
        'stat_strip' => StatStripBlock::class,
        'step_list' => StepListBlock::class,
        'text_image_split' => TextImageSplitBlock::class,
        'marquee' => MarqueeBlock::class,
        'form' => FormBlock::class,
        'contact_form' => ContactFormBlock::class,
        'contact_card' => ContactCardBlock::class,
        'detail_section' => DetailSectionBlock::class,
        'card_carousel' => CardCarouselBlock::class,
        'item_gallery' => ItemGalleryBlock::class,
        // Fixed blocks — content a page template used to hardcode, now
        // positioned like any other block but never added or deleted by hand.
        'quicknav' => QuicknavBlock::class,
    ];

    /** @var array<string, class-string<BlockDefinition>>|null Core's plus the enabled modules' */
    private static ?array $map = null;

    /**
     * Definitions are stateless, so one instance per type per request is
     * enough — and instantiating lazily keeps a page that renders three block
     * types from loading eighteen partials.
     *
     * @var array<string, BlockDefinition>
     */
    private static array $instances = [];

    /**
     * @return array<string, class-string<BlockDefinition>>
     */
    private static function map(): array
    {
        // "+" rather than array_merge(): a module cannot replace a Core block
        // by registering its type key.
        return self::$map ??= self::CORE_MAP + ModuleRegistry::collectMap('blockDefinitions');
    }

    /** Forgets the merged list; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$map = null;
        self::$instances = [];
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::map());
    }

    /**
     * The definition for one type, or null when the key is not registered —
     * the single lookup every caller goes through, so an unknown
     * `section_type` fails the same way everywhere.
     */
    public static function get(string $type): ?BlockDefinition
    {
        if (!array_key_exists($type, self::map())) {
            return null;
        }

        if (!isset(self::$instances[$type])) {
            $class = self::map()[$type];
            self::$instances[$type] = new $class();
        }

        return self::$instances[$type];
    }

    /**
     * Every registered definition, in registration order.
     *
     * @return array<string, BlockDefinition>
     */
    public static function all(): array
    {
        $definitions = [];
        foreach (array_keys(self::map()) as $type) {
            $definitions[$type] = self::get($type);
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::map());
    }

    /**
     * The module that owns this block type, whether or not it is enabled, or
     * null for a Core block and for a type nothing declares. Callers use it to
     * explain an unregistered type, never to reach behaviour: a disabled
     * module's block stays unrenderable and unwritable.
     */
    public static function moduleOwnerOf(string $type): ?string
    {
        return ModuleRegistry::ownerOf('blockDefinitions', $type);
    }
}
