<?php

/**
 * POST /api/admin/delete-article.php
 *
 * Removes one article through ArticleService::delete(), in ONE database
 * transaction (ContentPages::deleteOwner()):
 *
 *   1. its content page: every block through SectionRegistry::delete()
 *      (words, child rows), its block drafts, the link and the page row —
 *      ContentPages::deleteFor(), which goes FIRST because the link's
 *      RESTRICT key refuses the other order;
 *   2. the article row, and with it by CASCADE its translations. Its topic
 *      stays: it belongs to every article that has it.
 *
 * A failure in either step rolls both back: the article is still there,
 * whole. Media Library items are never deleted (MEDIA.md). Redirects stay as
 * they are (REDIRECTS.md): content that is gone answers 404 at its old
 * address.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\ArticleRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleService;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('articles.manage');

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
    exit('Invalid article id.');
}

$repository = new ArticleRepository();

if ($repository->find($id) === null) {
    $_SESSION['admin_articles_errors'] = [AdminTranslator::trans('articles.not_found')];
    header('Location: /admin/articles.php');
    exit;
}

// Read before the delete: the words go with the row.
$name = ArticleLocalization::name($id);

try {
    ArticleService::delete($id, $repository);
    $_SESSION['admin_articles_flash'] = AdminTranslator::trans('articles.deleted', ['name' => $name !== '' ? $name : '#' . $id]);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-article.php] ' . $e->getMessage());
    $_SESSION['admin_articles_errors'] = [AdminTranslator::trans('articles.error.delete_failed')];
}

header('Location: /admin/articles.php');
exit;
