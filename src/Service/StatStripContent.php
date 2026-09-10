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
 * DEFAULTS is the fallback used whenever a section's row is missing, or the
 * database is unreachable, so the public site never breaks because of a CMS
 * content problem — it silently falls back to the exact stats that used to
 * be hardcoded.
 *
 * `is_active = false` on an *existing* strip row is a different, deliberate
 * case: it means the site owner has intentionally hidden the whole section,
 * and must NOT fall back to the defaults. forSection()'s returned 'state'
 * field is how a template tells the three cases apart: STATE_FALLBACK (no
 * row / DB unreachable — render the default stats), STATE_ACTIVE (row is
 * active — render its own stats) and STATE_HIDDEN (row exists and
 * is_active = false — render nothing for this section).
 *
 * Once a strip's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 stats), even if that list is empty — an
 * individually hidden/deleted stat must stay hidden, not fall back to the
 * defaults. Defaults only apply when the whole section is missing/
 * unreachable, never per missing/hidden stat.
 */
class StatStripContent
{
    /** No row exists (or the row lookup failed) — rendering DEFAULTS. */
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

    private const DEFAULTS = [
        'index:capability-band' => [
            'items' => [
                [
                    'primary_text_nl' => 'CO₂ & MOPA',
                    'primary_text_en' => 'CO₂ & MOPA',
                    'secondary_text_nl' => 'Lasertechnologie',
                    'secondary_text_en' => 'Laser technology',
                ],
                [
                    'primary_text_nl' => 'Hout · Metaal',
                    'primary_text_en' => 'Wood · Metal',
                    'secondary_text_nl' => 'Acryl · glas op aanvraag',
                    'secondary_text_en' => 'Acrylic · glass on request',
                ],
                [
                    'primary_text_nl' => 'Particulier',
                    'primary_text_en' => 'Personal',
                    'secondary_text_nl' => '& zakelijk',
                    'secondary_text_en' => '& business',
                ],
                [
                    'primary_text_nl' => 'Nijmegen',
                    'primary_text_en' => 'Nijmegen',
                    'secondary_text_nl' => 'Werkplaats & ophalen',
                    'secondary_text_en' => 'Workshop & pickup',
                ],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*) and 'items':
     *                                list of primary_text_nl/en,
     *                                secondary_text_nl/en. Templates must
     *                                check 'state' !== STATE_HIDDEN before
     *                                rendering the section at all.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // A page-builder-attached instance not in DEFAULTS has no hardcoded
        // fallback copy — an unreachable database degrades to an empty strip
        // rather than throwing.
        $defaults = self::DEFAULTS[$cacheKey] ?? ['items' => []];

        try {
            $repository = new StatStripRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[StatStripContent] falling back to defaults for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: NOT a fallback case. The content fields
            // are filled with defaults only as a defensive fallback for a
            // template that forgets to check 'state' — see forSection() docblock.
            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_HIDDEN];
        }

        try {
            $items = $repository->findItemsByStripId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[StatStripContent] falling back to defaults for "' . $cacheKey . '" (items lookup failed): ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a strip row that
        // exists and is active means the admin has deliberately curated its
        // stats, so an empty result is "all stats hidden/deleted", not
        // "missing data", and must not fall back to the defaults above.
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
