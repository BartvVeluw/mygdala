<?php

/**
 * POST /api/admin/move-personalization-view.php
 *
 * Moves one personalization view up or down. The order decides which tab the
 * customer sees first on the product page, so it is real configuration rather
 * than decoration.
 *
 * Same up/down model as the product photo and variant editors: a POST with a
 * server-validated id and a direction from a two-value allow-list, never a
 * drag-and-drop payload of ids the endpoint would have to trust.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_personalization_validation.php';

use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\ProductPersonalizationContent;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('personalization.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$viewId = filter_input(INPUT_POST, 'view_id', FILTER_VALIDATE_INT);
$direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';

if ($viewId === false || $viewId === null || $viewId < 1) {
    http_response_code(400);
    exit('Invalid view id.');
}

$repository = new ProductPersonalizationRepository();
$view = $repository->findViewById($viewId);

if ($view === null) {
    http_response_code(404);
    exit('View not found.');
}

try {
    $repository->moveView($viewId, $direction);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/move-personalization-view.php] ' . $e->getMessage());
}

personalizationRedirect((int) $view['product_id']);
