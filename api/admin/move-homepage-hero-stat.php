<?php

/**
 * POST /api/admin/move-homepage-hero-stat.php
 *
 * Simple stat ordering: swaps one stat with its previous/next neighbour in
 * display order (direction=up|down). Same approach as
 * api/admin/move-stat-strip-item.php.
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
$direction = $_POST['direction'] ?? '';

if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new HomepageHeroRepository();
$item = $repository->findStatById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$heroId = (int) $item['homepage_hero_id'];

$repository->moveStat($heroId, $itemId, $direction);
HomepageHeroContent::clearCache();

header('Location: /admin/homepage-hero.php?saved=1');
exit;
