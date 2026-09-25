<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Module\PortfolioModule;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * How the Portfolio answers the Media Library's "do you use any of these
 * images, and where?" — the module's own contribution through
 * App\Module\PortfolioModule::mediaUsageProviders(), the same shape as
 * App\Service\Blog\BlogPostMediaUsage.
 *
 * Since Media Library 2.0 an item's picture is a library item
 * (portfolio_gallery_items.media_id). This is what keeps that picture from
 * being deleted while an item still shows it: the library refuses to delete
 * an item any provider reports, and the RESTRICT foreign key is the database's
 * backstop underneath. An item from before the library (media_id NULL) uses
 * no library item and is not reported.
 *
 * ONE QUERY FOR THE WHOLE BATCH, the contract every provider follows.
 *
 * WHO READS THE TITLE: only an administrator who may open the item
 * (portfolio.manage, the permission admin/portfolio-item.php demands);
 * anybody else is told the picture is used
 * (App\Service\Media\VisibleMediaUsages).
 *
 * A DISABLED MODULE REPORTS NOTHING (MODULES.md): the registry only asks the
 * modules that are running, and the rows stay as they are.
 */
final class PortfolioMediaUsage extends MediaUsageProvider
{
    public function key(): string
    {
        return 'portfolio_item';
    }

    public function label(): string
    {
        return 'Portfolio';
    }

    public function usagesFor(array $mediaIds): array
    {
        $ids = array_values(array_map('intval', $mediaIds));

        if ($ids === []) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, media_id FROM portfolio_gallery_items WHERE media_id IN (' . $this->placeholders(count($ids)) . ')'
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        PortfolioLocalization::preloadItems(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $usages = [];

        foreach ($rows as $row) {
            $itemId = (int) $row['id'];
            $title = PortfolioLocalization::itemName($itemId);

            $usages[(int) $row['media_id']][] = new MediaUsage(
                source: $this->key(),
                label: 'Portfolio: ' . ($title !== '' ? $title : '#' . $itemId),
                permission: PortfolioModule::PORTFOLIO_MANAGE,
                editUrl: '/admin/portfolio-item.php?id=' . $itemId,
            );
        }

        return $usages;
    }
}
