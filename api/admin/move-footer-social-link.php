<?php

/**
 * POST /api/admin/move-footer-social-link.php
 *
 * Moves a social profile one place up or down in the list
 * (FooterSocialLinkRepository::move()); the footer shows them in that order.
 * The same one-step-at-a-time pattern as api/admin/move-nav-item.php: two
 * buttons, no JavaScript, and it works with a keyboard and on a phone. There
 * is no drag-and-drop for this list: a handful of rows, and ↑/↓ are the one
 * way that works for everybody.
 *
 * Only the id and the direction come from the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FooterSocialLinkRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\SocialProfiles;

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
    exit('Social media niet gevonden.');
}

$direction = (string) ($_POST['direction'] ?? '');
if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new FooterSocialLinkRepository();

if ($repository->findById($idParam) === null) {
    http_response_code(404);
    exit('Social media niet gevonden.');
}

try {
    $repository->move($idParam, $direction);
    SocialProfiles::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/move-footer-social-link.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.volgorde_kon_opgeslagen');
}

// Back to the row that moved, so the editor keeps their place.
header('Location: /admin/footer.php#footer-social-' . $idParam);
exit;
