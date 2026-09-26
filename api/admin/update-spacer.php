<?php

/**
 * POST /api/admin/update-spacer.php
 *
 * Saves the height of one Witruimte block (admin/spacer.php?section=...).
 * The same `<page>:<key>` gate as api/admin/update-cta-band.php: the page must
 * exist by its immutable content_key AND the block's row must already exist
 * (created by App\Service\SectionRegistry::create()), before anything is
 * read or written.
 *
 * The height is a word from the closed list App\Service\SpacerContent::SIZES;
 * anything else is refused, never stored and never turned into a class. A
 * spacer has no words and no other setting, so there is no language here and
 * no transaction: one column of one row.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PageRepository;
use App\Repository\SpacerRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\SpacerContent;

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

$repository = new SpacerRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/spacer.php?section=' . urlencode($sectionParam);
$size = (string) ($_POST['size'] ?? '');

if (!array_key_exists($size, SpacerContent::SIZES)) {
    $_SESSION['admin_spacer_errors'] = [AdminTranslator::trans('block_spacer.error_hoogte')];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->updateSize((int) $section['id'], $size);
    SpacerContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-spacer.php] ' . $e->getMessage());

    $_SESSION['admin_spacer_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
