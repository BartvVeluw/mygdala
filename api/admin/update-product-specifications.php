<?php

/**
 * POST /api/admin/update-product-specifications.php
 *
 * THE ONE SAVE of Shop → Specificaties (admin/product-specifications.php,
 * Shop Product & Ordering 2.0): the library of reusable product properties,
 * as it is on screen — names in the one language being edited, units, the
 * order, and the properties removed (which takes their value off every
 * product that had one). App\Service\SpecificationLibraryEditor checks
 * everything, then writes everything in one transaction.
 *
 * Two answers, one set of rules, like the product editor: the editor script
 * asks for JSON (App\Service\AdminEditorResponse: 200, 422 with messages by
 * field, 500); a form posted without it gets the PRG redirect.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\AdminEditorResponse;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\ShopLocalization;
use App\Service\SpecificationLibraryEditor;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$json = AdminEditorResponse::wantsJson();
$back = '/admin/product-specifications.php';
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

$db = Database::connection();
$editor = SpecificationLibraryEditor::fromRequest($_POST, $language !== '' ? $language : ShopLocalization::defaultLanguage(), $db);
$errors = $editor->validate();
if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors['language_code'] = AdminTranslator::trans('validation.language_unknown');
}

if ($errors !== []) {
    if ($json) {
        AdminEditorResponse::invalid($errors, AdminTranslator::trans('editor.invalid'));
    }

    $_SESSION['admin_specifications_errors'] = AdminEditorResponse::messages($errors);
    header('Location: ' . $back);
    exit;
}

try {
    $db->beginTransaction();
    $editor->save();
    $db->commit();
    ShopLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-product-specifications.php] ' . $e->getMessage());

    if ($json) {
        AdminEditorResponse::failed(AdminTranslator::trans('shop.specifications.save_failed'));
    }

    $_SESSION['admin_specifications_errors'] = [AdminTranslator::trans('shop.specifications.save_failed')];
    header('Location: ' . $back);
    exit;
}

if ($json) {
    AdminEditorResponse::saved(AdminTranslator::trans('common.saved'));
}

header('Location: ' . $back . '?saved=1');
exit;
