<?php

/**
 * POST /api/admin/delete-blog-category.php
 *
 * Removes one blog category. The link rows in blog_post_categories go with it
 * (ON DELETE CASCADE) and NOTHING ELSE HAPPENS: no post is deleted, no post
 * is unpublished, and a post that loses its only category simply becomes an
 * uncategorised post, which the listing and the detail page both render
 * without complaint.
 *
 * That is the whole "safe deletion" policy for this taxonomy, and it is safe
 * because a category carries no content of its own — it is a grouping. A
 * category that still has posts is therefore deletable, with the count shown
 * on the button so it is an informed choice; refusing would leave an editor
 * unable to reorganise their own blog without emptying it first.
 *
 * No redirect is written for the archive URL that stops existing: inventing a
 * destination for content that is gone is how a clean 404 becomes a soft 404
 * (REDIRECTS.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\BlogCategoryRepository;
use App\Service\AdminAuth;
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

try {
    $repository = new BlogCategoryRepository();
    $category = $repository->find($id);

    if ($category === null) {
        $_SESSION['admin_blog_taxonomy_errors'] = ['Deze categorie bestaat niet (meer).'];
        header('Location: /admin/blog-categories.php');
        exit;
    }

    $repository->delete($id);

    $_SESSION['admin_blog_taxonomy_flash'] = 'Categorie "' . (string) $category['name']
        . '" is verwijderd. De berichten die erin stonden zijn ongewijzigd gebleven.';
} catch (\Throwable $e) {
    error_log('[api/admin/delete-blog-category.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De categorie kon niet worden verwijderd. Probeer het opnieuw.'];
}

header('Location: /admin/blog-categories.php');
exit;
