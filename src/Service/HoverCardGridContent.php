<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\HoverCardGridRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\RequestLanguage;

/**
 * Read model of the Hover kaarten grid block (App\Service\Blocks\HoverCardGridBlock):
 * an optional heading above a grid of picture cards that react to a pointer
 * and to the keyboard (CONTENT-BLOCKS.md, "Hover kaarten grid").
 *
 * EVERY CHOICE IS A WORD FROM A CLOSED LIST (CONTENT-BLOCKS.md, "Een
 * weergavekeuze is een woord uit een gesloten lijst"). The first word of each
 * list below is its default and what a new grid starts with; a stored value
 * this class does not know reads as that default. What a word looks like is
 * assets/css/blocks/hover-card-grid.css's, never a value stored here.
 *
 * A CARD IS A PICTURE FIRST. It shows when its picture is a picture of the
 * Media Library; the editor refuses a card without one, and a card whose
 * picture is gone (only possible outside the editor: the library refuses to
 * delete a used item) is left out rather than drawn as an empty frame. Its
 * alt text is the library's; the second picture is an alternative view that
 * only a pointer or the keyboard brings up, so it is decoration (alt="").
 *
 * WORDS PER LANGUAGE. The grid's eyebrow, title and lead and each card's
 * badge, title, text and link label are in block_translations and arrive as
 * one string each in the language of the request, the fallback to the
 * default language applied (App\Service\Blocks\BlockLocalization). All of them
 * are optional, and the default language decides whether each one is there,
 * as it does everywhere (docs/multilingual/ARCHITECTURE.md): a word that only
 * exists as a translation is not shown.
 *
 * A LINK NEEDS A NAME. A card goes somewhere through LinkChoice (a page, blog
 * post or product by id, or a typed address), resolved per render in the
 * language of the request. It is only a link when that address resolves AND
 * the card has a title or a link label to name it: the whole card becomes
 * clickable through one real link, and a link without words would be a link
 * a screen reader cannot name. The editor refuses that combination; this is
 * the second line.
 *
 * The three states of every block (CONTENT-BLOCKS.md): no row or a failed
 * lookup is STATE_FALLBACK, a row switched off is STATE_HIDDEN. Both render
 * nothing, and so does an active grid without a card to show.
 */
final class HoverCardGridContent
{
    public const STATE_FALLBACK = 'fallback';

    public const STATE_ACTIVE = 'active';

    public const STATE_HIDDEN = 'hidden';

    /** The owner tables of the words (HoverCardGridBlock::translatableFields()). */
    public const TABLE = 'hover_card_grids';

    public const ITEMS = 'hover_card_grid_items';

    /** Where a card's words sit: over its picture, or under it. */
    public const LAYOUTS = ['overlay', 'open'];

    /** The shape of a card's picture: the theme's rounded corners, none, a circle, or an organic blob. */
    public const SHAPES = ['rounded', 'square', 'circle', 'organic'];

    /** How many cards stand side by side on a wide screen; fewer on smaller ones. */
    public const COLUMNS = ['3', '2', '4'];

    /** How much of an overlay card's picture the veil behind its words covers. */
    public const OVERLAYS = ['medium', 'light', 'dark'];

    /** How much a card moves when it is reached: the zoom, the lift, the change of an organic shape. */
    public const EFFECTS = ['normal', 'subtle'];

