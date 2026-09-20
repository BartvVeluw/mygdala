<?php

/**
 * POST /api/admin/update-blog-tag.php
 *
 * Renames one tag. There is no create endpoint beside it on purpose: tags are
 * created where they are used, on a post (App\Service\Blog\BlogPostService::
 * resolveTagIds()), so a tag that exists here always has a reason to.
 *
 * A renamed archive keeps its old URL working through the shared Redirect
 * Manager — see App\Service\Blog\BlogTaxonomy.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\BlogTagRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogSlug;
use App\Database;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogTaxonomy;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Routing\LocalizedSlugInput;
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

$db = Database::connection();
$repository = new BlogTagRepository($db);
$tag = $repository->find($id);

if ($tag === null) {
    http_response_code(404);
    exit('Tag not found.');
}

// ONE LANGUAGE per request (Multilingual 2.0 phase 5 wave B): the name
// written is that of the language `language_code` names, and every other
// name of this tag stays as it is. A name is required only in the DEFAULT
// language — a translation is optional because it falls back.
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$name = trim((string) ($_POST['name'] ?? ''));
$slugInput = (string) ($_POST['slug'] ?? '');
// A tag slug is measured against its own, narrower column.
$slug = BlogSlug::sanitize($slugInput, BlogLocalization::TAG_SLUG_MAX_LENGTH);

$errors = [];
$isWritableLanguage = $language !== '' && SiteLanguages::isActive($language);

if (!$isWritableLanguage) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} elseif ($name === '' && $language === BlogLocalization::defaultLanguage()) {
    $errors[] = AdminTranslator::trans('validation.geef_tag_naam');
} elseif (mb_strlen($name) > BlogLocalization::TAG_NAME_MAX_LENGTH) {
    $errors[] = AdminTranslator::trans('validation.naam_mag_maximaal_100_tekens');
}

/**
 * THE ARCHIVE'S ADDRESS BELONGS TO THE LANGUAGE BEING EDITED — the same rule
 * api/admin/update-blog-category.php states, through the same
 * App\Service\Routing\LocalizedSlugInput.
 */
$tagSlugs = BlogLocalization::tags();
$currentSlug = $isWritableLanguage ? BlogLocalization::tagSlug($tag, $language) : null;

if ($isWritableLanguage && LocalizedSlugInput::needsFirstAddress($slug, $language, $currentSlug, $name)) {
    $slug = BlogSlug::unique(
        '',
        $name,
        static fn (string $candidate): bool => $tagSlugs->slugTaken($candidate, $language, $id),
        BlogLocalization::TAG_SLUG_MAX_LENGTH
    );
}

if ($isWritableLanguage && ($slug !== '' || LocalizedSlugInput::addressIsRequired($language))) {
    $slugError = BlogSlug::validationError(
        $slug,
        static fn (string $candidate): bool => $tagSlugs->slugTaken($candidate, $language, $id)
            || (LocalizedSlugInput::addressIsRequired($language) && $repository->slugExists($candidate, $id))
    );

    if ($slugError !== null) {
        $errors[] = $slugError;
    }
}

if ($errors !== []) {
    // What was sent but not written, per tag and per language — see
    // api/admin/update-blog-category.php.
    $_SESSION['admin_blog_taxonomy_errors'] = $errors;
    $_SESSION['admin_blog_taxonomy_old'] = [
        'id' => $id,
        'language_code' => $language,
        'name' => $name,
        'slug' => $slugInput,
    ];
    header('Location: /admin/blog-tags.php');
    exit;
}

try {
    // Row and name are ONE transaction.
    $db->beginTransaction();
    // Only the default language moves the neutral key.
    $repository->update($id, [
        'slug' => LocalizedSlugInput::neutralSlug($slug, $language, (string) $tag['slug']),
    ]);
    BlogLocalization::saveTag($id, $language, [
        // NULL is "this language has no public route", not an address.
        BlogLocalization::SLUG => LocalizedSlugInput::stored($slug),
        BlogLocalization::NAME => $name,
    ]);
    $db->commit();

    $_SESSION['admin_blog_taxonomy_flash'] = 'Tag "' . $name . '" is opgeslagen.';
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-blog-tag.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De tag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/blog-tags.php');
    exit;
}

// In the URL space of the language that was edited — see
// api/admin/update-blog-category.php.
BlogTaxonomy::recordTagSlugChange((string) ($currentSlug ?? ''), $slug, $language);

// ?saved=1 on the successful path only — see update-blog-category.php.
header('Location: /admin/blog-tags.php?saved=1');
exit;
