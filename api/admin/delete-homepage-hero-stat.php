<?php

/**
 * POST /api/admin/delete-homepage-hero-stat.php
 *
 * Permanently deletes one Homepage Hero stat — distinct from hiding one via
 * its "Zichtbaar" checkbox (update-homepage-hero-stat.php). Also how a slot
 * is freed back up under the max-3 cap (see
 * HomepageHeroRepository::countStatsByHeroId()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Repository\HomepageHeroRepository;

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

$repository = new HomepageHeroRepository();
$item = $repository->findStatById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

try {
    $repository->deleteStat($itemId);
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-homepage-hero-stat.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_stat_errors'] = ['Statistiek kon niet worden verwijderd.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
