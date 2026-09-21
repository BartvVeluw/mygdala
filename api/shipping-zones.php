<?php

/**
 * GET /api/shipping-zones.php
 *
 * Lists every destination country that currently resolves to a shipping
 * zone (App\Repository\ShippingZoneRepository), so checkout.php can build
 * its "Land" dropdown from actual configuration instead of a hardcoded
 * NL/BE list — adding a zone/country later (a data change, see MAIN.MD
 * "Shipping zones") makes it show up here automatically, no frontend code
 * change needed.
 *
 * Display names for country codes are a small presentation-only lookup
 * table here (not stored in the database, which only needs the code) —
 * extend COUNTRY_LABELS when a new country is added; an unlisted code still
 * works, it just falls back to showing the raw code.
 *
 * Response (200): { "countries": [ { "code": "NL", "label": "Nederland", "label_en": "Netherlands" }, ... ] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Repository\ShippingZoneRepository;
use App\Service\Routing\ApiLanguage;
use App\Service\Shipping\ShippingCountries;

header('Content-Type: application/json; charset=utf-8');

// Every country is named in the language of the page asking (?lang=).
ApiLanguage::apply($_GET['lang'] ?? null);

try {
    $zones = (new ShippingZoneRepository())->findAllWithCountries();
} catch (\Throwable $e) {
    error_log('[api/shipping-zones.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load shipping zones right now.']);
    exit;
}

$countries = [];
foreach ($zones as $zone) {
    foreach ($zone['countries'] as $code) {
        $countries[] = ['code' => $code, 'label' => ShippingCountries::name($code)];
    }
}

usort($countries, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

echo json_encode(['countries' => $countries]);
