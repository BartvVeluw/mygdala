<?php

/**
 * POST /api/admin/update-blog-post.php
 *
 * Saves one blog post: everything on admin/blog-post.php's three tabs, which
 * are ONE form to this one endpoint. That is deliberate — this endpoint reads
 * the whole post from one request, so a form carrying only the SEO fields
 * would blank the body, and a form carrying only the content fields would
 * blank the publication moment.
 *
 * WHAT IS SANITISED WHERE. The two rich-text bodies go through
 * App\Service\RichTextSanitizer, the same allowlist Portfolio descriptions
 * and the Rich text block use — the editor in the browser is a convenience,
 * never the security boundary. The status is normalised against a closed set.
 * The two media ids are resolved against the Media Library before anything is
 * written, so an id naming nothing becomes "no image" rather than a stored
 * reference. The category ids are filtered against the categories that
 * actually exist, and the tag line becomes tag ids through
 * App\Service\Blog\BlogPostService.
 *
 * NO FILE IS EVER DELETED HERE. A featured image belongs to the Media Library
 * and may be on three other posts (MEDIA.md); removing it from a post removes
 * the reference and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostService;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSlug;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\RichTextSanitizer;

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

$db = Database::connection();
$repository = new BlogPostRepository($db);
$post = $repository->find($id);

if ($post === null) {
    http_response_code(404);
    exit('Post not found.');
}

// ONE LANGUAGE per request (Multilingual 2.0 phase 5 wave B): the words
// written are those of the language `language_code` names, which must be an
// active website language. Every other translation of this post stays exactly
// as it is. The rich body travels along unsanitized here and is cleaned on
// its way into storage, as it always was.
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

$submitted = [
    'language_code' => $language,
    'title' => trim((string) ($_POST['title'] ?? '')),
    'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
    'body' => (string) ($_POST['body'] ?? ''),
    'author_name' => trim((string) ($_POST['author_name'] ?? '')),
    'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
    'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? '')),
    'published_at' => trim((string) ($_POST['published_at'] ?? '')),
];

// Indexability is a CLOSED two-value choice, never free text: it decides a
// <meta name="robots"> value. The form's hidden companion field is what makes
// an unticked checkbox arrive here at all.
$noindex = ($_POST['noindex'] ?? '0') === '1';

$errors = BlogPostService::validate($submitted);

if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

/**
 * THE ADDRESS BELONGS TO THE LANGUAGE BEING EDITED (Multilingual 2.0 phase 6,
 * docs/multilingual/ROUTING.md). Saving the English version writes the English
 * address and leaves /blog/mijn-bericht exactly where it is; a collision is a
 * collision inside one language only, because /blog/x and /en/blog/x are
 * different URLs.
 *
 * `blog_posts.slug` stays in step with the DEFAULT language's address: it is
 * the neutral key the stored redirects were written against.
 *
 * A translation's FIRST address is made from its own title when the field is
 * left blank, the way a new post's is made from its title, and an existing one
 * is never regenerated. Only the default language must have one at all — a
 * translation without an address simply has no public URL yet.
 */
$isDefaultLanguage = $language !== '' && $language === BlogLocalization::defaultLanguage();
$postSlugs = BlogLocalization::posts();

$slugInput = trim((string) ($_POST['slug'] ?? ''));
$slug = BlogSlug::sanitize($slugInput);

$currentSlug = $language === '' ? null : BlogLocalization::postSlug($post, $language);

if ($slug === '' && !$isDefaultLanguage && $language !== '' && $submitted['title'] !== '' && $currentSlug === null) {
    $slug = BlogSlug::unique(
        '',
        $submitted['title'],
        static fn (string $candidate): bool => $postSlugs->slugTaken($candidate, $language, $id)
    );
}

if ($slug !== '' || $isDefaultLanguage) {
    $slugError = BlogSlug::validationError(
        $slug,
        static fn (string $candidate): bool => $language !== '' && (
            $postSlugs->slugTaken($candidate, $language, $id)
            || ($isDefaultLanguage && $repository->slugExists($candidate, $id))
        )
    );

    if ($slugError !== null) {
        $errors[] = $slugError;
    }
}

