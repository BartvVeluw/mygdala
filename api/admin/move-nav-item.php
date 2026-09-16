<?php

/**
 * POST /api/admin/move-nav-item.php
 *
 * Moves one menu link, submenu item or header button a single place up or
 * down inside its own group (NavigationRepository::move()). The same
 * one-step-at-a-time pattern as api/admin/move-form-field.php: two buttons,
 * no JavaScript, and it works with a keyboard and on a phone. The
 * drag-and-drop list and api/admin/reorder-nav-items.php stay for a mouse.
 *
 * Only the id and the direction come from the request; which group the item
 * belongs to is read from its own row, so a crafted post can never move an
 * item into another list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\NavigationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;

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

$direction = (string) ($_POST['direction'] ?? '');
if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new NavigationRepository();

if ($repository->findById($idParam) === null) {
    http_response_code(404);
    exit('Menu-item niet gevonden.');
}

try {
    $repository->move($idParam, $direction);
} catch (\Throwable $e) {
    error_log('[api/admin/move-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_error'] = AdminTranslator::trans('validation.volgorde_kon_opgeslagen');
}

// Back to the row that moved, so the editor's place on a long list is kept.
header('Location: /admin/navigation.php#nav-item-' . $idParam);
exit;
