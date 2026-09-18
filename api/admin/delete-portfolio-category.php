<?php

/**
 * POST /api/admin/delete-portfolio-category.php
 *
 * Permanently removes a Portfolio category — but only when it is not
 * currently assigned to any Portfolio item. The usage count is checked here
 * first (for a friendly error message); the
 * portfolio_item_categories.portfolio_category_id foreign key is RESTRICT
 * as a defense-in-depth backstop, so even a race (another tab assigning the
 * category between this check and the DELETE) fails safely rather than
 * silently orphaning relationships.
 *
 * ITS NAME GOES WITH IT, in every language: portfolio_category_translations
 * hangs off the row with ON DELETE CASCADE (Multilingual 2.0 phase 5 wave A).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PortfolioCategoryRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('portfolio.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
if ($categoryId === false || $categoryId === null || $categoryId < 1) {
    http_response_code(400);
    exit('Invalid category id.');
}

$repository = new PortfolioCategoryRepository();
$category = $repository->findById($categoryId);

if ($category === null) {
    http_response_code(404);
    exit('Category not found.');
}

$withCounts = $repository->findAllWithUsageCounts();
$itemCount = 0;
foreach ($withCounts as $row) {
    if ((int) $row['id'] === $categoryId) {
        $itemCount = (int) $row['item_count'];
        break;
    }
}

if ($itemCount > 0) {
    $_SESSION['admin_portfolio_category_errors'] = [
        'Categorie "' . $category['name_nl'] . '" is nog toegewezen aan ' . $itemCount . ' portfolio-item(s) en kan niet worden verwijderd. Verwijder eerst de toewijzing bij die items.',
    ];
    header('Location: /admin/portfolio.php');
    exit;
}

try {
    $repository->delete($categoryId);
} catch (\Throwable $e) {
    // Defense-in-depth: the RESTRICT foreign key rejects the DELETE if the
    // category turned out to still be in use (e.g. assigned in another tab
    // right after the count check above).
    error_log('[api/admin/delete-portfolio-category.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_category_errors'] = ['Categorie kon niet worden verwijderd — mogelijk net toegewezen aan een item.'];
    header('Location: /admin/portfolio.php');
    exit;
}

header('Location: /admin/portfolio.php?category_saved=1');
exit;