    /** Where the heading above the grid sits. */
    public const HEADER_ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * Each choice and its closed list; the first word is the default.
     *
     * @var array<string, list<string>>
     */
    public const CHOICES = [
        'layout' => self::LAYOUTS,
        'shape' => self::SHAPES,
        'columns' => self::COLUMNS,
        'overlay' => self::OVERLAYS,
        'effect' => self::EFFECTS,
        'header_align' => self::HEADER_ALIGNMENTS,
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*); eyebrow, title and
     *     lead (a string each, '' when empty); the six choices, each a word of
     *     its list; and 'cards', a list of: image (src, alt, width, height),
     *     picture (the main picture as partials/responsive-image.php prints
     *     it: focus point, fit, a phone picture of its own),
     *     hover_image (null, or src, width, height), badge, title, body,
     *     link_label and href (a string each, href '' for a card that goes
     *     nowhere). Templates must check 'state' !== STATE_HIDDEN first; the
     *     fields are present (empty, the choices at their defaults) in every
     *     state.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new HoverCardGridRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
            $items = $row !== null && (bool) $row['is_active'] ? $repository->findItemsByGridId((int) $row['id']) : [];
        } catch (\Throwable $e) {
            error_log('[HoverCardGridContent] lookup failed for "' . $pageSlug . ':' . $sectionKey . '": ' . $e->getMessage());
            $row = null;
            $items = [];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + self::emptyContent();
        }

        $gridId = (int) $row['id'];

        // The words of the grid and of every card at once; nothing when the
        // page already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$gridId]]);

        // Every picture of the grid in one query; each card then reads its
        // own from MediaService's per-request cache.
        MediaService::preload(array_merge(
            array_map(static fn (array $item): ?int => isset($item['media_id']) ? (int) $item['media_id'] : null, $items),
            array_map(static fn (array $item): ?int => isset($item['hover_media_id']) ? (int) $item['hover_media_id'] : null, $items)
        ));

        $cards = [];
        foreach ($items as $item) {
            $card = self::card($item);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE]
            + self::shownWords(self::TABLE, $gridId, ['eyebrow', 'title', 'lead'])
            + self::settings($row)
            + ['cards' => $cards];
    }

    /**
     * The six choices of a stored row, a refused save's hand-back or a
     * posted form, each checked against its list: a value the list does not
     * know is the default. For the page and the editor alike.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string> choice => word
     */
    public static function settings(array $values): array
    {
        $settings = [];
        foreach (self::CHOICES as $choice => $list) {
            $value = $values[$choice] ?? null;
            $settings[$choice] = is_scalar($value) && in_array((string) $value, $list, true) ? (string) $value : $list[0];
        }

        return $settings;
    }

    /**
     * The picture a card may show: a picture of the library, never a video
     * or a file no kind claims. The endpoint reads a posted id through this
     * too, so what can be stored is what can be shown.
     */
    public static function picture(?int $id): ?MediaItem
    {
        $item = MediaService::find($id);

        return $item !== null && $item->isPicture() ? $item : null;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * One stored card as the partial gets it, or null when it has no picture
     * to show.
     *
     * @param array<string, mixed> $item a hover_card_grid_items row
     *
     * @return array<string, mixed>|null
     */
    private static function card(array $item): ?array
    {
        $picture = self::picture(isset($item['media_id']) ? (int) $item['media_id'] : null);
        if ($picture === null) {
            return null;
        }

        $itemId = (int) $item['id'];
        $hover = self::picture(isset($item['hover_media_id']) ? (int) $item['hover_media_id'] : null);
        // The same picture twice is no second picture.
        if ($hover !== null && $hover->id === $picture->id) {
            $hover = null;
        }

        $image = self::image($picture) + ['alt' => trim($picture->altText)];
        $card = [
            'image' => $image,
            'picture' => ResponsiveImage::fromRow($item, self::imageSlot())->forRender($image),
            'hover_image' => $hover !== null ? self::image($hover) : null,
        ] + self::shownWords(self::ITEMS, $itemId, ['badge', 'title', 'body', 'link_label']);

        // The shared rule of every block link (LinkChoice): an item of the
        // site by id, resolved now in the language being read, or the typed
        // address. Only a link a visitor's assistive technology can name.
        $href = LinkChoice::href($item['link_type'] ?? null, $item['link_target_id'] ?? 0, (string) ($item['link_url'] ?? ''));
        $named = $card['title'] !== '' || $card['link_label'] !== '';
        $card['href'] = $named ? $href : '';
        if ($card['href'] === '') {
            $card['link_label'] = '';
        }

        return $card;
    }

    /**
     * Where a card keeps its main picture's presentation (Responsive Media
     * 2.0): the image_ columns of hover_card_grid_items, with a fit of its
     * own — the picture sits in the grid's shape — and no phone height: the
     * shape decides the frame on every screen. The second picture, shown on
     * a hover, takes none: it is an alternative view that fills the same
     * frame from its middle.
     */
    public static function imageSlot(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('image_', 'media_id', fit: true);
    }

    /**
     * @return array{src: string, width: int|null, height: int|null}
     */
    private static function image(MediaItem $item): array
    {
        return [
            'src' => $item->publicPath(),
            'width' => $item->hasDimensions() ? $item->width : null,
            'height' => $item->hasDimensions() ? $item->height : null,
        ];
    }

    /**
     * The words of some fields of one owner, each in the request's language,
     * and each '' unless the default language has it.
     *
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private static function shownWords(string $table, int $ownerId, array $fields): array
    {
        $words = [];
        foreach ($fields as $field) {
            $words[$field] = BlockLocalization::hasDefaultWords($table, $ownerId, $field)
                ? BlockLocalization::text($table, $ownerId, $field)
                : '';
        }

        return $words;
    }

    /**
     * Every field forSection() returns, empty and at its default.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return ['eyebrow' => '', 'title' => '', 'lead' => ''] + self::settings([]) + ['cards' => []];
    }
}
