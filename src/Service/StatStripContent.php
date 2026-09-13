<?php

namespace App\Service;

use App\Repository\StatStripRepository;

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
 * `is_active = false` on an *existing* strip row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned 'state' field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own stats) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 *
 * Once a strip's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 stats), even if that list is empty — an
 * individually hidden/deleted stat stays hidden.
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

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*) and 'items':
     *                                list of primary_text_nl/en,
     *                                secondary_text_nl/en. Templates must
     *                                only render the section when 'state'
     *                                === STATE_ACTIVE.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

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

        try {
            $items = $repository->findItemsByStripId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[StatStripContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['items' => [], 'state' => self::STATE_FALLBACK];
        }

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a strip row that
        // exists and is active means the admin has deliberately curated its
        // stats, so an empty result is "all stats hidden/deleted", not
        // "missing data".
        $items = array_map(static function (array $item): array {
            $primaryNl = (string) $item['primary_text_nl'];
            $secondaryNl = (string) $item['secondary_text_nl'];

            return [
                'primary_text_nl' => $primaryNl,
                'primary_text_en' => self::valueOrDefault($item['primary_text_en'] ?? null, $primaryNl),
                'secondary_text_nl' => $secondaryNl,
                'secondary_text_en' => self::valueOrDefault($item['secondary_text_en'] ?? null, $secondaryNl),
            ];
        }, $items);

        return self::$cache[$cacheKey] = [
            'items' => $items,
            'state' => self::STATE_ACTIVE,
        ];
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
}
