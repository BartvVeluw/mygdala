<?php

namespace App\Service;

use App\Repository\FeatureGridRepository;
use App\Service\Blocks\BlockLocalization;

/**
 * Content for the "Feature grid" section (`.feature-grid` > `.feature-card`)
 * — the site's first repeater-based CMS section type. See
 * docs/CMS_CONTENT_AUDIT.md, proposed type #3, and MAIN.MD for the exact
 * differences found between the two current usages (homepage value props,
 * over-mij "Mijn stijl") before this schema was chosen.
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) grids that predate the page builder, kept for
 * their admin-facing labels. Adding a grid to a page never needs a schema
 * change (this is exactly why section_key exists alongside page_slug: a page
 * can have more than one grid without a schema rewrite).
 *
 * ICON_KEYS is the complete, closed set of icons a card may use. The CMS
 * only ever stores one of these keys, never markup — partials/feature-icons.php
 * is the only place that maps a key to its (theme-owned, hand-authored) SVG.
 *
 * There is no hardcoded fallback copy. A missing row, or a lookup that fails,
 * is STATE_FALLBACK: there is nothing to render, and a failure is logged. See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The heading and every
 * card's title and text are stored per website language in
 * block_translations: the grid's words on its own row, each card's on the
 * card's row (FeatureGridBlock::childTables()). They come out of
 * App\Service\Blocks\BlockLocalization as one LocalizedValue per field, the
 * fallback already applied; is_active, the icon and the order stay in the
 * tables. This class decides no language itself.
 *
 * `is_active = false` on an *existing* grid row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own heading + cards) and STATE_HIDDEN (row exists and is_active = false —
 * render nothing for this section).
 *
 * Once a grid's row exists and is active, its *items* come strictly from the
 * database (only is_active = 1 cards), even if that list is empty — an
 * individually hidden/deleted card stays hidden. A card without its title
 * and text in the default language is not there either: the default language
 * decides whether a card shows, as it does for the block.
 */
class FeatureGridContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own heading + cards. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) grids and their admin-facing labels —
     * the "Pages" list in admin/pages.php. Keyed by "page_slug:section_key".
     * has_heading controls whether the admin editor shows the section-level
     * eyebrow/title/lead inputs and whether the frontend has any heading
     * markup to fill — the homepage grid has none in the current design.
     */
    public const SECTIONS = [
        'index:value-props' => [
            'page_slug' => 'index',
            'section_key' => 'value-props',
            'page_label' => 'Homepage',
            'section_label' => 'Feature grid: Waardeproposities',
            'has_heading' => false,
        ],
        'over-mij:mijn-stijl' => [
            'page_slug' => 'over-mij',
            'section_key' => 'mijn-stijl',
            'page_label' => 'Over mij',
            'section_label' => 'Feature grid: Mijn stijl',
            'has_heading' => true,
        ],
    ];

    /**
     * Controlled icon identifiers a card may use, mapped to an admin-facing
     * label. The frontend/theme (partials/feature-icons.php) maps each key
     * to its fixed SVG markup — never store raw SVG/HTML in the database.
     */
    public const ICON_KEYS = [
        'precision' => 'Precisie (kompas)',
        'heart' => 'Hart',
        'diamond' => 'Diamant',
        'location' => 'Locatie (pin)',
    ];

    /** The owner tables of this block's words (FeatureGridBlock::translatableFields()). */
    private const TABLE = 'feature_grids';
    private const ITEMS = 'feature_grid_items';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus eyebrow,
     *                                title and lead (a LocalizedValue each,
     *                                empty when the section has no heading),
     *                                and 'items': a list of icon_key plus
     *                                title and body (a LocalizedValue each).
     *                                Templates must only render the section
     *                                when 'state' === STATE_ACTIVE; the
     *                                content fields are still present
     *                                (empty) otherwise, purely so a template
     *                                that forgets the check fails safe
     *                                instead of erroring on a missing key.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new FeatureGridRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[FeatureGridContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

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

        $gridId = (int) $row['id'];

        try {
            $items = $repository->findItemsByGridId($gridId, true);
        } catch (\Throwable $e) {
            error_log('[FeatureGridContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // The words of the grid and all of its cards at once; nothing when
        // the page already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$gridId]]);

        $content = BlockLocalization::words(self::TABLE, $gridId);

        // Only is_active cards are queried above, and whatever comes back —
        // including an empty list — is authoritative: a grid row that
        // exists and is active means the admin has deliberately curated its
        // cards, so an empty result is "all cards hidden/deleted", not
        // "missing data".
        $content['items'] = [];
        foreach ($items as $item) {
            $itemId = (int) $item['id'];

            if (!BlockLocalization::hasRequiredWords(self::ITEMS, $itemId)) {
                continue;
            }

            $iconKey = (string) $item['icon_key'];

            $content['items'][] = [
                'icon_key' => array_key_exists($iconKey, self::ICON_KEYS) ? $iconKey : array_key_first(self::ICON_KEYS),
            ] + BlockLocalization::words(self::ITEMS, $itemId);
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
