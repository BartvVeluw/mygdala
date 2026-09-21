<?php

namespace App\Service;

use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\BlockImage;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\TypedLink;

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
 * has a title — not a stored "is_lead" flag — because that is exactly how
 * the two current instances already differ (the title-less "intro" section's
 * first paragraph is `.lead`, the titled "idee-naar-product" section's
 * paragraph is plain).
 *
 * Images render via one of three fixed template branches purely based on
 * `count($images)`: exactly 1 -> `.hero__media-frame` (single framed
 * photo), exactly 2 -> `.service-detail__gallery` with the
 * `grid-template-columns:1fr 1fr` override, 3+ -> the gallery's own
 * default 3-col grid. No layout/markup info is ever stored per image.
 *
 * The button (button_label + button_url) is fully optional and
 * all-or-nothing, same convention as CtaBandContent's secondary button: a
 * half-filled button (label without URL, or vice versa) is treated as "no
 * button", never rendered as a broken link. The label that counts is the
 * default language's, the one every other language falls back to.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The eyebrow, title and
 * button label, the text of every paragraph and the alt text of every image
 * are stored per website language in block_translations: the section's words
 * on its own row, each paragraph's and each image's on that row
 * (TextImageSplitBlock::childTables()). They come out of
 * App\Service\Blocks\BlockLocalization as one string per field, in the
 * language of the request, the fallback already applied; the layout, the
 * button URL, the media and the order stay in the tables. An image's alt text
 * is layered over the media item's own by BlockImage::fromOwner(). This class
 * decides no language itself.
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
 * section stays empty. A paragraph without its text in the default language
 * is not there either: the default language decides whether a paragraph
 * shows, as it does for the block.
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

    /** The owner tables of this block's words (TextImageSplitBlock::translatableFields()). */
    private const TABLE = 'text_image_splits';
    private const PARAGRAPHS = 'text_image_split_paragraphs';
    private const IMAGES = 'text_image_split_images';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus layout,
     *                                eyebrow, title and button_label (a
     *                                string each), button_url (the
     *                                label empty and the URL '' together
     *                                when there is no button), 'paragraphs':
     *                                a list of content (a string),
     *                                and 'images': a list of image_path, alt
     *                                (a string), width, height and
     *                                media_id. Templates must only render the
     *                                section when 'state' === STATE_ACTIVE;
     *                                the content fields are still present
     *                                (empty) otherwise, purely so a template
     *                                that forgets the check fails safe
     *                                instead of erroring on a missing key.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;

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

        $sectionId = (int) $row['id'];

        // The words of the section, its paragraphs and its images at once;
        // nothing when the page already loaded them
        // (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$sectionId]]);

        $content = [
            'layout' => in_array($row['layout'] ?? null, ['image_left', 'image_right'], true) ? $row['layout'] : 'image_right',
        ] + BlockLocalization::words(self::TABLE, $sectionId) + [
            'button_url' => TypedLink::href((string) ($row['button_url'] ?? '')),
        ];

        // A button only renders when it has both a label in the default
        // language and a URL — a half-filled optional button would be
        // broken/dead.
        if (BlockLocalization::raw(self::TABLE, $sectionId, 'button_label', BlockLocalization::defaultLanguage()) === ''
            || $content['button_url'] === ''
        ) {
            $content['button_label'] = '';
            $content['button_url'] = '';
        }

        try {
            $paragraphs = $repository->findParagraphsBySectionId($sectionId);
            $images = $repository->findImagesBySectionId($sectionId);
        } catch (\Throwable $e) {
            error_log('[TextImageSplitContent] paragraphs/images lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // Whatever comes back — including an empty list — is authoritative
        // once the section row exists and is active: the admin has
        // deliberately curated this content, so an empty result means "all
        // paragraphs/images removed", not "missing data".
        $content['paragraphs'] = [];
        foreach ($paragraphs as $paragraph) {
            $paragraphId = (int) $paragraph['id'];

            if (BlockLocalization::hasRequiredWords(self::PARAGRAPHS, $paragraphId)) {
                $content['paragraphs'][] = BlockLocalization::words(self::PARAGRAPHS, $paragraphId);
            }
        }

        // Media Library first, the row's own image_path second, and the
        // image's own alt text (per language) over the media item's default
        // — all of that lives in App\Service\Media\BlockImage so the
        // integrated blocks share one answer. It also returns width/height,
        // null whenever the library does not know them.
        $content['images'] = array_map(
            static fn (array $image): array => BlockImage::fromOwner(
                $image,
                BlockLocalization::text(self::IMAGES, (int) $image['id'], 'alt')
            ),
            $images
        );

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
     * Every field forSection() returns, empty. `layout` keeps its structural
     * value so a template reading it still gets one of the two branches.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return ['layout' => 'image_right']
            + BlockLocalization::words(self::TABLE, 0)
            + ['button_url' => '', 'paragraphs' => [], 'images' => []];
    }
}
