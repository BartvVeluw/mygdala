<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Database;
use App\Repository\ContentBlockDraftRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\ContentOwners\ContentPages;
use App\Service\SectionRegistry;

/**
 * A new content block before its first save (Content Blocks Lifecycle 1.0,
 * CONTENT-BLOCKS.md, "De levensloop van een nieuw blok").
 *
 * Choosing a block in the picker used to create its content row AND place it
 * on the page, so an editor who looked and went back left an empty block
 * between the others. Now it creates the content row only — every editor
 * needs one: its words, cards, items and uploads hang from its id — and
 * records it here as a draft. The page does not know it exists: no
 * page_sections row, no position, nothing on the public page, nothing in the
 * block list.
 *
 *   open()     the picker's choice: the content row plus its draft record.
 *   place()    the editor's first successful save, INSIDE that save's
 *              transaction: the draft becomes a page_sections row at the
 *              bottom of the page's list. The draft row is locked, so a
 *              double submit places it once; the page is locked, so two
 *              drafts cannot take one position; availability is asked again,
 *              so a capped type cannot be placed twice. A save that fails
 *              rolls it all back and the editor keeps what they typed.
 *   discard()  Annuleren: the content goes the way a deleted block's goes
 *              (SectionRegistry::discardContent()), never a Media Library
 *              item it chose. A draft nobody came back to (the browser's back
 *              button, a closed tab) is removed after STALE_AFTER_HOURS by
 *              purgeStale(), which the next block choice runs.
 *
 * An existing block never passes through here: cancelling its editor is
 * leaving without saving, and nothing is removed.
 */
final class ContentBlockDrafts
{
    /** How long a draft nobody saved or cancelled is kept before purgeStale() removes it. */
    public const STALE_AFTER_HOURS = 48;

    /**
     * Opens a new block of $type as a draft on $page and returns it in the
     * shape of a page_sections row (id 0), ready for its editor URL. The
     * caller has validated $type (and $preset) against
     * SectionRegistry::availableForPage() and the page's permission.
     *
     * @param array<string, mixed> $page a real `pages` row
     *
     * @return array<string, mixed>
     */
    public static function open(array $page, string $type, ?string $preset = null): array
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key'], $preset);

        $draft = [
            'id' => 0,
            'page_id' => (int) $page['id'],
            'page_slug' => (string) $page['content_key'],
            'section_type' => $type,
            'section_key' => $sectionKey,
            'section_id' => $sectionId,
        ];

        try {
            (new ContentBlockDraftRepository())->create((int) $page['id'], $type, $sectionKey, $sectionId);
        } catch (\Throwable $e) {
            // Without its record the row could never be placed nor found:
            // take it back rather than leave it behind.
            SectionRegistry::discardContent($draft);
            throw $e;
        }

