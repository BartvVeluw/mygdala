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
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the body is the text of the
 * language named in `language_code`, which must be an active language of the
 * website registry; only that language is written, through
 * App\Service\Blocks\BlockLocalization, and every other language stays as it
 * is. The body is rich text, and BlockLocalization sanitizes it through
 * RichTextSanitizer before it reaches the database because the block declares
 * it rich (the Quill toolbar is convenience, never the security boundary).
 * Whether it is shown is the same in every language.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\RichTextContent;
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
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

$body = trim((string) ($_POST[RichTextContent::BODY] ?? ''));
$isActive = isset($_POST['is_active']);

$old = ['language_code' => $languageCode, RichTextContent::BODY => $body, 'is_active' => $isActive];

$errors = [];
if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('rich_text_sections', $languageCode, [RichTextContent::BODY => $body])) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$redirect = '/admin/rich-text.php?section=' . urlencode($sectionParam);

if ($errors !== []) {
    $_SESSION['admin_rich_text_errors'] = $errors;
    $_SESSION['admin_rich_text_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // Whether it is shown and its body in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, ['is_active' => $isActive]);
    BlockLocalization::save('rich_text_sections', (int) $section['id'], $languageCode, [RichTextContent::BODY => $body]);

    $db->commit();
    RichTextContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-rich-text-section.php] ' . $e->getMessage());

    $_SESSION['admin_rich_text_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_rich_text_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
