<?php

/**
 * POST /api/admin/update-blog-post-content-mode.php   (id, content_mode)
 *
 * The editor's deliberate switch between a post's classic body and its
 * content blocks (Blog 2.0, BLOG.md "Klassieke tekst en contentblokken"):
 *
 *   content_mode=blocks  convert: the classic body per language becomes one
 *                        Tekst block (only for a post without blocks yet),
 *                        then the post shows its blocks
 *   content_mode=legacy  back to the classic body; the blocks stay, unshown
 *
 * Nothing is deleted either way (App\Service\Blog\BlogContentConversion). The
 * editor asks before converting, in the CMS's own dialog.
 *
 * The four guards first, as every Blog endpoint (delete-blog-post.php is
 * the model): login, blog.manage, POST, CSRF. The mode is a closed pair.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogContentConversion;
use App\Service\Blog\BlogContentMode;
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
$mode = $_POST['content_mode'] ?? null;

if ($id === false || $id === null || $id < 1 || !is_string($mode) || !in_array($mode, BlogContentMode::ALL, true)) {
    http_response_code(400);
    exit('Invalid request.');
}

if ((new BlogPostRepository())->find($id) === null) {
    http_response_code(404);
    exit('Not found');
}

try {
    if ($mode === BlogContentMode::BLOCKS) {
        BlogContentConversion::toBlocks($id);
    } else {
        BlogContentConversion::toLegacy($id);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/update-blog-post-content-mode.php] post #' . $id . ': ' . $e->getMessage());
    $_SESSION['admin_blog_post_errors'] = ['De inhoud kon niet worden omgezet. Er is niets veranderd.'];
    header('Location: /admin/blog-post.php?id=' . $id . '&tab=inhoud');
    exit;
}

header('Location: /admin/blog-post.php?id=' . $id . '&tab=inhoud&updated=1');
exit;
