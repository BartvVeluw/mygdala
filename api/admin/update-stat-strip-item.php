<?php

/**
 * POST /api/admin/update-stat-strip-item.php
 *
 * Edits one Stat strip item's content and visibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\StatStripContent;
use App\Repository\StatStripRepository;

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

$repository = new StatStripRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$strip = $repository->findById((int) $item['stat_strip_id']);
if ($strip === null) {
    http_response_code(404);
    exit('Strip not found.');
}

$sectionKey = $strip['page_slug'] . ':' . $strip['section_key'];

$fields = [
    'primary_text_nl' => trim((string) ($_POST['primary_text_nl'] ?? '')),
    'primary_text_en' => trim((string) ($_POST['primary_text_en'] ?? '')),
    'secondary_text_nl' => trim((string) ($_POST['secondary_text_nl'] ?? '')),
    'secondary_text_en' => trim((string) ($_POST['secondary_text_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
if ($fields['primary_text_nl'] === '') {
    $errors[] = 'Primaire tekst (NL) is verplicht.';
}
if ($fields['secondary_text_nl'] === '') {
    $errors[] = 'Secundaire tekst (NL) is verplicht.';
}

if ($errors !== []) {
    $_SESSION['admin_stat_strip_item_errors'] = $errors;
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->updateItem($itemId, $fields);
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-stat-strip-item.php] ' . $e->getMessage());
    $_SESSION['admin_stat_strip_item_errors'] = ['Stat kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
