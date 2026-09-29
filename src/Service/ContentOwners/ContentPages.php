<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

use App\Database;
use App\Repository\ContentPageRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\PageContent;
use App\Service\SectionRegistry;

/**
 * The content page of a product or project: the `pages` row that holds its
 * content blocks (Product & Portfolio Content Pages 1.0, CONTENT-BLOCKS.md
 * "Blokken op een product of project").
 *
 * WHY A PAGE ROW. The block engine addresses a block list by one opaque
 * storage key (`page_slug` = `pages.content_key`) and keeps the list in
 * `page_sections`, which belongs to `pages`. Giving an owner a page row of its
 * own is the smallest change that lets the SAME engine serve it: the block
 * picker, every block editor and endpoint, ordering, hiding, deleting,
 * translations (block_translations), media usage and form usage all work on
 * that row exactly as on any page, with no second engine and no copied
 * editor. What makes it a content page and not a page is `owner_type`: every
 * listing of pages leaves it out (PageRepository), it has no slug, no
 * translations and no address of its own, and admin/page.php sends an editor
 * who lands on it to the owner's own editor.
 *
 * MADE WHEN FIRST NEEDED. A product without blocks has no content page, so
 * nothing changes for the thousands of rows that never get one:
 * api/admin/add-page-section.php calls ensure() for the first block.
 *
 * STORAGE KEY. "<kind>_<id>" (product_12, portfolio_project_3). An ordinary
 * page's content_key is a slug — a-z, 0-9 and "-" only
 * (PageService::sanitizeSlug()) — so the underscore keeps the two namespaces
 * apart for good, and the key never changes: a product rename or a new slug
 * touches no block row.
 */
final class ContentPages
{
    /**
     * The content page of this owner, or null while it has none. Owners of a
     * module that is switched off are still found: their blocks stay.
     *
     * @return array<string, mixed>|null a `pages` row
     */
    public static function pageFor(string $kind, int $ownerId): ?array
    {
        $owner = ContentOwners::get($kind);

        if ($owner === null || $ownerId < 1) {
            return null;
        }

        $pageId = (new ContentPageRepository())->pageIdFor($owner->linkTable(), $owner->linkColumn(), $ownerId);

        return $pageId === null ? null : (new PageRepository())->findById($pageId);
    }

    /**
     * The content page of this owner, made now when it has none: the page row
     * and its link in one transaction. Only for an owner of a module that is
     * on, and only for an owner that exists.
     *
     * @return array<string, mixed> a `pages` row
     */
    public static function ensure(string $kind, int $ownerId): array
    {
        $owner = ContentOwners::getEnabled($kind);

        if ($owner === null || $ownerId < 1 || !$owner->exists($ownerId)) {
            throw new \InvalidArgumentException("No content owner {$kind} #{$ownerId}.");
        }

        $existing = self::pageFor($kind, $ownerId);
        if ($existing !== null) {
            return $existing;
        }

        $db = Database::connection();
        $pages = new PageRepository($db);
        $db->beginTransaction();

        try {
            $pageId = $pages->createContentPage(self::contentKey($kind, $ownerId), $kind);
            (new ContentPageRepository($db))->link($owner->linkTable(), $owner->linkColumn(), $ownerId, $pageId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            // Two first blocks at the same moment: the other request made it.
            $existing = self::pageFor($kind, $ownerId);
            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }

        PageContent::clearCache();

        return $pages->findById($pageId) ?? throw new \RuntimeException('Content page vanished.');
    }

    /**
     * Removes this owner's content page, when it has one: every block through
     * SectionRegistry::delete() (its words, child rows and files), exactly as
     * PageService::delete() removes a page's blocks, then the link and the page
     * row. Called by the owner's own delete BEFORE it deletes the owner, which
     * its link table's RESTRICT key would otherwise refuse.
     */
    public static function deleteFor(string $kind, int $ownerId): void
    {
        $owner = ContentOwners::get($kind);
        $page = self::pageFor($kind, $ownerId);

        if ($owner === null || $page === null) {
            return;
        }

        $pageId = (int) $page['id'];
        $sections = new PageSectionRepository();

        foreach ($sections->findForPage($pageId) as $pageSection) {
            if (SectionRegistry::isDeletable((string) $pageSection['section_type'])) {
                SectionRegistry::delete($pageSection, $sections);
                continue;
            }

            // A block of a module that is off right now: the row goes with the
            // page, the way PageService::delete() detaches one.
            $sections->delete((int) $pageSection['id']);
        }

        $db = Database::connection();
        $db->beginTransaction();

        try {
            (new ContentPageRepository($db))->unlink($owner->linkTable(), $owner->linkColumn(), $ownerId);
            (new PageRepository($db))->delete($pageId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        PageContent::clearCache();
    }

    /**
     * Whose content page this is, or null for an ordinary page (or a content
     * page whose link is gone).
     *
     * @param array<string, mixed> $page a `pages` row
     *
     * @return array{owner: ContentOwner, id: int}|null
     */
    public static function ownerOf(array $page): ?array
    {
        $kind = (string) ($page['owner_type'] ?? '');
        $owner = $kind === '' ? null : ContentOwners::get($kind);

        if ($owner === null) {
            return null;
        }

        $ownerId = (new ContentPageRepository())->ownerIdFor($owner->linkTable(), $owner->linkColumn(), (int) $page['id']);

        return $ownerId === null ? null : ['owner' => $owner, 'id' => $ownerId];
    }

    /**
     * The kind a block list belongs to: ContentOwners::PAGE for an ordinary
     * page, else the owner's kind. What a block's `owners` capability is
     * checked against (SectionRegistry::isAllowedOnPage()).
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function kindOf(array $page): string
    {
        $kind = (string) ($page['owner_type'] ?? '');

        return $kind === '' ? ContentOwners::PAGE : $kind;
    }

    /** Whether this `pages` row is a content page rather than a page. */
    public static function isContentPage(array $page): bool
    {
        return (string) ($page['owner_type'] ?? '') !== '';
    }

    /**
     * What the CMS calls a content page: its owner's name ("Product: Eiken
     * plank"), or the owner's label with its id when it has no name.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function name(array $page): string
    {
        $resolved = self::ownerOf($page);

        if ($resolved === null) {
            return '';
        }

        $name = $resolved['owner']->name($resolved['id']);

        return $resolved['owner']->label() . ': ' . ($name !== '' ? $name : '#' . $resolved['id']);
    }

    /**
     * The storage key of an owner's content page, also before it exists: the
     * page_slug its blocks are stored under, and what the block picker's
     * "allowed here?" answer is asked about.
     */
    public static function contentKey(string $kind, int $ownerId): string
    {
        return $kind . '_' . $ownerId;
    }

    /**
     * A stand-in `pages` row for an owner that has no content page yet, in
     * the shape SectionRegistry::availableDefinitionsForPage() reads: id 0
     * (no blocks), the key the page will get and the owner's kind. Never
     * written anywhere.
     *
     * @return array<string, mixed>
     */
    public static function placeholder(string $kind, int $ownerId): array
    {
        return ['id' => 0, 'content_key' => self::contentKey($kind, $ownerId), 'owner_type' => $kind];
    }
}
