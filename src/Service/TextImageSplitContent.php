<?php

namespace App\Service;

use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\TypedLink;

/**
 * Content for the "Tekst met afbeelding" block: an ordered list of ITEMS,
 * each a text beside at most one picture (Tekst met afbeelding 2.0,
 * db/migrations/20260924100000). Same repeater architecture as
 * App\Service\FaqContent: a block row, and child rows that each own their
 * words.
 *
 * AN ITEM has, the same in every language: its picture (a Media Library
 * item), which side the picture is on, the picture's share of the row, how
 * high the picture is, which part of a cropped picture stays in view, and a
 * button address. Per website language (BlockLocalization): an eyebrow, a
 * title, a rich-text body, a button label and the picture's own alt text.
 * The layout is four closed lists of keys (SIDES, COLUMNS, HEIGHTS and
 * App\Service\Media\ImageFocus); what they look like is
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

    /** How high the picture is (CSS tokens in text-image-split.css). */
    public const HEIGHTS = ['small', 'medium', 'large'];

    /** What a new item starts with: picture on the right, as a new block always had it. */
    public const DEFAULTS = [
        'image_side' => 'right',
        'image_column' => '50',
        'image_height' => 'medium',
        'image_focus' => ImageFocus::DEFAULT,
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
     * @return array<string, mixed> 'state' (one of STATE_*) and 'items': a
     *                                list of image_side, image_column,
     *                                image_height, image_focus (keys),
     *                                eyebrow, title,
     *                                body (sanitized HTML), button_label,
     *                                button_url (both '' when there is no
     *                                button) and image: null, or image_path,
     *                                alt, width, height and media_id.
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
     * @return array{image_side: string, image_column: string, image_height: string, image_focus: string}
     */
    public static function layout(array $values): array
    {
        $pick = static fn (mixed $value, array $allowed, string $default): string
            => is_string($value) && in_array($value, $allowed, true) ? $value : $default;

        return [
            'image_side' => $pick($values['image_side'] ?? null, self::SIDES, self::DEFAULTS['image_side']),
            'image_column' => $pick($values['image_column'] ?? null, self::COLUMNS, self::DEFAULTS['image_column']),
            'image_height' => $pick($values['image_height'] ?? null, self::HEIGHTS, self::DEFAULTS['image_height']),
            'image_focus' => ImageFocus::normalise($values['image_focus'] ?? null),
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
        $buttonUrl = TypedLink::href((string) ($item['button_url'] ?? ''));

        // A button only renders with a label in the default language and a
        // URL — a half-filled optional button would be broken or dead.
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

        return $layout + [
            'eyebrow' => $words['eyebrow'],
            'title' => $words['title'],
            'body' => $hasBody ? $words['body'] : '',
            'button_label' => $words['button_label'],
            'button_url' => $buttonUrl,
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
        return ['items' => []];
    }
}
