<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

use App\Repository\PageRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;

/**
 * Keeps an owner that must keep content (RequiresContent, today: a
 * non-draft article) from losing its last meaningful block through a block
 * change (CONTENT-BLOCKS.md, "Een eigenaar die inhoud moet houden").
 *
 * Not in an endpoint: in the three places every block change of a list
 * passes, so no block editor knows about articles.
 *
 *   ContentBlockDrafts::place()   every block editor's save, as its last
 *                                 write inside its transaction: emptying a
 *                                 text, switching a block off in its own
 *                                 editor, removing its last item;
 *   SectionRegistry::delete()     the block list's delete;
 *   SectionRegistry::setActive()  the block list's hide.
 *
 * Two checks. assertMayRemove() judges a delete or a hide BEFORE it happens
 * (a delete removes a block's files before its rows). assertIntact() judges
 * what the list is AFTER a write, inside that write's transaction, so a
 * refused change is rolled back whole. Either throws OwnerContentRequired.
 *
 * An ordinary page has no owner and is never judged; neither is an owner
 * without RequiresContent. The whole list going with its owner
 * (ContentPages::deleteFor()) is not a change of the list and is never
 * refused.
 */
final class OwnerContentGuard
{
    /**
     * Refuses deleting or hiding this block when it is the last meaningful
     * block of an owner that must keep one.
     *
     * @param array<string, mixed> $pageSection the page_sections row
     */
    public static function assertMayRemove(array $pageSection): void
    {
        $required = self::requirementFor((int) ($pageSection['page_id'] ?? 0));

        if ($required !== null && !ContentPages::hasMeaningfulBlocks($required['kind'], $required['id'], (int) $pageSection['id'])) {
            throw new OwnerContentRequired($required['message']);
        }
    }

    /**
     * Refuses the change just written when it left an owner that must keep
     * content without a meaningful block. Call it inside the change's
     * transaction, after its last write.
     */
    public static function assertIntact(int $pageId): void
    {
        $required = self::requirementFor($pageId);

        if ($required === null) {
            return;
        }

        // The write happened in this request: read it, not a cached copy.
        foreach (BlockDefinitions::all() as $definition) {
            $definition->clearCache();
        }
        BlockLocalization::clearCache();

        if (!ContentPages::hasMeaningfulBlocks($required['kind'], $required['id'])) {
            throw new OwnerContentRequired($required['message']);
        }
    }

    /**
     * The editor's message for a refusal, or null for any other failure (an
     * endpoint then says what it always said).
     */
    public static function messageFor(\Throwable $e): ?string
    {
        return $e instanceof OwnerContentRequired ? $e->getMessage() : null;
    }

    /**
     * @return array{kind: string, id: int, message: string}|null the owner that must keep content, or null
     */
    private static function requirementFor(int $pageId): ?array
    {
        $page = $pageId < 1 ? null : (new PageRepository())->findById($pageId);

        if ($page === null || !ContentPages::isContentPage($page)) {
            return null;
        }

        $resolved = ContentPages::ownerOf($page);
        $owner = $resolved['owner'] ?? null;

        if (!$owner instanceof RequiresContent || !$owner->requiresContent($resolved['id'])) {
            return null;
        }

        return ['kind' => $owner->kind(), 'id' => $resolved['id'], 'message' => $owner->contentRequiredMessage()];
    }
}
