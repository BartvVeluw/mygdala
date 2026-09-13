<?php

namespace App\Service;

use App\Repository\TextImageSplitRepository;
use App\Service\Media\BlockImage;

/**
 * Content for the "Text + image split" section (`.service-detail__head`,
 * text column + image(s) column) — see docs/CMS_CONTENT_AUDIT.md, proposed
 * type #7. Same repeater architecture as App\Service\FaqContent (read that
 * class's docblock first — this mirrors it item for item), but with TWO
 * repeaters on one section instead of one: paragraphs and images.
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) Text + image split blocks that predate the page
 * builder, kept for their admin-facing labels (over-mij.php had exactly two;
 * diensten.php's `.service-detail__head` usages are the larger, unrelated
 * "Material/service detail" type with points-lists, not plain text+image, and
 * are out of scope). Adding a block to a page never needs a schema change
 * (section_key is what lets a page have more than one of these blocks without
 * a schema rewrite).
 *
 * `layout` ('image_left' | 'image_right') picks between two fixed markup
 * branches the template already has — it is the only "variant" field this
 * type has, and it is deliberately not a free layout/CSS configuration.
 *
 * Paragraphs are plain text (never raw HTML) rendered one per `<p>` by the
 * template. Whether the FIRST paragraph gets the `.lead` CSS class is a
 * presentation decision the TEMPLATE makes purely from whether the section
 * has a title (title_nl === '') — not a stored "is_lead" flag — because
 * that is exactly how the two current instances already differ (the
 * title-less "intro" section's first paragraph is `.lead`, the titled
 * "idee-naar-product" section's paragraph is plain).
 *
 * Images render via one of three fixed template branches purely based on
 * `count($images)`: exactly 1 -> `.hero__media-frame` (single framed
 * photo), exactly 2 -> `.service-detail__gallery` with the
 * `grid-template-columns:1fr 1fr` override, 3+ -> the gallery's own
 * default 3-col grid. No layout/markup info is ever stored per image.
 *
 * The button (button_label_nl/en + button_url) is fully optional and
 * all-or-nothing, same convention as CtaBandContent's secondary button: a
 * half-filled button (label without URL, or vice versa) is treated as "no
 * button", never rendered as a broken link.
 *
 * There is no hardcoded fallback copy. A missing row, or a lookup that fails,
 * is STATE_FALLBACK: there is nothing to render, and a failure is logged. See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * `is_active = false` on an *existing* section row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — nothing to render), STATE_ACTIVE (row is active — render its
 * own content) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 *
 * Once a section's row exists and is active, its paragraphs/images come
 * strictly from the database, even if that list is empty — an emptied-out
 * section stays empty.
 */
class TextImageSplitContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) sections and their admin-facing
     * labels — the "Pages" list in admin/pages.php. Keyed by
     * "page_slug:section_key".
     */
    public const SECTIONS = [
        'over-mij:intro' => [
            'page_slug' => 'over-mij',
            'section_key' => 'intro',
            'page_label' => 'Over mij',
            'section_label' => 'Tekst + afbeelding: Intro',
        ],
        'over-mij:idee-naar-product' => [
            'page_slug' => 'over-mij',
            'section_key' => 'idee-naar-product',
            'page_label' => 'Over mij',
            'section_label' => 'Tekst + afbeelding: Van idee naar product',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus layout,
     *                                eyebrow_nl/en, title_nl/en,
     *                                button_label_nl/en, button_url (all
     *                                three '' together when there is no
     *                                button), 'paragraphs': list of
     *                                content_nl/en, and 'images': list of
     *                                image_path/alt_nl/en. Templates must
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
            $repository = new TextImageSplitRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[TextImageSplitContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

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
            'layout' => in_array($row['layout'] ?? null, ['image_left', 'image_right'], true) ? $row['layout'] : 'image_right',
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'button_label_nl' => (string) ($row['button_label_nl'] ?? ''),
            'button_url' => (string) ($row['button_url'] ?? ''),
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['button_label_en'] = self::valueOrDefault($row['button_label_en'] ?? null, $content['button_label_nl']);

        // A button only renders when it has both a label and a URL — a
        // half-filled optional button would be broken/dead.
        if ($content['button_label_nl'] === '' || $content['button_url'] === '') {
            $content['button_label_nl'] = '';
            $content['button_label_en'] = '';
            $content['button_url'] = '';
        }

        try {
            $paragraphs = $repository->findParagraphsBySectionId((int) $row['id']);
            $images = $repository->findImagesBySectionId((int) $row['id']);
        } catch (\Throwable $e) {
            error_log('[TextImageSplitContent] paragraphs/images lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // Whatever comes back — including an empty list — is authoritative
        // once the section row exists and is active: the admin has
        // deliberately curated this content, so an empty result means "all
        // paragraphs/images removed", not "missing data".
        $content['paragraphs'] = array_map(static function (array $paragraph): array {
            $contentNl = (string) $paragraph['content_nl'];

            return [
                'content_nl' => $contentNl,
                'content_en' => self::valueOrDefault($paragraph['content_en'] ?? null, $contentNl),
            ];
        }, $paragraphs);

        // Media Library first, the row's own image_path second, and the
        // block's own alt text over the media item's default — all of that
        // lives in App\Service\Media\BlockImage so the integrated blocks
        // share one answer. It also returns width/height, null whenever the
        // library does not know them.
        $content['images'] = array_map(
            static fn (array $image): array => BlockImage::fromRow($image),
            $images
        );

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
     * Every field forSection() returns, empty. `layout` keeps its structural
     * value so a template reading it still gets one of the two branches.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return [
            'layout' => 'image_right',
            'eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '',
            'button_label_nl' => '', 'button_label_en' => '', 'button_url' => '',
            'paragraphs' => [], 'images' => [],
        ];
    }
}
