<?php

/**
 * POST /api/admin/update-blog-tag.php
 *
 * Renames one tag. There is no create endpoint beside it on purpose: tags are
 * created where they are used, on a post (App\Service\Blog\BlogPostService::
 * resolveTagIds()), so a tag that exists here always has a reason to.
 *
 * A renamed archive keeps its old URL working through the shared Redirect
 * Manager — see App\Service\Blog\BlogTaxonomy.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\BlogTagRepository;
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
    exit('Invalid tag id.');
}

$repository = new BlogTagRepository();
$tag = $repository->find($id);

if ($tag === null) {
    http_response_code(404);
    exit('Tag not found.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$slug = BlogSlug::sanitize((string) ($_POST['slug'] ?? ''));

$errors = [];

if ($name === '') {
    $errors[] = AdminTranslator::trans('validation.geef_tag_naam');
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
    header('Location: /admin/blog-tags.php');
    exit;
}

try {
    $repository->update($id, [
        'name' => mb_substr($name, 0, 100),
        'name_en' => mb_substr(trim((string) ($_POST['name_en'] ?? '')), 0, 100),
        'slug' => $slug,
    ]);

    $_SESSION['admin_blog_taxonomy_flash'] = 'Tag "' . $name . '" is opgeslagen.';
} catch (\Throwable $e) {
    error_log('[api/admin/update-blog-tag.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De tag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/blog-tags.php');
    exit;
}

BlogTaxonomy::recordTagSlugChange((string) $tag['slug'], $slug);

// ?saved=1 on the successful path only — see update-blog-category.php.
header('Location: /admin/blog-tags.php?saved=1');
exit;
