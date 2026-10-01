<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Database;
use App\Repository\PageSectionRepository;
use App\Repository\SearchIndexRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentOwner;
use App\Service\Language\SiteLanguages;

/**
 * The block text of every page as the site search reads it (Search 2.0,
 * SEARCH.md "De tekst van de blokken"): `search_block_texts`, one row per
 * shown block and website language, written from BlockTextExtractor.
 *
 * DERIVED, NEVER LEADING. The blocks are the truth; this is a copy for
 * searching that may always be thrown away and rebuilt. It holds only TEXT:
 * whether a page, product, project, post or article may be found at all is
 * decided by its search provider from its own tables at every search, so a
 * status change, a module switched off or a deleted owner can never leave a
 * stale result behind.
 *
 * KEPT CURRENT where a block's words change, inside the transaction of that
 * change (ContentBlockDrafts::place() — every block editor's save —,
 * SectionRegistry::setActive(), the hero editors, a carousel card, a block
 * placed at once, a Blog conversion, Projectinformatie). A deleted block,
 * page or owner takes its rows along by CASCADE. Reordering needs nothing:
 * the order is read from page_sections at search time.
 *
 * REBUILT WHOLE when its FINGERPRINT is out of date: the extractor's
 * VERSION, the website's languages (default first) and the registered block
 * types. So the first search after an update that brings this table, after
 * a language is added or the default changes, or after a module is switched
 * on or off, rebuilds it once (ensureCurrent(), under a MySQL lock; a
 * request that does not get the lock searches with what is there). Also by
 * hand: `php scripts/rebuild-search-index.php`. Bump VERSION when what a
 * block offers changes (a new searchFields() entry), and every site
 * reindexes on its next search.
 *
 * NEVER IN THE WAY: indexing a block that fails is logged and forgets the
 * fingerprint, so the next search rebuilds; it never fails the save it
 * belongs to.
 */
final class BlockSearchIndex
{
    /** Bump when the extracted text of existing blocks changes. */
    public const VERSION = 1;

    private const INDEX = 'blocks';

    /** Pages per transaction while rebuilding. */
    private const BATCH = 50;

    // ------------------------------------------------------------ keeping it current

    /**
     * Reindex one placed block (all its languages) — or remove its rows when
     * it shows nothing. Call it after the block's words are written, inside
     * that write's transaction.
     *
     * @param array<string, mixed> $pageSection the page_sections row
     */
    public static function reindexSection(array $pageSection): void
    {
        self::guarded(static function () use ($pageSection): void {
            $fresh = (new PageSectionRepository())->findById((int) $pageSection['id']);

            BlockLocalization::clearCache();
            $rows = $fresh === null ? [] : self::rows([$fresh]);
            (new SearchIndexRepository())->replaceSections([(int) $pageSection['id']], $rows);
        });
    }

    /**
     * reindexSection() for the block that holds this content row, when it is
     * placed (a draft has no rows to index yet).
     */
    public static function reindexBlock(string $type, int $sectionId): void
    {
        self::guarded(static function () use ($type, $sectionId): void {
            $pageSection = (new PageSectionRepository())->findBySectionTypeAndId($type, $sectionId);

            if ($pageSection !== null) {
                self::reindexSection($pageSection);
            }
        });
    }

    /** Every block of these pages, whatever was indexed for them before. */
    public static function reindexPages(array $pageIds): void
    {
        $sections = new PageSectionRepository();
        $all = [];
        foreach ($pageIds as $pageId) {
            foreach ($sections->findForPage((int) $pageId) as $row) {
                $all[] = $row;
            }
        }

        BlockLocalization::clearCache();
        (new SearchIndexRepository())->replacePages(array_map('intval', $pageIds), self::rows($all));
    }

