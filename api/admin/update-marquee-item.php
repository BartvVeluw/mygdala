<?php

/**
 * POST /api/admin/update-marquee-item.php
 *
 * Edits one Marquee item's content and visibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\MarqueeContent;
use App\Repository\MarqueeRepository;

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

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new MarqueeRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$section = $repository->findById((int) $item['marquee_section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$fields = [
    'label_nl' => trim((string) ($_POST['label_nl'] ?? '')),
    'label_en' => trim((string) ($_POST['label_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
if ($fields['label_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.tekst_nl_verplicht');
}

if ($errors !== []) {
    $_SESSION['admin_marquee_item_errors'] = $errors;
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->updateItem($itemId, $fields);
    MarqueeContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-marquee-item.php] ' . $e->getMessage());
    $_SESSION['admin_marquee_item_errors'] = ['Item kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/marquee.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
