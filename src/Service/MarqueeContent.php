<?php

namespace App\Service;

use App\Repository\MarqueeRepository;
use App\Service\Blocks\BlockLocalization;

/**
 * Content for the "Marquee" section (`.marquee` > `.marquee__track`) — the
 * homepage's scrolling materialenband. See docs/CMS_CONTENT_AUDIT.md's
 * "Content trapped in JS" note: this used to be the MARQUEE_ITEMS array in
 * assets/js/blocks/marquee.js, invisible to any page-scraping or markup-based content
 * model. It is now CMS-managed the same way as the other repeater section
 * types (Feature grid/FAQ/Stat strip/Step list): PHP renders the items into
 * the page, and assets/js/blocks/marquee.js's initMarquee() only handles behaviour
 * (duplicating/padding the rendered items for the seamless scroll loop),
 * never the content itself.
 *
 * An ordinary repeatable block (phase 2 of docs/content-blocks/ROADMAP.md):
 * every instance is addressed by (page_slug, section_key), owns its own
 * items, and is created/deleted through App\Service\SectionRegistry like any
 * other repeater type. There is no hardcoded list of "known" marquees and no
 * hardcoded page:key path any more — the homepage's materialenband is simply
 * the first instance that ever existed, and a second marquee on the same or
 * any other page behaves identically.
 *
 * The current markup has no section-level heading — a marquee section only
 * ever carries visibility, never eyebrow/title/lead fields. Presentation
 * concerns (scroll speed, the "\2726" separator glyph, the loop animation)
 * stay entirely in assets/css/blocks/marquee.css and assets/js/blocks/marquee.js; only the item
 * labels are CMS content.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). Every item's label is
 * stored per website language in block_translations, on the item's own row
 * (MarqueeBlock::childTables()); the section itself has no words. It comes
 * out of App\Service\Blocks\BlockLocalization as one LocalizedValue, the
 * fallback already applied; is_active and the order stay in the tables. This
 * class decides no language itself.
 *
 * `is_active = false` on an *existing* section is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned 'state' field
 * is how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own items) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 *
 * Once a section's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 items), even if that list is empty — an
 * individually hidden/deleted item must stay hidden. An item without its
 * label in the default language is not there either: the default language
 * decides whether an item shows, as it does for a block.
 */
class MarqueeContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own items. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /** The owner tables of this block's words (MarqueeBlock::translatableFields()). */
    private const TABLE = 'marquee_sections';
    private const ITEMS = 'marquee_items';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*) and 'items': a
     *                                list of label (a LocalizedValue each).
     *                                Templates must check 'state' !==
     *                                STATE_HIDDEN before rendering the
     *                                section at all.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // A block type that no longer carries hardcoded copy: a missing row
        // or an unreachable database degrades to an empty marquee rather
        // than throwing (the partial then renders nothing).
        $empty = ['items' => []];

        try {
            $repository = new MarqueeRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[MarqueeContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = $empty + ['state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = $empty + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = $empty + ['state' => self::STATE_HIDDEN];
        }

        $sectionId = (int) $row['id'];

        try {
            $rows = $repository->findItemsBySectionId($sectionId, true);
        } catch (\Throwable $e) {
            error_log('[MarqueeContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = $empty + ['state' => self::STATE_FALLBACK];
        }

        // The words of every item in the section at once; nothing when the
        // page already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$sectionId]]);

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a section row that
        // exists and is active means the admin has deliberately curated its
        // items, so an empty result is "all items hidden/deleted", not
        // "missing data".
        $items = [];
        foreach ($rows as $item) {
            $itemId = (int) $item['id'];

            if (BlockLocalization::hasRequiredWords(self::ITEMS, $itemId)) {
                $items[] = BlockLocalization::words(self::ITEMS, $itemId);
            }
        }

        return self::$cache[$cacheKey] = [
            'items' => $items,
            'state' => self::STATE_ACTIVE,
        ];
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handlers right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }
}
