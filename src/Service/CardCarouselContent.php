<?php

namespace App\Service;

use App\Repository\CardCarouselRepository;
use App\Service\Media\BlockImage;

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
 * `index_label` ("01", "02", ...) is derived from a card's position among
 * the visible cards, never stored — the same "derived, not stored" treatment
 * it had when the carousel was generated from a fixed list of four.
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

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, lead_nl/en
     *                                and 'cards': a list (possibly empty) of
     *                                index_label, image_path (+
     *                                image_alt_nl/en), title_nl/en,
     *                                body_nl/en, link_url +
     *                                link_label_nl/en (all three ''
     *                                together when there is no link) and
     *                                'tags' (list of label_nl/en).
     *                                Templates must check 'state' !==
     *                                STATE_HIDDEN before rendering.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $empty = [
            'id' => 0,
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'cards' => [],
        ];

        try {
            $repository = new CardCarouselRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[CardCarouselContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + $empty;
        }

        $content = [
            'id' => (int) $row['id'],
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);

        try {
            $cards = $repository->findCardsByCarouselId((int) $row['id'], true);
            $tagsByCard = $repository->findTagsByCardIds(array_map(
                static fn (array $card): int => (int) $card['id'],
                $cards
            ));
        } catch (\Throwable $e) {
            error_log('[CardCarouselContent] card lookup failed for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + $empty;
        }

        $content['cards'] = [];
        foreach (array_values($cards) as $index => $card) {
            $content['cards'][] = self::card($card, $index, $tagsByCard[(int) $card['id']] ?? []);
        }

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param array<string, mixed> $card
     * @param array<int, array<string, mixed>> $tags
     *
     * @return array<string, mixed>
     */
    private static function card(array $card, int $index, array $tags): array
    {
        // Media Library first, the card's own image_path second, and the
        // card's own alt text over the media item's default — see
        // App\Service\Media\BlockImage. An empty result still means "no
        // photo, render the theme's fixed icon", exactly as before.
        $image = BlockImage::fromRow($card, 'media_id', 'image_path', 'image_alt_nl', 'image_alt_en');

        $result = [
            'id' => (int) $card['id'],
            'index_label' => sprintf('%02d', $index + 1),
            'image_path' => $image['image_path'],
            'image_alt_nl' => $image['alt_nl'],
            'image_width' => $image['width'],
            'image_height' => $image['height'],
            'title_nl' => (string) $card['title_nl'],
            'body_nl' => (string) ($card['body_nl'] ?? ''),
            'link_url' => (string) ($card['link_url'] ?? ''),
            'link_label_nl' => (string) ($card['link_label_nl'] ?? ''),
        ];

        $result['image_alt_en'] = $image['alt_en'];
        $result['title_en'] = self::valueOrDefault($card['title_en'] ?? null, $result['title_nl']);
        $result['body_en'] = self::valueOrDefault($card['body_en'] ?? null, $result['body_nl']);
        $result['link_label_en'] = self::valueOrDefault($card['link_label_en'] ?? null, $result['link_label_nl']);

        // A link only renders when it has both a label and a URL — the same
        // all-or-nothing rule every other optional button in this project
        // follows.
        if ($result['link_url'] === '' || $result['link_label_nl'] === '') {
            $result['link_url'] = '';
            $result['link_label_nl'] = '';
            $result['link_label_en'] = '';
        }

        $result['tags'] = array_map(static function (array $tag): array {
            $labelNl = (string) $tag['label_nl'];

            return [
                'label_nl' => $labelNl,
                'label_en' => self::valueOrDefault($tag['label_en'] ?? null, $labelNl),
            ];
        }, array_values($tags));

        return $result;
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }
}
