<?php

namespace App\Repository;

/**
 * All portfolio_item_images SQL: the extra photos of an item's project page,
 * in their own order (db/migrations/20260906060000_create_portfolio_item_images_table.php).
 *
 * Portfolio 2.0 edits them again (MODULES.md, "Portfolio"). A photo is a
 * Media Library item (`media_id`, 20260925160000) with its path and thumbnail
 * written along, the pattern every integrated table follows; a photo from
 * before the library keeps its own file and media_id NULL until it is
 * removed. The item's own picture (portfolio_gallery_items.media_id) is NOT
 * one of these rows: it stays the separate main image, shown first.
 *
 * Same shape/conventions as the old VariantImageRepository: no is_primary flag, the
 * first row in sort_order order is simply the first image in the gallery.
 *
 * This repository never touches a file. Which removed photo's file may go
 * (only an old one on Portfolio's own path, never a library file) is the
 * caller's decision, made on the rows replaceForItem() hands back.
 *
 * Its ALT TEXT is not here: since Multilingual 2.0 phase 5 it lives per
 * website language in portfolio_item_image_translations and is read through
 * App\Service\PortfolioLocalization.
 */
class PortfolioItemImageRepository extends Repository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByPortfolioItemId(int $portfolioItemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, portfolio_item_id, media_id, image_path, thumbnail_path, sort_order
             FROM portfolio_item_images
             WHERE portfolio_item_id = :portfolio_item_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['portfolio_item_id' => $portfolioItemId]);

        return $stmt->fetchAll();
    }

    /**
     * Makes an item's photos exactly $photos, in that order, and returns the
     * rows that are no longer among them.
     *
     * Each entry is either an existing row of THIS item (`id`), which keeps
     * everything but its position, or a new library photo (`media_id` with
     * the path and thumbnail to write along). An `id` that is not one of this
     * item's rows is ignored, so a tampered form can neither move nor steal
     * another item's photo. The caller runs this inside its own transaction.
     *
     * @param list<array{id?: int, media_id?: int, image_path?: string, thumbnail_path?: ?string}> $photos
     * @return list<array<string, mixed>> the removed rows, as findByPortfolioItemId() returns them
     */
    public function replaceForItem(int $portfolioItemId, array $photos): array
    {
        $existing = [];
        foreach ($this->findByPortfolioItemId($portfolioItemId) as $row) {
            $existing[(int) $row['id']] = $row;
        }

        $keep = [];
        $position = 0;

        $move = $this->db->prepare(
            'UPDATE portfolio_item_images SET sort_order = :sort_order, updated_at = NOW()
             WHERE id = :id AND portfolio_item_id = :portfolio_item_id'
        );
        $insert = $this->db->prepare(
            'INSERT INTO portfolio_item_images (portfolio_item_id, media_id, image_path, thumbnail_path, sort_order, created_at, updated_at)
             VALUES (:portfolio_item_id, :media_id, :image_path, :thumbnail_path, :sort_order, NOW(), NOW())'
        );

        foreach ($photos as $photo) {
            $id = (int) ($photo['id'] ?? 0);

            if ($id > 0) {
                if (!isset($existing[$id]) || isset($keep[$id])) {
                    continue;
                }

                $keep[$id] = true;
                $move->execute(['sort_order' => $position, 'id' => $id, 'portfolio_item_id' => $portfolioItemId]);
                $position++;
                continue;
            }

            $mediaId = (int) ($photo['media_id'] ?? 0);
            if ($mediaId < 1 || trim((string) ($photo['image_path'] ?? '')) === '') {
                continue;
            }

            $insert->execute([
                'portfolio_item_id' => $portfolioItemId,
                'media_id' => $mediaId,
                'image_path' => (string) $photo['image_path'],
                'thumbnail_path' => $photo['thumbnail_path'] ?? null,
                'sort_order' => $position,
            ]);
            $position++;
        }

        $removed = array_values(array_diff_key($existing, $keep));

        if ($removed !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $removed);
            $delete = $this->db->prepare(
                'DELETE FROM portfolio_item_images WHERE portfolio_item_id = ? AND id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ')'
            );
            $delete->execute([$portfolioItemId, ...$ids]);
        }

        return $removed;
    }
}
