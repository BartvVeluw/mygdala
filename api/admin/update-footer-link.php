<?php

/**
 * POST /api/admin/update-footer-link.php — admin/footer-link.php's edit form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\LinkResolver;
use App\Repository\FooterRepository;
use App\Repository\PageRepository;

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
    exit('Footer-link niet gevonden.');
}

$repository = new FooterRepository();
$pageRepository = new PageRepository();

if ($repository->findLinkById($idParam) === null) {
    http_response_code(404);
    exit('Footer-link niet gevonden.');
}

$labelNl = trim((string) ($_POST['label_nl'] ?? ''));
$labelEn = trim((string) ($_POST['label_en'] ?? ''));
$linkType = (string) ($_POST['link_type'] ?? '');
$targetPageIdRaw = trim((string) ($_POST['target_page_id'] ?? ''));
$targetPageId = $targetPageIdRaw === '' ? null : (int) $targetPageIdRaw;
$targetRoute = trim((string) ($_POST['target_route'] ?? '')) ?: null;
$externalUrl = trim((string) ($_POST['external_url'] ?? '')) ?: null;
$actionKey = trim((string) ($_POST['action_key'] ?? '')) ?: null;
$openInNewTab = isset($_POST['open_in_new_tab']);
$isVisible = isset($_POST['is_visible']);

$errors = [];
if ($labelNl === '' || mb_strlen($labelNl) > 100) {
    $errors[] = 'Label (NL) is verplicht (max. 100 tekens).';
}
if ($labelEn === '' || mb_strlen($labelEn) > 100) {
    $errors[] = 'Label (EN) is verplicht (max. 100 tekens).';
}

$linkError = LinkResolver::validate($linkType, $targetPageId, $targetRoute, $externalUrl, $actionKey, LinkResolver::LINK_TYPES_FOOTER, $pageRepository);
if ($linkError !== null) {
    $errors[] = $linkError;
}

$old = [
    'label_nl' => $labelNl, 'label_en' => $labelEn, 'link_type' => $linkType,
    'target_page_id' => $targetPageIdRaw, 'target_route' => $targetRoute,
    'external_url' => $externalUrl, 'action_key' => $actionKey,
    'open_in_new_tab' => $openInNewTab, 'is_visible' => $isVisible,
];

if ($errors !== []) {
    $_SESSION['admin_footer_link_errors'] = $errors;
    $_SESSION['admin_footer_link_old'] = $old;
    header('Location: /admin/footer-link.php?id=' . $idParam);
    exit;
}

try {
    $repository->updateLink($idParam, [
        'label_nl' => $labelNl,
        'label_en' => $labelEn,
        'link_type' => $linkType,
        'target_page_id' => $linkType === 'page' ? $targetPageId : null,
        'target_route' => $linkType === 'route' ? $targetRoute : null,
        'external_url' => $linkType === 'external' ? $externalUrl : null,
        'action_key' => $linkType === 'action' ? $actionKey : null,
        'open_in_new_tab' => $openInNewTab,
        'is_visible' => $isVisible,
    ]);
} catch (\Throwable $e) {
    error_log('[api/admin/update-footer-link.php] ' . $e->getMessage());
    $_SESSION['admin_footer_link_errors'] = ['Link kon niet worden opgeslagen.'];
    $_SESSION['admin_footer_link_old'] = $old;
    header('Location: /admin/footer-link.php?id=' . $idParam);
    exit;
}

header('Location: /admin/footer.php?saved=1');
exit;
