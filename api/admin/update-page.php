<?php

/**
 * POST /api/admin/update-page.php
 *
 * Saves one page's settings (admin/page.php's "Algemeen" + "SEO" cards) —
 * Title, Slug, Status, SEO title, Meta description, indexability and the
 * page's own social sharing image, for every CMS page alike. Same guard order and PRG/session-flash pattern as every other admin
 * endpoint.
 *
 * Two INDEPENDENT locks are enforced HERE, not by the disabled inputs in
 * the form, so a forged request cannot get past either:
 *
 *   - a page served at a fixed URL (App\Service\PageContent::isRouteBound())
 *     keeps its stored slug: its public URL is decided by the route it is
 *     served from, not by this field;
 *   - a PROTECTED page (isProtected(): the site root, or a page carrying
 *     application-critical functionality) additionally keeps its stored
 *     status, so nobody can take the homepage or the storefront offline.
 *
 * An ordinary content page that merely happens to be served from its own
 * file — Diensten, Portfolio, Over mij, Contact — can be set to Concept like
 * any other page. Title and SEO fields are saved normally everywhere.
 *
 * A slug is only ever changed when the administrator actually submits a
 * different one — it is never silently regenerated from a changed Title (see
 * App\Service\PageService), so a published URL stays put unless someone
 * deliberately edits it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\Redirects\SlugChangeRedirects;
use App\Service\Media\MediaService;
use App\Repository\PageRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

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
    exit('Invalid page id.');
}

$repository = new PageRepository();
$page = $repository->findById($id);

if ($page === null) {
    http_response_code(404);
    exit('Page not found.');
}

$isProtected = PageContent::isProtected($page);
$hasFixedUrl = PageContent::isRouteBound($page);

$title = trim((string) ($_POST['title'] ?? ''));
$slugInput = trim((string) ($_POST['slug'] ?? ''));
$statusInput = trim((string) ($_POST['status'] ?? ''));
$metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
$metaTitleEn = trim((string) ($_POST['meta_title_en'] ?? ''));
$metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
$metaDescriptionEn = trim((string) ($_POST['meta_description_en'] ?? ''));

// Indexability is a CLOSED two-value choice, never free text: it decides a
// <meta name="robots"> value, and nothing an administrator types may reach
// that tag. The form's hidden companion field is what makes an unticked
// checkbox arrive here at all — without it "leave it out of the index" could
// be switched on but never off.
$noindex = ($_POST['noindex'] ?? '0') === '1';

$errors = [];

/**
 * The page's own social image: a Media Library reference chosen with the
 * picker, or empty to fall back to the site-wide Standaard deel-afbeelding.
 * Nothing is uploaded here any more — that happens once, inside the picker.
 *
 * The submitted value is resolved against the library BEFORE anything is
 * written, so an id naming nothing can never be stored; and no file is ever
 * deleted here, because the same image may be in use on four other pages.
 */
$socialMedia = MediaService::find(
    isset($_POST['og_media_id']) && is_numeric($_POST['og_media_id']) ? (int) $_POST['og_media_id'] : null
);
$socialImageSubmitted = array_key_exists('og_media_id', $_POST);

if ($socialImageSubmitted && $socialMedia === null && trim((string) $_POST['og_media_id']) !== '') {
    $errors[] = AdminTranslator::trans('validation.gekozen_deel_afbeelding_bestaat_meer');
}

if ($title === '') {
    $errors[] = AdminTranslator::trans('validation.titel_verplicht');
} elseif (mb_strlen($title) > PageService::MAX_TITLE_LENGTH) {
    $errors[] = 'Titel mag maximaal ' . PageService::MAX_TITLE_LENGTH . ' tekens zijn.';
}

if ($isProtected) {
    // The site root and the storefront must stay published.
    $status = (string) $page['status'];
} else {
    $status = $statusInput;
    if (!PageContent::isValidStatus($status)) {
        $errors[] = AdminTranslator::trans('validation.ongeldige_status');
    }
}

if ($hasFixedUrl) {
    // The URL comes from the route this page is served at; the submitted
    // slug is discarded rather than stored as a value that does nothing.
    $slug = (string) $page['slug'];
} else {
    $slug = PageService::sanitizeSlug($slugInput);
    if ($slug === '') {
        $errors[] = AdminTranslator::trans('validation.slug_bevat_geldige_tekens');
    } else {
        $slugError = PageService::validateSlug($repository, $slug, $id);
        if ($slugError !== null) {
            $errors[] = $slugError;
        }
    }
}

