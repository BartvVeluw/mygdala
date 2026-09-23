<?php

/**
 * POST /api/admin/update-page.php
 *
 * Saves one page's settings (admin/page.php's "Pagina" and "SEO" tabs) — web
 * address (slug), Status, whether the page shows its breadcrumb, indexability
 * and the page's own social sharing image, plus its Title, SEO title and Meta
 * description in ONE website language — for every CMS page alike. Same guard
 * order and PRG/session-flash pattern as every other admin endpoint.
 *
 * ONE LANGUAGE PER SAVE (Multilingual 2.0 phase 2). The form carries the text
 * of the language on screen and says which one in `language_code`; that
 * language is checked against the website language registry and its text is
 * written through App\Service\PageLocalization, which is the only writer of
 * page_translations. Every other language's text is left exactly as stored.
 * The title is required in the default language only: a translation falls
 * back to it.
 *
 * Two INDEPENDENT locks are enforced HERE, not by the form, so a forged
 * request cannot get past either:
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
 * App\Service\PageService) — and only once they have confirmed the move: the
 * first save that would change it writes nothing and asks (see below). A
 * published URL therefore stays put unless someone deliberately changes it
 * and says so twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PageTranslation;
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

// The website language the text fields below are in. Only a language the
// registry has, and has switched on, can be written; anything else is refused
// before a word is stored, and it never becomes a column name.
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$isDefaultLanguage = $languageIsWritable && $languageCode === PageLocalization::defaultLanguage();

$title = trim((string) ($_POST['title'] ?? ''));
$slugInput = trim((string) ($_POST['slug'] ?? ''));
$statusInput = trim((string) ($_POST['status'] ?? ''));
$metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
$metaDescription = trim((string) ($_POST['meta_description'] ?? ''));

// The address the editor confirmed on admin/page.php, present only on the
// save that follows a confirmation card. See the confirmation step below.
$confirmedSlug = trim((string) ($_POST['confirmed_slug'] ?? ''));

// Indexability is a CLOSED two-value choice, never free text: it decides a
// <meta name="robots"> value, and nothing an administrator types may reach
// that tag. The form's hidden companion field is what makes an unticked
// checkbox arrive here at all — without it "leave it out of the index" could
// be switched on but never off.
$noindex = ($_POST['noindex'] ?? '0') === '1';

/**
 * Whether this page prints its breadcrumb. Same two-value shape and the same
 * hidden companion field as indexability above, and for the same reason: an
 * unticked checkbox sends nothing at all.
 *
 * The DEFAULT WHEN THE FIELD IS ABSENT is "on", which is what the column
 * defaults to and what every page did before this was a choice — so a request
 * that does not carry this form section cannot quietly take a breadcrumb away.
 * See App\Service\Breadcrumbs\PageBreadcrumb.
 */
$showBreadcrumb = ($_POST['show_breadcrumb'] ?? '1') === '1';

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
// A share image is a raster image (MediaType::SOCIAL_IMAGE); the one the page
// already has stays acceptable.
$socialMedia = MediaService::findSocialImage(
    isset($_POST['og_media_id']) && is_numeric($_POST['og_media_id']) ? (int) $_POST['og_media_id'] : null,
    (int) ($page['og_media_id'] ?? 0)
);
$socialImageSubmitted = array_key_exists('og_media_id', $_POST);

if ($socialImageSubmitted && $socialMedia === null && trim((string) $_POST['og_media_id']) !== '') {
    $errors[] = AdminTranslator::trans('validation.gekozen_deel_afbeelding_bestaat_meer');
}

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

