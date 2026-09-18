<?php

/**
 * POST /api/admin/update-portfolio-category.php
 *
 * Renames a Portfolio category: its NAME IN ONE WEBSITE LANGUAGE, the one
 * `language_code` names (Multilingual 2.0 phase 5 wave A). Every other
 * language's name stays exactly as it is, so switching the editing language
 * can never overwrite a translation with a stale copy.
 *
 * The slug never changes — see
 * db/migrations/20260906070000_create_portfolio_categories_table.php — and
 * relationships are keyed by category id, so renaming never affects which
 * Portfolio items carry this category, in any language.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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

$categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
if ($categoryId === false || $categoryId === null || $categoryId < 1) {
    http_response_code(400);
    exit('Invalid category id.');
}

$db = Database::connection();
$repository = new PortfolioCategoryRepository($db);
$category = $repository->findById($categoryId);

if ($category === null) {
    http_response_code(404);
    exit('Category not found.');
}

// The language comes from the form's hidden field, is normalised by
// LanguageCode and must be an active website language. It never becomes a
// column name.
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$name = trim((string) ($_POST['name'] ?? ''));

$errors = [];
if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
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
}

if ($errors !== []) {
    $_SESSION['admin_portfolio_category_errors'] = $errors;
    header('Location: /admin/portfolio.php');
    exit;
}

try {
    $db->beginTransaction();
    PortfolioLocalization::saveCategory($categoryId, $language, $name);
    $repository->touch($categoryId);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-portfolio-category.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_category_errors'] = ['Categorie kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/portfolio.php');
    exit;
}

header('Location: /admin/portfolio.php?category_saved=1');
exit;
