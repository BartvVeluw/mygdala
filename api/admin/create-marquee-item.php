<?php

/**
 * POST /api/admin/create-marquee-item.php
 *
 * Adds a new item to a Marquee section. Same guard order / PRG pattern as
 * api/admin/create-stat-strip-item.php.
 *
 * A NEW ITEM IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like a
 * new page: the label the form sends is stored as the website's default
 * language, where it is required, and every other language is added
 * afterwards on the item's own card (update-marquee-item.php). The row and
 * its words are one transaction, so an item never exists without its words
 * or the other way round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
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

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('marquee_items')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('marquee_items', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_marquee_item_errors'] = $errors;
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $itemId = $repository->createItem($sectionId);
    BlockLocalization::save('marquee_items', $itemId, $defaultLanguage, $words);

    $db->commit();
    MarqueeContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-marquee-item.php] ' . $e->getMessage());
    $_SESSION['admin_marquee_item_errors'] = ['Item kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/marquee.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
