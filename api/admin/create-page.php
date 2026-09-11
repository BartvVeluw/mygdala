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
 * Slug: an explicitly typed slug is sanitized and then validated as-is
 * (unique + not a reserved application route); an empty one is generated
 * from the title and made unique/non-reserved automatically — see
 * App\Service\PageService. `is_system` and `route_path` are never accepted
 * from the request: a page created here is always an ordinary content page
 * (PageRepository::create() hardcodes that), so no admin input can mint a
 * protected page or claim an application route.
 *
 * Template: the chosen starting point decides which content blocks the new
 * page begins with, and nothing else — it is applied once, here, and never
 * recorded on the page (PAGE-TEMPLATES.md). An absent, stale or forged key
 * falls back to "Lege pagina" (App\Service\PageTemplates\PageTemplates::resolve()),
 * which is exactly what this endpoint did before templates existed, so a
 * request without the field still creates a perfectly good empty page. The
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
use App\Service\PageService;
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
$status = trim((string) ($_POST['status'] ?? PageContent::STATUS_DRAFT));
$metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
$metaTitleEn = trim((string) ($_POST['meta_title_en'] ?? ''));
$metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
$metaDescriptionEn = trim((string) ($_POST['meta_description_en'] ?? ''));
$template = PageTemplates::resolve(isset($_POST['template']) ? trim((string) $_POST['template']) : null);

$errors = [];

if ($title === '') {
    $errors[] = AdminTranslator::trans('validation.titel_verplicht');
} elseif (mb_strlen($title) > PageService::MAX_TITLE_LENGTH) {
    $errors[] = 'Titel mag maximaal ' . PageService::MAX_TITLE_LENGTH . ' tekens zijn.';
}

if (!PageContent::isValidStatus($status)) {
    $errors[] = AdminTranslator::trans('validation.ongeldige_status');
}

$slug = '';
if ($slugInput === '') {
    $slug = $title !== '' ? PageService::generateSlug($repository, $title) : '';
} else {
    $slug = PageService::sanitizeSlug($slugInput);
    if ($slug === '') {
        $errors[] = AdminTranslator::trans('validation.slug_bevat_geldige_tekens');
    } else {
        $slugError = PageService::validateSlug($repository, $slug, null);
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

$old = [
    'title' => $title,
    'slug' => $slugInput,
    'status' => $status,
    'meta_title' => $metaTitle,
    'meta_title_en' => $metaTitleEn,
    'meta_description' => $metaDescription,
    'meta_description_en' => $metaDescriptionEn,
    'template' => $template->key(),
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
        'title' => $title,
        'status' => $status,
        'meta_title' => $metaTitle === '' ? null : $metaTitle,
        'meta_title_en' => $metaTitleEn === '' ? null : $metaTitleEn,
        'meta_description' => $metaDescription === '' ? null : $metaDescription,
        'meta_description_en' => $metaDescriptionEn === '' ? null : $metaDescriptionEn,
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
