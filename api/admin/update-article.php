<?php

/**
 * POST /api/admin/update-article.php
 *
 * Saves one article: everything on admin/article.php's tabs, which are ONE
 * form (the pattern of api/admin/update-blog-post.php), for ONE website
 * language — the words and the address of `language_code`; every other
 * translation stays as it is. The blocks are not here: they have their own
 * endpoints, as on any page.
 *
 *   - status and date: the Publishing Engine's PublicationRules, all four
 *     statuses;
 *   - "can publish" (ArticleService::publishErrors()) is judged on what the
 *     article is ABOUT TO BE: the save is written inside a transaction, the
 *     rule reads it back on the same connection, and a refusal rolls it all
 *     back, so a half-published state cannot exist;
 *   - the address belongs to the edited language, unique there; a renamed
 *     address of a reachable article gets a redirect (SlugChangeRedirects),
 *     after the commit;
 *   - the image is resolved against the Media Library, the topic against
 *     the topics that exist; a forged id becomes an error, never a stored
 *     reference. No file is ever deleted here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\ArticleRepository;
use App\Repository\ArticleTopicRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleService;
use App\Service\Articles\ArticleSlug;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\Publishing\PublicationRules;
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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid article id.');
}

$db = Database::connection();
$repository = new ArticleRepository($db);
$article = $repository->find($id);

if ($article === null) {
    http_response_code(404);
    exit('Article not found.');
}

$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$isDefault = $language !== '' && $language === ArticleLocalization::defaultLanguage();

$submitted = [
    'language_code' => $language,
    'title' => trim((string) ($_POST['title'] ?? '')),
    'slug' => trim((string) ($_POST['slug'] ?? '')),
    'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
    'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
    'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
    'author_name' => trim((string) ($_POST['author_name'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? '')),
    'published_at' => trim((string) ($_POST['published_at'] ?? '')),
    'topic_id' => (string) (int) ($_POST['topic_id'] ?? 0),
    // A closed two-value choice; the hidden companion makes "off" arrive.
    'noindex' => ($_POST['noindex'] ?? '0') === '1',
];

$errors = [];

if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

foreach ([
    'title' => ['common.title', ArticleLocalization::MAX_TITLE_LENGTH],
    'excerpt' => ['articles.excerpt', ArticleLocalization::MAX_EXCERPT_LENGTH],
    'meta_title' => ['page.meta_title', ArticleLocalization::MAX_META_TITLE_LENGTH],
    'meta_description' => ['page.meta_description', ArticleLocalization::MAX_META_DESCRIPTION_LENGTH],
    'author_name' => ['articles.byline', ArticleService::MAX_AUTHOR_LENGTH],
] as $field => [$labelKey, $max]) {
    if (mb_strlen((string) $submitted[$field]) > $max) {
        $errors[] = AdminTranslator::trans('articles.error.too_long', ['field' => AdminTranslator::trans($labelKey), 'max' => (string) $max]);
    }
}

if ($isDefault && $submitted['title'] === '') {
    $errors[] = AdminTranslator::trans('articles.error.title_required');
}

/*
 * THE ADDRESS of the edited language. The default language must have one; a
 * translation gets one from its own title when the field is left blank, and
 * a translation without a title has no version at all (no address either).
 */
$translations = ArticleLocalization::articles();
$taken = static fn (string $candidate): bool => $translations->slugTaken($candidate, $language, $id);
$currentSlug = $language === '' ? null : ArticleLocalization::slug($id, $language);
$slug = ArticleSlug::sanitize($submitted['slug']);

if (!$isDefault && $submitted['title'] === '') {
    $slug = '';
} elseif ($slug === '' && !$isDefault && $language !== '') {
    $slug = ArticleSlug::unique('', $submitted['title'], $taken);
}

if ($language !== '' && ($slug !== '' || $isDefault)) {
    $problem = ArticleSlug::problem($slug, $taken);
    if ($problem !== null) {
        $errors[] = $problem;
    }
}

foreach (PublicationRules::validate($submitted['status'], $submitted['published_at'], PublicationStatus::ALL) as $error) {
    $errors[] = $error;
}

$featuredInput = trim((string) ($_POST['featured_media_id'] ?? ''));
$featuredMedia = MediaService::findImage(is_numeric($featuredInput) ? (int) $featuredInput : null);
if ($featuredMedia === null && $featuredInput !== '' && $featuredInput !== '0') {
    $errors[] = AdminTranslator::trans('validation.gekozen_uitgelichte_afbeelding_bestaat_meer');
}

$topicId = (int) $submitted['topic_id'];
if ($topicId > 0 && !(new ArticleTopicRepository($db))->exists($topicId)) {
    $errors[] = AdminTranslator::trans('articles.error.topic_unknown');
}

$back = static function (array $errors) use ($submitted, $id): never {
    $_SESSION['admin_article_errors'] = $errors;
    $_SESSION['admin_article_old'] = $submitted;
    header('Location: /admin/article.php?id=' . $id);
    exit;
};

if ($errors !== []) {
    $back($errors);
}

$status = PublicationStatus::normalize($submitted['status']);

try {
    $db->beginTransaction();

    $repository->update($id, [
        'status' => $status,
        'published_at' => PublicationRules::resolvePublishedAt($status, $submitted['published_at']),
        'author_name' => $submitted['author_name'],
        'featured_media_id' => $featuredMedia?->id,
        'topic_id' => $topicId,
        'noindex' => $submitted['noindex'],
    ]);

    ArticleLocalization::save($id, $language, [
        ArticleLocalization::SLUG => $slug === '' ? null : $slug,
        ArticleLocalization::TITLE => $submitted['title'],
        ArticleLocalization::EXCERPT => $submitted['excerpt'],
        ArticleLocalization::META_TITLE => $submitted['meta_title'],
        ArticleLocalization::META_DESCRIPTION => $submitted['meta_description'],
    ]);

    // Going out (or staying out): judge the article as it is about to be.
    $publishErrors = $status === PublicationStatus::DRAFT ? [] : ArticleService::publishErrors($id);
    if ($publishErrors !== []) {
        $db->rollBack();
        ArticleLocalization::clearCache();
        $back($publishErrors);
    }

    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ArticleLocalization::clearCache();
    error_log('[api/admin/update-article.php] ' . $e->getMessage());
    $back([AdminTranslator::trans('articles.error.save_failed')]);
}

ArticleService::recordSlugChange($article, $repository->find($id) ?? [], $language, $currentSlug, $slug === '' ? null : $slug);

header('Location: /admin/article.php?id=' . $id . '&updated=1');
exit;
