<?php

/**
 * POST /api/admin/update-portfolio-category.php
 *
 * Renames a Portfolio category (name_nl/name_en only — the slug never
 * changes, see db/migrations/20260906070000_create_portfolio_categories_table.php).
 * Relationships are keyed by category id, so renaming never affects which
 * Portfolio items carry this category.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
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

$nameNl = trim((string) ($_POST['name_nl'] ?? ''));
$nameEn = trim((string) ($_POST['name_en'] ?? ''));

$errors = [];
if ($nameNl === '') {
    $errors[] = AdminTranslator::trans('validation.naam_nl_verplicht');
} elseif (mb_strlen($nameNl) > 100 || mb_strlen($nameEn) > 100) {
    $errors[] = AdminTranslator::trans('validation.naam_mag_maximaal_100_tekens');
}

if ($errors !== []) {
    $_SESSION['admin_portfolio_category_errors'] = $errors;
    header('Location: /admin/portfolio.php');
    exit;
}

$repository->rename($categoryId, $nameNl, $nameEn !== '' ? $nameEn : null);

header('Location: /admin/portfolio.php?category_saved=1');
exit;