        return self::asPageSection($draft);
    }

    /**
     * The draft record of this content row, or null when it is not a draft
     * (placed on its page, or never one).
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $type, int $sectionId): ?array
    {
        return $sectionId > 0 ? (new ContentBlockDraftRepository())->findByRow($type, $sectionId) : null;
    }

    /**
     * Whether a content row is one of THIS block type's: on a page as that
     * type, or a draft of it. For the editors whose table another block
     * shares (the galleries, Projecten), which used to ask page_sections
     * alone and must not refuse their own new block.
     */
    public static function belongsTo(string $type, int $sectionId): bool
    {
        return (new PageSectionRepository())->findBySectionTypeAndId($type, $sectionId) !== null
            || self::find($type, $sectionId) !== null;
    }

    /**
     * Where a saved block stands on its page: the page_sections row of this
     * content row, placing it there first when it is a draft. Call it inside
     * the saving endpoint's transaction, as its last write; it opens one of
     * its own when there is none. Null only for a content row that is on no
     * page and no draft — a row this code did not make — whose endpoint then
     * redirects back to its editor as before.
     *
     * Throws when the draft may not be placed any more (its type is no
     * longer available on the page, such as a second instance of a type
     * capped at one); the endpoint's own failure path then keeps the input.
     *
     * @return array<string, mixed>|null the page_sections row
     */
    public static function place(string $type, int $sectionId): ?array
    {
        $db = Database::connection();
        $ownTransaction = !$db->inTransaction();

        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $drafts = new ContentBlockDraftRepository($db);
            $sections = new PageSectionRepository($db);
            $draft = $drafts->findByRow($type, $sectionId, true);

            if ($draft === null) {
                $placed = $sections->findBySectionTypeAndId($type, $sectionId);
            } else {
                $drafts->lockPage((int) $draft['page_id']);
                $page = (new PageRepository($db))->findById((int) $draft['page_id']);

                if ($page === null || !array_key_exists($type, SectionRegistry::availableForPage($page, $sections))) {
                    throw new \DomainException("A draft {$type} #{$sectionId} can no longer be placed on page #{$draft['page_id']}.");
                }

                $id = $sections->create((int) $page['id'], (string) $page['content_key'], $type, $draft['section_key'], $sectionId);
                $drafts->delete((int) $draft['id']);
                $placed = $sections->findById($id);
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return $placed;
    }

    /**
     * Annuleren on a new block: its content and its record go, and a
     * product's or project's content page made only for it goes too, so the
     * owner is back to "no content page" exactly as before the choice.
     *
     * The record goes FIRST, in its own locked transaction: from then on no
     * save can place the row, and a save that placed it a moment earlier has
     * already removed the record, so this finds nothing and removes nothing.
     * An existing block can never be reached here.
     *
     * @param array<string, mixed> $draft a record from this class
     */
    public static function discard(array $draft, bool $dropEmptyContentPage = true): void
    {
        $db = Database::connection();
        $drafts = new ContentBlockDraftRepository($db);
        $db->beginTransaction();

        try {
            $current = $drafts->findByRow((string) $draft['section_type'], (int) $draft['section_id'], true);

            if ($current === null
                || (new PageSectionRepository($db))->findBySectionTypeAndId((string) $draft['section_type'], (int) $draft['section_id']) !== null
            ) {
                $db->commit();

                return;
            }

            $drafts->delete((int) $current['id']);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        SectionRegistry::discardContent(self::asPageSection($current));

        if ($dropEmptyContentPage) {
            self::dropEmptyContentPage((int) $current['page_id']);
        }
    }

    /**
     * Every draft of one page, before the page itself is deleted
     * (PageService::delete(), ContentPages::deleteFor()).
     */
    public static function discardForPage(int $pageId): void
    {
        foreach ((new ContentBlockDraftRepository())->findForPage($pageId) as $draft) {
            self::discard($draft, false);
        }
    }

    /**
     * Removes the drafts nobody saved or cancelled within STALE_AFTER_HOURS.
     * Cheap when there are none; a failure is logged and never stops the
     * request that asked.
     */
    public static function purgeStale(): void
    {
        try {
            foreach ((new ContentBlockDraftRepository())->findOlderThan(self::STALE_AFTER_HOURS) as $draft) {
                self::discard($draft);
            }
        } catch (\Throwable $e) {
            error_log('[ContentBlockDrafts::purgeStale] ' . $e->getMessage());
        }
    }

    /**
     * A draft in the shape of a page_sections row (id 0: it has none), which
     * is what a block definition's editUrl(), deleteFiles() and
     * deleteContent() take.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public static function asPageSection(array $draft): array
    {
        return [
            'id' => 0,
            'page_id' => (int) $draft['page_id'],
            'page_slug' => (string) $draft['page_slug'],
            'section_type' => (string) $draft['section_type'],
            'section_key' => $draft['section_key'] ?? null,
            'section_id' => (int) $draft['section_id'],
            'sort_order' => null,
            'is_active' => 1,
        ];
    }

    /**
     * A product's or project's content page exists only for its blocks; one
     * that a cancelled first block made, and that holds nothing now, goes.
     * An ordinary page is never touched.
     */
    private static function dropEmptyContentPage(int $pageId): void
    {
        $page = (new PageRepository())->findById($pageId);

        if ($page === null || !ContentPages::isContentPage($page)) {
            return;
        }

        if ((new PageSectionRepository())->findForPage($pageId) !== []
            || (new ContentBlockDraftRepository())->countForPage($pageId) > 0
        ) {
            return;
        }

        $owner = ContentPages::ownerOf($page);

        if ($owner !== null) {
            ContentPages::deleteFor($owner['owner']->kind(), $owner['id']);
        }
    }
}
