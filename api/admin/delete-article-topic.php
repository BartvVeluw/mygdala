<?php

/**
 * POST /api/admin/delete-article-topic.php
 *
 * Removes one topic and, by CASCADE, its translations. Its articles stay and
 * simply have no topic any more (articles.topic_id ON DELETE SET NULL): a
 * topic is a way to browse, never a reason to lose an article. Its old
 * address answers 404, like any content that is gone (REDIRECTS.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\ArticleTopicRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleLocalization;
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
    exit('Invalid topic id.');
}

$topics = new ArticleTopicRepository();
if (!$topics->exists($id)) {
    http_response_code(404);
    exit('Topic not found.');
}

$name = ArticleLocalization::topicName($id);

try {
    $topics->delete($id);
    ArticleLocalization::topics()->forget($id);
    $_SESSION['admin_article_topics_flash'] = AdminTranslator::trans('articles.topic_deleted', ['name' => $name]);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-article-topic.php] ' . $e->getMessage());
    $_SESSION['admin_article_topics_errors'] = [AdminTranslator::trans('articles.error.delete_failed')];
}

header('Location: /admin/article-topics.php');
exit;
