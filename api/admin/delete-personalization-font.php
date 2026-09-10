<?php

/**
 * POST /api/admin/delete-personalization-font.php
 *
 * Permanently removes a font from the library, file and all.
 *
 * It REFUSES when a placed order was actually engraved in that font. Not
 * because the order screen would break — every order keeps its own copy of
 * the label, the CSS stack and the file path, so it stays readable — but
 * because the file itself is still needed to PRODUCE that order, and an owner
 * clearing out the library almost never means "and lose the outlines behind
 * an engraving I still have to cut". Deactivating is offered instead, and
 * does the whole job: the font disappears from every product in the shop
 * immediately.
 *
 * POST-only and CSRF-protected: a destructive action must never be reachable
 * as a link a crawler, a prefetch or a pasted URL could trigger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PersonalizationFontRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationFontUploader;

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
$font = $repository->findById($id);

if ($font === null) {
    http_response_code(404);
    exit('Font not found.');
}

try {
    if ($repository->isUsedByOrder((string) $font['font_key'])) {
        $_SESSION['admin_font_errors'] = [
            'Dit lettertype is gebruikt in een bestelling en kan daarom niet verwijderd worden — het bestand is nog nodig om die gravure te maken. Zet het op "niet actief" om het uit de shop te halen.',
        ];
        header('Location: /admin/personalization-fonts.php');
        exit;
    }

    $deleted = $repository->delete($id);
    PersonalizationFonts::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-personalization-font.php] ' . $e->getMessage());
    $_SESSION['admin_font_errors'] = ['Het lettertype kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/personalization-fonts.php');
    exit;
}

// Only after the database says the row is gone: an unlink cannot be rolled
// back. delete() is a no-op outside assets/fonts/personalization/, so a
// built-in row (which has no file at all) removes nothing.
if ($deleted && $font['file_path'] !== null) {
    (new PersonalizationFontUploader())->delete((string) $font['file_path']);
}

header('Location: /admin/personalization-fonts.php?deleted=1');
exit;
