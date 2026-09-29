<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PortfolioGalleryRepository;
use App\Service\ContentOwners\ContentOwner;

/**
 * A Portfolio project as the owner of content blocks (Product & Portfolio
 * Content Pages 1.0): the blocks of its project page, edited on the project's
 * Pagina-inhoud tab, where its layout (App\Service\PortfolioProjectLayout)
 * decides whether they follow the project's own head or make up the whole
 * page. Contributed by App\Module\PortfolioModule::contentOwners(); the link
 * table is `portfolio_content_pages` (db/migrations/20260930100000).
 */
final class PortfolioContentOwner implements ContentOwner
{
    public const KIND = 'portfolio_project';

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Project';
    }

    public function moduleKey(): string
    {
        return 'portfolio';
    }

    public function linkTable(): string
    {
        return 'portfolio_content_pages';
    }

    public function linkColumn(): string
    {
        return 'portfolio_item_id';
    }

    public function exists(int $ownerId): bool
    {
        return $ownerId > 0 && (new PortfolioGalleryRepository())->findItemById($ownerId) !== null;
    }

    public function name(int $ownerId): string
    {
        return PortfolioLocalization::itemName($ownerId);
    }

    public function editUrl(int $ownerId): string
    {
        return '/admin/portfolio-item.php?id=' . $ownerId . '&tab=inhoud';
    }
}
