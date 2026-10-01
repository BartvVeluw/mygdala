<?php

/**
 * POST /api/admin/create-article.php
 *
 * Creates an article as a CONCEPT with a title and an address in the default
 * language, and opens its editor. The pattern of api/admin/create-blog-post.php.
 * A draft has no live URL, so nothing here writes a redirect; its content
 * page is made when its first block is added (ContentPages::ensure()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\ArticleRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleSlug;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Publishing\PublicationStatus;

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

$language = ArticleLocalization::defaultLanguage();
$title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, ArticleLocalization::MAX_TITLE_LENGTH);

if ($title === '') {
    $_SESSION['admin_articles_errors'] = [AdminTranslator::trans('articles.error.title_required')];
    header('Location: /admin/articles.php');
    exit;
}

$db = Database::connection();
$translations = ArticleLocalization::articles();

try {
    // Row, title and address are ONE transaction.
    $db->beginTransaction();
    $articleId = (new ArticleRepository($db))->create(['status' => PublicationStatus::DRAFT, 'published_at' => null]);
    ArticleLocalization::save($articleId, $language, [
        ArticleLocalization::TITLE => $title,
        ArticleLocalization::SLUG => ArticleSlug::unique('', $title, static fn (string $slug): bool => $translations->slugTaken($slug, $language, $articleId)),
    ]);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-article.php] ' . $e->getMessage());
    $_SESSION['admin_articles_errors'] = [AdminTranslator::trans('articles.error.create_failed')];
    header('Location: /admin/articles.php');
    exit;
}

header('Location: /admin/article.php?id=' . $articleId . '&created=1');
exit;
