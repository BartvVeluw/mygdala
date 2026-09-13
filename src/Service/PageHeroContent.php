<?php

namespace App\Service;

use App\Repository\PageHeroRepository;

/**
 * Content for the "Page hero" section — the eyebrow/H1/lead/breadcrumb block
 * repeated identically (same markup/CSS) at the top of several pages. See
 * docs/CMS_CONTENT_AUDIT.md, "Recommended smallest next step", and
 * App\Service\SiteSettings for the equivalent pattern this mirrors.
 *
 * PAGES below is the fixed, known list of pages that had this section before
 * the page builder existed; any other page gets one by attaching the block
 * (App\Service\Blocks\PageHeroBlock::create()). Never a schema change. An
 * empty *_en value on an active row falls back to the *_nl value, matching
 * the NL-fallback convention already used elsewhere on this site (e.g.
 * products).
 *
 * There is no hardcoded fallback copy, per page or per field. A missing row,
 * or a lookup that fails, is STATE_FALLBACK: there is nothing to render, and a
 * failure is logged. An active row renders exactly what it stores; the editor
 * requires eyebrow, title and breadcrumb label, so an empty one only comes
 * from data written outside it. See CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * startingValues() is a different thing: what a Page hero that does not exist
 * yet starts out with in the editor and in PageHeroBlock::create(). Generic
 * and meant to be edited, and never rendered in place of a stored row.
 *
 * `is_active = false` on an *existing* row is a deliberate hide, and a
 * different case from a missing row. forSlug()'s returned `state` field is how
 * a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * content) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 */
class PageHeroContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known page slugs and their admin-facing label — the "Pages" list in
     * admin/pages.php. Only pages in this list have an editable Page Hero.
     */
    public const PAGES = [
        'diensten' => 'Diensten',
        'portfolio' => 'Portfolio',
        'over-mij' => 'Over mij',
        'contact' => 'Contact',
        'shop' => 'Shop',
    ];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @return array<string, string> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, lead_nl/en,
     *                                breadcrumb_label_nl/en — lead_* may be
     *                                ''. Templates must only render the
     *                                section when 'state' === STATE_ACTIVE;
     *                                the content fields are still present
     *                                (empty) otherwise, purely so a template
     *                                that forgets the check fails safe
     *                                instead of erroring on a missing key.
     */
    public static function forSlug(string $pageSlug): array
    {
        if (isset(self::$cache[$pageSlug])) {
            return self::$cache[$pageSlug];
        }

        $row = null;
        try {
            $row = (new PageHeroRepository())->findBySlug($pageSlug);
        } catch (\Throwable $e) {
            error_log('[PageHeroContent] lookup failed for "' . $pageSlug . '": ' . $e->getMessage());
        }

        if ($row === null) {
            return self::$cache[$pageSlug] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: the content fields are still filled in
            // (empty) purely so a template that forgets to check 'state'
            // fails safe instead of erroring on a missing key.
            return self::$cache[$pageSlug] = self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $content = [
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'breadcrumb_label_nl' => (string) ($row['breadcrumb_label_nl'] ?? ''),
        ];

        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);
        $content['breadcrumb_label_en'] = self::valueOrDefault($row['breadcrumb_label_en'] ?? null, $content['breadcrumb_label_nl']);
        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$pageSlug] = $content;
    }

    /**
     * What a Page hero that does not exist yet starts out with: the editor's
     * form for a page without a row (admin/page-hero.php) and the row
     * PageHeroBlock::create() writes. Generic, editable copy that fills the
     * three fields the editor requires — never rendered in place of a stored
     * row, which forSlug() answers with nothing when it is missing.
     *
     * @return array<string, string>
     */
    public static function startingValues(string $pageLabel): array
    {
        return [
            'eyebrow_nl' => 'Nieuw',
            'eyebrow_en' => '',
            'title_nl' => 'Nieuwe sectie — pas deze titel aan',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'breadcrumb_label_nl' => $pageLabel,
            'breadcrumb_label_en' => '',
        ];
    }

    /**
     * Clears the in-process cache — used by the admin save handler right
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
     * @return array<string, string>
     */
    private static function emptyContent(): array
    {
        return [
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'breadcrumb_label_nl' => '', 'breadcrumb_label_en' => '',
        ];
    }
}
