<?php

declare(strict_types=1);

namespace App\Service\Media\Usage;

use App\Database;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * The content blocks that pick their images from the Media Library: Text +
 * image split, Detailsectie (its main image and its extra images) and the
 * cards of a Kaarten-carrousel.
 *
 * ONE QUERY FOR ALL FOUR TABLES. A UNION rather than four round trips,
 * because this provider is called once per page of the library listing and
 * the listing must not grow a query per item. Each branch selects the same
 * four columns — media id, a Dutch label, the page slug and the section key
 * — so the loop below does not care which block a row came from.
 *
 * Blocks that are NOT here (Homepage hero, Item-galerij, Portfolio, the Shop
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
        // card id. `editor` carries whichever of the two this row needs.
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
        ';

        $stmt = Database::connection()->prepare($sql);
        // The same id list four times: a named placeholder cannot be reused
        // across a statement here, so each branch gets its own positional set.
        $stmt->execute(array_merge($ids, $ids, $ids, $ids));

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

        if ($pageSlug === '' || $sectionKey === '') {
            return null;
        }

        return '/admin/' . (string) $row['editor'] . '.php?section=' . rawurlencode($pageSlug . ':' . $sectionKey);
    }
}
