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

use App\Service\Language\AdminTranslator;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
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

$repository = new BlogPostRepository();
$post = $repository->find($id);

if ($post === null) {
    http_response_code(404);
    exit('Post not found.');
}

$submitted = [
    'title' => trim((string) ($_POST['title'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
    'excerpt_en' => trim((string) ($_POST['excerpt_en'] ?? '')),
    'author_name' => trim((string) ($_POST['author_name'] ?? '')),
    'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
    'meta_title_en' => trim((string) ($_POST['meta_title_en'] ?? '')),
    'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
    'meta_description_en' => trim((string) ($_POST['meta_description_en'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? '')),
    'published_at' => trim((string) ($_POST['published_at'] ?? '')),
];

// Indexability is a CLOSED two-value choice, never free text: it decides a
// <meta name="robots"> value. The form's hidden companion field is what makes
// an unticked checkbox arrive here at all.
$noindex = ($_POST['noindex'] ?? '0') === '1';

$errors = BlogPostService::validate($submitted);

$slugInput = trim((string) ($_POST['slug'] ?? ''));
$slug = BlogSlug::sanitize($slugInput);
$slugError = BlogSlug::validationError(
    $slug,
    static fn (string $candidate): bool => $repository->slugExists($candidate, $id)
);

if ($slugError !== null) {
    $errors[] = $slugError;
}

$featuredMedia = MediaService::find(
    isset($_POST['featured_media_id']) && is_numeric($_POST['featured_media_id']) ? (int) $_POST['featured_media_id'] : null
);
if ($featuredMedia === null && trim((string) ($_POST['featured_media_id'] ?? '')) !== '') {
    $errors[] = AdminTranslator::trans('validation.gekozen_uitgelichte_afbeelding_bestaat_meer');
}

$socialMedia = MediaService::find(
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
        'body' => (string) ($_POST['body'] ?? ''),
        'body_en' => (string) ($_POST['body_en'] ?? ''),
        'noindex' => $noindex,
        'categories' => $categoryIds,
        'tags' => $tagLine,
    ];
    header('Location: /admin/blog-post.php?id=' . $id);
    exit;
}

$status = BlogPostStatus::normalize($submitted['status']);

try {
    $repository->update($id, [
        'title' => $submitted['title'],
        'title_en' => $submitted['title_en'],
        'slug' => $slug,
        'excerpt' => $submitted['excerpt'],
        'excerpt_en' => $submitted['excerpt_en'],
        'body' => RichTextSanitizer::sanitize((string) ($_POST['body'] ?? '')),
        'body_en' => RichTextSanitizer::sanitize((string) ($_POST['body_en'] ?? '')),
        'featured_media_id' => $featuredMedia?->id,
        'status' => $status,
        'published_at' => BlogPostService::resolvePublishedAt($status, $submitted['published_at']),
        'author_name' => $submitted['author_name'],
        'meta_title' => $submitted['meta_title'],
        'meta_title_en' => $submitted['meta_title_en'],
        'meta_description' => $submitted['meta_description'],
        'meta_description_en' => $submitted['meta_description_en'],
        'noindex' => $noindex,
        'og_media_id' => $socialMedia?->id,
    ]);

    $repository->setCategories($id, $categoryIds);
    $repository->setTags($id, BlogPostService::resolveTagIds($tagLine));
} catch (\Throwable $e) {
    error_log('[api/admin/update-blog-post.php] ' . $e->getMessage());

    $_SESSION['admin_blog_post_errors'] = ['Het bericht kon niet worden opgeslagen. Probeer het opnieuw.'];
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
BlogPostService::recordSlugChange($post, $repository->find($id) ?? []);

header('Location: /admin/blog-post.php?id=' . $id . '&updated=1');
exit;
