<?php

/**
 * POST /api/admin/update-blog-category.php
 *
 * Saves one blog category. The screen renders one form per category, and this
 * endpoint reads every field that form carries — so a save can only ever
 * change the category it was sent for, and can never blank a field that was
 * not on it.
 *
 * A renamed archive keeps its old URL working through the shared Redirect
 * Manager; see App\Service\Blog\BlogTaxonomy for the conditions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\BlogCategoryRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogSlug;
use App\Database;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogTaxonomy;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('blog.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid category id.');
}

$db = Database::connection();
$repository = new BlogCategoryRepository($db);
$category = $repository->find($id);

if ($category === null) {
    http_response_code(404);
    exit('Category not found.');
}

// ONE LANGUAGE per request (Multilingual 2.0 phase 5 wave B): the name
// and description written are those of the language `language_code` names,
// and every other translation of this category stays as it is. A name is
// required only in the DEFAULT language.
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$slug = BlogSlug::sanitize((string) ($_POST['slug'] ?? ''));
$isActive = ($_POST['is_active'] ?? '0') === '1';

$errors = [];

if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    $problems = BlogLocalization::categories()->problems(
        $language,
        [BlogLocalization::NAME => $name, BlogLocalization::DESCRIPTION => $description],
        [BlogLocalization::NAME]
    );

    if (($problems[BlogLocalization::NAME] ?? null) === 'missing') {
        $errors[] = AdminTranslator::trans('validation.geef_categorie_naam');
    }
    foreach ($problems as $problem) {
        if ($problem === 'too_long') {
            $errors[] = AdminTranslator::trans('validation.naam_mag_maximaal_100_tekens');
            break;
        }
    }
}

$slugError = BlogSlug::validationError(
    $slug,
    static fn (string $candidate): bool => $repository->slugExists($candidate, $id)
);

if ($slugError !== null) {
    $errors[] = $slugError;
}

if ($errors !== []) {
    $_SESSION['admin_blog_taxonomy_errors'] = $errors;
    header('Location: /admin/blog-categories.php');
    exit;
}

try {
    // Row and words are ONE transaction.
    $db->beginTransaction();
    $repository->update($id, [
        'slug' => $slug,
        'is_active' => $isActive,
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
    ]);
    BlogLocalization::saveCategory($id, $language, [
        BlogLocalization::NAME => $name,
        BlogLocalization::DESCRIPTION => $description,
    ]);
    $db->commit();

    $_SESSION['admin_blog_taxonomy_flash'] = 'Categorie "' . $name . '" is opgeslagen.';
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-blog-category.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De categorie kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/blog-categories.php');
    exit;
}

BlogTaxonomy::recordCategorySlugChange(
    (string) $category['slug'],
    $slug,
    (int) $category['is_active'] === 1,
    $isActive
);

// ?saved=1 only on the successful path, the convention every admin write
// endpoint in this directory follows: it is the SERVER saying a save landed,
// which is what a save bar reads and what a rejected save must never carry.
header('Location: /admin/blog-categories.php?saved=1');
exit;
