<?php

declare(strict_types=1);

/**
 * Shared slug generation for the admin Portfolio item editor
 * (update-portfolio-item.php). Same algorithm as
 * api/admin/_product_validation.php's generateUniqueSlug(), reused here
 * against PortfolioGalleryRepository instead of ProductRepository.
 */

use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;

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
