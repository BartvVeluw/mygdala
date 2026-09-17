<?php

namespace App\Service;

use App\Repository\FaqRepository;
use App\Service\Blocks\BlockLocalization;

/**
 * Content for the "FAQ list" section (`.faq-list` > `.faq-item` /
 * `<details>`) — see docs/CMS_CONTENT_AUDIT.md, proposed type #9. Same
 * repeater architecture as App\Service\FeatureGridContent (read that
 * class's docblock first — this mirrors it item for item).
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) FAQ blocks that predate the page builder, kept
 * for their admin-facing labels. Adding a FAQ block to a page never needs a
 * schema change (section_key is what lets a page have more than one FAQ list
 * without a schema rewrite).
 *
 * There is no hardcoded fallback copy. A missing row, or a lookup that fails,
 * is STATE_FALLBACK: there is nothing to render, and a failure is logged. See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The heading and every
 * question and answer are stored per website language in block_translations:
 * the section's words on its own row, each item's on the item's row
 * (FaqBlock::childTables()). They come out of
 * App\Service\Blocks\BlockLocalization as one LocalizedValue per field, the
 * fallback already applied; is_active and the order stay in the tables. This
 * class decides no language itself.
 *
 * `is_active = false` on an *existing* section row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own heading + questions) and STATE_HIDDEN (row exists and is_active = false
 * — render nothing for this section).
 *
 * Once a section's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 items), even if that list is empty — an
 * individually hidden/deleted item stays hidden. An item without its question
 * and answer in the default language is not there either: the default
 * language decides whether an item shows, as it does for the block.
 */
class FaqContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own heading + items. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) FAQ sections and their admin-facing
     * labels — the "Pages" list in admin/pages.php. Keyed by
     * "page_slug:section_key".
     */
    public const SECTIONS = [
        'diensten:faq' => [
            'page_slug' => 'diensten',
            'section_key' => 'faq',
            'page_label' => 'Diensten',
            'section_label' => 'FAQ: Veelgestelde vragen',
        ],
    ];

    /** The owner tables of this block's words (FaqBlock::translatableFields()). */
    private const TABLE = 'faq_sections';
    private const ITEMS = 'faq_items';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus eyebrow and
     *                                title (a LocalizedValue each), and
     *                                'items': a list of question and answer
     *                                (a LocalizedValue each). Templates must
     *                                only render the section when 'state' ===
     *                                STATE_ACTIVE; the content fields are
     *                                still present (empty) otherwise, purely
     *                                so a template that forgets the check
     *                                fails safe instead of erroring on a
     *                                missing key.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new FaqRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[FaqContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: the content fields are still filled in
            // (empty) purely so a template that forgets to check 'state'
            // fails safe instead of erroring on a missing key.
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $sectionId = (int) $row['id'];

        try {
            $items = $repository->findItemsBySectionId($sectionId, true);
        } catch (\Throwable $e) {
            error_log('[FaqContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // The words of the section and all of its questions at once; nothing
        // when the page already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$sectionId]]);

        $content = BlockLocalization::words(self::TABLE, $sectionId);

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a section row that
        // exists and is active means the admin has deliberately curated its
        // questions, so an empty result is "all questions hidden/deleted",
        // not "missing data".
        $content['items'] = [];
        foreach ($items as $item) {
            $itemId = (int) $item['id'];

            if (BlockLocalization::hasRequiredWords(self::ITEMS, $itemId)) {
                $content['items'][] = BlockLocalization::words(self::ITEMS, $itemId);
            }
        }

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
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

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return BlockLocalization::words(self::TABLE, 0) + ['items' => []];
    }
}
