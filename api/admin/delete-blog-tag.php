<?php

/**
 * POST /api/admin/delete-blog-tag.php
 *
 * Removes one tag everywhere at once. Its rows in blog_post_tags go with it
 * (ON DELETE CASCADE); no post is deleted and no post loses anything else.
 * That is the whole safe-deletion policy for tags — a tag holds no content,
 * so removing one is exactly as reversible as retyping it on the posts that
 * had it.
 *
 * No redirect is written for the archive URL that stops existing, for the
 * same reason deleting a page writes none (REDIRECTS.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\BlogTagRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogLocalization;
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

try {
    $repository = new BlogTagRepository();
    $tag = $repository->find($id);

    if ($tag === null) {
        $_SESSION['admin_blog_taxonomy_errors'] = ['Deze tag bestaat niet (meer).'];
        header('Location: /admin/blog-tags.php');
        exit;
    }

    // The name for the message, READ BEFORE THE DELETE. A tag is named per
    // website language since Multilingual 2.0 phase 5 wave B, and those rows
    // go with the tag (ON DELETE CASCADE) — asking afterwards would name an
    // empty string.
    $name = BlogLocalization::tagLabel($id);

    $repository->delete($id);

    $_SESSION['admin_blog_taxonomy_flash'] = 'Tag "' . $name . '" is verwijderd.';
} catch (\Throwable $e) {
    error_log('[api/admin/delete-blog-tag.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De tag kon niet worden verwijderd. Probeer het opnieuw.'];
}

header('Location: /admin/blog-tags.php');
exit;
