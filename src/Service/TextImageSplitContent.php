<?php

namespace App\Service;

use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\BlockImage;
use App\Service\Media\ImagePresentation;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\RequestLanguage;

/**
 * Content for the "Tekst met afbeelding" block: an ordered list of ITEMS,
 * each a text beside at most one picture (Tekst met afbeelding 2.0,
 * db/migrations/20260924100000). Same repeater architecture as
 * App\Service\FaqContent: a block row, and child rows that each own their
 * words.
 *
 * AN ITEM has, the same in every language: its picture (a Media Library
 * item), which side the picture is on, the picture's share of the row, how
 * high the picture is, how the picture sits in that frame on a large screen
 * and on a phone (Responsive Media 2.0: its focus point, its fit, a phone's
 * own picture, point, fit and height; imageSlot()), and a button address. Per
 * website language (BlockLocalization): an eyebrow, a title, a rich-text
 * body, a button label and the picture's own alt text. The layout is three
 * closed lists of keys (SIDES, COLUMNS, HEIGHTS); what they look like is
 * assets/css/blocks/text-image-split.css's, never stored CSS.
 *
 * AN ITEM SHOWS when it has a picture, or text in the default language: an
 * eyebrow, a title, a body or a whole button (the four things the block
 * itself used to show without a picture). The default language decides
 * presence, as it does for every block. An item with none of it is not saved
 * (the editor refuses it). The button is all-or-nothing per item, as it
 * always was for the block: a label in the default language and a URL, or no
 * button.
 *
 * The body is sanitized HTML (RichTextSanitizer, on save and again on read
 * in BlockLocalization); everything else is plain text. An image's alt text
 * is layered over the media item's own by BlockImage::fromOwner(). This
 * class decides no language itself.
 *
 * THE BLOCK'S OWN HEADING, above all its items: an optional title and an
 * optional lead, per website language on the block row. Like every optional
 * word, each shows only when the default language has it.
 *
 * There is no hardcoded fallback copy. A missing row, or a lookup that fails,
 * is STATE_FALLBACK: there is nothing to render, and a failure is logged. See
 * CONTENT-BLOCKS.md, "Het inhoudscontract". `is_active = false` on an existing
 * row is STATE_HIDDEN. Once the row exists and is active, its items come
 * strictly from the database, even if that list is empty.
 */
class TextImageSplitContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /** Which side of the text the picture is on. */
    public const SIDES = ['left', 'right'];

    /** The picture's share of the row on a wide screen, in percent; the text has the rest. */
    public const COLUMNS = ['25', '50', '75'];

    /**
     * How high the picture is: the three steps of App\Service\Media\ImagePresentation
     * (small is compact, medium normal, large large), with the lengths below.
     */
    public const HEIGHTS = ['small', 'medium', 'large'];

    /*
     * THE STEPS' LENGTHS, the literal values of text-image-split.css
     * (Tests\Service\ImagePresentationContractTest pins every one of them to
     * the stylesheet), from which the CMS works out its preview frames
     * (editorFrames()). Responsive Media 3.1, MEDIA.md "Compact, Normaal, Groot".
     *
     *   wide (more than STACK_MAX_WIDTH): text and picture side by side; the
     *       picture is its share of the row wide and WIDE_HEIGHTS high
     *   a tablet (one column, STACK_MAX_WIDTH and less, above
     *       MOBILE_MAX_WIDTH): the picture is the column's full width and has
     *       its step's shape, STACKED_RATIOS, never taller than STACKED_MAX.
     *       A shape rather than a phone's fixed height, so a step on a tablet
     *       never looks a step smaller (Responsive Media 3.1).
     *   a phone (MOBILE_MAX_WIDTH and less): the column's full width at the
     *       fixed heights a phone always had, PHONE_HEIGHTS, or a phone's own
     *       height, PHONE_OWN_HEIGHTS (ImagePresentation::onPhone() decides
     *       which). Responsive Media 3.1.1 brought these back unchanged: the
     *       tablet bug never was a phone bug.
     */

    /** Up to this window width an item is one column: its text, then its picture. */
    public const STACK_MAX_WIDTH = 860;

    /** The room between text and picture on a wide screen, in px: var(--sp-6). */
    public const COLUMN_GAP = 64;

    /** @var array<string, string> --text-image-height-<step> on a wide screen */
    public const WIDE_HEIGHTS = [
        'small' => 'clamp(14rem, 24vw, 20rem)',
        'medium' => 'clamp(18rem, 36vw, 30rem)',
        'large' => 'clamp(22rem, 50vw, 42rem)',
    ];

    /** @var array<string, string> --text-image-ratio-<step> in one column */
    public const STACKED_RATIOS = [
        'small' => '16 / 9',
        'medium' => '4 / 3',
        'large' => '1 / 1',
    ];

    /** --text-image-stacked-max: in one column never taller than the large step on a wide screen. */
    public const STACKED_MAX = '42rem';

    /** @var array<string, string> --text-image-height-<step> on a phone, automatic */
    public const PHONE_HEIGHTS = [
        'small' => '12rem',
        'medium' => '16rem',
        'large' => '20rem',
    ];

    /** @var array<string, string> a phone's own height (ResponsiveImage::MOBILE_HEIGHTS) */
    public const PHONE_OWN_HEIGHTS = [
        'compact' => '12rem',
        'normal' => '16rem',
        'large' => '24rem',
    ];

    /** What a new item starts with: picture on the right, as a new block always had it. */
    public const DEFAULTS = [
        'image_side' => 'right',
        'image_column' => '50',
        'image_height' => 'medium',
    ];

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

    /** The owner table of the items' words (TextImageSplitBlock::translatableFields()). */
    private const TABLE = 'text_image_splits';
    private const ITEMS = 'text_image_split_items';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), the block's
     *                                own title and lead ('' when it has
     *                                none) and 'items': a
     *                                list of image_side, image_column,
     *                                image_height (keys), mobile_height
     *                                (a phone's own height key, or null),
     *                                eyebrow, title,
     *                                body (sanitized HTML), button_label,
     *                                button_url (both '' when there is no
     *                                button), image: null, or image_path,
     *                                alt, width, height and media_id, and
     *                                picture: null, or what
     *                                partials/responsive-image.php prints
     *                                (ResponsiveImage::forRender()).
     *                                Templates must only render the section
     *                                when 'state' === STATE_ACTIVE; 'items'
     *                                is still present (empty) otherwise.
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
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $sectionId = (int) $row['id'];

        // The words of every item at once; nothing when the page already
        // loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$sectionId]]);

        try {
            $items = $repository->findItemsBySectionId($sectionId);
        } catch (\Throwable $e) {
            error_log('[TextImageSplitContent] items lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        $content = ['items' => []];
        foreach (['title', 'lead'] as $field) {
            $content[$field] = BlockLocalization::hasDefaultWords(self::TABLE, $sectionId, $field)
                ? BlockLocalization::text(self::TABLE, $sectionId, $field)
                : '';
        }
        foreach ($items as $item) {
            $shown = self::item($item);
            if ($shown !== null) {
                $content['items'][] = $shown;
            }
        }

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * An item's layout as keys this block knows: each value itself when it is
     * one, else the default. Stored rows and posted rows both pass through
     * here, so nothing else ever reaches the database or the markup.
     *
     * @param array<string, mixed> $values
     * How the picture sits in its frame (its focus point, its fit, a phone's
     * own picture, point, fit and height) is not a layout key: it is the
     * item's Responsive Media presentation (imageSlot()).
     *
     * @return array{image_side: string, image_column: string, image_height: string}
     */
    public static function layout(array $values): array
    {
        $pick = static fn (mixed $value, array $allowed, string $default): string
            => is_string($value) && in_array($value, $allowed, true) ? $value : $default;

        return [
            'image_side' => $pick($values['image_side'] ?? null, self::SIDES, self::DEFAULTS['image_side']),
            'image_column' => $pick($values['image_column'] ?? null, self::COLUMNS, self::DEFAULTS['image_column']),
            'image_height' => $pick($values['image_height'] ?? null, self::HEIGHTS, self::DEFAULTS['image_height']),
        ];
    }

    /**
     * Where an item keeps its picture's presentation (Responsive Media 2.0):
     * the image_ columns of text_image_split_items, with a fit of its own —
     * the picture fills a frame of the item's chosen height — and a phone
     * height of its own (text-image-split.css).
     */
    public static function imageSlot(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('image_', 'media_id', fit: true, mobileHeight: true);
    }

    /**
     * The size of an item's picture on a view's reference screen
     * (ImagePresentation::VIEWPORTS), in px: from the same lengths the
     * stylesheet uses, never from a table of its own.
     *
     * @param string      $column       one of COLUMNS
     * @param string      $height       one of HEIGHTS
     * @param string|null $mobileHeight a phone's own height (ResponsiveImage::MOBILE_HEIGHTS), null for automatic
     *
     * @return array{0: float, 1: float} width, height
     */
    public static function pictureSize(string $view, string $column, string $height, ?string $mobileHeight = null): array
    {
        [$window] = ImagePresentation::viewport($view);
        $content = ImagePresentation::contentWidth($view);

        if ($window > self::STACK_MAX_WIDTH) {
            $width = ($content - self::COLUMN_GAP) * ((int) $column) / 100;

            return [$width, ImagePresentation::length(self::WIDE_HEIGHTS[$height], $view)];
        }

        // A phone: a fixed height, the automatic one or a phone's own.
        if ($window <= ResponsiveImage::MOBILE_MAX_WIDTH) {
            [$step, $source] = ImagePresentation::onPhone($height, $mobileHeight);
            $length = $source === ImagePresentation::OWN ? self::PHONE_OWN_HEIGHTS[$step] : self::PHONE_HEIGHTS[$height];

            return [(float) $content, ImagePresentation::length($length, $view)];
        }

        // A tablet: the shape of the step, whatever a phone chose.
        return [
            (float) $content,
            min($content / ImagePresentation::ratio(self::STACKED_RATIOS[$height]), ImagePresentation::length(self::STACKED_MAX, $view)),
        ];
    }

    /**
     * Every frame the CMS can show for an item, keyed by the choices that
     * shape it — its column, its height and a phone's own height ('' for
     * automatic) — for admin/_responsive_image_field.php's 'shapes', which
     * follows those three choices on screen without a table of its own.
     *
     * @return array{controls: list<string>, shapes: array<string, array<string, string>>}
     */
    public static function editorFrames(): array
    {
        $shapes = [];
        foreach (self::COLUMNS as $column) {
            foreach (self::HEIGHTS as $height) {
                foreach (array_merge([''], ResponsiveImage::MOBILE_HEIGHTS) as $mobile) {
                    $properties = [];
                    foreach (ImagePresentation::VIEWS as $view) {
                        [$width, $tall] = self::pictureSize($view, $column, $height, $mobile === '' ? null : $mobile);
                        $properties += ImagePresentation::frame($view, $width, $tall);
                    }
                    $shapes[$column . '|' . $height . '|' . $mobile] = $properties;
                }
            }
        }

        return [
            'controls' => ['[data-tis-column]', '[data-tis-height]', '[data-rm-mobile-height]'],
            'shapes' => $shapes,
        ];
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handler right after writing, and by
     * tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * One stored item as the partial gets it, or null when it has nothing to
     * show.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private static function item(array $item): ?array
    {
        $itemId = (int) $item['id'];

        // Media Library first, the row's own image_path second, and the
        // item's own alt text (per language) over the media item's default.
        $image = BlockImage::fromOwner($item, BlockLocalization::text(self::ITEMS, $itemId, 'alt'));
        $image = $image['image_path'] !== '' ? $image : null;

        $words = BlockLocalization::words(self::ITEMS, $itemId);
        // The shared rule of every block button (LinkChoice): a page, blog
        // post or product by id, resolved now in the language being read, or
        // the typed address. An item from before the type has only an
        // address, and is one.
        $buttonUrl = LinkChoice::href($item['button_link_type'] ?? null, $item['button_link_target_id'] ?? 0, (string) ($item['button_url'] ?? ''));

        // A button only renders with a label in the default language and a
        // destination — a half-filled optional button would be broken or
        // dead, and so would one whose page is gone or not published.
        if (!BlockLocalization::hasDefaultWords(self::ITEMS, $itemId, 'button_label') || $buttonUrl === '') {
            $words['button_label'] = '';
            $buttonUrl = '';
        }

        // As a paragraph always did, the body shows only when the default
        // language has one; a translation of it falls back to that.
        $hasBody = BlockLocalization::hasDefaultWords(self::ITEMS, $itemId, 'body');
        $hasText = $hasBody
            || BlockLocalization::hasDefaultWords(self::ITEMS, $itemId, 'eyebrow')
            || BlockLocalization::hasDefaultWords(self::ITEMS, $itemId, 'title')
            || $words['button_label'] !== '';

        if ($image === null && !$hasText) {
            return null;
        }

        $layout = self::layout($item);
        $presentation = ResponsiveImage::fromRow($item, self::imageSlot());

        return $layout + [
            'mobile_height' => $presentation->mobileHeight,
            'picture' => $image === null ? null : $presentation->forRender($image),
            'eyebrow' => $words['eyebrow'],
            'title' => $words['title'],
            'body' => $hasBody ? $words['body'] : '',
            'button_label' => $words['button_label'],
            'button_url' => $buttonUrl,
            // Button Styles 2.0: this item's button's choice, null = the default.
            'button_style' => \App\Service\Theme\ButtonStyles::storedChoice($item['button_style_id'] ?? null),
            'image' => $image,
        ];
    }

    /**
     * Every field forSection() returns, empty.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return ['title' => '', 'lead' => '', 'items' => []];
    }
}
