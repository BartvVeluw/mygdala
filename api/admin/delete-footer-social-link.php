<?php

/**
 * POST /api/admin/delete-footer-social-link.php
 *
 * Deletes one social profile. Asked first in the CMS's own dialog on
 * admin/footer.php (admin_confirm_attributes()), which also points at
 * hiding as the way to take a profile off the website without losing it;
 * that dialog is a courtesy, never the guard.
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

$repository = new FooterSocialLinkRepository();

if ($repository->findById($idParam) === null) {
    http_response_code(404);
    exit('Social media niet gevonden.');
}

try {
    $repository->delete($idParam);
    SocialProfiles::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-footer-social-link.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('footer.social_not_deleted');
    header('Location: /admin/footer.php#footer-social');
    exit;
}

header('Location: /admin/footer.php?deleted=social#footer-social');
exit;
