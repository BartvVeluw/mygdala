<?php

declare(strict_types=1);

/**
 * Shared validation for the admin Portfolio endpoints: the categories an
 * item's forms send, the page an item's editor chooses as its project page,
 * and a new category's slug (same algorithm as
 * api/admin/_product_validation.php's generateUniqueSlug(), reused against
 * PortfolioCategoryRepository).
 */

use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Service\PortfolioGalleryContent;

/**
 * Validates a submitted `categories[]` array (from admin/portfolio-item.php's
 * dynamic checkbox list) down to only ids that actually exist — unknown/
 * stale ids (e.g. a category deleted in another tab since the page loaded)
 * are silently dropped rather than failing the whole save. Used by both
 * create-portfolio-item.php and update-portfolio-item.php.
 *
 * @param mixed $submitted raw $_POST['categories'] value
 * @return list<int>
 */
function validatePortfolioCategoryIds(mixed $submitted, PortfolioCategoryRepository $repository): array
{
    if (!is_array($submitted)) {
        return [];
    }

    $submittedIds = array_values(array_unique(array_map('intval', $submitted)));
    if ($submittedIds === []) {
        return [];
    }

    $existing = $repository->findByIds($submittedIds);

    return array_map(static fn (array $category): int => (int) $category['id'], $existing);
}

/**
 * The project page an editor chose on admin/portfolio-item.php: null for "Geen
 * gekoppelde pagina" (an empty or absent value), the page's id for a page an
 * item may link to (App\Service\PortfolioGalleryContent::isLinkablePage()),
 * and false for anything else — an id no page has any more (deleted in another
 * tab since the screen loaded), a page with a template of its own, or no id at
 * all.
 *
 * Unlike an unknown category, an unusable page is not silently dropped: the
 * caller refuses false, because turning it into "no page" would quietly take
 * away a link the editor meant to keep.
 *
 * @param mixed $submitted raw $_POST['page_id'] value
 */
function validatePortfolioPageChoice(mixed $submitted, PageRepository $pages): int|false|null
{
    if ($submitted === null || (is_string($submitted) && trim($submitted) === '')) {
        return null;
    }

    if (!is_string($submitted)) {
        return false;
    }

    $pageId = filter_var(trim($submitted), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($pageId === false) {
        return false;
    }

    $page = $pages->findById($pageId);

    return $page !== null && PortfolioGalleryContent::isLinkablePage($page) ? (int) $page['id'] : false;
}

/**
 * Generates a URL-safe slug from a new category's Dutch name and makes it
 * unique against portfolio_categories.slug. Only called on create — the
 * slug is never regenerated on rename (see
 * db/migrations/20260906070000_create_portfolio_categories_table.php).
 */
function generatePortfolioCategorySlug(PortfolioCategoryRepository $repository, string $nameNl): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $nameNl) : $nameNl;
    $base = strtolower((string) ($ascii !== false ? $ascii : $nameNl));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'categorie';
    }

    $base = substr($base, 0, 90);

    $slug = $base;
    $suffix = 2;
    while ($repository->slugExists($slug)) {
        $slug = substr($base, 0, 95 - strlen((string) $suffix) - 1) . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}
