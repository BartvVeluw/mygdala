<?php

namespace App\Service;

use App\Repository\DetailSectionRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\LocalizedValue;
use App\Service\Media\BlockImage;

/**
 * Content for the "Detailsectie" page-builder block
 * (partials/section-detail-section.php) — the full-width, optionally
 * anchored content section the Diensten page is built out of: a numbered
 * heading, a lead, rich body content, an optional main image beside the text
 * (left or right), a list of "kenmerken", an optional image gallery, an
 * optional closing note and an optional CTA button.
 *
 * Phase 3 of the content-block refactor turned the four hardcoded material
 * sections (hout, metaal, acryl-glas, zakelijk — one closed, code-defined
 * set in the former App\Service\ServiceContent) into four ORDINARY instances
 * of this one type, addressed on (page_slug, section_key) like every other
 * repeatable block. Nothing in this class knows about materials, services or
 * the Diensten page; a second instance on another page is just another row.
 *
 * Two fields carry what used to be structural knowledge:
 *
 * - `anchor` — the section's `id` attribute. Filled = "this section can be
 *   linked to", which is also exactly what puts it in the page's quicknav
 *   (see navItemsForPage()). The four migrated sections kept their old
 *   service keys as anchors, so `/diensten.php#hout` and the footer
 *   "Materialen" links keep working.
 * - `nav_label` — the short label the quicknav shows for it, because a
 *   section's heading ("Hout graveren") is usually longer than the label
 *   that reads well in a nav ("Hout"). Empty = fall back to the title.
 *
 * `index_label` ("01", "02", ...) and the alternating `bg_soft` background
 * are derived from the section's POSITION among the active detail sections
 * on its page, never stored — the same "derived, not stored" treatment they
 * had before, now counted over a variable number of sections instead of a
 * fixed four.
 *
 * There are no hardcoded DEFAULTS: like every block phase 2 converted, this
 * type's content lives in the database only, so a missing row (or an
 * unreachable database) renders nothing at all rather than resurrecting copy
 * that an editor may have deliberately removed.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The section's words (nav
 * label, title, lead, rich body, main image alt text, closing note, CTA
 * label), every point's title and body, and every gallery image's alt text
 * are stored per website language in block_translations: the section's on
 * its own row, each point's and each image's on that child row
 * (DetailSectionBlock::childTables()). They come out of
 * App\Service\Blocks\BlockLocalization as one LocalizedValue per field, the
 * fallback already applied; the anchor, the image position, the CTA URL, the
 * media, the order and is_active stay in the tables. This class decides no
 * language itself, so an English-default site now gets its English words,
 * and its English body, on the first render.
 *
 * The body is rich text: BlockLocalization sanitizes it on save and again on
 * the way out — the same "sanitize on write, sanitize again on read" pattern
 * as RichTextContent.
 */
class DetailSectionContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /** The two image positions the template has markup for. */
    public const IMAGE_POSITIONS = ['image_left', 'image_right'];

    /** The owner tables of this block's words (DetailSectionBlock::translatableFields()). */
    private const TABLE = 'detail_sections';
    private const POINTS = 'detail_section_points';
    private const IMAGES = 'detail_section_images';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @var array<string, array<int, int>> page_slug => [detail_sections.id => 0-based position] */
    private static array $positions = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus id, anchor,
     *                                a LocalizedValue each for nav_label,
     *                                title, lead, body (sanitized HTML),
     *                                closing_note and cta_label,
     *                                main_image_path ('' = no main image)
     *                                with main_image_alt (a LocalizedValue,
     *                                layered over the media item's) and
     *                                main_image_width/height,
     *                                image_position, cta_url (cta_label and
     *                                cta_url both empty when there is no
     *                                CTA), 'points': a list of title and body
     *                                (a LocalizedValue each), and 'images': a
     *                                list of App\Service\Media\BlockImage::fromOwner().
     *                                Templates must check 'state' !==
     *                                STATE_HIDDEN before rendering the
     *                                section at all.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new DetailSectionRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + self::emptyContent();
        }

        $sectionId = (int) $row['id'];

        try {
            $points = $repository->findPointsBySectionId($sectionId, true);
            $images = $repository->findImagesBySectionId($sectionId);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] children lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        // The words of the section, its points and its gallery images at
        // once; nothing when the page already loaded them
        // (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$sectionId]]);

        $content = self::fromRow($row);

        // Whatever comes back — including an empty list — is authoritative:
        // an emptied-out gallery or points list stays empty. A point without
        // its title and body in the default language is not there either:
        // the default language decides whether a point shows.
        $content['points'] = [];
        foreach ($points as $point) {
            $pointId = (int) $point['id'];

            if (BlockLocalization::hasRequiredWords(self::POINTS, $pointId)) {
                $content['points'][] = BlockLocalization::words(self::POINTS, $pointId);
            }
        }

        // Media Library first, the row's own image_path second, and this
        // block's own alt text over the media item's default — all of it in
        // App\Service\Media\BlockImage, shared with the other integrated
        // blocks. width/height come along, null when the library does not
        // know them.
        $content['images'] = array_map(
            static fn (array $image): array => BlockImage::fromOwner(
                $image,
                BlockLocalization::bilingual(self::IMAGES, (int) $image['id'], 'alt')
            ),
            $images
        );

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * The section's "01"/"02"/... label and alternating soft background,
     * derived purely from its position among the ACTIVE detail sections on
     * its page (0-based). A section that is hidden, or on a page with no
     * block list at all, gets position 0.
     *
     * @return array{index_label: string, bg_soft: bool}
     */
    public static function positionMarkers(string $pageSlug, int $sectionId): array
    {
        $index = self::positionsForPage($pageSlug)[$sectionId] ?? 0;

        return [
            'index_label' => sprintf('%02d', $index + 1),
            'bg_soft' => ($index % 2) === 1,
        ];
    }

    /**
     * The quicknav's links for one page: every active, anchored detail
     * section on it, in the page's own block order. This is what replaced
     * the quicknav's hardcoded four material anchors — add, remove or
     * reorder a section and the nav follows on its own.
     *
     * The label is, per language, the section's short nav label, else its
     * title, and only then the default language's label the same way
     * (BlockLocalization::bilingualFirst()). A section without a label in the
     * default language gets no link: the default language decides whether it
     * is there, as it decides for the section itself.
     *
     * @return list<array{anchor: string, label: LocalizedValue}>
     */
    public static function navItemsForPage(string $pageSlug): array
    {
        try {
            $rows = (new DetailSectionRepository())->findActiveForPageInBlockOrder($pageSlug);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] nav lookup failed for "' . $pageSlug . '": ' . $e->getMessage());

            return [];
        }

        // The words of every section on the page in one query, not one per
        // link; nothing when the page already loaded them.
        BlockLocalization::preload([self::TABLE => array_map(static fn (array $row): int => (int) $row['id'], $rows)]);

        $items = [];
        foreach ($rows as $row) {
            $anchor = trim((string) ($row['anchor'] ?? ''));
            if ($anchor === '') {
                continue;
            }

            $label = BlockLocalization::bilingualFirst(self::TABLE, (int) $row['id'], ['nav_label', 'title']);
            if ($label->primaryValue() === '') {
                continue;
            }

            $items[] = [
                'anchor' => $anchor,
                'label' => $label,
            ];
        }

        return $items;
    }

    /**
     * What a new section row is created with, by DetailSectionBlock::create():
     * what is the same in every language. The words are startingWords().
     * Section-level fields only: a new section has no points and no images.
     *
     * @return array{anchor: string, image_position: string, cta_url: string}
     */
    public static function startingValues(): array
    {
        return [
            'anchor' => '',
            'image_position' => 'image_right',
            'cta_url' => '',
        ];
    }

    /**
     * The starting words of a new section, written in the website's default
     * language: the title the editor requires, saying that it is to be
     * changed.
     *
     * @return array<string, string> field => words
     */
    public static function startingWords(): array
    {
        return ['title' => 'Nieuwe sectie — pas deze titel aan'];
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handlers right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$positions = [];
        BlockLocalization::clearCache();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function fromRow(array $row): array
    {
        $sectionId = (int) $row['id'];
        $words = BlockLocalization::words(self::TABLE, $sectionId);

        // The main image resolves exactly like the extra images below it.
        $mainImage = BlockImage::fromOwner($row, $words['main_image_alt'], 'main_media_id', 'main_image_path');

        $content = [
            'id' => $sectionId,
            'anchor' => trim((string) ($row['anchor'] ?? '')),
        ] + $words;

        $content['main_image_path'] = $mainImage['image_path'];
        $content['main_image_alt'] = $mainImage['alt'];
        $content['main_image_width'] = $mainImage['width'];
        $content['main_image_height'] = $mainImage['height'];
        $content['image_position'] = in_array($row['image_position'] ?? null, self::IMAGE_POSITIONS, true)
            ? (string) $row['image_position']
            : 'image_right';
        $content['cta_url'] = (string) ($row['cta_url'] ?? '');

        // A CTA only renders when it has both a label in the default language
        // and a URL — a half-filled optional CTA would be a broken/dead link,
        // and a translated label alone could never show.
        if ($content['cta_label']->primaryValue() === '' || $content['cta_url'] === '') {
            $content['cta_label'] = LocalizedValue::of([]);
            $content['cta_url'] = '';
        }

        return $content;
    }

    /**
     * The shape of a section with nothing to show: every word empty, so a
     * template that forgets to check 'state' fails safe instead of erroring
     * on a missing key.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return [
            'id' => 0,
            'anchor' => '',
        ] + BlockLocalization::words(self::TABLE, 0) + [
            'main_image_path' => '',
            'main_image_width' => null,
            'main_image_height' => null,
            'image_position' => 'image_right',
            'cta_url' => '',
            'points' => [],
            'images' => [],
        ];
    }

    /**
     * @return array<int, int> detail_sections.id => 0-based position on the page
     */
    private static function positionsForPage(string $pageSlug): array
    {
        if (isset(self::$positions[$pageSlug])) {
            return self::$positions[$pageSlug];
        }

        try {
            $rows = (new DetailSectionRepository())->findActiveForPageInBlockOrder($pageSlug);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] position lookup failed for "' . $pageSlug . '": ' . $e->getMessage());

            return self::$positions[$pageSlug] = [];
        }

        $positions = [];
        foreach (array_values($rows) as $index => $row) {
            $positions[(int) $row['id']] = $index;
        }

        return self::$positions[$pageSlug] = $positions;
    }
}
