<?php

/**
 * POST /api/admin/create-stat-strip-item.php
 *
 * Adds a new stat to a Stat strip. Same guard order / PRG pattern as
 * api/admin/create-feature-grid-item.php.
 *
 * A NEW STAT IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like a
 * new page: the words the form sends are stored as the website's default
 * language, where both fields are required, and every other language is
 * added afterwards on the stat's own card (update-stat-strip-item.php). The
 * row and its words are one transaction, so a stat never exists without its
 * words or the other way round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
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

$stripId = filter_input(INPUT_POST, 'strip_id', FILTER_VALIDATE_INT);
if ($stripId === false || $stripId === null || $stripId < 1) {
    http_response_code(400);
    exit('Invalid strip id.');
}

$repository = new StatStripRepository();
$strip = $repository->findById($stripId);

if ($strip === null) {
    http_response_code(404);
    exit('Strip not found.');
}

$sectionKey = $strip['page_slug'] . ':' . $strip['section_key'];

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('stat_strip_items')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('stat_strip_items', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_stat_strip_item_errors'] = $errors;
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $itemId = $repository->createItem($stripId);
    BlockLocalization::save('stat_strip_items', $itemId, $defaultLanguage, $words);

    $db->commit();
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-stat-strip-item.php] ' . $e->getMessage());
    $_SESSION['admin_stat_strip_item_errors'] = ['Stat kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
