<?php

/**
 * POST /api/admin/move-personalization-font.php
 *
 * Swaps a font with its neighbour in the library — the same up/down model
 * every other ordered list in this CMS uses.
 *
 * Library order is not cosmetic: it is the order the customer sees in the
 * font selector, and the FIRST active font is the one a text zone starts on.
 * So this is how the owner chooses the default engraving font, without
 * configuring anything per product.
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
$direction = (string) ($_POST['direction'] ?? '');

if ($id === false || $id === null || $id < 1 || !in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

try {
    (new PersonalizationFontRepository())->move($id, $direction);
    PersonalizationFonts::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/move-personalization-font.php] ' . $e->getMessage());
    $_SESSION['admin_font_errors'] = ['De volgorde kon niet worden gewijzigd. Probeer het opnieuw.'];
}

header('Location: /admin/personalization-fonts.php');
exit;
