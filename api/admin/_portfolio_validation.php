<?php

declare(strict_types=1);

/**
 * Shared validation for the admin Portfolio endpoints: slug generation (same
 * algorithm as api/admin/_product_validation.php's generateUniqueSlug(),
 * reused here against the Portfolio repositories), the categories an item's
 * forms send, and the page an item's editor chooses as its project page.
 */

use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\PortfolioGalleryContent;

/**
 * Generates a URL-safe slug from the Dutch title and makes it unique against
 * portfolio_gallery_items.slug. Only called when "Enable project detail
 * page" is turned on and no slug has been saved yet — once a slug exists it
 * is only ever changed by the admin explicitly editing the field (see
 * update-portfolio-item.php), never silently regenerated from the title.
 */
function generatePortfolioItemSlug(PortfolioGalleryRepository $repository, string $titleNl, ?int $excludeId = null): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $titleNl) : $titleNl;
    $base = strtolower((string) ($ascii !== false ? $ascii : $titleNl));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'project';
    }

    $base = substr($base, 0, 150);

    $slug = $base;
    $suffix = 2;
    while ($repository->slugExists($slug, $excludeId)) {
        $slug = substr($base, 0, 165 - strlen((string) $suffix) - 1) . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}

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

/**
 * Sanitizes an admin-submitted slug (from the editable slug field): lowercase,
 * ASCII-transliterated, non [a-z0-9-] characters collapsed to a single "-",
 * trimmed of leading/trailing "-". Returns '' for an empty/unsalvageable
 * input so the caller can fall back to generatePortfolioItemSlug().
 */
function sanitizePortfolioItemSlug(string $rawSlug): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $rawSlug) : $rawSlug;
    $slug = strtolower((string) ($ascii !== false ? $ascii : $rawSlug));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return substr($slug, 0, 170);
}
