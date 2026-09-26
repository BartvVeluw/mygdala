<?php

namespace App\Service;

use App\Repository\RichTextRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\RequestLanguage;

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
 * THE BODY PER LANGUAGE (Multilingual 2.0 phase 3A). The body is stored per
 * website language in block_translations and read through
 * App\Service\Blocks\BlockLocalization, which also owns the fallback (the
 * asked-for language, then the default language) and sanitizes the HTML on
 * the way out — the same "sanitize on write, sanitize again on read" pattern
 * this block always followed. What this class hands the partial is one
 * string: the sanitized body in the language of the request. It decides no
 * language itself.
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

    /** The body, a translatable field of this block (RichTextBlock::translatableFields()). */
    public const BODY = 'body';

    /** The optional button's label, the other translatable field. */
    public const BUTTON_LABEL = 'button_label';

    /**
     * How the text and the button are aligned: a closed list, stored in
     * rich_text_sections.text_align. The first is the default and adds no
     * class, so a left-aligned block is the markup it always was.
     */
    public const ALIGNMENTS = [
        'left' => 'Links',
        'center' => 'Midden',
        'right' => 'Rechts',
    ];

    /**
     * How wide the text runs, a closed list: 'medium' is the narrow reading
     * column (--container-narrow) every text block had before this choice
     * existed, and stays the default, so an existing block adds no class and
     * looks as it did; 'large' is the site's normal content width
     * (--container), the one every other wide block uses. Value => the CMS
     * label.
     */
    public const WIDTHS = [
        'medium' => 'Medium',
        'large' => 'Breed',
    ];

    private const TABLE = 'rich_text_sections';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @param string|null $language the language of the body; the request's when
     *        null. Named explicitly only by a caller that needs one fixed
     *        language whatever is being read — the terms-and-conditions hash
     *        (App\Service\LegalPages::termsContent()).
     *
     * @return array{state: string, body: string, align: string, width: string, button_label: string, button_href: string}
     *         templates must check 'state' !== STATE_HIDDEN before rendering
     *         the section. 'body' is sanitized HTML in that language, empty
     *         when there is none; 'align' a key of ALIGNMENTS, 'width' one of WIDTHS; the button
     *         is there only when both its label and its href are.
     */
    public static function forSection(string $pageSlug, string $sectionKey, ?string $language = null): array
    {
        $language ??= RequestLanguage::current();
        $cacheKey = $language . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new RichTextRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[RichTextContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_FALLBACK);
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_FALLBACK);
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_HIDDEN);
        }

        // THE DEFAULT LANGUAGE DECIDES WHETHER THERE IS A BODY
        // (docs/multilingual/ARCHITECTURE.md): a block whose body exists only
        // as a translation shows nothing in any language.
        // The same rule for the button's label: a label that exists only as a
        // translation is no button.
        $bodyId = (int) $row['id'];
        $align = (string) ($row['text_align'] ?? '');
        $width = (string) ($row['content_width'] ?? '');
        $label = BlockLocalization::hasDefaultWords(self::TABLE, $bodyId, self::BUTTON_LABEL)
            ? BlockLocalization::value(self::TABLE, $bodyId, self::BUTTON_LABEL, $language)
            : '';
        $href = $label !== ''
            ? LinkChoice::href($row['button_link_type'] ?? null, $row['button_link_target_id'] ?? 0, (string) ($row['button_url'] ?? ''))
            : '';

        return self::$cache[$cacheKey] = [
            'state' => self::STATE_ACTIVE,
            self::BODY => BlockLocalization::hasDefaultWords(self::TABLE, $bodyId, self::BODY)
                ? BlockLocalization::value(self::TABLE, $bodyId, self::BODY, $language)
                : '',
            'align' => array_key_exists($align, self::ALIGNMENTS) ? $align : (string) array_key_first(self::ALIGNMENTS),
            'width' => self::width($width),
            self::BUTTON_LABEL => $href !== '' ? $label : '',
            'button_href' => $label !== '' ? $href : '',
        ];
    }

    /** Also drops the block words BlockLocalization holds for this request. */
    /** A stored width, or the default for anything this class does not know. */
    public static function width(string $stored): string
    {
        return array_key_exists($stored, self::WIDTHS) ? $stored : (string) array_key_first(self::WIDTHS);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /** @return array{state: string, body: string, align: string, width: string, button_label: string, button_href: string} */
    private static function emptyContent(string $state): array
    {
        return ['state' => $state, self::BODY => '', 'align' => 'left', 'width' => self::width(''), self::BUTTON_LABEL => '', 'button_href' => ''];
    }
}
