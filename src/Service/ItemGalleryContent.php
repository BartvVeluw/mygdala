<?php

namespace App\Service;

use App\Repository\ItemGalleryRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\CardPresentation;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\TypedLink;
use App\Service\Theme\ButtonStyles;

/**
 * Content for the "Portfolio-/collectiegalerij" block
 * (partials/section-item-gallery.php) — the one reusable block that renders
 * the `.gallery-grid` > `.gallery-item` component over a CHOSEN content
 * source. Phase 4 of docs/content-blocks/ROADMAP.md: it replaces both
 * Portfolio-specific fixed blocks (`portfolio_gallery` on Portfolio and
 * `portfolio_teaser` on the homepage), which rendered the same component
 * over hardcoded item sets with hardcoded display settings.
 *
 * THE SOURCE MODEL LIVES IN App\Service\ItemGallerySources: an explicit,
 * closed list of the content this block can show, every entry contributed by
 * whichever modules are enabled (the Portfolio's items, the Shop's "a
 * collection"). Deliberately NOT a query builder and not a generic
 * "entity + filters" abstraction. Requests are validated against that list
 * (isSource()), so a source key from the browser can never reach a table
 * name, a class name or a query.
 *
 * Every source yields items in ONE normalised shape
 * (App\Service\CollectionGalleryItems::mapProduct() there,
 * App\Service\PortfolioGalleryContent::mapItemRow() there), so the partial
 * has a single rendering path and knows nothing about portfolios or
 * products:
 *
 *   image_path, alt, title, subtitle (one string each, in the language of the request),
 *   categories (space-separated filter slugs),
 *   url ('' = not a link), is_detail_link (its own page, so it gets the
 *   "opens its own page" arrow), and optionally follows_fallback_link
 *   (default true: a card without a url follows the block's
 *   `fallback_link_url`; a source that decides every card's link itself says
 *   false, and such a card stays plain).
 *
 * WHICH ITEMS AND IN WHAT ORDER (Projecten 2.0) are block settings too, read
 * against closed lists here and applied by the source, which alone knows its
 * items: the scope (SCOPES: all, one category, or picked by hand), the
 * category, the order (SORTS, random included) and the maximum. A source that
 * does not read them (a collection) simply ignores them. Picked items are the
 * source's own relation, reached through ItemGallerySources.
 *
 * Display settings live on the block, not on the page: `show_filter_bar`,
 * `enable_lightbox`, `max_items`, `fallback_link_url`, `background`,
 * `tight_top` and how the cards look, `card_presentation`
 * (App\Service\Blocks\CardPresentation). Two instances on one page therefore have fully independent
 * settings — which is the point of the phase, and why the old
 * "Hele portfolio-sectie verbergen" toggle on admin/portfolio.php is gone:
 * a block's visibility is the block's own `is_active`.
 *
 * The filter bar only renders when the source actually supplies filter
 * categories (today: portfolio items and their CMS-managed categories). A
 * collection has no such taxonomy, so a collection-backed block simply has
 * no bar — a source property, not a per-page exception.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The block's own eyebrow,
 * title, lead, footer note and button label are stored per website language in
 * block_translations and come out of App\Service\Blocks\BlockLocalization as
 * one string each, in the language of the request, the fallback already
 * applied; the source and every display setting stay in item_galleries. The
 * items' own words belong to their source (Portfolio, Shop), and since phase 5
 * wave A they arrive in the same shape — one string per field, in the language
 * of the request, whichever source built it. This class decides no language
 * itself.
 *
 * `is_active = false` on an existing row is a deliberate hide, and a
 * different case from a missing row — the same three-state contract
 * (STATE_*) every other block Content class in this project uses. Like every
 * block since phase 2 there is no hardcoded fallback copy: a missing row
 * renders nothing.
 */
class ItemGalleryContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Which of its items a source that reads the scope setting shows
     * (ItemGallerySources::needsScope() — today the Portfolio's). The column
     * is still called `portfolio_scope`, after the source that brought it.
     */
    public const SCOPE_ALL = 'all';

    /** The visible items of one category (`portfolio_category_id`). */
    public const SCOPE_CATEGORY = 'category';

    /** The items picked by hand for this block, in their picked order. */
    public const SCOPE_MANUAL = 'manual';

    /** Every scope; the first is what a new block and an unknown value get. */
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_CATEGORY, self::SCOPE_MANUAL];

    /**
     * The orders of `item_sort`: the source's own order (for the Portfolio
     * "Standaard Portfolio-volgorde"), newest or oldest first, by title either
     * way, or random — a new draw per request. The first is the start.
     */
    public const SORTS = ['source', 'newest', 'oldest', 'title_asc', 'title_desc', 'random'];

    /** The only orders a hand-picked list has: its own, or random. */
    public const MANUAL_SORTS = ['source', 'random'];

    /** The two section backgrounds this theme has. */
    public const BACKGROUNDS = [
        'default' => ['label' => 'Standaard'],
        'soft' => ['label' => 'Zachte achtergrond'],
    ];

    /** The owner table of the block's words (ItemGalleryBlock::translatableFields()). */
    private const TABLE = 'item_galleries';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** Whether this request has already printed the shared lightbox overlay. */
    private static bool $lightboxOverlayClaimed = false;

    /** Whether an editor may choose this source right now. */
    public static function isSource(string $source): bool
    {
        return ItemGallerySources::isAvailable($source);
    }

    public static function isPortfolioScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    public static function isSort(string $sort): bool
    {
        return in_array($sort, self::SORTS, true);
    }

    public static function isBackground(string $background): bool
    {
        return array_key_exists($background, self::BACKGROUNDS);
    }

    public static function sourceLabel(string $source): string
    {
        return ItemGallerySources::label($source);
    }

    /**
     * One block instance, ready to render. Templates must check 'state' !==
     * STATE_HIDDEN before rendering the section at all.
     *
     * @return array<string, mixed>
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new ItemGalleryRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[ItemGalleryContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        return self::$cache[$cacheKey] = self::mapRow($row) + ['state' => self::STATE_ACTIVE];
    }

    /**
     * The same mapping used for an already-loaded row — the admin editor's
     * preview path, and how forSection() builds its result.
     *
     * @param array<string, mixed> $row an item_galleries row
     *
     * @return array<string, mixed>
     */
    public static function mapRow(array $row): array
    {
        // Two different bad values, two different answers. A source belonging
        // to a module that is switched off is KEPT as stored and simply
        // produces no items (ItemGallerySources::items()), so the block goes
        // quiet and the row is preserved intact for when the module comes
        // back. A source nothing declares at all can only come from a
        // hand-edited database, and degrades to the source a new block would
        // start with (ItemGallerySources::defaultSource()) — which is empty,
        // and so shows nothing, when no enabled module offers one.
        $source = (string) ($row['source_type'] ?? '');
        if (!ItemGallerySources::isKnown($source)) {
            error_log('[ItemGalleryContent] unknown source_type "' . $source . '" on item_galleries #' . (int) ($row['id'] ?? 0));
            $source = ItemGallerySources::defaultSource();
        }

        $scope = (string) ($row['portfolio_scope'] ?? self::SCOPE_ALL);
        if (!self::isPortfolioScope($scope)) {
            $scope = self::SCOPE_ALL;
        }

        // A hand-picked list has only its own order or a random one.
        $sort = (string) ($row['item_sort'] ?? 'source');
        if (!self::isSort($sort) || ($scope === self::SCOPE_MANUAL && !in_array($sort, self::MANUAL_SORTS, true))) {
            $sort = self::SORTS[0];
        }

        $background = (string) ($row['background'] ?? 'default');
        if (!self::isBackground($background)) {
            $background = 'default';
        }

        $collectionId = $row['collection_id'] === null ? null : (int) $row['collection_id'];
        $categoryId = ($row['portfolio_category_id'] ?? null) === null ? null : (int) $row['portfolio_category_id'];
        $maxItems = ($row['max_items'] ?? null) === null ? null : (int) $row['max_items'];

        $items = ItemGallerySources::items($source, [
            'gallery_id' => (int) ($row['id'] ?? 0),
            'portfolio_scope' => $scope,
            'category_id' => $categoryId,
            'collection_id' => $collectionId,
            'sort' => $sort,
            'max_items' => $maxItems,
        ]);
        if ($maxItems !== null && $maxItems > 0) {
            $items = array_slice($items, 0, $maxItems);
        }

        $showFilterBar = (bool) $row['show_filter_bar'];

        $galleryId = (int) ($row['id'] ?? 0);
        $words = BlockLocalization::words(self::TABLE, $galleryId);

        // A button needs a label in the DEFAULT language (a translation alone
        // never makes one appear), like every other block's button.
        if (!BlockLocalization::hasDefaultWords(self::TABLE, $galleryId, 'button_label')) {
            $words['button_label'] = '';
        }

        return $words + [
            'id' => (int) ($row['id'] ?? 0),
            'source_type' => $source,
            'portfolio_scope' => $scope,
            'portfolio_category_id' => $categoryId,
            'item_sort' => $sort,
            'collection_id' => $collectionId,
            'max_items' => $maxItems,
            'show_filter_bar' => $showFilterBar,
            'enable_lightbox' => (bool) $row['enable_lightbox'],
            // Typed by an editor, printed in the language being read
            // (App\Service\Routing\TypedLink).
            'fallback_link_url' => TypedLink::href((string) ($row['fallback_link_url'] ?? '')),
            'button_url' => TypedLink::href((string) ($row['button_url'] ?? '')),
            // Button Styles 2.0: the button's choice, null = the default.
            'button_style' => ButtonStyles::storedChoice($row['button_style_id'] ?? null),
            'background' => $background,
            'tight_top' => (bool) $row['tight_top'],
            // How the cards look (Card Presentation 2.0); an unknown or
            // missing value is the default, the cards as they always were.
            'card_presentation' => CardPresentation::stored($row['card_presentation'] ?? null),
            // Only a source that HAS a taxonomy can offer a filter bar; a
            // collection has none, so the setting simply has nothing to draw.
            // The source itself decides — this class does not know which
            // sources have categories.
            'filter_categories' => $showFilterBar ? ItemGallerySources::filterCategories($source) : [],
            'items' => $items,
        ];
    }

    /**
     * True for the FIRST block with a zoomable card on a page and false for
     * every one after it: they all share one overlay element
     * (partials/lightbox.php; assets/js/lightbox.js looks up a single
     * `[data-lightbox]`), so exactly one block prints it. Kept here rather than in a static inside the partial so
     * clearCache() can reset it — a test that renders several blocks in one
     * process is otherwise stuck with whichever one ran first.
     */
    public static function claimLightboxOverlay(): bool
    {
        if (self::$lightboxOverlayClaimed) {
            return false;
        }

        return self::$lightboxOverlayClaimed = true;
    }

    /**
     * Whether this gallery's settings can ever give it items — what the page
     * builder's "Leeg blok" asks of a gallery that shows none today
     * (ItemGalleryBlock::hasContent()). A gallery is a dynamic block: one
     * whose source is set is content, even while that source holds nothing.
     * It is not when there is nothing to draw from: no source, a collection
     * gallery without its collection, or a hand-picked list with nothing
     * picked. A source of a module that is off is not judged here.
     *
     * @param array<string, mixed> $content forSection() in STATE_ACTIVE
     */
    public static function isConfigured(array $content): bool
    {
        $source = (string) ($content['source_type'] ?? '');

        if ($source === '') {
            return false;
        }

        if (!ItemGallerySources::isAvailable($source)) {
            return true;
        }

        if (ItemGallerySources::needsCollection($source)) {
            return ($content['collection_id'] ?? null) !== null;
        }

        if (($content['portfolio_scope'] ?? self::SCOPE_ALL) === self::SCOPE_MANUAL && ItemGallerySources::supportsSelection($source)) {
            return ItemGallerySources::selectedItems($source, (int) ($content['id'] ?? 0)) !== [];
        }

        return true;
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handler right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$lightboxOverlayClaimed = false;
        BlockLocalization::clearCache();
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return BlockLocalization::words(self::TABLE, 0) + [
            'id' => 0,
            'source_type' => '',
            'portfolio_scope' => self::SCOPE_ALL,
            'portfolio_category_id' => null,
            'item_sort' => self::SORTS[0],
            'collection_id' => null,
            'max_items' => null,
            'show_filter_bar' => false,
            'enable_lightbox' => false,
            'fallback_link_url' => '',
            'button_url' => '',
            'button_style' => null,
            'background' => 'default',
            'tight_top' => false,
            'card_presentation' => CardPresentation::DEFAULT,
            'filter_categories' => [],
            'items' => [],
        ];
    }
}
