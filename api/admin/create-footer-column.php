<?php

/**
 * POST /api/admin/create-footer-column.php
 *
 * The "Kolom toevoegen" form on admin/footer.php: a new, visible column at
 * the end, with a title in the site's own language at least
 * (MULTILINGUAL.md). Links are added to it afterwards.
 *
 * Back to the Footer screen at the new column, with saved=1. On a refused
 * title the screen says why in its own alert.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FooterRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LocalizedValue;

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

$titleNl = trim((string) ($_POST['title_nl'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));

if (LocalizedValue::ofDutchEnglish($titleNl, $titleEn)->primaryValue() === ''
    || mb_strlen($titleNl) > 100
    || mb_strlen($titleEn) > 100
) {
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.titel_verplicht_max_100_tekens');
    header('Location: /admin/footer.php#footer-columns');
    exit;
}

try {
    $id = (new FooterRepository())->createColumn(['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => true]);
} catch (\Throwable $e) {
    error_log('[api/admin/create-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.kolom_kon_aangemaakt');
    header('Location: /admin/footer.php#footer-columns');
    exit;
}

header('Location: /admin/footer.php?saved=1#footer-column-' . $id);
exit;
