<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;

/**
 * WHO MAY CHANGE A BLOCK LIST (CONTENT-BLOCKS.md "Wie mag welke blokken
 * beheren"). A product's or a project's blocks live on a content page, a
 * `pages` row the block engine needs; that row is a technical holder and
 * never decides the permission. What the blocks BELONG to does:
 *
 *   an ordinary page        pages.manage
 *   a product               the Shop's products.manage
 *   a Portfolio project     the Portfolio's portfolio.manage
 *
 * (ContentOwner::permission(), so Core never names a module's right.) A Shop
 * manager edits the blocks of a product without ever reaching a CMS page, and
 * pages.manage alone opens no product's blocks.
 *
 * TWO STEPS, in every shared block screen and endpoint:
 *
 *   1. requireAny() / requireAnyForApi() where the other screens and
 *      endpoints ask for their one literal permission, after the login and
 *      before the POST and CSRF checks: anyone who manages no block list at
 *      all is turned away before the request is read.
 *   2. requirePage() / requirePageForApi() (or pageForKey…(), which finds and
 *      checks in one go) as soon as the block's list is known, BEFORE
 *      anything is read for the editor or written: the owner comes from the
 *      database row of that list (`pages.owner_type`), never from the
 *      request, so a forged content key, page id or block id reaches only a
 *      list its sender may manage anyway.
 *
 * NOT HERE: which blocks a list may hold. That is a block's `owners`
 * capability (SectionRegistry::isAllowedOnPage()), checked apart from this:
 * a Shop manager still cannot put a Paginakop on a product.
 */
final class ContentBlockAccess
{
    /**
     * Every permission that manages some block list: pages.manage, plus the
     * right of each owner whose module is on.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $permissions = [AdminPermissions::PAGES_MANAGE];

        foreach (ContentOwners::enabled() as $owner) {
            $permissions[] = $owner->permission();
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The permission this block list asks for. Null for a content page whose
     * kind no registered module knows: nobody but a super admin opens that.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function permissionFor(array $page): ?string
    {
        $kind = ContentPages::kindOf($page);

        if ($kind === ContentOwners::PAGE) {
            return AdminPermissions::PAGES_MANAGE;
        }

        return ContentOwners::get($kind)?->permission();
    }

    /** @param array<string, mixed> $page a `pages` row */
    public static function canManage(array $page): bool
    {
        $permission = self::permissionFor($page);

        return $permission === null ? AdminAuth::isSuperAdmin() : AdminAuth::can($permission);
    }

    /** Whether the signed-in user may manage the blocks of this kind of list (ContentOwners::PAGE or an owner kind). */
    public static function canManageKind(string $kind): bool
    {
        return self::canManage($kind === ContentOwners::PAGE ? ['owner_type' => null] : ['owner_type' => $kind]);
    }

    /** Step 1 for a screen: log in, then hold at least one block permission. */
    public static function requireAny(): void
    {
        AdminAuth::requireAnyPermission(self::permissions());
    }

    /** Step 1 for an endpoint: 401 without a login, 403 without any block permission. */
    public static function requireAnyForApi(): void
    {
        AdminAuth::requireLoginForApi();

        if (!AdminAuth::canAny(self::permissions())) {
            self::forbidApi();
        }
    }

    /**
     * Step 2 for a screen: the 403 page unless the user may manage this list.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function requirePage(array $page): void
    {
        $permission = self::permissionFor($page);

        if ($permission === null) {
            AdminAuth::requireSuperAdmin();

            return;
        }

        AdminAuth::requirePermission($permission);
    }

    /**
     * Step 2 for an endpoint: a plain 403, the one every guard gives.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function requirePageForApi(array $page): void
    {
        if (!self::canManage($page)) {
            self::forbidApi();
        }
    }

    /** Step 2 for a screen of a fixed block (FaqContent::SECTIONS and the like): always an ordinary page. */
    public static function requirePages(): void
    {
        AdminAuth::requirePermission(AdminPermissions::PAGES_MANAGE);
    }

    /** As requirePages(), for an endpoint. */
    public static function requirePagesForApi(): void
    {
        if (!AdminAuth::can(AdminPermissions::PAGES_MANAGE)) {
            self::forbidApi();
        }
    }

    /**
     * The list with this storage key, checked for the signed-in user (step 2
     * of a screen), or null when there is no such list.
     *
     * @return array<string, mixed>|null a `pages` row
     */
    public static function pageForKey(?string $contentKey): ?array
    {
        $page = $contentKey === null || $contentKey === '' ? null : (new PageRepository())->findByContentKey($contentKey);

        if ($page !== null) {
            self::requirePage($page);
        }

        return $page;
    }

    /**
     * As pageForKey(), for an endpoint.
     *
     * @return array<string, mixed>|null a `pages` row
     */
    public static function pageForKeyForApi(?string $contentKey): ?array
    {
        $page = $contentKey === null || $contentKey === '' ? null : (new PageRepository())->findByContentKey($contentKey);

        if ($page !== null) {
            self::requirePageForApi($page);
        }

        return $page;
    }

    /**
     * Where a block's editor sends its editor back to, and where an endpoint
     * lands after a change to the list: the owner's own editor on its
     * Pagina-inhoud tab for a content page, the page builder for a page. So
     * the way back never passes a screen that asks for pages.manage.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function listUrl(array $page): string
    {
        $resolved = ContentPages::isContentPage($page) ? ContentPages::ownerOf($page) : null;

        if ($resolved !== null) {
            return $resolved['owner']->editUrl($resolved['id']);
        }

        return '/admin/page.php?id=' . (int) ($page['id'] ?? 0);
    }

    /**
     * Where a block editor's successful save lands (Content Blocks Lifecycle
     * 1.0): the list the block stands in — the page builder, or the owner's
     * Pagina-inhoud tab — naming the block, so the list can say it was saved
     * and scroll to it (`saved=<page_sections id>#blok-<id>`).
     *
     * The destination is derived from the block's own page_sections row and
     * that row's page, never from the request: there is no return URL to
     * forge, and the list is the one the save was already allowed to write
     * to. A row that stands on no page (ContentBlockDrafts::place() returned
     * null) keeps the old behaviour: back to its editor, `saved=1`.
     *
     * @param array<string, mixed>|null $placed the block's page_sections row
     */
    public static function afterSaveUrl(?array $placed, string $editorUrl): string
    {
        $page = $placed === null ? null : (new PageRepository())->findById((int) $placed['page_id']);

        if ($page === null) {
            return $editorUrl . (str_contains($editorUrl, '?') ? '&' : '?') . 'saved=1';
        }

        $id = (int) $placed['id'];

        return self::listUrl($page) . '&saved=' . $id . '#blok-' . $id;
    }

    private static function forbidApi(): never
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden: missing permission.');
    }
}
