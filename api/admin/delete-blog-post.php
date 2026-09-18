<?php

/**
 * POST /api/admin/delete-blog-post.php
 *
 * Removes one blog post. What that does, exactly:
 *
 *   - its rows in blog_post_categories and blog_post_tags go with it
 *     (ON DELETE CASCADE), so no taxonomy relationship is left pointing at
 *     nothing;
 *   - the categories and tags themselves are untouched — they belong to the
 *     blog, not to this post;
 *   - NO MEDIA FILE IS DELETED. The featured image and the social image
 *     belong to the Media Library and may be on three other posts (MEDIA.md);
 *   - existing redirects are left exactly as they are. A redirect an editor
 *     wrote by hand is theirs, and an automatic one from an earlier rename
 *     now points at a URL that 404s — which is the honest answer for content
 *     that is gone, and is the Redirect Manager's own rule, not this
 *     endpoint's business to guess at (REDIRECTS.md).
 *
 * The screen confirms before it posts here, and this endpoint checks the
 * permission and the CSRF token again regardless.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\BlogPostRepository;
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
    exit('Invalid post id.');
}

try {
    $repository = new BlogPostRepository();
    $post = $repository->find($id);

    if ($post === null) {
        $_SESSION['admin_blog_errors'] = ['Dit bericht bestaat niet (meer).'];
        header('Location: /admin/blog.php');
        exit;
    }

    // The title for the message, READ BEFORE THE DELETE. A post is titled per
    // website language since Multilingual 2.0 phase 5 wave B, and those rows
    // go with the post (ON DELETE CASCADE) — asking afterwards would name an
    // empty string.
    $title = BlogLocalization::postName($id);

    $repository->delete($id);

    $_SESSION['admin_blog_flash'] = 'Bericht "' . $title . '" is verwijderd.';
} catch (\Throwable $e) {
    error_log('[api/admin/delete-blog-post.php] ' . $e->getMessage());
    $_SESSION['admin_blog_errors'] = ['Het bericht kon niet worden verwijderd. Probeer het opnieuw.'];
}

header('Location: /admin/blog.php');
exit;
