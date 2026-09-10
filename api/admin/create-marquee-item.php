<?php

/**
 * POST /api/admin/create-marquee-item.php
 *
 * Adds a new item to a Marquee section. Same guard order / PRG pattern as
 * api/admin/create-stat-strip-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

$sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
if ($sectionId === false || $sectionId === null || $sectionId < 1) {
    http_response_code(400);
    exit('Invalid section id.');
}

$repository = new MarqueeRepository();
$section = $repository->findById($sectionId);

if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$fields = [
    'label_nl' => trim((string) ($_POST['label_nl'] ?? '')),
    'label_en' => trim((string) ($_POST['label_en'] ?? '')),
];

$errors = [];
if ($fields['label_nl'] === '') {
    $errors[] = 'Tekst (NL) is verplicht.';
}

if ($errors !== []) {
    $_SESSION['admin_marquee_item_errors'] = $errors;
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->createItem($sectionId, $fields);
    MarqueeContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-marquee-item.php] ' . $e->getMessage());
    $_SESSION['admin_marquee_item_errors'] = ['Item kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/marquee.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
