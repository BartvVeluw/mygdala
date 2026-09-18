<?php

/**
 * POST /api/admin/create-portfolio-category.php
 *
 * Adds a new Portfolio category (admin/portfolio.php's "Portfolio
 * categorieën" manager, "+ Nieuwe categorie" form).
 *
 * A NEW CATEGORY IS BORN IN THE DEFAULT LANGUAGE (Multilingual 2.0 phase 5
 * wave A), like a new page and every new child row: its slug is generated
 * once here, from that name, and never changes again — see
 * db/migrations/20260906070000_create_portfolio_categories_table.php. Row and
 * name are one transaction, so a category can never exist without a name.
 * Translating it happens on the category's own row afterwards.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_portfolio_validation.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageFallback;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioLocalization;
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

// A new category is always written in the default language, whatever the
// screen's editing language is, so `language_code` from the form is not
// consulted here — the form sends the default language and this is the one
// place that decides it.
$language = LanguageFallback::defaultLanguage();
$name = trim((string) ($_POST['name'] ?? ''));

$errors = [];
$problems = PortfolioLocalization::categories()->problems(
    $language,
    [PortfolioLocalization::NAME => $name],
    [PortfolioLocalization::NAME]
);
if (($problems[PortfolioLocalization::NAME] ?? null) === 'missing') {
    $errors[] = AdminTranslator::trans('validation.naam_nl_verplicht');
} elseif (($problems[PortfolioLocalization::NAME] ?? null) === 'too_long') {
    $errors[] = AdminTranslator::trans('validation.naam_mag_maximaal_100_tekens');
}

if ($errors !== []) {
    $_SESSION['admin_portfolio_category_errors'] = $errors;
    header('Location: /admin/portfolio.php');
    exit;
}

$db = Database::connection();
$repository = new PortfolioCategoryRepository($db);

try {
    $db->beginTransaction();
    $slug = generatePortfolioCategorySlug($repository, $name);
    $categoryId = $repository->create($slug);
    PortfolioLocalization::saveCategory($categoryId, $language, $name);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-portfolio-category.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_category_errors'] = ['Categorie kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/portfolio.php');
    exit;
}

header('Location: /admin/portfolio.php?category_saved=1');
exit;
