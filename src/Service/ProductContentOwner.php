<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Service\ContentOwners\ContentOwner;

/**
 * A Shop product as the owner of content blocks (Product & Portfolio Content
 * Pages 1.0): the blocks under its product detail, edited on the product's
 * Pagina-inhoud tab. Contributed by App\Module\ShopModule::contentOwners(),
 * so Core never names a product; the link table is
 * `product_content_pages` (db/migrations/20260930100000).
 */
final class ProductContentOwner implements ContentOwner
{
    public const KIND = 'product';

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Product';
    }

    public function moduleKey(): string
    {
        return 'shop';
    }

    public function linkTable(): string
    {
        return 'product_content_pages';
    }

    public function linkColumn(): string
    {
        return 'product_id';
    }

    public function exists(int $ownerId): bool
    {
        return $ownerId > 0 && (new ProductRepository())->findByIdForAdmin($ownerId) !== null;
    }

    public function name(int $ownerId): string
    {
        return ShopLocalization::products()->name($ownerId, ShopLocalization::NAME);
    }

    public function editUrl(int $ownerId): string
    {
        return '/admin/product-form.php?id=' . $ownerId . '&tab=inhoud';
    }

    public function permission(): string
    {
        return \App\Module\ShopModule::PRODUCTS_MANAGE;
    }
}
