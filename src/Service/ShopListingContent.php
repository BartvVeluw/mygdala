<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ShopListingRepository;
use App\Service\Blocks\BlockHead;
use App\Service\Routing\RequestLanguage;

/**
 * The read model of the Shop's Productgrid and Collectie-tegels: whether the
 * instance is shown, and its optional head (App\Service\Blocks\BlockHead) in
 * the request's language.
 *
 * What the block LISTS is not its content and is not read here: the product
 * grid is filled from /api/products.php, the tiles come from
 * CollectionContent::activeForShop(). So the three states mean slightly more
 * than for a block whose row is everything:
 *
 *   STATE_FALLBACK  no row (the storefront of shop.php, which draws the grid
 *                   without a page section; a failed lookup): no head, and
 *                   the list renders as it always did.
 *   STATE_ACTIVE    the row, with its head.
 *   STATE_HIDDEN    the row switched off in its editor: nothing at all.
 */
final class ShopListingContent
{
    public const STATE_FALLBACK = 'fallback';

    public const STATE_ACTIVE = 'active';

    public const STATE_HIDDEN = 'hidden';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array{state: string, id: int, eyebrow: string, title: string, lead: string}
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        if ($pageSlug === '' || $sectionKey === '') {
            return self::emptyContent(self::STATE_FALLBACK);
        }

        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new ShopListingRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[ShopListingContent] lookup failed for "' . $pageSlug . ':' . $sectionKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_FALLBACK);
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_HIDDEN);
        }

        $id = (int) $row['id'];

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE, 'id' => $id] + BlockHead::words(ShopListingRepository::TABLE, $id);
    }

    /**
     * The same keys with no words: never a stand-in text.
     *
     * @return array{state: string, id: int, eyebrow: string, title: string, lead: string}
     */
    public static function emptyContent(string $state = self::STATE_FALLBACK): array
    {
        return ['state' => $state, 'id' => 0] + BlockHead::none();
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
