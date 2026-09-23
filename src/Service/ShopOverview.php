<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PageRepository;
use App\Service\Breadcrumbs\BreadcrumbItem;
use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Routing\RequestLanguage;

/**
 * Where the Shop's product overview lives, if anywhere (MODULES.md, "Shop").
 *
 * THE SHOP IS NOT A PAGE. Switching the module on gives a site products,
 * a cart and a checkout; it does not by itself give it a public page that
 * lists every product. Which page is the overview is the owner's choice under
 * Shop-instellingen, stored in the site setting `shop_overview`:
 *
 *   ''         no overview. /shop.php answers 404, and no product page, cart
 *              or breadcrumb links to an overview that does not exist. What a
 *              new installation starts with.
 *   '<id>'     a CMS page is the overview. The owner puts a product grid
 *              block (product_grid) on it wherever they like. Links go to that
 *              page's own address in the language being read, and /shop.php
 *              sends a visitor there. The page whose content_key is `shop`
 *              — the storefront page older installations were seeded with —
 *              is one of those pages, and keeps its address /shop.php.
 *   'builtin'  the automatic listing /shop.php showed before this setting
 *              existed. Only an existing installation has it, pinned by
 *              20260923140000; the screen offers it only while it is the
 *              current value, so an owner can leave it but a new site never
 *              drifts into it.
 *
 * Every Shop link to "the shop" asks here: product pages, the cart, the
 * checkout, collections, order status and the breadcrumb trails above them.
 * None of them writes /shop.php itself any more.
 */
final class ShopOverview
{
    public const SETTING_KEY = 'shop_overview';

    public const NONE = 'none';
    public const PAGE = 'page';
    public const BUILTIN = 'builtin';

    /** The stored value that keeps the automatic listing. */
    public const BUILTIN_VALUE = 'builtin';

    /** @var array{mode: string, page: ?array<string, mixed>}|null */
    private static ?array $resolved = null;

    /** none, page or builtin — what the stored setting means right now. */
    public static function mode(): string
    {
        return self::resolve()['mode'];
    }

    /**
     * The chosen overview page, published or not; null when the overview is
     * not a page.
     *
     * @return array<string, mixed>|null
     */
    public static function page(): ?array
    {
        return self::resolve()['page'];
    }

    /** Whether the chosen page is the storefront page that lives at /shop.php itself. */
    public static function isStorefrontPage(?array $page): bool
    {
        return $page !== null && (string) ($page['content_key'] ?? '') === 'shop';
    }

    /**
     * The overview's address in $language (default: the language being read),
     * or null when there is nothing a visitor may be sent to: no overview, or
     * a chosen page that is not published.
     */
    public static function url(?string $language = null): ?string
    {
        $resolved = self::resolve();

        if ($resolved['mode'] === self::BUILTIN) {
            return \App\Service\Routing\LocalizedUrl::path('/shop.php', $language);
        }

        $page = $resolved['page'];
        if ($page === null || !PageContent::isPublished($page)) {
            return null;
        }

        return PageContent::publicUrl($page, $language);
    }

    /**
     * The overview as one level of a breadcrumb trail, or the trail as it was
     * when there is no overview to point at. Named the way the overview names
     * itself: the page's title in the language being read, or the route's
     * label for the automatic listing.
     */
    public static function extendTrail(BreadcrumbTrail $trail): BreadcrumbTrail
    {
        $resolved = self::resolve();

        if ($resolved['mode'] === self::BUILTIN) {
            return $trail->toRoute('shop');
        }

        $url = self::url();
        if ($url === null || $resolved['page'] === null) {
            return $trail;
        }

        return $trail->to(BreadcrumbItem::link(
            PageLocalization::title((int) $resolved['page']['id'], RequestLanguage::current()),
            $url
        ));
    }

    /**
     * The pages an owner may choose from: every ordinary page, plus the
     * storefront page when the installation has one. Drafts are included and
     * marked by the screen, the way the Portfolio's page picker offers them.
     *
     * @return list<array<string, mixed>>
     */
    public static function choices(): array
    {
        return array_values(array_filter(
            (new PageRepository())->findAllForAdmin(),
            static fn (array $page): bool => self::isStorefrontPage($page)
                || (!PageContent::hasOwnTemplate($page) && !PageContent::isRouteBound($page))
        ));
    }

    /**
     * The value to store for what a request sent, or null when it is not a
     * valid choice. '' is always valid; 'builtin' only while it is already
     * the stored value; a page id must be one of choices() AND carry a
     * visible product grid — an overview that lists nothing is not offered —
     * unless it is the page already stored, so saving never silently changes
     * a choice the owner made before.
     */
    public static function normalise(mixed $requested, string $current): ?string
    {
        if (!is_scalar($requested)) {
            return null;
        }

        $value = trim((string) $requested);

        if ($value === '') {
            return '';
        }

        if ($value === self::BUILTIN_VALUE) {
            return $current === self::BUILTIN_VALUE ? $value : null;
        }

        if (preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1) {
            return null;
        }

        foreach (self::choices() as $page) {
            if ((int) $page['id'] === (int) $value) {
                return self::hasProductGrid($page) || $value === $current ? $value : null;
            }
        }

        return null;
    }

    /**
     * Whether a page shows the product grid block (attached and not hidden):
     * what makes it a page that can be the overview at all. The owner places
     * the block; choosing a page never adds one.
     *
     * @param array<string, mixed> $page
     */
    public static function hasProductGrid(array $page): bool
    {
        try {
            foreach ((new \App\Repository\PageSectionRepository())->findForPage((int) $page['id'], true) as $section) {
                if ((string) ($section['section_type'] ?? '') === 'product_grid') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[ShopOverview] section lookup failed: ' . $e->getMessage());
        }

        return false;
    }

    public static function clearCache(): void
    {
        self::$resolved = null;
    }

    /** @return array{mode: string, page: ?array<string, mixed>} */
    private static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $stored = trim(SiteSettings::get(self::SETTING_KEY));

        if ($stored === self::BUILTIN_VALUE) {
            return self::$resolved = ['mode' => self::BUILTIN, 'page' => null];
        }

        $page = null;
        if (preg_match('/^[1-9][0-9]{0,9}$/', $stored) === 1) {
            try {
                $page = (new PageRepository())->findById((int) $stored);
            } catch (\Throwable $e) {
                error_log('[ShopOverview] page lookup failed: ' . $e->getMessage());
                $page = null;
            }
        }

        return self::$resolved = $page === null
            ? ['mode' => self::NONE, 'page' => null]
            : ['mode' => self::PAGE, 'page' => $page];
    }
}
