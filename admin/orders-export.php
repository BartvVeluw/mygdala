<?php

/**
 * GET /admin/orders-export.php[?from=YYYY-MM-DD&to=YYYY-MM-DD]
 *
 * Downloads a sales-administration CSV of orders (optionally restricted to a
 * date range) — see App\Service\OrderCsvExport for the column layout and
 * MAIN.MD "Export for bookkeeping". Read-only, admin-authenticated GET; no
 * CSRF token needed (nothing is mutated), matching the rest of the admin
 * area's read-only pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\OrderRepository;
use App\Service\AdminAuth;
use App\Service\OrderCsvExport;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$from = (is_string($from) && preg_match($datePattern, $from)) ? $from : null;
$to = (is_string($to) && preg_match($datePattern, $to)) ? $to : null;

try {
    $orders = (new OrderRepository())->findForExport($from, $to);
} catch (\Throwable $e) {
    error_log('[admin/orders-export.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Export kon niet worden gegenereerd.');
}

$filename = 'bestellingen-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$stream = fopen('php://output', 'w');
OrderCsvExport::stream($stream, $orders);
fclose($stream);
