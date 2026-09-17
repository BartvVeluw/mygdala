<?php

declare(strict_types=1);

namespace App\Service\Media\Usage;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;
use App\Service\PageLocalization;

/**
 * A CMS page's own social-sharing image (`pages.og_media_id`) — the per-page
 * override in the SEO hierarchy described in SEO.md.
 *
 * One query for the whole batch, and it reads only the `pages` table: the
 * SEO hierarchy itself is untouched by the Media Library, this provider just
 * reports which page picked which item.
 */
final class PageSocialImageMediaUsage extends MediaUsageProvider
{
    public function key(): string
    {
        return 'page_social_image';
    }

    public function label(): string
    {
        return "Pagina's";
    }

    public function usagesFor(array $mediaIds): array
    {
        $ids = array_values(array_map('intval', $mediaIds));

        $stmt = Database::connection()->prepare(
            'SELECT id, og_media_id FROM pages WHERE og_media_id IN (' . $this->placeholders(count($ids)) . ')'
        );
        $stmt->execute($ids);

        $rows = $stmt->fetchAll();
        // The page's name lives per language; the CMS calls a page by its
        // name in the default language (App\Service\PageLocalization).
        PageLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $usages = [];

        foreach ($rows as $row) {
            $usages[(int) $row['og_media_id']][] = new MediaUsage(
                source: $this->key(),
                label: 'Deel-afbeelding van "' . PageLocalization::name((int) $row['id']) . '"',
                // What admin/page.php itself demands.
                permission: AdminPermissions::PAGES_MANAGE,
                editUrl: '/admin/page.php?id=' . (int) $row['id'],
            );
        }

        return $usages;
    }
}