foreach ([
    'SEO-titel (NL)' => [$metaTitle, PageService::MAX_META_TITLE_LENGTH],
    'SEO-titel (EN)' => [$metaTitleEn, PageService::MAX_META_TITLE_LENGTH],
    'Meta description (NL)' => [$metaDescription, PageService::MAX_META_DESCRIPTION_LENGTH],
    'Meta description (EN)' => [$metaDescriptionEn, PageService::MAX_META_DESCRIPTION_LENGTH],
] as $label => [$value, $max]) {
    if (mb_strlen($value) > $max) {
        $errors[] = $label . ' mag maximaal ' . $max . ' tekens zijn.';
    }
}

if ($errors !== []) {
    // Nothing to clean up: this endpoint stores no files of its own.
    $_SESSION['admin_page_errors'] = $errors;
    $_SESSION['admin_page_old'] = [
        'title' => $title,
        'slug' => $slugInput,
        'status' => $statusInput,
        'meta_title' => $metaTitle,
        'meta_title_en' => $metaTitleEn,
        'meta_description' => $metaDescription,
        'meta_description_en' => $metaDescriptionEn,
        'noindex' => $noindex,
    ];
    header('Location: /admin/page.php?id=' . $id);
    exit;
}

try {
    $repository->update($id, [
        'slug' => $slug,
        'title' => $title,
        'status' => $status,
        'meta_title' => $metaTitle === '' ? null : $metaTitle,
        'meta_title_en' => $metaTitleEn === '' ? null : $metaTitleEn,
        'meta_description' => $metaDescription === '' ? null : $metaDescription,
        'meta_description_en' => $metaDescriptionEn === '' ? null : $metaDescriptionEn,
        'noindex' => $noindex,
    ]);

    // Only when the field was on the submitted form at all, so a save from a
    // section of this screen that does not carry it leaves the choice alone.
    if ($socialImageSubmitted) {
        if ($socialMedia !== null) {
            $repository->updateOgImagePath($id, $socialMedia->path, $socialMedia->id);
        } else {
            // Back to NULL: the page falls back to the site-wide Standaard
            // deel-afbeelding again (App\Service\PageSeo). The FILE stays —
            // it belongs to the Media Library.
            $repository->updateOgImagePath($id, null, null);
        }
    }

    PageContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-page.php] ' . $e->getMessage());

    $_SESSION['admin_page_errors'] = ['Pagina kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/page.php?id=' . $id);
    exit;
}

/**
 * The page moved, so its old URL must keep working: a permanent (301)
 * redirect from the old path to the new one, visible and editable in
 * Beheer → Redirects like any other. See REDIRECTS.md.
 *
 * Four conditions, all of them about not inventing a redirect nobody needs:
 *
 *   - the slug really changed. An ordinary save — new title, new meta
 *     description, a section added — leaves the slug alone and writes nothing;
 *   - the page has no fixed URL. A route-bound page's slug is discarded above
 *     and its public URL never moves;
 *   - the page was PUBLISHED before this save. A draft's slug was never a
 *     working URL, so there is nothing to preserve, and this is also what
 *     keeps a brand-new page from generating one (a page is created as a
 *     draft, and create-page.php does not call this at all);
 *   - and it is still published after it. Renaming a page while taking it
 *     offline in the same save would otherwise point the old URL at a new one
 *     that 404s — two dead URLs where there was one;
 *
 * and it runs AFTER the save succeeded, so a redirect can never point at a
 * slug the page did not actually get.
 *
 * Deliberately not here: deleting or unpublishing a page writes no redirect.
 * Guessing that everything which used to be at a deleted URL now belongs on
 * the homepage is how a clean 404 becomes a soft 404; an editor who wants a
 * destination can add one by hand.
 */
$oldSlug = (string) ($page['slug'] ?? '');

if (
    !$hasFixedUrl
    && $oldSlug !== ''
    && $oldSlug !== $slug
    && PageContent::isPublished($page)
    && $status === PageContent::STATUS_PUBLISHED
) {
    (new SlugChangeRedirects())->record($oldSlug, $slug);
}

header('Location: /admin/page.php?id=' . $id . '&updated=1');
exit;
