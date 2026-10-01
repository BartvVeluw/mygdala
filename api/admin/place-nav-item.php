<?php

/**
 * POST /api/admin/place-nav-item.php
 *
 * One drag on Header & navigatie (admin/navigation.php, admin/assets/admin.js)
 * as one change: a menu item goes under another parent, back to the top
 * level, or to another place in its own list — NavigationRepository::place(),
 * which validates the whole move against the stored tree and writes it in
 * one transaction (HEADER-FOOTER.md, "Verplaatsen"). Never one request per
 * sibling.
 *
 * Body: id, parent_id (empty = the top level), position (0-based place in the
 * new list, counted without the item itself; empty = last).
 *
 * JSON, same convention as reorder-nav-items.php: {ok: true}, or {ok: false,
 * error} with the reason in words, which the screen shows before it reloads.
 * Nothing in the request is trusted beyond the three numbers: whether the
 * parent exists, is a menu link of this navigation, is not the item or below
 * it, and leaves room for the item's own submenu is the repository's to say.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\NavigationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

$refuse = static function (string $key, int $status = 422): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => AdminTranslator::trans($key)]);
    exit;
};

$number = static fn (string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';

$id = $number('id');
if (!ctype_digit($id) || (int) $id < 1) {
    $refuse('validation.nav_place_not_found', 404);
}

$parentRaw = $number('parent_id');
if ($parentRaw !== '' && (!ctype_digit($parentRaw) || (int) $parentRaw < 1)) {
    $refuse('validation.nav_place_parent_unknown');
}

$positionRaw = $number('position');
if ($positionRaw !== '' && !ctype_digit($positionRaw)) {
    $refuse('validation.volgorde_kon_opgeslagen');
}

try {
    $error = (new NavigationRepository())->place(
        (int) $id,
        $parentRaw === '' ? null : (int) $parentRaw,
        $positionRaw === '' ? null : (int) $positionRaw
    );
} catch (\Throwable $e) {
    error_log('[api/admin/place-nav-item.php] ' . $e->getMessage());
    $refuse('validation.volgorde_kon_opgeslagen', 500);
}

if ($error !== null) {
    $refuse($error, $error === 'validation.nav_place_not_found' ? 404 : 422);
}

echo json_encode(['ok' => true]);
