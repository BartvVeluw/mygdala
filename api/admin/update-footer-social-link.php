<?php

/**
 * POST /api/admin/update-footer-social-link.php
 *
 * Saves one social profile row of admin/footer.php: its network, its address
 * and whether it is shown. Hiding keeps the row and its address here; showing
 * it again brings back exactly the same icon. The order is not touched here
 * (move-footer-social-link.php).
 *
 * The rules are in api/admin/_footer_social_link_input.php, shared with
 * create-footer-social-link.php. A refused save goes back to the same row
 * with what was typed and why, WITHOUT saved=1, which is how the save bar
 * tells a refused save from a written one (admin/assets/save-bar.js).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_footer_social_link_input.php';

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

[$errors, $data, $old] = validateFooterSocialLinkInput($_POST, false);

if ($errors === []) {
    try {
        $repository->update($idParam, $data);
        SocialProfiles::clearCache();

        header('Location: /admin/footer.php?saved=1#footer-social-' . $idParam);
        exit;
    } catch (\Throwable $e) {
        error_log('[api/admin/update-footer-social-link.php] ' . $e->getMessage());
        $errors = [AdminTranslator::trans('footer.social_not_saved')];
    }
}

$_SESSION['admin_footer_social_error'] = ['id' => $idParam, 'errors' => $errors, 'old' => $old];
header('Location: /admin/footer.php#footer-social-' . $idParam);
exit;
