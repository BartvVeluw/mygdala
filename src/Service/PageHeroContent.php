<?php

namespace App\Service;

use App\Repository\PageHeroRepository;
use App\Service\Media\BlockImage;

/**
 * Content for the "Page hero" section — the header at the top of an ordinary
 * page: the breadcrumb, the H1, and optionally an eyebrow, a lead and an
 * image behind them. See docs/CMS_CONTENT_AUDIT.md, "Recommended smallest
 * next step", and App\Service\SiteSettings for the equivalent pattern this
 * mirrors.
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
 * failure is logged. An active row renders exactly what it stores. The editor
 * requires the title and the breadcrumb label, so an empty title only comes
 * from data written outside it; the eyebrow and the lead are optional, and an
 * empty one is simply not rendered (partials/section-page-hero.php). See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * THE IMAGE is a Media Library reference and nothing more (MEDIA.md). A page
 * hero never had an image of its own, so there is no legacy path column and
 * no local alt text behind `media_id`: BlockImage::fromRow() resolves the
 * item, its alt text and its size, and an id that no longer names an item is
 * no image. There is no video, because the library holds images only
 * (docs/content-blocks/DECISIONS.md).
 *
 * THE CHOICES — where the text sits, how large the title and the intro text
 * are — are closed lists of words, never CSS; assets/css/blocks/page-hero.css
 * decides what a word looks like. A stored value outside its list (a
 * hand-edited row) reads as the default, and every default is how the header
 * looked before these choices existed.
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

    /** Where the header's text sits. LEFT is where it sat before this was a choice. */
    public const POSITION_LEFT = 'left';
    public const POSITION_CENTER = 'center';
    public const POSITION_RIGHT = 'right';

    /** All valid `content_position` values, for save-time and render-time validation. */
    public const POSITIONS = [self::POSITION_LEFT, self::POSITION_CENTER, self::POSITION_RIGHT];

    /**
     * The steps a title or an intro text can take, one closed list for both.
     * A step names a place on the site's type scale rather than a size, so a
     * change to that scale in core.css moves every step with it. NORMAL is
     * the size both had before this was a choice.
     */
    public const SIZE_SMALL = 'small';
    public const SIZE_NORMAL = 'normal';
    public const SIZE_LARGE = 'large';

    /** All valid `title_size` and `text_size` values, for save-time and render-time validation. */
    public const SIZES = [self::SIZE_SMALL, self::SIZE_NORMAL, self::SIZE_LARGE];

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

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus the texts
     *     eyebrow_nl/en, title_nl/en, lead_nl/en and breadcrumb_label_nl/en
     *     (strings; eyebrow_* and lead_* may be ''); the image as media_id
     *     (int|null), image_path ('' for no image), image_alt_nl/en and
     *     image_width/height (int|null when unknown); and content_position,
     *     title_size and text_size, always one of POSITIONS / SIZES.
     *     Templates must only render the section when 'state' ===
     *     STATE_ACTIVE; the content fields are still present (empty, the
     *     choices at their defaults) otherwise, purely so a template that
     *     forgets the check fails safe instead of erroring on a missing key.
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

        return self::$cache[$pageSlug] = $content + self::imageOf($row) + self::choicesOf($row);
    }

    /**
     * What a Page hero that does not exist yet starts out with: the editor's
     * form for a page without a row (admin/page-hero.php) and the row
     * PageHeroBlock::create() writes. Generic, editable copy in the two
     * fields the editor requires, no image, and today's look for every
     * choice — never rendered in place of a stored row, which forSlug()
     * answers with nothing when it is missing.
     *
     * The eyebrow starts empty. It used to start as "Nieuw" only because the
     * editor required one; an optional eyebrow that nobody chose would be a
     * word on the page nobody wrote.
     *
     * @return array<string, string|null>
     */
    public static function startingValues(string $pageLabel): array
    {
        return [
            'eyebrow_nl' => '',
            'eyebrow_en' => '',
            'title_nl' => 'Nieuwe sectie — pas deze titel aan',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'breadcrumb_label_nl' => $pageLabel,
            'breadcrumb_label_en' => '',
            'media_id' => null,
            'content_position' => self::POSITION_LEFT,
            'title_size' => self::SIZE_NORMAL,
            'text_size' => self::SIZE_NORMAL,
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
     * The image a row points at, resolved through the library once per
     * request. A page_heroes row carries a media_id and nothing else, so the
     * path and alt columns BlockImage falls back to are simply absent: what
     * comes back is the item, or no image at all.
     *
     * @param array<string, mixed> $row
     *
     * @return array{media_id: int|null, image_path: string, image_alt_nl: string, image_alt_en: string, image_width: int|null, image_height: int|null}
     */
    private static function imageOf(array $row): array
    {
        $image = BlockImage::fromRow($row);

        return [
            'media_id' => $image['media_id'],
            'image_path' => $image['image_path'],
            'image_alt_nl' => $image['alt_nl'],
            'image_alt_en' => $image['alt_en'],
            'image_width' => $image['width'],
            'image_height' => $image['height'],
        ];
    }

    /**
     * The render-time half of the closed lists; the endpoint refuses anything
     * else at save time. A legacy NULL or a hand-edited value becomes the
     * default instead of an unstyled class.
     *
     * @param array<string, mixed> $row
     *
     * @return array{content_position: string, title_size: string, text_size: string}
     */
    private static function choicesOf(array $row): array
    {
        return [
            'content_position' => self::oneOf($row['content_position'] ?? null, self::POSITIONS, self::POSITION_LEFT),
            'title_size' => self::oneOf($row['title_size'] ?? null, self::SIZES, self::SIZE_NORMAL),
            'text_size' => self::oneOf($row['text_size'] ?? null, self::SIZES, self::SIZE_NORMAL),
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return [
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'breadcrumb_label_nl' => '', 'breadcrumb_label_en' => '',
            'media_id' => null, 'image_path' => '',
            'image_alt_nl' => '', 'image_alt_en' => '',
            'image_width' => null, 'image_height' => null,
            'content_position' => self::POSITION_LEFT,
            'title_size' => self::SIZE_NORMAL,
            'text_size' => self::SIZE_NORMAL,
        ];
    }
}
