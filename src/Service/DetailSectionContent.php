<?php

namespace App\Service;

use App\Repository\DetailSectionRepository;
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
 * - `nav_label_nl/en` — the short label the quicknav shows for it, because
 *   a section's heading ("Hout graveren") is usually longer than the label
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
 * `content_html` is re-sanitized on read, defensively, even though it was
 * already sanitized on save — the same "sanitize on write, sanitize again on
 * read" pattern as RichTextContent.
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

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @var array<string, array<int, int>> page_slug => [detail_sections.id => 0-based position] */
    private static array $positions = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus anchor,
     *                                nav_label_nl/en, title_nl/en,
     *                                lead_nl/en, content_html(+_en),
     *                                main_image_path + main_image_alt_nl/en
     *                                ('' path = no main image),
     *                                image_position, closing_note_nl/en,
     *                                cta_label_nl/en + cta_url (all three ''
     *                                together when there is no CTA),
     *                                'points': list of title_nl/en +
     *                                body_nl/en, and 'images': list of
     *                                image_path/alt_nl/en. Templates must
     *                                check 'state' !== STATE_HIDDEN before
     *                                rendering the section at all.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $empty = [
            'id' => 0,
            'anchor' => '',
            'nav_label_nl' => '', 'nav_label_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'content_html' => '', 'content_html_en' => '',
            'main_image_path' => '', 'main_image_alt_nl' => '', 'main_image_alt_en' => '',
            'image_position' => 'image_right',
            'closing_note_nl' => '', 'closing_note_en' => '',
            'cta_label_nl' => '', 'cta_label_en' => '', 'cta_url' => '',
            'points' => [], 'images' => [],
        ];

        try {
            $repository = new DetailSectionRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + $empty;
        }

        $content = self::fromRow($row);

        try {
            $points = $repository->findPointsBySectionId((int) $row['id'], true);
            $images = $repository->findImagesBySectionId((int) $row['id']);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] children lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        // Whatever comes back — including an empty list — is authoritative:
        // an emptied-out gallery or points list stays empty.
        $content['points'] = array_map(static function (array $point): array {
            $titleNl = (string) $point['title_nl'];
            $bodyNl = (string) $point['body_nl'];

            return [
                'title_nl' => $titleNl,
                'title_en' => self::valueOrDefault($point['title_en'] ?? null, $titleNl),
                'body_nl' => $bodyNl,
                'body_en' => self::valueOrDefault($point['body_en'] ?? null, $bodyNl),
            ];
        }, $points);

        // Media Library first, the row's own image_path second, and this
        // block's own alt text over the media item's default — all of it in
        // App\Service\Media\BlockImage, shared with the other integrated
        // blocks. width/height come along, null when the library does not
        // know them.
        $content['images'] = array_map(
            static fn (array $image): array => BlockImage::fromRow($image),
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
     * @return list<array{anchor: string, label_nl: string, label_en: string}>
     */
    public static function navItemsForPage(string $pageSlug): array
    {
        try {
            $rows = (new DetailSectionRepository())->findActiveForPageInBlockOrder($pageSlug);
        } catch (\Throwable $e) {
            error_log('[DetailSectionContent] nav lookup failed for "' . $pageSlug . '": ' . $e->getMessage());

            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $anchor = trim((string) ($row['anchor'] ?? ''));
            if ($anchor === '') {
                continue;
            }

            $titleNl = (string) $row['title_nl'];
            $labelNl = self::valueOrDefault($row['nav_label_nl'] ?? null, $titleNl);

            $items[] = [
                'anchor' => $anchor,
                'label_nl' => $labelNl,
                'label_en' => self::valueOrDefault(
                    $row['nav_label_en'] ?? null,
                    self::valueOrDefault($row['title_en'] ?? null, $labelNl)
                ),
            ];
        }

        return $items;
    }

    /**
     * Section-level fields only (no points/images) — used by the admin edit
     * page to pre-fill a brand-new section's form.
     *
     * @return array<string, string>
     */
    public static function defaultsForSection(): array
    {
        return [
            'anchor' => '',
            'nav_label_nl' => '',
            'nav_label_en' => '',
            'title_nl' => 'Nieuwe sectie — pas deze titel aan',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'content_html' => '',
            'content_html_en' => '',
            'image_position' => 'image_right',
            'closing_note_nl' => '',
            'closing_note_en' => '',
            'cta_label_nl' => '',
            'cta_label_en' => '',
            'cta_url' => '',
        ];
    }

    /**
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$positions = [];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function fromRow(array $row): array
    {
        // The main image resolves exactly like the extra images below it.
        $mainImage = BlockImage::fromRow($row, 'main_media_id', 'main_image_path', 'main_image_alt_nl', 'main_image_alt_en');

        $content = [
            'id' => (int) $row['id'],
            'anchor' => trim((string) ($row['anchor'] ?? '')),
            'nav_label_nl' => (string) ($row['nav_label_nl'] ?? ''),
            'title_nl' => (string) $row['title_nl'],
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'content_html' => (string) (RichTextSanitizer::sanitize($row['content_html'] ?? null) ?? ''),
            'main_image_path' => $mainImage['image_path'],
            'main_image_alt_nl' => $mainImage['alt_nl'],
            'main_image_width' => $mainImage['width'],
            'main_image_height' => $mainImage['height'],
            'image_position' => in_array($row['image_position'] ?? null, self::IMAGE_POSITIONS, true)
                ? (string) $row['image_position']
                : 'image_right',
            'closing_note_nl' => (string) ($row['closing_note_nl'] ?? ''),
            'cta_label_nl' => (string) ($row['cta_label_nl'] ?? ''),
            'cta_url' => (string) ($row['cta_url'] ?? ''),
        ];

        $content['nav_label_en'] = self::valueOrDefault($row['nav_label_en'] ?? null, $content['nav_label_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);
        $content['main_image_alt_en'] = $mainImage['alt_en'];
        $content['closing_note_en'] = self::valueOrDefault($row['closing_note_en'] ?? null, $content['closing_note_nl']);
        $content['cta_label_en'] = self::valueOrDefault($row['cta_label_en'] ?? null, $content['cta_label_nl']);

        // An empty English body means "same as Dutch", exactly like
        // RichTextContent — the partial then emits no language attributes.
        $content['content_html_en'] = (string) (RichTextSanitizer::sanitize($row['content_html_en'] ?? null) ?? '');

        // A CTA only renders when it has both a label and a URL — a
        // half-filled optional CTA would be a broken/dead link.
        if ($content['cta_label_nl'] === '' || $content['cta_url'] === '') {
            $content['cta_label_nl'] = '';
            $content['cta_label_en'] = '';
            $content['cta_url'] = '';
        }

        return $content;
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

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }
}