    /**
     * Rebuild everything now (scripts/rebuild-search-index.php,
     * ensureCurrent()). Pages in batches, each in its own transaction, so a
     * large site never holds one long lock; the fingerprint is written last.
     *
     * @return int the number of pages read
     */
    public static function rebuild(): int
    {
        $repository = new SearchIndexRepository();
        $db = Database::connection();
        $pageIds = $repository->allPageIds();

        foreach (array_chunk($pageIds, self::BATCH) as $batch) {
            // Inside a caller's transaction (a test's), join it.
            $ownTransaction = !$db->inTransaction();
            if ($ownTransaction) {
                $db->beginTransaction();
            }
            try {
                self::reindexPages($batch);
                if ($ownTransaction) {
                    $db->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }

        $repository->deleteOtherLanguages(self::languages());
        $repository->saveFingerprint(self::INDEX, self::fingerprint());

        return count($pageIds);
    }

    /**
     * Rebuild when the stored fingerprint is not the current one. Cheap when
     * it is (one query). Never throws: a failure is logged, and search goes
     * on with what the index has.
     */
    public static function ensureCurrent(): void
    {
        try {
            $repository = new SearchIndexRepository();

            if ($repository->fingerprint(self::INDEX) === self::fingerprint() || !$repository->tryLock(self::INDEX)) {
                return;
            }

            try {
                // Another request may have finished while this one waited.
                if ($repository->fingerprint(self::INDEX) !== self::fingerprint()) {
                    self::rebuild();
                }
            } finally {
                $repository->unlock(self::INDEX);
            }
        } catch (\Throwable $e) {
            error_log('[BlockSearchIndex] rebuild failed: ' . $e->getMessage());
        }
    }

    /** Whether the index was built with today's rules (for the rebuild script and tests). */
    public static function isCurrent(): bool
    {
        return (new SearchIndexRepository())->fingerprint(self::INDEX) === self::fingerprint();
    }

    /** Makes the next search rebuild the index. */
    public static function invalidate(): void
    {
        (new SearchIndexRepository())->forgetFingerprint(self::INDEX);
    }

    /**
     * What the index was built from: the extractor's version, the website's
     * languages with the default first (fallback words depend on it) and the
     * block types registered right now.
     */
    public static function fingerprint(): string
    {
        $types = BlockDefinitions::types();
        sort($types);

        return hash('sha256', implode('|', [self::VERSION, implode(',', self::languages()), implode(',', $types)]));
    }

    // ------------------------------------------------------------ for the search providers

    /**
     * Pages whose shown blocks contain at least one of $needles in $language.
     *
     * @param list<string> $needles
     * @return list<int>
     */
    public static function pagesMatchingAny(array $needles, string $language): array
    {
        return (new SearchIndexRepository())->pagesMatchingAny($needles, $language, BlockDefinitions::types());
    }

    /**
     * Owners of this kind whose blocks contain $needle in $language: the
     * shape SearchCandidates::ids() takes for its extra source.
     *
     * @return list<int>
     */
    public static function ownersMatching(ContentOwner $owner, string $needle, string $language): array
    {
        return (new SearchIndexRepository())->ownersMatching($owner->linkTable(), $owner->linkColumn(), $needle, $language, BlockDefinitions::types());
    }

    /**
     * The block text of these pages in $language, the blocks in page order:
     * page id => ['headings' => …, 'body' => …]. Two strings per page, read
     * in ONE query for every page together.
     *
     * @param list<int> $pageIds
     * @return array<int, array{headings: string, body: string}>
     */
    public static function pageTexts(array $pageIds, string $language): array
    {
        $texts = [];

        foreach ((new SearchIndexRepository())->textsForPages($pageIds, $language, BlockDefinitions::types()) as $pageId => $blocks) {
            $texts[$pageId] = [
                'headings' => trim(implode(' ', array_column($blocks, 0))),
                'body' => trim(implode(' ', array_column($blocks, 1))),
            ];
        }

        return $texts;
    }

    /**
     * pageTexts() for owners of one kind: owner id => text. Two queries for
     * all of them (their content pages, then the text).
     *
     * @param list<int> $ownerIds
     * @return array<int, array{headings: string, body: string}>
     */
    public static function ownerTexts(ContentOwner $owner, array $ownerIds, string $language): array
    {
        $pages = (new SearchIndexRepository())->contentPagesOf($owner->linkTable(), $owner->linkColumn(), $ownerIds);
        $texts = self::pageTexts(array_values($pages), $language);
        $byOwner = [];

        foreach ($pages as $ownerId => $pageId) {
            if (isset($texts[$pageId])) {
                $byOwner[$ownerId] = $texts[$pageId];
            }
        }

        return $byOwner;
    }

    // ------------------------------------------------------------ internals

    /** @return list<string> the website's languages, the default first */
    private static function languages(): array
    {
        $default = SiteLanguages::defaultCode();
        // Sorted: moving a language in the switcher changes no text.
        $codes = array_values(array_diff(SiteLanguages::activeCodes(), [$default]));
        sort($codes);

        return [$default, ...$codes];
    }

    /**
     * @param list<array<string, mixed>> $pageSections
     * @return list<array{page_section_id: int, page_id: int, section_type: string, language_code: string, heading_text: string, body_text: string}>
     */
    private static function rows(array $pageSections): array
    {
        $byId = [];
        foreach ($pageSections as $pageSection) {
            $byId[(int) $pageSection['id']] = $pageSection;
        }

        $rows = [];
        foreach (BlockTextExtractor::extract($pageSections, self::languages()) as $sectionId => $languages) {
            foreach ($languages as $language => $text) {
                $rows[] = [
                    'page_section_id' => $sectionId,
                    'page_id' => (int) $byId[$sectionId]['page_id'],
                    'section_type' => (string) $byId[$sectionId]['section_type'],
                    'language_code' => (string) $language,
                    'heading_text' => $text['headings'],
                    'body_text' => $text['body'],
                ];
            }
        }

        return $rows;
    }

    private static function guarded(\Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            error_log('[BlockSearchIndex] a block could not be indexed: ' . $e->getMessage());

            try {
                self::invalidate();
            } catch (\Throwable) {
                // The table itself is unreachable; the next search will say so in the log.
            }
        }
    }
}
