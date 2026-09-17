<?php

/**
 * POST /api/admin/create-footer-column.php
 *
 * The "Kolom toevoegen" form on admin/footer.php: a new, visible column at
 * the end, with its title in the website's DEFAULT language
 * (App\Service\FooterLocalization), in the same transaction as the row.
 * Translations are added on the column's own editor, links afterwards.
 *
 * Back to the Footer screen at the new column, with saved=1. On a refused
 * title the screen says why in its own alert.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\FooterRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FooterLocalization;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageFallback;

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

$title = trim((string) ($_POST['title'] ?? ''));
$language = LanguageFallback::defaultLanguage();

if (FooterLocalization::columns()->problems($language, [FooterLocalization::TITLE => $title], [FooterLocalization::TITLE]) !== []) {
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.titel_verplicht_max_100_tekens');
    header('Location: /admin/footer.php#footer-columns');
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();
    $id = (new FooterRepository($db))->createColumn(['is_visible' => true]);
    FooterLocalization::saveColumnTitle($id, $language, $title);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.kolom_kon_aangemaakt');
    header('Location: /admin/footer.php#footer-columns');
    exit;
}

header('Location: /admin/footer.php?saved=1#footer-column-' . $id);
exit;
