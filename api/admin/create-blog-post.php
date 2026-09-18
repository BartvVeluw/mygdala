<?php

/**
 * POST /api/admin/create-blog-post.php
 *
 * Creates a post as a CONCEPT and sends the editor straight into it. Same
 * guard order and PRG/session-flash pattern as every other admin write
 * endpoint in this directory.
 *
 * A new post is deliberately a draft with no publication date: nothing an
 * editor has not written yet should be reachable, and "Gepubliceerd" is one
 * click away on the Publicatie tab once it says what they meant. That is also
 * why nothing here writes a redirect — a draft's slug was never a live URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\BlogPostRepository;
use App\Service\Blog\BlogLocalization;
use App\Service\AdminAuth;
use App\Service\Blog\BlogPostService;
use App\Service\Blog\BlogPostStatus;
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

// A NEW POST IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0 phase 5
// wave B), like a new page: its slug comes from that title, and translating
// it happens on the post itself afterwards.
$language = BlogLocalization::defaultLanguage();
$title = trim((string) ($_POST['title'] ?? ''));

if ($title === '') {
    $_SESSION['admin_blog_errors'] = ['Geef het bericht een titel.'];
    header('Location: /admin/blog.php');
    exit;
}

$title = mb_substr($title, 0, BlogPostService::MAX_TITLE_LENGTH);

$db = Database::connection();
$repository = new BlogPostRepository($db);

try {
    // Row and title are ONE transaction: a post is never in the overview
    // without the title that names it there.
    $db->beginTransaction();

    $postId = $repository->create([
        'slug' => BlogPostService::slugFor('', $title, $repository),
        'status' => BlogPostStatus::DRAFT,
        'published_at' => null,
    ]);
    BlogLocalization::savePost($postId, $language, [BlogLocalization::TITLE => $title]);

    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-blog-post.php] ' . $e->getMessage());
    $_SESSION['admin_blog_errors'] = ['Het bericht kon niet worden aangemaakt. Probeer het opnieuw.'];
    header('Location: /admin/blog.php');
    exit;
}

header('Location: /admin/blog-post.php?id=' . $postId . '&created=1');
exit;
