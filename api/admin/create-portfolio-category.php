<?php

/**
 * POST /api/admin/create-portfolio-category.php
 *
 * Adds a new Portfolio category (admin/portfolio.php's "Portfolio
 * categorieën" manager, "+ Nieuwe categorie" form). The slug is generated
 * once here, from name_nl, and never changes again — see
 * db/migrations/20260906070000_create_portfolio_categories_table.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_portfolio_validation.php';

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

$repository = new PortfolioCategoryRepository();

try {
    $slug = generatePortfolioCategorySlug($repository, $nameNl);
    $repository->create($nameNl, $nameEn !== '' ? $nameEn : null, $slug);
} catch (\Throwable $e) {
    error_log('[api/admin/create-portfolio-category.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_category_errors'] = ['Categorie kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/portfolio.php');
    exit;
}

header('Location: /admin/portfolio.php?category_saved=1');
exit;
