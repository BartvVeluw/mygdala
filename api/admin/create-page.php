<?php

/**
 * POST /api/admin/create-page.php
 *
 * Creates a new CMS content page from admin/page-new.php, then redirects
 * into admin/page.php so the owner can immediately compose it with the page
 * builder. Same guard order and PRG/session-flash pattern as every other
 * admin endpoint: session login, POST-only, CSRF, then server-side
 * validation.
 *
 * Text: the title, SEO title and meta description are the page's text in the
 * website's DEFAULT language, where every page starts (Multilingual 2.0 phase
 * 2); translating it happens afterwards, on admin/page.php. They are written
 * through App\Service\PageLocalization inside the installer's transaction.
 *
 * Slug: an explicitly typed slug is sanitized and then validated as-is
 * (unique + not a reserved application route); an empty one — or one the
 * form was still filling in from the title by itself (`slug_auto`, see
 * admin/assets/admin.js) — is generated from the title and made
 * unique/non-reserved automatically. See App\Service\PageService. `is_system` and `route_path` are never accepted
 * from the request: a page created here is always an ordinary content page
 * (PageRepository::create() hardcodes that), so no admin input can mint a
 * protected page or claim an application route.
 *
 * Template: the chosen starting point decides which content blocks the new
 * page begins with, and nothing else — it is applied once, here, and never
 * recorded on the page (PAGE-TEMPLATES.md). An absent, stale or forged key
 * falls back to "Lege pagina" (App\Service\PageTemplates\PageTemplates::resolve()),
 * so a request without the field still creates a perfectly good page: its
 * heading, and nothing the editor did not choose. The
 * page and its blocks are written in ONE transaction by
 * App\Service\PageTemplates\PageTemplateInstaller: a template that fails
 * halfway leaves no page behind to clean up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PageTemplates\PageTemplateInstaller;
use App\Service\PageTemplates\PageTemplates;
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

$repository = new PageRepository();

$title = trim((string) ($_POST['title'] ?? ''));
$slugInput = trim((string) ($_POST['slug'] ?? ''));
// "1" while admin/page-new.php was still filling the address in from the
// title. Such an address was never typed by the editor, so it is made unique
// from the title exactly like an empty field rather than refused because
// another page already has it. The flag only chooses between those two
// server-side paths; it cannot let through an address validation refuses.
$slugIsAutomatic = ($_POST['slug_auto'] ?? '') === '1';
$status = trim((string) ($_POST['status'] ?? PageContent::STATUS_DRAFT));
$metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
$metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
$template = PageTemplates::resolve(isset($_POST['template']) ? trim((string) $_POST['template']) : null);

/**
 * WHERE THE NEW PAGE SITS (docs/pages/NESTING.md): under the page chosen in
 * "Bovenliggende pagina", or at the top when none is. A new page under
 * another follows that tree's admin group; a new root page takes the group
 * chosen here, and the website group when none is.
 */
$parentInput = filter_var($_POST['parent_id'] ?? '', FILTER_VALIDATE_INT);
$parentId = ($parentInput === false || $parentInput < 1) ? null : $parentInput;
$adminGroupInput = is_string($_POST['admin_group'] ?? null) ? trim($_POST['admin_group']) : '';

$errors = [];

$parentError = PageService::validateParent(null, $parentId);
if ($parentError !== null) {
    $errors[] = $parentError;
}

$adminGroup = PageService::resolveAdminGroup($parentId, $adminGroupInput, \App\Service\PageAdminGroup::WEBSITE);

if ($title === '') {
    $errors[] = AdminTranslator::trans('validation.titel_verplicht');
} elseif (mb_strlen($title) > PageService::MAX_TITLE_LENGTH) {
    $errors[] = 'Titel mag maximaal ' . PageService::MAX_TITLE_LENGTH . ' tekens zijn.';
}

/**
 * A NEW PAGE IS CREATED IN THE DEFAULT LANGUAGE. This screen has no language
 * switch of its own — a page has to exist before it can be translated — so
 * the title, the SEO fields and the address all belong to that language
 * (docs/multilingual/ROUTING.md).
 */
$pageLanguage = PageLocalization::defaultLanguage();

if (!PageContent::isValidStatus($status)) {
    $errors[] = AdminTranslator::trans('validation.ongeldige_status');
}

$slug = '';
if ($slugInput === '' || $slugIsAutomatic) {
    $slug = $title !== '' ? PageService::generateSlug($repository, $title, $pageLanguage) : '';
} else {
    $slug = PageService::sanitizeSlug($slugInput);
    if ($slug === '') {
        $errors[] = AdminTranslator::trans('validation.slug_bevat_geldige_tekens');
    } else {
        $slugError = PageService::validateSlug($repository, $slug, null, $pageLanguage);
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

$old = [
    'title' => $title,
    'slug' => $slugInput,
    'status' => $status,
    'meta_title' => $metaTitle,
    'meta_description' => $metaDescription,
    'template' => $template->key(),
    'slug_auto' => $slugIsAutomatic ? '1' : '0',
    'parent_id' => (string) ($parentId ?? 0),
    'admin_group' => $adminGroup,
];

if ($errors !== []) {
    $_SESSION['admin_page_errors'] = $errors;
    $_SESSION['admin_page_old'] = $old;
    header('Location: /admin/page-new.php');
    exit;
}

try {
    $id = PageTemplateInstaller::install($template, [
        'content_key' => PageService::generateContentKey($repository, $slug),
        'slug' => $slug,
        'status' => $status,
        'parent_id' => $parentId,
        'admin_group' => $adminGroup,
    ], [
        $pageLanguage => [
            PageTranslation::TITLE => $title,
            PageTranslation::META_TITLE => $metaTitle,
            PageTranslation::META_DESCRIPTION => $metaDescription,
        ],
    ], [
        // A new page is created in the default language, so that is the
        // language whose address it gets. Every other language stays without
        // one until an editor writes it, and therefore has no public URL —
        // see docs/multilingual/ROUTING.md.
        $pageLanguage => $slug,
    ]);
} catch (\Throwable $e) {
    error_log('[api/admin/create-page.php] ' . $e->getMessage());
    $_SESSION['admin_page_errors'] = ['Pagina kon niet worden aangemaakt. Probeer het opnieuw.'];
    $_SESSION['admin_page_old'] = $old;
    header('Location: /admin/page-new.php');
    exit;
}

header('Location: /admin/page.php?id=' . $id . '&created=1');
exit;
