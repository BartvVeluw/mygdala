<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repository\PageRepository;
use App\Service\PageLocalization;
use App\Service\PageTranslation;

/**
 * A test page the way the CMS makes one: the `pages` row, and its name in the
 * website's default language in page_translations (Multilingual 2.0 phase 2).
 *
 * A `pages` row has no title column any more, so a test that only created
 * the row would have a nameless page, and every assertion about a page's name
 * in a trail, a list or a <title> would silently test nothing.
 * api/admin/create-page.php stores a new page exactly like this.
 *
 * Writes on the shared connection, so a test that wraps itself in a
 * transaction rolls the text back together with the page.
 */
final class PageFixture
{
    /**
     * @param array{content_key: string, slug: string, status: string} $page
     *
     * @return int the new page's id
     */
    public static function create(array $page, string $title): int
    {
        $id = (new PageRepository())->create($page);

        PageLocalization::save($id, PageLocalization::defaultLanguage(), [PageTranslation::TITLE => $title]);

        return $id;
    }
}
