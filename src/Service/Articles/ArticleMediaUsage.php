<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Module\ArticlesModule;
use App\Repository\ArticleRepository;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * How Articles answers the Media Library's "is this image used, and where?"
 * (MEDIA.md), so a featured image cannot be deleted while an article shows
 * it — with the RESTRICT key on articles.featured_media_id underneath. One
 * query per batch. Images inside an article's BLOCKS are reported by the
 * blocks themselves, as on any page.
 *
 * Only while the module runs (MediaUsageRegistry asks enabled modules).
 */
final class ArticleMediaUsage extends MediaUsageProvider
{
    public function key(): string
    {
        return 'article';
    }

    public function label(): string
    {
        return 'Artikelen';
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = (new ArticleRepository())->usingMedia(array_values(array_map('intval', $mediaIds)));
        ArticleLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $usages = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $name = ArticleLocalization::name($id);

            $usages[(int) $row['featured_media_id']][] = new MediaUsage(
                source: $this->key(),
                label: 'Artikel: ' . ($name !== '' ? $name : '#' . $id),
                permission: ArticlesModule::ARTICLES_MANAGE,
                editUrl: '/admin/article.php?id=' . $id,
            );
        }

        return $usages;
    }
}
