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
 *
 * ITS NAME IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0 phase 5
 * wave B), like a new page and every new row since phase 3B: the slug comes
 * from that name and never changes again by itself, so a category cannot be
 * born in a translation. Row and name are one transaction. Translating it, and
 * giving it a description, happens on its own card afterwards.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Database;
use App\Repository\BlogCategoryRepository;
use App\Service\Blog\BlogLocalization;
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

// A new category is always written in the default language, whatever the
// screen's editing language is, so `language_code` from the form is not
// consulted here — this is the one place that decides it.
$language = BlogLocalization::defaultLanguage();
$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '' || mb_strlen($name) > BlogLocalization::CATEGORY_NAME_MAX_LENGTH) {
    $_SESSION['admin_blog_taxonomy_errors'] = [AdminTranslator::trans('validation.geef_categorie_naam')];
    header('Location: /admin/blog-categories.php');
    exit;
}

$db = Database::connection();
$repository = new BlogCategoryRepository($db);

try {
    $db->beginTransaction();

    $slug = BlogSlug::unique(
        (string) ($_POST['slug'] ?? ''),
        $name,
        static fn (string $candidate): bool => $repository->slugExists($candidate)
    );

    $id = $repository->create([
        'slug' => $slug,
        'is_active' => true,
        'sort_order' => $repository->nextPosition(),
    ]);
    // A new category is created in the default language, so that is the
    // language whose ADDRESS it gets (Multilingual 2.0 phase 6,
    // docs/multilingual/ROUTING.md). Every other language stays without one
    // until an editor writes it, and therefore has no public URL.
    BlogLocalization::saveCategory($id, $language, [
        BlogLocalization::SLUG => $slug,
        BlogLocalization::NAME => $name,
    ]);

    $db->commit();

    $_SESSION['admin_blog_taxonomy_flash'] = 'Categorie "' . $name . '" is aangemaakt.';
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-blog-category.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De categorie kon niet worden aangemaakt. Probeer het opnieuw.'];
}

header('Location: /admin/blog-categories.php');
exit;
