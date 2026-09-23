<?php

declare(strict_types=1);

namespace App\Service\Media\Usage;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * The content blocks that pick their images from the Media Library: Text +
 * image split, Detailsectie (its main image and its extra images), the cards
 * of a Kaarten-carrousel, the image behind a Paginakop, and the Homepage
 * Hero's image and video.
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
              FROM text_image_split_images i
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

            SELECT hh.media_id, \'Homepage Hero (afbeelding)\', \'homepage-hero\', hh.page_slug, NULL, NULL
              FROM homepage_hero hh
             WHERE hh.media_id IN (' . $placeholders . ')

            UNION ALL

            SELECT hh.video_media_id, \'Homepage Hero (video)\', \'homepage-hero\', hh.page_slug, NULL, NULL
              FROM homepage_hero hh
             WHERE hh.video_media_id IN (' . $placeholders . ')
        ';

        $stmt = Database::connection()->prepare($sql);
        // The same id list once per branch: a named placeholder cannot be
        // reused across a statement here, so each branch gets its own
        // positional set.
        $stmt->execute(array_merge(...array_fill(0, 7, $ids)));

        $usages = [];

        foreach ($stmt->fetchAll() as $row) {
            $mediaId = (int) $row['media_id'];
            $pageSlug = (string) ($row['page_slug'] ?? '');
            $sectionKey = (string) ($row['section_key'] ?? '');
            $label = (string) $row['kind'];

            if ($pageSlug !== '') {
                $label .= ' op "' . $pageSlug . '"';
            }

            $usages[$mediaId][] = new MediaUsage(
                source: $this->key(),
                label: $label,
                // Every editor this links to — the block screens, the
                // carousel card and the Paginakop — demands pages.manage, and
                // the label names the page.
                permission: AdminPermissions::PAGES_MANAGE,
                editUrl: $this->editUrl($row, $pageSlug, $sectionKey),
            );
        }

        return $usages;
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
