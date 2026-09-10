<?php

/**
 * POST /api/admin/update-personalization-font.php
 *
 * Renames a library font and/or switches it on or off.
 *
 * `font_key` is deliberately not updatable: it is what a placed order
 * records, and rewriting it would change what a historical order says it was
 * engraved in. The file behind an uploaded font is not replaceable either —
 * a different file is a different font, and uploading it as a new row keeps
 * every already-placed order pointing at the shapes it was actually ordered
 * in.
 *
 * DEACTIVATING is the normal way to retire a font: it disappears from every
 * product in the shop immediately, while historical orders keep rendering
 * from their own snapshot.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PersonalizationFontRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationFonts;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('personalization.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid font id.');
}

$repository = new PersonalizationFontRepository();

if ($repository->findById($id) === null) {
    http_response_code(404);
    exit('Font not found.');
}

$label = trim((string) ($_POST['label'] ?? ''));
$isActive = ($_POST['is_active'] ?? null) === '1';

if ($label === '' || mb_strlen($label) > 100) {
    $_SESSION['admin_font_errors'] = ['Geef het lettertype een naam van maximaal 100 tekens.'];
    header('Location: /admin/personalization-fonts.php');
    exit;
}

try {
    $repository->update($id, $label, $isActive);
    PersonalizationFonts::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-personalization-font.php] ' . $e->getMessage());
    $_SESSION['admin_font_errors'] = ['Het lettertype kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/personalization-fonts.php');
    exit;
}

header('Location: /admin/personalization-fonts.php?updated=1');
exit;