// Required in the default language only. A translation left empty is not a
// missing title: visitors get the default language's (PageLocalization).
if ($title === '' && $isDefaultLanguage) {
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

/**
 * THE ADDRESS BELONGS TO THE LANGUAGE BEING EDITED (Multilingual 2.0 phase 6,
 * docs/multilingual/ROUTING.md). Saving the English version writes the English
 * address and leaves the Dutch one exactly where it is; the two are checked
 * for collisions inside their own language only, because /over-ons and
 * /en/over-ons are different URLs.
 *
 * `pages.slug` stays in step with the DEFAULT language's address. It is the
 * page's neutral key — what stored redirects were written against and what
 * `content_key` was derived from — so the default language writes both, and
 * every other language writes only its own row.
 */
if ($hasFixedUrl) {
    // The URL comes from the route this page is served at; the submitted
    // slug is discarded rather than stored as a value that does nothing.
    $slug = (string) $page['slug'];
} else {
    $slug = PageService::sanitizeSlug($slugInput);

    /**
     * AN ADDRESS IS REQUIRED IN THE DEFAULT LANGUAGE AND OPTIONAL IN EVERY
     * OTHER (docs/multilingual/ROUTING.md).
     *
     * The default language's address is the page's address: it is kept in
     * step with the neutral `pages.slug`, every existing link names it, and a
     * page without one would not be reachable at all.
     *
     * A translation without one is an ordinary, meaningful state: the page
     * simply has no public URL in that language, the language switch shows
     * that version as unavailable and no hreflang advertises it. Demanding a
     * slug here would be demanding that every translation be published the
     * moment a word of it is written.
     */
    /**
     * A TRANSLATION'S FIRST ADDRESS is made from its title when the editor
     * left the field blank — the convention api/admin/create-page.php has
     * always used, applied per language instead of once per page. Saving the
     * English version of a page therefore publishes an English URL without
     * anybody having to think about slugs, and an existing address is never
     * regenerated from a changed title.
     */
    if (
        $slug === ''
        && !$isDefaultLanguage
        && $languageIsWritable
        && $title !== ''
        && PageService::currentSlug($page, $languageCode) === null
    ) {
        $slug = PageService::generateSlug($repository, $title, $languageCode, $id);
    }

    if ($slug === '' && $isDefaultLanguage) {
        $errors[] = AdminTranslator::trans('validation.slug_bevat_geldige_tekens');
    } elseif ($slug !== '' && $languageIsWritable) {
        $slugError = PageService::validateSlug($repository, $slug, $id, $languageCode);
        if ($slugError !== null) {
            $errors[] = $slugError;
        }
    }
}

foreach ([
    'SEO-titel' => [$metaTitle, PageService::MAX_META_TITLE_LENGTH],
    'Meta description' => [$metaDescription, PageService::MAX_META_DESCRIPTION_LENGTH],
] as $label => [$value, $max]) {
    if (mb_strlen($value) > $max) {
        $errors[] = $label . ' mag maximaal ' . $max . ' tekens zijn.';
    }
}

/**
 * What the editor sent, handed back to admin/page.php whenever this save does
 * not go through — refused, or waiting for a confirmation — so the form shows
 * their input rather than the stored page. The social image choice travels
 * along when the form carried one; otherwise a confirmation would quietly put
 * the stored image back.
 */
$submitted = [
    // Which language the three text fields were typed in: admin/page.php only
    // hands them back to a form showing that same language.
    'language_code' => $languageCode,
    'title' => $title,
    'slug' => $slugInput,
    'status' => $statusInput,
    'meta_title' => $metaTitle,
    'meta_description' => $metaDescription,
    'noindex' => $noindex,
    'show_breadcrumb' => $showBreadcrumb,
];

if ($socialImageSubmitted) {
    $submitted['og_media_id'] = trim((string) $_POST['og_media_id']);
}

if ($errors !== []) {
    // Nothing to clean up: this endpoint stores no files of its own.
    $_SESSION['admin_page_errors'] = $errors;
    $_SESSION['admin_page_old'] = $submitted;
    header('Location: /admin/page.php?id=' . $id);
    exit;
}

// The address THIS LANGUAGE had before this save — what a rename has to keep
// working. For the default language that is the page's own slug; for any
// other it is that language's row, and null when it had no address at all
// (giving a language its first address moves nothing).
$oldSlug = (string) (PageService::currentSlug($page, $languageCode) ?? '');

/**
 * A new web address is confirmed before it is written.
 *
 * The first valid save that would move the page stores NOTHING. It hands the
 * editor's input back exactly like a refused save does, so no typed value is
 * lost, together with what the move is; admin/page.php then shows the current
 * and the new address, where the page is linked from and what happens to the
 * old address. Confirming submits the same form again, now carrying
 * `confirmed_slug`, and PageService::urlChangeNeedsConfirmation() lets exactly
 * that address through — an address changed again after confirming is asked
 * about again.
 *
 * Enforced here rather than by the disclosure the field sits behind, so a
 * forged or scripted request cannot move a page's address unasked either.
 * Nothing here searches the site for the old address or rewrites it:
 * App\Service\PageUsage explains why links by id need no rewriting and typed
 * links must not get one.
 */
if (PageService::urlChangeNeedsConfirmation($page, $slug, $confirmedSlug, $languageCode)) {
    $_SESSION['admin_page_old'] = ['slug' => $slug] + $submitted;
    $_SESSION['admin_page_url_change'] = [
        'page_id' => $id,
        'old_slug' => $oldSlug,
        'new_slug' => $slug,
        'status' => $status,
    ];
    header('Location: /admin/page.php?id=' . $id);
    exit;
}

$db = Database::connection();

try {
    // The page's own settings and its text in this language are one save:
    // both land, or neither does.
    $db->beginTransaction();

    $repository->update($id, [
        // Only the default language moves the neutral key; an English rename
        // must not silently rewrite the Dutch URL every redirect was written
        // against.
        'slug' => ($isDefaultLanguage && $slug !== '') ? $slug : (string) $page['slug'],
        'status' => $status,
        'noindex' => $noindex,
        'show_breadcrumb' => $showBreadcrumb,
    ]);

    PageLocalization::save(
        $id,
        $languageCode,
        [
            PageTranslation::TITLE => $title,
            PageTranslation::META_TITLE => $metaTitle,
            PageTranslation::META_DESCRIPTION => $metaDescription,
        ],
        // A route-bound page has no address of its own in any language, and
        // '' is "this language has no public route" rather than an address.
        ($hasFixedUrl || $slug === '') ? null : $slug
    );

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

    $db->commit();

    PageContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

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
 * Five conditions, all of them about not inventing a redirect nobody needs:
 *
 *   - the slug really changed. An ordinary save — new title, new meta
 *     description, a section added — leaves the slug alone and writes nothing;
 *   - it changed INTO an address. A translation whose slug was cleared has no
 *     public URL in that language any more (docs/multilingual/ROUTING.md);
 *     that is the language version going away, not moving, so its old URL
 *     404s like any other gone page's instead of being sent to /en;
 *   - and the three in PageService::oldAddressWillRedirect(): the page has no
 *     fixed URL, it was published before this save (a draft's slug was never
 *     a working URL, which is also what keeps a brand-new page from
 *     generating one — create-page.php does not call this at all), and it is
 *     still published after it (renaming while taking the page offline would
 *     point the old URL at a new one that 404s). admin/page.php asks the same
 *     method before it tells the editor what confirming will do;
 *
 * and it runs AFTER the save succeeded, so a redirect can never point at a
 * slug the page did not actually get.
 *
 * Deliberately not here: deleting or unpublishing a page writes no redirect.
 * Guessing that everything which used to be at a deleted URL now belongs on
 * the homepage is how a clean 404 becomes a soft 404; an editor who wants a
 * destination can add one by hand.
 */
if (
    $oldSlug !== ''
    && $oldSlug !== $slug
    && $slug !== ''
    && PageService::oldAddressWillRedirect($page, $status)
) {
    // In this language's URL space: renaming the English version records
    // /en/old -> /en/new, and leaves the Dutch addresses alone.
    (new SlugChangeRedirects())->record($oldSlug, $slug, $languageCode);
}

header('Location: /admin/page.php?id=' . $id . '&updated=1');
exit;
