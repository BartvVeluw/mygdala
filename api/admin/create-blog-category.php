<?php

/**
 * POST /api/admin/create-blog-category.php
 *
 * Adds one blog category. The slug is optional: left empty it is derived from
 * the name, and either way it is made unique against the categories that
 * already exist (App\Service\Blog\BlogSlug).
 *
 * A new category is ACTIVE and lands after the last one in the ordering, so
 * it works the moment it is made and nothing else moves.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\BlogCategoryRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogSlug;
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

$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    $_SESSION['admin_blog_taxonomy_errors'] = ['Geef de categorie een naam.'];
    header('Location: /admin/blog-categories.php');
    exit;
}

try {
    $repository = new BlogCategoryRepository();

    $repository->create([
        'name' => mb_substr($name, 0, 150),
        'slug' => BlogSlug::unique(
            (string) ($_POST['slug'] ?? ''),
            $name,
            static fn (string $candidate): bool => $repository->slugExists($candidate)
        ),
        'is_active' => true,
        'sort_order' => $repository->nextPosition(),
    ]);

    $_SESSION['admin_blog_taxonomy_flash'] = 'Categorie "' . $name . '" is aangemaakt.';
} catch (\Throwable $e) {
    error_log('[api/admin/create-blog-category.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De categorie kon niet worden aangemaakt. Probeer het opnieuw.'];
}

header('Location: /admin/blog-categories.php');
exit;
