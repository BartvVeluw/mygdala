<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Service\Blocks\BlockLocalization;
use App\Service\RichTextContent;

/**
 * Words for a test's own blocks, written the way the CMS writes them: per
 * website language, through App\Service\Blocks\BlockLocalization
 * (Multilingual 2.0 phase 3, docs/multilingual/ARCHITECTURE.md). The twin of
 * Tests\Support\PageFixture for block text.
 *
 * ::removeForPage() is for tests that clean up by deleting content rows
 * themselves: call it BEFORE those rows go, or their words stay behind as
 * orphans in the test database (BlockLocalization::orphans()).
 */
final class BlockTextFixture
{
    /** A Rich text block's body, in the default language unless one is named. */
    public static function richText(int $sectionId, string $html, ?string $language = null): void
    {
        BlockLocalization::save('rich_text_sections', $sectionId, $language ?? BlockLocalization::defaultLanguage(), [
            RichTextContent::BODY => $html,
        ]);
        RichTextContent::clearCache();
    }

    /**
     * Delete the words of every row of one block content table on one page.
     * The table name is the test's own constant, never input.
     */
    public static function removeForPage(string $contentTable, string $pageSlug): void
    {
        $rows = Database::connection()->prepare("SELECT id FROM `{$contentTable}` WHERE page_slug = ?");
        $rows->execute([$pageSlug]);

        foreach ($rows->fetchAll() as $row) {
            BlockLocalization::deleteOwner($contentTable, (int) $row['id']);
        }
    }
}
