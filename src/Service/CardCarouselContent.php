<?php

namespace App\Service;

use App\Repository\CardCarouselRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\TypedLink;

/**
 * Content for the "Kaarten-carrousel" page-builder block
 * (partials/section-card-carousel.php) — a section heading plus an ordered
 * list of cards, rendered in the theme's existing orbit carousel.
 *
 * Phase 3 of the content-block refactor replaced the homepage's fixed
 * `services_carousel` with this type. That block filled itself automatically
 * from the four material services, so an editor could neither choose which
 * cards appeared nor how many there were; here the cards are ROWS an editor
 * curates. Nothing in this class or its template assumes a card count — a
 * carousel with zero, one or twenty cards is equally valid — and nothing in
 * it knows about services, so the same block type can be placed on any page
 * the registry allows.
 *
 * A card's fields are exactly what the existing carousel markup renders and
 * no more: image (+ alt), title, body text, tags and one link button. An
 * empty image_path means "render the theme's fixed icon instead of a photo",
 * which is what the Acryl & glas card has always done — the template only
 * has to check whether the path is empty, so there is no separate
 * presentation-mode field.
 *
 * `index_label` is what the card prints above its title: the card's own
 * `number_label` word ("01", "Nieuw", ...), and nothing at all when it is
 * empty. Until 2026-09 an empty label printed the card's place among the
 * visible cards; db/migrations/20260923170000 wrote that number into every
 * card that showed one, so no site changed, and an owner can now leave a
 * label empty on purpose.
 *
 * `image_position` is the CSS object-position of the card's focus point
 * (App\Service\Media\ImageFocus): which part of the cropped picture stays
 * in view. The editor's preview uses the same value.
 *
 * THE BUTTON points at what `link_type` says: 'url' is the address typed in
 * link_url, translated per render by TypedLink; 'page', 'blog_post' and
 * 'product' are an item of this website by id (link_target_id), resolved by
 * App\Service\Routing\LinkTargets in the language being read — and left out
 * when that item cannot be opened or its module is off. A NULL link_type with
 * an address is a row written before the type existed and reads as 'url'.
 *
 * `desktop_layout` is how the cards sit on a screen wider than a phone: the
 * rotating 'orbit' every carousel had, or 'row', side by side as on a phone.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The heading, every card's
 * title, body, alt text and link label and every tag's label are stored per
 * website language in block_translations, each on the id of its own row, three
 * levels deep (CardCarouselBlock::childTables()). They come out of
 * App\Service\Blocks\BlockLocalization as one string per field, in the
 * language of the request, the fallback already applied; is_active, the order,
 * the link URL and the image reference stay in the tables. This class decides
 * no language itself. The words of the carousel, all of its cards and all of
 * their tags are loaded in one query (BlockLocalization::preloadBlocks()), so
 * reading a card or a tag never costs a query of its own.
 *
 * The default language decides whether a card or a tag is there at all: a
 * card without a title in it, or a tag without a label in it, is left out,
 * exactly as a row without its Dutch words could not exist before. A link
 * button needs a URL and a label in the default language, the label every
 * other language falls back to.
 *
 * There are no hardcoded DEFAULTS: like every block phase 2 converted, this
 * type's content lives in the database only, so a missing row (or an
 * unreachable database) renders nothing at all.
 */
class CardCarouselContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /** The rotating carousel: what every carousel was before the choice existed. */
    public const LAYOUT_ORBIT = 'orbit';

    /** The cards side by side, as a phone shows them, on every screen. */
    public const LAYOUT_ROW = 'row';

    /** @var list<string> */
    public const LAYOUTS = [self::LAYOUT_ORBIT, self::LAYOUT_ROW];

    /** The owner tables of this block's words (CardCarouselBlock::translatableFields()). */
    /**
     * Where the carousel's heading (bovenlabel, titel and lead) sits, a
     * closed list: 'left' is how every carousel looked before the choice
     * existed and stays the default (no class). The cards themselves do not
     * follow it.
     */
    public const HEADER_ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * How tall the picture of every card of this carousel is, a closed list:
     * 'medium' is today's height and the default (no class); 'small' leaves
     * more of the card to its text, 'large' gives the picture more. The
     * heights are tokens in assets/css/blocks/card-carousel.css, the same for
     * every card of one carousel.
     */
    public const IMAGE_HEIGHTS = ['small', 'medium', 'large'];

    private const TABLE = 'card_carousels';
    private const CARDS = 'carousel_cards';
    private const TAGS = 'carousel_card_tags';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus eyebrow,
     *                                title and lead (a string each)
     *                                and 'cards': a list (possibly empty) of
     *                                desktop_layout (one of LAYOUTS),
     *                                header_align (one of HEADER_ALIGNMENTS),
     *                                image_height (one of IMAGE_HEIGHTS),
     *                                index_label ('' for none), image_path
     *                                (+ image_alt, a string, image_width /
     *                                image_height and image_position), title, body and
     *                                link_label (a string each),
     *                                link_url (with link_label empty and
     *                                link_url '' together when there is no
     *                                link) and 'tags' (a list of 'label', a
     *                                string each). Templates must
     *                                check 'state' !== STATE_HIDDEN before
     *                                rendering.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new CardCarouselRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[CardCarouselContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + self::emptyContent();
        }

        $carouselId = (int) $row['id'];

        // The words of the carousel, of every card and of every tag at once;
        // nothing when the page already loaded them
        // (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$carouselId]]);

        $content = [
            'id' => $carouselId,
            'desktop_layout' => self::layout((string) ($row['desktop_layout'] ?? '')),
            'header_align' => self::headerAlign((string) ($row['header_align'] ?? '')),
            'image_height' => self::imageHeight((string) ($row['image_height'] ?? '')),
        ] + BlockLocalization::words(self::TABLE, $carouselId);

        try {
            $cards = $repository->findCardsByCarouselId($carouselId, true);
            $tagsByCard = $repository->findTagsByCardIds(array_map(
                static fn (array $card): int => (int) $card['id'],
                $cards
            ));
        } catch (\Throwable $e) {
            error_log('[CardCarouselContent] card lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        $content['cards'] = [];
        foreach ($cards as $card) {
            if (!BlockLocalization::hasRequiredWords(self::CARDS, (int) $card['id'])) {
                continue;
            }

            $content['cards'][] = self::card($card, $tagsByCard[(int) $card['id']] ?? []);
        }

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
     * @param array<string, mixed> $card
     * @param array<int, array<string, mixed>> $tags
     *
     * @return array<string, mixed>
     */
    private static function card(array $card, array $tags): array
    {
        $cardId = (int) $card['id'];

        // Media Library first, the card's own image_path second, and the
        // card's own alt text in each language over the media item's default
        // — see App\Service\Media\BlockImage. An empty result still means "no
        // photo, render the theme's fixed icon", exactly as before.
        $image = BlockImage::fromOwner($card, BlockLocalization::text(self::CARDS, $cardId, 'image_alt'));

        $result = [
            'id' => $cardId,
            'image_path' => $image['image_path'],
            'image_width' => $image['width'],
            'image_height' => $image['height'],
            'image_position' => ImageFocus::objectPosition($card['image_focus'] ?? null),
        ] + BlockLocalization::words(self::CARDS, $cardId);

        $result['index_label'] = (string) ($result['number_label'] ?? '');
        unset($result['number_label']);
        $result['image_alt'] = $image['alt'];
        $result['link_url'] = self::href($card);

        // A link only renders when it has both a label and a URL — the same
        // all-or-nothing rule every other optional button in this project
        // follows. The label that counts is the default language's, the one
        // every other language falls back to.
        $defaultLabel = BlockLocalization::raw(self::CARDS, $cardId, 'link_label', BlockLocalization::defaultLanguage());

        if ($result['link_url'] === '' || $defaultLabel === '') {
            $result['link_url'] = '';
            $result['link_label'] = '';
        }

        $result['tags'] = [];
        foreach ($tags as $tag) {
            $tagId = (int) $tag['id'];

            if (BlockLocalization::hasRequiredWords(self::TAGS, $tagId)) {
                $result['tags'][] = BlockLocalization::words(self::TAGS, $tagId);
            }
        }

        return $result;
    }

    /** A stored layout, or the orbit for anything this class does not know. */
    public static function layout(string $stored): string
    {
        return in_array($stored, self::LAYOUTS, true) ? $stored : self::LAYOUT_ORBIT;
    }

    /** A stored heading alignment, or the default for anything this class does not know. */
    public static function headerAlign(string $stored): string
    {
        return in_array($stored, self::HEADER_ALIGNMENTS, true) ? $stored : self::HEADER_ALIGNMENTS[0];
    }

    /** A stored picture height, or the default ('medium') for anything this class does not know. */
    public static function imageHeight(string $stored): string
    {
        return in_array($stored, self::IMAGE_HEIGHTS, true) ? $stored : 'medium';
    }

    /**
     * Where a card's button goes, in the language being read, or '' for no
     * button.
     *
     * @param array<string, mixed> $card a carousel_cards row
     */
    public static function href(array $card): string
    {
        return LinkChoice::href($card['link_type'] ?? null, $card['link_target_id'] ?? 0, (string) ($card['link_url'] ?? ''));
    }

    /**
     * The shape of a carousel with nothing to show.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return ['id' => 0, 'desktop_layout' => self::LAYOUT_ORBIT, 'header_align' => self::headerAlign(''), 'image_height' => self::imageHeight('')]
            + BlockLocalization::words(self::TABLE, 0) + ['cards' => []];
    }
}
