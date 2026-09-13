<?php

namespace App\Service;

use App\Repository\StepListRepository;

/**
 * Content for the "Step list" section (`.process` > `.process-step`) — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #5. Same repeater architecture as
 * App\Service\FaqContent (read that class's docblock first — this mirrors it
 * item for item).
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) step list blocks that predate the page builder,
 * kept for their admin-facing labels. Adding a step list to a page never
 * needs a schema change.
 *
 * There is no hardcoded fallback copy. A missing row, or a lookup that fails,
 * is STATE_FALLBACK: there is nothing to render, and a failure is logged. See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * `is_active = false` on an *existing* section row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own heading + steps) and STATE_HIDDEN (row exists and is_active = false —
 * render nothing for this section).
 *
 * Once a section's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 items), even if that list is empty — an
 * individually hidden/deleted step stays hidden.
 *
 * Step numbers ("1", "2", ...) are deliberately NOT a content field: the
 * frontend numbers steps purely from their display order via a CSS counter
 * (`.process{ counter-reset: step; }` in assets/css/core.css), so
 * reordering/adding/removing steps in the admin renumbers them automatically.
 */
class StepListContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own heading + items. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) step list sections and their
     * admin-facing labels — the "Pages" list in admin/pages.php. Keyed by
     * "page_slug:section_key".
     */
    public const SECTIONS = [
        'index:werkwijze' => [
            'page_slug' => 'index',
            'section_key' => 'werkwijze',
            'page_label' => 'Homepage',
            'section_label' => 'Werkwijze (stappenlijst)',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, and
     *                                'items': list of
     *                                title_nl/en/body_nl/en. Templates must
     *                                only render the section when 'state'
     *                                === STATE_ACTIVE; the content fields
     *                                are still present (empty) otherwise,
     *                                purely so a template that forgets the
     *                                check fails safe instead of erroring on
     *                                a missing key.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new StepListRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[StepListContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

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
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);

        try {
            $items = $repository->findItemsBySectionId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[StepListContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a section row that
        // exists and is active means the admin has deliberately curated its
        // steps, so an empty result is "all steps hidden/deleted", not
        // "missing data".
        $content['items'] = array_map(static function (array $item): array {
            $titleNl = (string) $item['title_nl'];
            $bodyNl = (string) $item['body_nl'];

            return [
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
        return ['eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '', 'items' => []];
    }
}
