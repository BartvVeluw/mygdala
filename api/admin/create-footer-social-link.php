<?php

/**
 * POST /api/admin/create-footer-social-link.php
 *
 * The "Social media toevoegen" form on admin/footer.php: a network from the
 * closed registry and the address of the profile. The new row is visible and
 * goes to the end of the list (FooterSocialLinkRepository::create()). Two
 * profiles on the same network are allowed (HEADER-FOOTER.md).
 *
 * The rules are in api/admin/_footer_social_link_input.php, shared with
 * update-footer-social-link.php. A refused profile goes back to the add form
 * with what was typed and why (session flash `admin_footer_social_error`,
 * id 0 for the add form); a saved one back to its own row with saved=1.
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

[$errors, $data, $old] = validateFooterSocialLinkInput($_POST, true);

if ($errors === []) {
    try {
        $id = (new FooterSocialLinkRepository())->create($data);
        SocialProfiles::clearCache();

        header('Location: /admin/footer.php?saved=1#footer-social-' . $id);
        exit;
    } catch (\Throwable $e) {
        error_log('[api/admin/create-footer-social-link.php] ' . $e->getMessage());
        $errors = [AdminTranslator::trans('footer.social_not_saved')];
    }
}

$_SESSION['admin_footer_social_error'] = ['id' => 0, 'errors' => $errors, 'old' => $old];
header('Location: /admin/footer.php#footer-social-new');
exit;
