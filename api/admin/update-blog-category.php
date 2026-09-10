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

use App\Repository\BlogCategoryRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogTaxonomy;
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

$repository = new BlogCategoryRepository();
$category = $repository->find($id);

if ($category === null) {
    http_response_code(404);
    exit('Category not found.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$slug = BlogSlug::sanitize((string) ($_POST['slug'] ?? ''));
$isActive = ($_POST['is_active'] ?? '0') === '1';

$errors = [];

if ($name === '') {
    $errors[] = 'Geef de categorie een naam.';
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
    $repository->update($id, [
        'name' => mb_substr($name, 0, 150),
        'name_en' => mb_substr(trim((string) ($_POST['name_en'] ?? '')), 0, 150),
        'slug' => $slug,
        'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500),
        'description_en' => mb_substr(trim((string) ($_POST['description_en'] ?? '')), 0, 500),
        'is_active' => $isActive,
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
    ]);

    $_SESSION['admin_blog_taxonomy_flash'] = 'Categorie "' . $name . '" is opgeslagen.';
} catch (\Throwable $e) {
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
