<?php

namespace App\Service;

use App\Repository\CtaBandRepository;

/**
 * Content for the "CTA band" block (`.cta-band.cta-band--card`) — the
 * eyebrow/H2/lead/button(s) block that closes several pages.
 *
 * ONE standard reusable block type (phase 2 of
 * docs/content-blocks/ROADMAP.md): every instance is addressed by
 * (page_slug, section_key) and owns its own content, so two CTA bands on the
 * same page are two independent blocks rather than two views of one row —
 * see db/migrations/20260908270000_make_cta_bands_repeatable_per_instance.php.
 * There is no list of "known" CTA pages any more, and no hardcoded per-page
 * copy: the content of every instance lives in the database, where the
 * migration that first created this table already put it.
 *
 * The secondary button is fully optional: secondary_label_nl === '' means
 * "no second button" and the frontend must not render one — it must never be
 * forced.
 *
 * `is_active = false` on an *existing* row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field
 * is how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — there is nothing to render), STATE_ACTIVE (row is active —
 * render its content) and STATE_HIDDEN (row exists and is_active = false —
 * render nothing for this block).
 */
class CtaBandContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * The section_key every CTA band that predates the repeatable schema
     * uses. Instances added afterwards get a random custom-xxxxxxxx key like
     * every other repeater type (App\Service\SectionRegistry::create()).
     * Must stay in sync with the migration named above.
     */
    public const MIGRATED_SECTION_KEY = 'main';

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @return array<string, string> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, lead_nl/en,
     *                                primary_label_nl/en, primary_url,
     *                                secondary_label_nl/en, secondary_url —
     *                                lead_* and secondary_* may be ''.
     *                                Templates must check 'state' !==
     *                                STATE_HIDDEN before rendering the block
     *                                at all.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new CtaBandRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[CtaBandContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        return self::$cache[$cacheKey] = self::fromRow($row);
    }

    /**
     * This page's FIRST CTA band, whichever instance that is — for a
     * non-CMS template that deliberately borrows a page's CTA band
     * (portfolio-detail.php reuses Portfolio's) and must not hardcode a
     * section_key to do it.
     *
     * @return array<string, string> same shape as forSection()
     */
    public static function firstOnPage(string $pageSlug): array
    {
        $cacheKey = $pageSlug . ':#first';
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new CtaBandRepository())->findFirstBySlug($pageSlug);
        } catch (\Throwable $e) {
            error_log('[CtaBandContent] first-instance lookup failed for "' . $pageSlug . '": ' . $e->getMessage());
            $row = null;
        }

        return self::$cache[$cacheKey] = self::fromRow($row);
    }

    /**
     * Clears the in-process cache — used by the admin save handler right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, string>
     */
    private static function fromRow(?array $row): array
    {
        if ($row === null) {
            return self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: the content fields are still filled in
            // (empty) purely so a template that forgets to check 'state'
            // fails safe instead of erroring on a missing key.
            return self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $content = [
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'primary_label_nl' => (string) ($row['primary_label_nl'] ?? ''),
            'primary_url' => (string) ($row['primary_url'] ?? ''),
            'secondary_label_nl' => (string) ($row['secondary_label_nl'] ?? ''),
            'secondary_url' => (string) ($row['secondary_url'] ?? ''),
        ];

        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);
        $content['primary_label_en'] = self::valueOrDefault($row['primary_label_en'] ?? null, $content['primary_label_nl']);
        $content['secondary_label_en'] = self::valueOrDefault($row['secondary_label_en'] ?? null, $content['secondary_label_nl']);

        // A secondary button only renders when it has both a label and a
        // URL — a half-filled optional button would be broken/dead.
        if ($content['secondary_label_nl'] === '' || $content['secondary_url'] === '') {
            $content['secondary_label_nl'] = '';
            $content['secondary_label_en'] = '';
            $content['secondary_url'] = '';
        }

        $content['state'] = self::STATE_ACTIVE;

        return $content;
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }

    /**
     * @return array<string, string>
     */
    private static function emptyContent(): array
    {
        return [
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'primary_label_nl' => '', 'primary_label_en' => '', 'primary_url' => '',
            'secondary_label_nl' => '', 'secondary_label_en' => '', 'secondary_url' => '',
        ];
    }
}
