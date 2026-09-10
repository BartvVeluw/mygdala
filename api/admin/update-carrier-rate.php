<?php

/**
 * POST /api/admin/update-carrier-rate.php
 *
 * Admin edit of one carrier_rates row from /admin/carrier-rates.php: mode
 * (automatic/manual — see CreateCarrierRatesTable's docblock for what this
 * controls), price, and active state. Never touches the sync-only columns
 * (pending_price/last_checked_at/needs_review) — those are exclusively
 * written by App\Service\Shipping\PostNl\PostNlRateSyncService or the
 * "apply pending price" action (api/admin/apply-carrier-rate-pending.php).
 *
 * Setting mode to "manual" here IS the manual-override mechanism required by
 * MAIN.MD: the next sync will record what it sees in pending_price without
 * ever touching this price again, until an admin switches it back to
 * "automatic" or explicitly applies a pending price.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\CarrierRateRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('shipping.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$id = filter_input(INPUT_POST, 'carrier_rate_id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid carrier rate id.');
}

$repository = new CarrierRateRepository();
if ($repository->findById($id) === null) {
    http_response_code(404);
    exit('Carrier rate not found.');
}

$errors = [];

$mode = is_string($_POST['mode'] ?? null) ? trim($_POST['mode']) : '';
if (!in_array($mode, ['automatic', 'manual'], true)) {
    $errors[] = 'Kies een geldige modus (automatisch of handmatig).';
}

$priceRaw = is_string($_POST['price'] ?? null) ? trim(str_replace(',', '.', $_POST['price'])) : '';
$price = 0.0;
if ($priceRaw === '' || !is_numeric($priceRaw)) {
    $errors[] = 'Prijs is verplicht en moet een geldig bedrag zijn.';
} else {
    $price = (float) $priceRaw;
    if ($price <= 0 || $price > 9999.99) {
        $errors[] = 'Prijs moet groter dan 0 en maximaal € 9.999,99 zijn.';
    }
}

$isActive = ($_POST['is_active'] ?? null) === '1';

if ($errors !== []) {
    $_SESSION['admin_carrier_rates_errors'] = $errors;
    header('Location: /admin/carrier-rates.php');
    exit;
}

try {
    $repository->updateManual($id, ['mode' => $mode, 'price' => $price, 'is_active' => $isActive]);
} catch (\Throwable $e) {
    error_log('[api/admin/update-carrier-rate.php] ' . $e->getMessage());
    $_SESSION['admin_carrier_rates_errors'] = ['Tarief kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/carrier-rates.php');
    exit;
}

header('Location: /admin/carrier-rates.php?updated=1');
exit;
