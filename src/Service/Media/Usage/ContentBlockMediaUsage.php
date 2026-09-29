<?php

declare(strict_types=1);

namespace App\Service\Media\Usage;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * The content blocks that pick their images from the Media Library: every
 * item of a Tekst met afbeelding, Detailsectie (its main image and its extra
 * images), the cards of a Kaarten-carrousel and the icon of a card's label,
 * the image behind a Paginakop,
 * the Homepage Hero's image and video, the icons of Kenmerken in kaartjes,
 * the background picture of an Oproep met knop, the picture or video of a
 * Mediabanner with its poster, both pictures of every card of a Hover
 * kaarten grid, and every further item of a media sequence (a Paginakop's
 * and a Mediabanner's, App\Service\Media\MediaSequence) — and the phone's
 * own picture of every one of them that has one (Responsive Media 2.0, the
 * <prefix>mobile_media_id columns of App\Service\Media\ResponsiveImageSlot).
 *
 * ONE QUERY FOR ALL OF THEM. A UNION rather than five round trips,
 * because this provider is called once per page of the library listing and
 * the listing must not grow a query per item. Each branch selects the same
 * columns — media id, a Dutch label, the editor, the page slug, the section
 * key and a card id — so the loop below does not care which block a row came
 * from.
 *
 * Blocks that are NOT here (Item-galerij, Portfolio, the Shop
 * blocks) still own their own image paths and are deliberately untouched in
 * V1; they are simply absent from this list rather than reported as unused.
 * MEDIA.md keeps that list, and a block joining the library later adds one
 * branch here.
 */
final class ContentBlockMediaUsage extends MediaUsageProvider
{
    public function key(): string
    {
        return 'content_blocks';
    }

    public function label(): string
    {
        return 'Content-blokken';
    }

    public function usagesFor(array $mediaIds): array
    {
        $ids = array_values(array_map('intval', $mediaIds));
        $placeholders = $this->placeholders(count($ids));

        // The editor URL of a block instance is
        // admin/<type>.php?section=<page_slug>:<section_key> (CONTENT-BLOCKS.md),
        // except a carousel CARD, which has a screen of its own keyed on the
        // card id, and a Paginakop, which is addressed by its page alone.
        // `editor` carries whichever of those this row needs.
        $sql = '
            SELECT i.media_id           AS media_id,
                   \'Tekst + afbeelding\' AS kind,
                   \'text-image-split\'   AS editor,
                   s.page_slug          AS page_slug,
                   s.section_key        AS section_key,
                   NULL                 AS card_id
              FROM text_image_split_items i
              JOIN text_image_splits s ON s.id = i.text_image_split_id
             WHERE i.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT d.main_media_id, \'Detailsectie (hoofdafbeelding)\', \'detail-section\', d.page_slug, d.section_key, NULL
              FROM detail_sections d
             WHERE d.main_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT di.media_id, \'Detailsectie (afbeelding)\', \'detail-section\', d.page_slug, d.section_key, NULL
              FROM detail_section_images di
              JOIN detail_sections d ON d.id = di.section_id
             WHERE di.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT c.media_id, \'Carrousel-kaart\', \'carousel-card\', ca.page_slug, ca.section_key, c.id
              FROM carousel_cards c
              JOIN card_carousels ca ON ca.id = c.carousel_id
             WHERE c.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT h.media_id, \'Paginakop\', \'page-hero\', h.page_slug, NULL, NULL
              FROM page_heroes h
             WHERE h.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hs.media_id, \'Paginakop (diavoorstelling)\', \'page-hero\', h.page_slug, NULL, NULL
              FROM page_hero_images hs
              JOIN page_heroes h ON h.id = hs.page_hero_id
             WHERE hs.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hh.media_id, \'Homepage Hero (afbeelding)\', \'homepage-hero\', hh.page_slug, NULL, NULL
              FROM homepage_hero hh
             WHERE hh.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hh.video_media_id, \'Homepage Hero (video)\', \'homepage-hero\', hh.page_slug, NULL, NULL
              FROM homepage_hero hh
             WHERE hh.video_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT fi.icon_media_id, \'Kenmerken in kaartjes (icoon)\', \'feature-grid\', fg.page_slug, fg.section_key, NULL
              FROM feature_grid_items fi
              JOIN feature_grids fg ON fg.id = fi.feature_grid_id
             WHERE fi.icon_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT cb.background_media_id, \'Oproep met knop (achtergrond)\', \'cta-band\', cb.page_slug, cb.section_key, NULL
              FROM cta_bands cb
             WHERE cb.background_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT mb.media_id, \'Mediabanner\', \'media-banner\', mb.page_slug, mb.section_key, NULL
              FROM media_banners mb
             WHERE mb.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT mb.poster_media_id, \'Mediabanner (poster)\', \'media-banner\', mb.page_slug, mb.section_key, NULL
              FROM media_banners mb
             WHERE mb.poster_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT mi.media_id, \'Mediabanner (reeks)\', \'media-banner\', mb.page_slug, mb.section_key, NULL
              FROM media_banner_items mi
              JOIN media_banners mb ON mb.id = mi.media_banner_id
             WHERE mi.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hi.media_id, \'Hover kaarten grid\', \'hover-card-grid\', hg.page_slug, hg.section_key, NULL
              FROM hover_card_grid_items hi
              JOIN hover_card_grids hg ON hg.id = hi.hover_card_grid_id
             WHERE hi.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hi.hover_media_id, \'Hover kaarten grid (tweede afbeelding)\', \'hover-card-grid\', hg.page_slug, hg.section_key, NULL
              FROM hover_card_grid_items hi
              JOIN hover_card_grids hg ON hg.id = hi.hover_card_grid_id
             WHERE hi.hover_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT i.image_mobile_media_id, \'Tekst + afbeelding (telefoon)\', \'text-image-split\', s.page_slug, s.section_key, NULL
              FROM text_image_split_items i
              JOIN text_image_splits s ON s.id = i.text_image_split_id
             WHERE i.image_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT c.image_mobile_media_id, \'Carrousel-kaart (telefoon)\', \'carousel-card\', ca.page_slug, ca.section_key, c.id
              FROM carousel_cards c
              JOIN card_carousels ca ON ca.id = c.carousel_id
             WHERE c.image_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT h.image_mobile_media_id, \'Paginakop (telefoon)\', \'page-hero\', h.page_slug, NULL, NULL
              FROM page_heroes h
             WHERE h.image_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hh.image_mobile_media_id, \'Homepage Hero (telefoon)\', \'homepage-hero\', hh.page_slug, NULL, NULL
              FROM homepage_hero hh
             WHERE hh.image_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT cb.background_mobile_media_id, \'Oproep met knop (achtergrond, telefoon)\', \'cta-band\', cb.page_slug, cb.section_key, NULL
              FROM cta_bands cb
             WHERE cb.background_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT mb.image_mobile_media_id, \'Mediabanner (telefoon)\', \'media-banner\', mb.page_slug, mb.section_key, NULL
              FROM media_banners mb
             WHERE mb.image_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hi.image_mobile_media_id, \'Hover kaarten grid (telefoon)\', \'hover-card-grid\', hg.page_slug, hg.section_key, NULL
              FROM hover_card_grid_items hi
              JOIN hover_card_grids hg ON hg.id = hi.hover_card_grid_id
             WHERE hi.image_mobile_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT c.label_icon_media_id, \'Carrousel-kaart (label-icoon)\', \'carousel-card\', ca.page_slug, ca.section_key, c.id
              FROM carousel_cards c
              JOIN card_carousels ca ON ca.id = c.carousel_id
             WHERE c.label_icon_media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT di.image_mobile_media_id, \'Detailsectie (galerij, telefoon)\', \'detail-section\', d.page_slug, d.section_key, NULL
              FROM detail_section_images di
              JOIN detail_sections d ON d.id = di.section_id
             WHERE di.image_mobile_media_id IN (' . $placeholders . ')
        ';

        $stmt = Database::connection()->prepare($sql);
        // The same id list once per branch: a named placeholder cannot be
        // reused across a statement here, so each branch gets its own
        // positional set.
        $stmt->execute(array_merge(...array_fill(0, substr_count($sql, 'UNION ALL') + 1, $ids)));

        $usages = [];

        foreach ($stmt->fetchAll() as $row) {
            $mediaId = (int) $row['media_id'];
            $pageSlug = (string) ($row['page_slug'] ?? '');
            $sectionKey = (string) ($row['section_key'] ?? '');
            $label = (string) $row['kind'];

            if ($pageSlug !== '') {
                $label .= ' op "' . $this->ownerName($pageSlug) . '"';
            }

            $usages[$mediaId][] = new MediaUsage(
                source: $this->key(),
                label: $label,
                // Who may open the editor this links to: the permission of the
                // block list, by its owner (App\Service\ContentOwners\ContentBlockAccess)
                // — pages.manage on a page, products.manage on a product,
                // portfolio.manage on a project. Whoever lacks it is told the
                // item is used, never where (VisibleMediaUsages).
                permission: $this->permission($row, $pageSlug),
                editUrl: $this->editUrl($row, $pageSlug, $sectionKey),
            );
        }

        return $usages;
    }

    /**
     * What the label calls the block list a picture is used on: the page's
     * storage key, as it always did — except for the content page of a
     * product or project (App\Service\ContentOwners\ContentPages), whose key
     * ("product_12") means nothing to an editor, so its owner is named
     * instead ("Product: Eiken plank"). Asked only for such a key, which an
     * ordinary page's slug-shaped key never is.
     */
    private function ownerName(string $pageSlug): string
    {
        if (!str_contains($pageSlug, '_')) {
            return $pageSlug;
        }

        $page = \App\Service\PageContent::forContentKey($pageSlug);
        $name = $page === null ? '' : \App\Service\ContentOwners\ContentPages::name($page);

        return $name !== '' ? $name : $pageSlug;
    }

    /**
     * The permission of the block list a usage is on, from its `pages` row
     * (ContentBlockAccess::permissionFor()), never from the block type or the
     * key: the holder page of a product's blocks asks products.manage, a
     * page's pages.manage. The Homepage Hero and a Paginakop are only ever on
     * an ordinary page; a list without a page row (a fixed block's own page)
     * is one; a content page whose kind no registered module knows falls back
     * to pages.manage, whose editor refuses it anyway.
     *
     * @param array<string, mixed> $row
     */
    private function permission(array $row, string $pageSlug): string
    {
        if (in_array((string) $row['editor'], ['homepage-hero', 'page-hero'], true) || $pageSlug === '') {
            return AdminPermissions::PAGES_MANAGE;
        }

        // PageContent caches the row per request, as ownerName() asks it too.
        $page = \App\Service\PageContent::forContentKey($pageSlug);
        $permission = $page === null ? null : \App\Service\ContentOwners\ContentBlockAccess::permissionFor($page);

        return $permission ?? AdminPermissions::PAGES_MANAGE;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function editUrl(array $row, string $pageSlug, string $sectionKey): ?string
    {
        $cardId = $row['card_id'] ?? null;

        if ($cardId !== null) {
            return '/admin/carousel-card.php?card_id=' . (int) $cardId;
        }

        // The Homepage Hero has one editor of its own, for the one Hero.
        if ((string) $row['editor'] === 'homepage-hero') {
            return '/admin/homepage-hero.php';
        }

        // One Paginakop per page and no section_key, so its editor takes the
        // page slug (App\Service\Blocks\PageHeroBlock::editUrl()).
        if ((string) $row['editor'] === 'page-hero') {
            return $pageSlug === '' ? null : '/admin/page-hero.php?slug=' . rawurlencode($pageSlug);
        }

        if ($pageSlug === '' || $sectionKey === '') {
            return null;
        }

        return '/admin/' . (string) $row['editor'] . '.php?section=' . rawurlencode($pageSlug . ':' . $sectionKey);
    }
}
