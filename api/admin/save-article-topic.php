<?php

/**
 * POST /api/admin/save-article-topic.php
 *
 * Creates (id 0) or updates one article topic for ONE website language: its
 * name, description and address there, plus its language-neutral order.
 * The pattern of api/admin/update-blog-category.php, smaller: a topic has no
 * active flag and no "primary" (ARTICLES.md, "Onderwerpen").
 *
 * The default language must have a name and an address; a translation
 * without a name has no version (and so no address). A renamed address
 * redirects its old URL in that language (SlugChangeRedirects).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\ArticleTopicRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleService;
use App\Service\Articles\ArticleSlug;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

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
$id = is_int($id) && $id > 0 ? $id : 0;

$db = Database::connection();
$topics = new ArticleTopicRepository($db);

if ($id > 0 && !$topics->exists($id)) {
    http_response_code(404);
    exit('Topic not found.');
}

$language = $id === 0
    ? ArticleLocalization::defaultLanguage()
    : (LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '');
$isDefault = $language === ArticleLocalization::defaultLanguage();
$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$sortOrder = max(0, min(9999, (int) ($_POST['sort_order'] ?? 0)));

$errors = [];
if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}
if ($isDefault && $name === '') {
    $errors[] = AdminTranslator::trans('articles.error.topic_name_required');
}
if (mb_strlen($name) > ArticleLocalization::MAX_TOPIC_NAME_LENGTH || mb_strlen($description) > ArticleLocalization::MAX_TOPIC_DESCRIPTION_LENGTH) {
    $errors[] = AdminTranslator::trans('articles.error.topic_too_long');
}

$translations = ArticleLocalization::topics();
$taken = static fn (string $candidate): bool => $translations->slugTaken($candidate, $language, $id > 0 ? $id : null);
$currentSlug = $id > 0 && $language !== '' ? ArticleLocalization::topicSlug($id, $language) : null;
$slug = $name === '' ? '' : ArticleSlug::sanitize((string) ($_POST['slug'] ?? ''));

if ($name !== '' && $slug === '' && $currentSlug === null) {
    $slug = ArticleSlug::unique('', $name, $taken);
}
if ($name !== '' && $language !== '') {
    $problem = ArticleSlug::problem($slug, $taken);
    if ($problem !== null) {
        $errors[] = $problem;
    }
}

if ($errors !== []) {
    $_SESSION['admin_article_topics_errors'] = $errors;
    header('Location: /admin/article-topics.php' . ($id > 0 ? '?edit=' . $id : ''));
    exit;
}

try {
    $db->beginTransaction();
    if ($id === 0) {
        $id = $topics->create($sortOrder);
    } else {
        $topics->update($id, $sortOrder);
    }
    ArticleLocalization::saveTopic($id, $language, [
        ArticleLocalization::SLUG => $slug === '' ? null : $slug,
        ArticleLocalization::NAME => $name,
        ArticleLocalization::DESCRIPTION => $description,
    ]);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/save-article-topic.php] ' . $e->getMessage());
    $_SESSION['admin_article_topics_errors'] = [AdminTranslator::trans('articles.error.save_failed')];
    header('Location: /admin/article-topics.php');
    exit;
}

ArticleService::recordTopicSlugChange($language, $currentSlug, $slug === '' ? null : $slug);

$_SESSION['admin_article_topics_flash'] = AdminTranslator::trans('articles.topic_saved');
header('Location: /admin/article-topics.php');
exit;
