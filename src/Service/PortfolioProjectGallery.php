<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Media\MediaService;

/**
 * The extra photos of a Portfolio item's project page as its editor posts
 * them (admin/portfolio-item.php, api/admin/update-portfolio-item.php).
 *
 * A photo is named by a TOKEN, the scheme the product editor uses for its
 * pictures (admin/_product_gallery.php, App\Service\ProductGallery):
 *
 *   photo:<id>   a photo this item already has (portfolio_item_images.id)
 *   media:<id>   a library image chosen in this visit
 *
 * The browser is trusted with nothing but the order. A `photo:` token that is
 * not one of THIS item's rows is dropped, so a tampered form can neither move
 * nor steal another item's photo; a `media:` token must name an image in the
 * library (MediaService::findImage()), the check every picker field makes.
 *
 * ONE RULE AGAINST DOUBLES. The main picture stands apart and is shown first
 * (MODULES.md, "Portfolio"), so a library image that is the item's main
 * picture is not also taken as a photo, and a library image is taken at most
 * once. Nothing is ever stored twice for one project page.
 */
final class PortfolioProjectGallery
{
    private const TOKEN = '/^(photo|media):([1-9][0-9]{0,9})$/';

    /**
     * The photos to store, in the posted order, ready for
     * App\Repository\PortfolioItemImageRepository::replaceForItem().
     *
     * @param mixed $posted the raw `gallery[]` value
     * @param list<array<string, mixed>> $existing the item's photo rows
     * @return list<array{id?: int, media_id?: int, image_path?: string, thumbnail_path?: ?string}>
     */
    public static function resolve(mixed $posted, array $existing, ?int $mainMediaId): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $rowsById = [];
        foreach ($existing as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $photos = [];
        $takenRows = [];
        $takenMedia = [];

        if ($mainMediaId !== null && $mainMediaId > 0) {
            $takenMedia[$mainMediaId] = true;
        }

        foreach ($posted as $token) {
            if (!is_string($token) || preg_match(self::TOKEN, trim($token), $match) !== 1) {
                continue;
            }

            $id = (int) $match[2];

            if ($match[1] === 'photo') {
                $row = $rowsById[$id] ?? null;
                $rowMediaId = (int) ($row['media_id'] ?? 0);

                if ($row === null || isset($takenRows[$id]) || ($rowMediaId > 0 && isset($takenMedia[$rowMediaId]))) {
                    continue;
                }

                $takenRows[$id] = true;
                if ($rowMediaId > 0) {
                    $takenMedia[$rowMediaId] = true;
                }
                $photos[] = ['id' => $id];
                continue;
            }

            if (isset($takenMedia[$id])) {
                continue;
            }

            $media = MediaService::findImage($id);
            if ($media === null) {
                continue;
            }

            $takenMedia[$id] = true;
            $photos[] = [
                'media_id' => $media->id,
                'image_path' => $media->path,
                'thumbnail_path' => $media->thumbnailPath ?? $media->path,
            ];
        }

        return $photos;
    }

    /**
     * What the editor shows: the photos a refused save posted (tokens, resolved
     * again), else the item's stored ones.
     *
     * @param list<array<string, mixed>> $existing the item's photo rows
     * @param list<string>|null $oldTokens the refused request's tokens, or null
     * @return list<array{token: string, src: string, name: string, media_id: ?int}>
     */
    public static function forEditor(array $existing, ?array $oldTokens): array
    {
        MediaService::findMany(array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['media_id'] ?? 0),
            $existing
        ))));

        $stored = [];
        foreach ($existing as $row) {
            $media = MediaService::find((int) ($row['media_id'] ?? 0));
            $path = (string) (($row['thumbnail_path'] ?? '') !== '' ? $row['thumbnail_path'] : $row['image_path']);

            $stored['photo:' . (int) $row['id']] = [
                'token' => 'photo:' . (int) $row['id'],
                'src' => '/' . ltrim($path, '/'),
                'name' => $media !== null ? $media->displayName() : basename((string) $row['image_path']),
                'media_id' => $media?->id,
            ];
        }

        if ($oldTokens === null) {
            return array_values($stored);
        }

        $photos = [];
        foreach ($oldTokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            if (isset($stored[$token])) {
                $photos[] = $stored[$token];
                continue;
            }

            if (preg_match('/^media:([1-9][0-9]{0,9})$/', $token, $match) === 1) {
                $media = MediaService::findImage((int) $match[1]);
                if ($media !== null) {
                    $photos[] = [
                        'token' => 'media:' . $media->id,
                        'src' => $media->displayPath(),
                        'name' => $media->displayName(),
                        'media_id' => $media->id,
                    ];
                }
            }
        }

        return $photos;
    }
}