$featuredMedia = MediaService::findImage(
    isset($_POST['featured_media_id']) && is_numeric($_POST['featured_media_id']) ? (int) $_POST['featured_media_id'] : null
);
if ($featuredMedia === null && trim((string) ($_POST['featured_media_id'] ?? '')) !== '') {
    $errors[] = AdminTranslator::trans('validation.gekozen_uitgelichte_afbeelding_bestaat_meer');
}

$socialMedia = MediaService::findImage(
    isset($_POST['og_media_id']) && is_numeric($_POST['og_media_id']) ? (int) $_POST['og_media_id'] : null
);
if ($socialMedia === null && trim((string) ($_POST['og_media_id'] ?? '')) !== '') {
    $errors[] = AdminTranslator::trans('validation.gekozen_deel_afbeelding_bestaat_meer');
}

// Category ids are checked against the categories that really exist, so a
// crafted POST can only ever hit or miss a real row.
$existingCategoryIds = array_map(
    static fn (array $category): int => (int) $category['id'],
    (new BlogCategoryRepository())->all()
);
$categoryIds = array_values(array_intersect(
    array_map('intval', (array) ($_POST['categories'] ?? [])),
    $existingCategoryIds
));

$tagLine = trim((string) ($_POST['tags'] ?? ''));

if ($errors !== []) {
    $_SESSION['admin_blog_post_errors'] = $errors;
    $_SESSION['admin_blog_post_old'] = $submitted + [
        'slug' => $slugInput,
        'noindex' => $noindex,
        'categories' => $categoryIds,
        'tags' => $tagLine,
    ];
    header('Location: /admin/blog-post.php?id=' . $id);
    exit;
}

$status = BlogPostStatus::normalize($submitted['status']);

try {
    // Row, words, categories and tags are ONE transaction.
    $db->beginTransaction();
    $repository->update($id, [
        // Only the default language moves the neutral key.
        'slug' => ($isDefaultLanguage && $slug !== '') ? $slug : (string) $post['slug'],
        'featured_media_id' => $featuredMedia?->id,
        'status' => $status,
        'published_at' => BlogPostService::resolvePublishedAt($status, $submitted['published_at']),
        'author_name' => $submitted['author_name'],
        'noindex' => $noindex,
        'og_media_id' => $socialMedia?->id,
    ]);

    BlogLocalization::savePost($id, $language, [
        // '' is "this language has no public route", not an address.
        BlogLocalization::SLUG => $slug === '' ? null : $slug,
        BlogLocalization::TITLE => $submitted['title'],
        BlogLocalization::EXCERPT => $submitted['excerpt'],
        BlogLocalization::BODY => RichTextSanitizer::sanitize($submitted['body']),
        BlogLocalization::META_TITLE => $submitted['meta_title'],
        BlogLocalization::META_DESCRIPTION => $submitted['meta_description'],
    ]);

    $repository->setCategories($id, $categoryIds);
    $repository->setTags($id, BlogPostService::resolveTagIds($tagLine));
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-blog-post.php] ' . $e->getMessage());

    $_SESSION['admin_blog_post_errors'] = ['Het bericht kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_blog_post_old'] = $submitted + [
        'slug' => $slugInput,
        'noindex' => $noindex,
        'categories' => $categoryIds,
        'tags' => $tagLine,
    ];
    header('Location: /admin/blog-post.php?id=' . $id);
    exit;
}

/**
 * The post moved, so its old URL must keep working — a permanent redirect in
 * the same table, with the same rules, an editor already knows from renaming
 * a page (REDIRECTS.md). It runs only when the post was public BEFORE this
 * save and still is after it, and only after the save succeeded, so a
 * redirect can never point at a slug the post did not actually get. See
 * App\Service\Blog\BlogPostService::recordSlugChange().
 */
BlogPostService::recordSlugChange($post, $repository->find($id) ?? [], $language, $currentSlug, $slug);

header('Location: /admin/blog-post.php?id=' . $id . '&updated=1');
exit;
