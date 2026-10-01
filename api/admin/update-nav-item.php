<?php

/**
 * POST /api/admin/update-nav-item.php
 *
 * Updates an existing nav_items row: its label in ONE website language
 * (`language_code`, App\Service\NavigationLocalization; every other
 * language's label stays as it is), destination, visibility and
 * — for a top-level item — whether it is a menu link or a header button
 * (App\Service\NavigationPresentation). The shared rules are in
 * api/admin/_nav_item_input.php.
 *
 * And its place: "Bovenliggend item" may move a menu link to another parent
 * or to the top level. That is NavigationRepository::place(), the same move
 * a drag on the overview makes, inside this save's transaction; the item
 * goes LAST in its new list, and an unchanged parent keeps its position
 * (HEADER-FOOTER.md, "Verplaatsen"). A form without the field keeps the
 * stored parent. Changing the presentation is not such a move: the item
 * stays top-level and joins the end of the other group
 * (NavigationRepository::update()).
 *
 * Same guard order and PRG pattern as api/admin/update-form.php, including
 * the redirect back to the editor with saved=1 for the save bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_nav_item_input.php';

use App\Database;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\NavigationLocalization;

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

$idParam = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit('Menu-item niet gevonden.');
}

$db = Database::connection();
$repository = new NavigationRepository($db);
$existing = $repository->findById($idParam);
if ($existing === null) {
    http_response_code(404);
    exit('Menu-item niet gevonden.');
}

[$errors, $data, $old] = validateNavItemInput($_POST, $existing, $repository, new PageRepository());

$editorUrl = '/admin/navigation-item.php?id=' . $idParam;

if ($errors !== []) {
    $_SESSION['admin_nav_item_errors'] = $errors;
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

try {
    // The item's language-neutral settings and its label in this language
    // are one save.
    $db->beginTransaction();
    $repository->update($idParam, $data);
    // A new parent from the editor's list: last in its new list, the old
    // list closing its gap — the same move a drag makes, judged again
    // against the tree as it stands inside this transaction.
    $placeError = $data['parent_changed'] ? $repository->place($idParam, $data['parent_id']) : null;
    if ($placeError !== null) {
        $db->rollBack();
        $_SESSION['admin_nav_item_errors'] = [AdminTranslator::trans($placeError)];
        $_SESSION['admin_nav_item_old'] = $old;
        header('Location: ' . $editorUrl);
        exit;
    }
    // "Gebruik titel van bestemming": no words of its own, in any language —
    // the menu shows the page's title (NavigationLocalization::labelFor()).
    if ($data['label_follows']) {
        NavigationLocalization::clear($idParam);
    } else {
        NavigationLocalization::save($idParam, $data['language_code'], $data['label']);
    }
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_item_errors'] = [AdminTranslator::trans('validation.navigation_item_not_saved')];
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

header('Location: ' . $editorUrl . '&saved=1');
exit;
