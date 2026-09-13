<?php

namespace App\Service;

use App\Repository\FeatureGridRepository;

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
 * `is_active = false` on an *existing* grid row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own heading + cards) and STATE_HIDDEN (row exists and is_active = false —
 * render nothing for this section).
 *
 * Once a grid's row exists and is active, its *items* come strictly from the
 * database (only is_active = 1 cards), even if that list is empty — an
 * individually hidden/deleted card stays hidden.
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

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, lead_nl/en
     *                                (may be '' when the section has no
     *                                heading), and 'items': list of
     *                                icon_key/title_nl/en/body_nl/en.
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

        $content = [
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);

        try {
            $items = $repository->findItemsByGridId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[FeatureGridContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // Only is_active cards are queried above, and whatever comes back —
        // including an empty list — is authoritative: a grid row that
        // exists and is active means the admin has deliberately curated its
        // cards, so an empty result is "all cards hidden/deleted", not
        // "missing data".
        $content['items'] = array_map(static function (array $item): array {
            $titleNl = (string) $item['title_nl'];
            $bodyNl = (string) $item['body_nl'];
            $iconKey = (string) $item['icon_key'];

            return [
                'icon_key' => array_key_exists($iconKey, self::ICON_KEYS) ? $iconKey : array_key_first(self::ICON_KEYS),
                'title_nl' => $titleNl,
                'title_en' => self::valueOrDefault($item['title_en'] ?? null, $titleNl),
                'body_nl' => $bodyNl,
                'body_en' => self::valueOrDefault($item['body_en'] ?? null, $bodyNl),
            ];
        }, $items);

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return ['eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '', 'lead_nl' => '', 'lead_en' => '', 'items' => []];
    }
}
