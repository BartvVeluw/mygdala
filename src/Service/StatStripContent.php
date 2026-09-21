<?php

namespace App\Service;

use App\Repository\StatStripRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Routing\RequestLanguage;

/**
 * Content for the "Stat strip" section (`.stat-strip` > `.stat`) — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #10. Currently only used once:
 * index.php's Capability band (the dark `bg-forest` band with 4 stats,
 * right after the Services carousel).
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) strips that currently exist on the site — see
 * FeatureGridContent's docblock for why section_key exists alongside
 * page_slug even for a single-usage type.
 *
 * The current markup has no section-level heading — a stat strip section
 * only ever carries visibility, never eyebrow/title/lead fields (unlike
 * FeatureGridContent, whose over-mij usage does have a heading).
 *
 * There is no hardcoded fallback copy. A missing row, or a lookup that fails,
 * is STATE_FALLBACK: there is nothing to render, and a failure is logged. See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The number and caption of
 * every stat are stored per website language in block_translations, each
 * stat's on its own row (StatStripBlock::childTables()); the strip itself has
 * no words. They come out of App\Service\Blocks\BlockLocalization as one
 * string per field, in the language of the request, the fallback already
 * applied; is_active and the order stay in the tables. This class decides no
 * language itself.
 *
 * `is_active = false` on an *existing* strip row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned 'state' field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own stats) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 *
 * Once a strip's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 stats), even if that list is empty — an
 * individually hidden/deleted stat stays hidden. A stat without its number
 * and caption in the default language is not there either: the default
 * language decides whether a stat shows, as it does for a block.
 */
class StatStripContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own stats. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) strips and their admin-facing labels —
     * the "Pages" list in admin/pages.php. Keyed by "page_slug:section_key".
     */
    public const SECTIONS = [
        'index:capability-band' => [
            'page_slug' => 'index',
            'section_key' => 'capability-band',
            'page_label' => 'Homepage',
            'section_label' => 'Stat strip: Capability band',
        ],
    ];

    /** The owner tables of this block's words (StatStripBlock::translatableFields()). */
    private const TABLE = 'stat_strips';
    private const ITEMS = 'stat_strip_items';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*) and 'items': a
     *                                list of primary_text and secondary_text
     *                                (a string each). Templates must
     *                                only render the section when 'state'
     *                                === STATE_ACTIVE.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new StatStripRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[StatStripContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['items' => [], 'state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['items' => [], 'state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: 'items' is still there (empty) purely so
            // a template that forgets to check 'state' fails safe instead of
            // erroring on a missing key.
            return self::$cache[$cacheKey] = ['items' => [], 'state' => self::STATE_HIDDEN];
        }

        $stripId = (int) $row['id'];

        try {
            $rows = $repository->findItemsByStripId($stripId, true);
        } catch (\Throwable $e) {
            error_log('[StatStripContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['items' => [], 'state' => self::STATE_FALLBACK];
        }

        // The words of every stat in the strip at once; nothing when the page
        // already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$stripId]]);

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a strip row that
        // exists and is active means the admin has deliberately curated its
        // stats, so an empty result is "all stats hidden/deleted", not
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
