<?php

/**
 * POST /api/admin/update-rich-text-section.php
 *
 * Saves one Rich text page-builder section (admin/rich-text.php?section=...).
 * Same guard order and PRG/session-flash pattern as
 * api/admin/update-faq-section.php, and the same "a page-builder-attached
 * section is valid only when the page AND its content row already exist"
 * gate — an arbitrary page_slug:section_key pair from the request is never
 * trusted beyond that.
 *
 * `content_html` is rich text: sanitized through RichTextSanitizer before it
 * ever reaches the database, exactly like every other rich-text field in
 * this project (the Quill toolbar is convenience, never the security
 * boundary).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\RichTextContent;
use App\Service\RichTextSanitizer;
use App\Repository\PageRepository;
use App\Repository\RichTextRepository;

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

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new RichTextRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$contentRaw = trim((string) ($_POST['content_html'] ?? ''));
$contentEnRaw = trim((string) ($_POST['content_html_en'] ?? ''));
$isActive = isset($_POST['is_active']);

$old = ['content_html' => $contentRaw, 'content_html_en' => $contentEnRaw, 'is_active' => $isActive];

$errors = [];
if (mb_strlen($contentRaw) > 50000 || mb_strlen($contentEnRaw) > 50000) {
    $errors[] = AdminTranslator::trans('validation.text_too_long');
}

if ($errors !== []) {
    $_SESSION['admin_rich_text_errors'] = $errors;
    $_SESSION['admin_rich_text_old'] = $old;
    header('Location: /admin/rich-text.php?section=' . urlencode($sectionParam));
    exit;
}

try {
    $repository->upsertSection($pageSlug, $sectionKey, [
        'content_html' => RichTextSanitizer::sanitize($contentRaw),
        'content_html_en' => RichTextSanitizer::sanitize($contentEnRaw),
        'is_active' => $isActive,
    ]);
    RichTextContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-rich-text-section.php] ' . $e->getMessage());

    $_SESSION['admin_rich_text_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_rich_text_old'] = $old;
    header('Location: /admin/rich-text.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/rich-text.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
