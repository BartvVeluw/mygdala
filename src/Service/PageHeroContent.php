<?php

namespace App\Service;

use App\Repository\PageHeroRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\Routing\RequestLanguage;

/**
 * Content for the "Page hero" section — the header at the top of an ordinary
 * page: the H1, and optionally an eyebrow, a lead and an image behind them.
 * See App\Service\SiteSettings for the equivalent pattern this mirrors.
 *
 * THE BREADCRUMB IS NOT PART OF THIS BLOCK. It is the page's own navigation
 * (App\Service\Breadcrumbs\PageBreadcrumb), so hiding or deleting a header
 * no longer takes it down with it. The legacy `page_heroes.breadcrumb_label_*`
 * columns from when it was are gone (db/migrations/20260917180000).
 *
 * PAGES below is the fixed, known list of pages that had this section before
 * the page builder existed; any other page gets one by attaching the block
 * (App\Service\Blocks\PageHeroBlock::create()). Never a schema change.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The eyebrow, the title and
 * the lead are stored per website language in block_translations and come out
 * of App\Service\Blocks\BlockLocalization as one string each, in the language
 * of the request, the fallback to the default language already applied. The
 * image and the choices stay in page_heroes, the same in every language.
 * This class decides no language itself.
 *
 * There is no hardcoded fallback copy, per page or per field. A missing row,
 * or a lookup that fails, is STATE_FALLBACK: there is nothing to render, and a
 * failure is logged. An active row renders exactly what it stores. The editor
 * requires the title in the default language, so an empty title only comes
 * from data written outside it; the eyebrow and the lead are optional, and an
 * empty one is simply not rendered (partials/section-page-hero.php). See
 * CONTENT-BLOCKS.md, "Het inhoudscontract".
 *
 * THE IMAGE is a Media Library reference (MEDIA.md). A page hero never had
 * an image of its own, so there is no legacy path column behind `media_id`:
 * BlockImage::fromOwner() resolves the item and its size, and an id that no
 * longer names an item is no image. Its alt text is layered like every other
 * block's: the header's own `image_alt` word per language, else the item's.
 * There is no video (docs/content-blocks/DECISIONS.md).
 *
 * THE CHOICES — where the text sits, how large the title and the intro text
 * are, where the picture goes (image_mode), how tall a header with a picture
 * behind it is (hero_height) and which part of a cropped picture stays in
 * view (image_focus, App\Service\Media\ImageFocus) — are closed lists of
 * words, never CSS; assets/css/blocks/page-hero.css decides what a word looks
 * like. A stored value outside its list (a hand-edited row) reads as the
 * default, and every default is how the header looked before these choices
 * existed. effectiveImageMode() is where the picture really goes.
 *
 * startingValues() and startingWords() are a different thing: what a Page
 * hero that does not exist yet starts out with in the editor and in
 * PageHeroBlock::create(). Generic and meant to be edited, and never rendered
 * in place of a stored row.
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
     * Where the picture goes. NONE is a header without one; BACKGROUND is the
     * picture behind the text, the only place it could be before this was a
     * choice (db/migrations/20260924120000); LEFT and RIGHT put it beside the
     * text, in one band with it.
     */
    public const IMAGE_NONE = 'none';
    public const IMAGE_BACKGROUND = 'background';
    public const IMAGE_LEFT = 'left';
    public const IMAGE_RIGHT = 'right';

    /** All valid `image_mode` values, for save-time and render-time validation. */
    public const IMAGE_MODES = [self::IMAGE_NONE, self::IMAGE_BACKGROUND, self::IMAGE_LEFT, self::IMAGE_RIGHT];

    /**
     * How tall a header with a picture behind its text is at least. A step,
     * never a length: assets/css/blocks/page-hero.css decides what each one
     * measures on a wide and on a narrow screen. MEDIUM is the height such a
     * header had before this was a choice. A header with a picture beside its
     * text, or without one, takes the height of its text and ignores this.
     */
    public const HEIGHT_SMALL = 'small';
    public const HEIGHT_MEDIUM = 'medium';
    public const HEIGHT_LARGE = 'large';

    /** All valid `hero_height` values, for save-time and render-time validation. */
    public const HEIGHTS = [self::HEIGHT_SMALL, self::HEIGHT_MEDIUM, self::HEIGHT_LARGE];

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

    /** The owner table of this block's words (PageHeroBlock::translatableFields()). */
    private const TABLE = 'page_heroes';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus the words
     *     eyebrow, title and lead (a string each; eyebrow and lead may
     *     be empty); the image as media_id (int|null), image_path ('' for no
     *     image), image_alt (a string) and image_width/height
     *     (int|null when unknown); and content_position, title_size,
     *     text_size, image_mode and hero_height, always one of POSITIONS /
     *     SIZES / IMAGE_MODES / HEIGHTS, and image_focus, always an
     *     ImageFocus key.
     *     Templates must only render the section when 'state' ===
     *     STATE_ACTIVE; the content fields are still present (empty, the
     *     choices at their defaults) otherwise, purely so a template that
     *     forgets the check fails safe instead of erroring on a missing key.
     */
    public static function forSlug(string $pageSlug): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $row = null;
        try {
            $row = (new PageHeroRepository())->findBySlug($pageSlug);
        } catch (\Throwable $e) {
            error_log('[PageHeroContent] lookup failed for "' . $pageSlug . '": ' . $e->getMessage());
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

        $heroId = (int) $row['id'];

        // THE DEFAULT LANGUAGE DECIDES WHETHER THE HEADER IS THERE
        // (docs/multilingual/ARCHITECTURE.md): without a title in the default
        // language the header says nothing in any language, and the partial
        // renders nothing.
        $content = BlockLocalization::hasDefaultWords(self::TABLE, $heroId, 'title')
            ? BlockLocalization::words(self::TABLE, $heroId)
            : BlockLocalization::words(self::TABLE, 0);
        $content['state'] = self::STATE_ACTIVE;

        // The block's own alt text is one of its words; the image puts the
        // library's under it (BlockImage), so it replaces the word here.
        return self::$cache[$cacheKey] = array_merge($content, self::imageOf($row, $content['image_alt'] ?? '')) + self::choicesOf($row);
    }

    /**
     * What a Page hero that does not exist yet starts out with: the editor's
     * form for a page without a row (admin/page-hero.php) and the row
     * PageHeroBlock::create() writes. No image, and today's look for every
     * choice — never rendered in place of a stored row, which forSlug()
     * answers with nothing when it is missing.
     *
     * What is the same in every language; the words are startingWords().
     *
     * @return array{media_id: null, content_position: string, title_size: string, text_size: string, image_mode: string, hero_height: string, image_focus: string}
     */
    public static function startingValues(): array
    {
        return [
            'media_id' => null,
            'content_position' => self::POSITION_LEFT,
            'title_size' => self::SIZE_NORMAL,
            'text_size' => self::SIZE_NORMAL,
            'image_mode' => self::IMAGE_NONE,
            'hero_height' => self::HEIGHT_MEDIUM,
            'image_focus' => ImageFocus::DEFAULT,
        ];
    }

    /**
     * Where the picture really goes: the chosen place when there is a picture
     * to put there, else IMAGE_NONE. A header whose picture is gone, or that
     * names a place without ever choosing a picture, is a header without one,
     * so it prints no picture markup at all.
     *
     * @param array<string, mixed> $content what forSlug() returns
     */
    public static function effectiveImageMode(array $content): string
    {
        if ((string) ($content['image_path'] ?? '') === '') {
            return self::IMAGE_NONE;
        }

        return self::oneOf($content['image_mode'] ?? null, self::IMAGE_MODES, self::IMAGE_NONE);
    }

    /**
     * The starting words of a header that does not exist yet, written in the
     * website's default language: generic, editable copy in the one field the
     * editor requires. The eyebrow starts empty. It used to start as "Nieuw"
     * only because the editor required one; an optional eyebrow that nobody
     * chose would be a word on the page nobody wrote.
     *
     * @return array<string, string> field => words
     */
    public static function startingWords(): array
    {
        return ['title' => 'Nieuwe sectie — pas deze titel aan'];
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handler right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * The image a row points at, resolved through the library once per
     * request. A page_heroes row carries a media_id and no path column, so
     * what comes back is the item or no image at all. Its alt text is layered
     * (BlockImage): the header's own, in the language of the request, else
     * the item's. Only a picture beside the text uses it; one behind the text
     * is decoration (partials/section-page-hero.php).
     *
     * @param array<string, mixed> $row
     *
     * @return array{media_id: int|null, image_path: string, image_alt: string, image_width: int|null, image_height: int|null}
     */
    private static function imageOf(array $row, string $ownAlt): array
    {
        $image = BlockImage::fromOwner($row, $ownAlt);

        return [
            'media_id' => $image['media_id'],
            'image_path' => $image['image_path'],
            'image_alt' => $image['alt'],
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
     * A row without an `image_mode` at all (read before
     * db/migrations/20260924120000 ran) had its picture behind the text, the
     * only place there was, so that is how it reads.
     *
     * @return array{content_position: string, title_size: string, text_size: string, image_mode: string, hero_height: string, image_focus: string}
     */
    private static function choicesOf(array $row): array
    {
        $modeBeforeItWasAChoice = ($row['media_id'] ?? null) !== null ? self::IMAGE_BACKGROUND : self::IMAGE_NONE;

        return [
            'content_position' => self::oneOf($row['content_position'] ?? null, self::POSITIONS, self::POSITION_LEFT),
            'title_size' => self::oneOf($row['title_size'] ?? null, self::SIZES, self::SIZE_NORMAL),
            'text_size' => self::oneOf($row['text_size'] ?? null, self::SIZES, self::SIZE_NORMAL),
            'image_mode' => self::oneOf($row['image_mode'] ?? $modeBeforeItWasAChoice, self::IMAGE_MODES, self::IMAGE_NONE),
            'hero_height' => self::oneOf($row['hero_height'] ?? null, self::HEIGHTS, self::HEIGHT_MEDIUM),
            'image_focus' => ImageFocus::normalise($row['image_focus'] ?? null),
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
        return BlockLocalization::words(self::TABLE, 0) + [
            'media_id' => null,
            'image_path' => '',
            'image_alt' => BlockImage::fromOwner([], null)['alt'],
            'image_width' => null,
            'image_height' => null,
            'content_position' => self::POSITION_LEFT,
            'title_size' => self::SIZE_NORMAL,
            'text_size' => self::SIZE_NORMAL,
            'image_mode' => self::IMAGE_NONE,
            'hero_height' => self::HEIGHT_MEDIUM,
            'image_focus' => ImageFocus::DEFAULT,
        ];
    }
}
