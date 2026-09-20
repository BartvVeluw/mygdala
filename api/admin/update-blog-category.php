<?php

/**
 * POST /api/admin/update-blog-category.php
 *
 * Saves one blog category. The screen renders one form per category, and this
 * endpoint reads every field that form carries — so a save can only ever
 * change the category it was sent for, and can never blank a field that was
 * not on it.
 *
 * A renamed archive keeps its old URL working through the shared Redirect
 * Manager; see App\Service\Blog\BlogTaxonomy for the conditions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\BlogCategoryRepository;
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
    exit('Invalid category id.');
}

$db = Database::connection();
$repository = new BlogCategoryRepository($db);
$category = $repository->find($id);

if ($category === null) {
    http_response_code(404);
    exit('Category not found.');
}

// ONE LANGUAGE per request (Multilingual 2.0 phase 5 wave B): the name
// and description written are those of the language `language_code` names,
// and every other translation of this category stays as it is. A name is
// required only in the DEFAULT language.
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$slugInput = (string) ($_POST['slug'] ?? '');
$slug = BlogSlug::sanitize($slugInput);
$isActive = ($_POST['is_active'] ?? '0') === '1';
$sortOrder = (int) ($_POST['sort_order'] ?? 0);

$errors = [];
$isWritableLanguage = $language !== '' && SiteLanguages::isActive($language);

if (!$isWritableLanguage) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    $problems = BlogLocalization::categories()->problems(
        $language,
        [BlogLocalization::NAME => $name, BlogLocalization::DESCRIPTION => $description],
        [BlogLocalization::NAME]
    );

    if (($problems[BlogLocalization::NAME] ?? null) === 'missing') {
        $errors[] = AdminTranslator::trans('validation.geef_categorie_naam');
    }
    foreach ($problems as $problem) {
        if ($problem === 'too_long') {
            $errors[] = AdminTranslator::trans('validation.naam_mag_maximaal_100_tekens');
            break;
        }
    }
}

/**
 * THE ARCHIVE'S ADDRESS BELONGS TO THE LANGUAGE BEING EDITED (Multilingual
 * 2.0 phase 6, docs/multilingual/ROUTING.md). Saving the English version
 * writes /en/blog/category/<slug> and leaves /blog/categorie/<slug> exactly
 * where it is; a collision is a collision inside one language only.
 *
 * `blog_categories.slug` stays in step with the DEFAULT language's address:
 * it is the neutral key the stored redirects were written against. The rule
 * that decides all of this is App\Service\Routing\LocalizedSlugInput's, the
 * same one the page and the post editor follow — there is no second copy of
 * it here.
 */
$categorySlugs = BlogLocalization::categories();
$currentSlug = $isWritableLanguage ? BlogLocalization::categorySlug($category, $language) : null;

if ($isWritableLanguage && LocalizedSlugInput::needsFirstAddress($slug, $language, $currentSlug, $name)) {
    $slug = BlogSlug::unique(
        '',
        $name,
        static fn (string $candidate): bool => $categorySlugs->slugTaken($candidate, $language, $id)
    );
}

if ($isWritableLanguage && ($slug !== '' || LocalizedSlugInput::addressIsRequired($language))) {
    $slugError = BlogSlug::validationError(
        $slug,
        static fn (string $candidate): bool => $categorySlugs->slugTaken($candidate, $language, $id)
            || (LocalizedSlugInput::addressIsRequired($language) && $repository->slugExists($candidate, $id))
    );

    if ($slugError !== null) {
        $errors[] = $slugError;
    }
}

if ($errors !== []) {
    // What was sent but not written, so the card comes back with the
    // editor's own input rather than the stored row — the same PRG
    // arrangement admin/page.php and admin/blog-post.php use. It is kept per
    // category AND per language, because the screen shows one card per
    // category in one language.
    $_SESSION['admin_blog_taxonomy_errors'] = $errors;
    $_SESSION['admin_blog_taxonomy_old'] = [
        'id' => $id,
        'language_code' => $language,
        'name' => $name,
        'description' => $description,
        'slug' => $slugInput,
        'is_active' => $isActive,
        'sort_order' => $sortOrder,
    ];
    header('Location: /admin/blog-categories.php');
    exit;
}

try {
    // Row and words are ONE transaction.
    $db->beginTransaction();
    $repository->update($id, [
        // Only the default language moves the neutral key.
        'slug' => LocalizedSlugInput::neutralSlug($slug, $language, (string) $category['slug']),
        'is_active' => $isActive,
        'sort_order' => $sortOrder,
    ]);
    BlogLocalization::saveCategory($id, $language, [
        // NULL is "this language has no public route", not an address.
        BlogLocalization::SLUG => LocalizedSlugInput::stored($slug),
        BlogLocalization::NAME => $name,
        BlogLocalization::DESCRIPTION => $description,
    ]);
    $db->commit();

    $_SESSION['admin_blog_taxonomy_flash'] = 'Categorie "' . $name . '" is opgeslagen.';
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-blog-category.php] ' . $e->getMessage());
    $_SESSION['admin_blog_taxonomy_errors'] = ['De categorie kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/blog-categories.php');
    exit;
}

// In the URL space of the language that was edited: the address that moved is
// that language's, and a language that had none is not moving anything.
BlogTaxonomy::recordCategorySlugChange(
    (string) ($currentSlug ?? ''),
    $slug,
    (int) $category['is_active'] === 1,
    $isActive,
    $language
);

// ?saved=1 only on the successful path, the convention every admin write
// endpoint in this directory follows: it is the SERVER saying a save landed,
// which is what a save bar reads and what a rejected save must never carry.
header('Location: /admin/blog-categories.php?saved=1');
exit;
