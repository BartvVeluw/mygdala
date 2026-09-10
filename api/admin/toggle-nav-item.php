<?php

/**
 * POST /api/admin/toggle-nav-item.php — same convention as
 * toggle-page-section.php: hide/show without deleting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\NavigationRepository;

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

$isVisible = ($_POST['is_visible'] ?? '0') === '1';

try {
    (new NavigationRepository())->setVisible($idParam, $isVisible);
} catch (\Throwable $e) {
    error_log('[api/admin/toggle-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_error'] = 'Zichtbaarheid kon niet worden opgeslagen.';
}

header('Location: /admin/navigation.php');
exit;
