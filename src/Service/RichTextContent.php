<?php

namespace App\Service;

use App\Repository\RichTextRepository;

/**
 * Content for the "Rich text" page-builder section — an ordinary long-form
 * body block in a narrow reading column (partials/section-rich-text.php),
 * edited with the same Quill editor every other rich-text field in this
 * project uses (admin/rich-text.php, admin/_richtext_field.php).
 *
 * This is the section type that replaced the old, separate
 * `information_pages.content_html` page system: the three legal/information
 * pages' bodies were migrated into it verbatim (see
 * db/migrations/20260908100100_create_rich_text_sections_table.php), so a
 * former information page is now just a normal CMS page carrying a Page Hero
 * and a Rich text section — composable, reorderable and extendable with any
 * other section type, instead of being locked to one fixed template.
 *
 * Unlike PageHeroContent there are no per-section DEFAULTS: this type never
 * existed as hardcoded markup, so there is no historical copy to fall back
 * to. A missing row or an unreachable database therefore yields empty
 * content, which the partial renders as nothing at all.
 *
 * The English body (`content_html_en`) is optional and was added in phase 2
 * (db/migrations/20260908270200_add_english_body_to_rich_text_sections.php),
 * exactly as the original migration anticipated, so a bilingual paragraph
 * could move into this block without losing its English copy. Empty means
 * "same as Dutch".
 *
 * `content_html` is re-sanitized on read, defensively, even though it was
 * already sanitized on save — the same "sanitize on write, sanitize again on
 * read" pattern as PortfolioGalleryContent::itemForDetailPage() and the
 * information pages this replaced.
 */
class RichTextContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * The section_key every body migrated from an information page uses.
     * Sections added afterwards get a random custom-xxxxxxxx key like every
     * other repeater type (App\Service\SectionRegistry::create()).
     */
    public const MIGRATED_SECTION_KEY = 'content';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array{state: string, content_html: string, content_html_en: string}
     *         templates must check 'state' !== STATE_HIDDEN before rendering
     *         the section. 'content_html_en' is '' when this section has no
     *         separate English body — the partial then renders the Dutch
     *         body in both languages, the "leeg = zelfde als NL" rule every
     *         other block type uses.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $empty = ['content_html' => '', 'content_html_en' => ''];

        try {
            $row = (new RichTextRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[RichTextContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + $empty;
        }

        return self::$cache[$cacheKey] = [
            'state' => self::STATE_ACTIVE,
            'content_html' => RichTextSanitizer::sanitize($row['content_html'] ?? null) ?? '',
            'content_html_en' => RichTextSanitizer::sanitize($row['content_html_en'] ?? null) ?? '',
        ];
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
