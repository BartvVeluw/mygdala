<?php

declare(strict_types=1);

/**
 * Shared server-side validation for the admin shipping-rate create/edit
 * endpoints (create-shipping-rate.php / update-shipping-rate.php), so both
 * apply exactly the same rules.
 */

use App\Repository\CarrierRateRepository;
use App\Service\Shipping\ShippingProfile;

/**
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: array<int, string>, 1: array{shipping_profile:string, min_weight_grams:?int, max_weight_grams:?int, price:float, sort_order:int, enabled:bool, carrier_rate_id:?int}}
 */
function validateShippingRateInput(array $input): array
{
    $errors = [];

    $profile = is_string($input['shipping_profile'] ?? null) ? trim($input['shipping_profile']) : '';
    if (!ShippingProfile::isValid($profile)) {
        $errors[] = 'Kies een geldige verzendmethode.';
    }

    $min = parseOptionalShippingWeight($input['min_weight_grams'] ?? null, $errors, 'Vanaf-gewicht');
    $max = parseOptionalShippingWeight($input['max_weight_grams'] ?? null, $errors, 'Tot-gewicht');

    if ($min !== null && $max !== null && $min > $max) {
        $errors[] = 'Vanaf-gewicht mag niet groter zijn dan tot-gewicht.';
    }

    // A rate either links to a central carrier rate (its live price is what
    // counts — see App\Repository\ShippingRateRepository) or uses its own
    // fixed price below; only the fixed-price case requires the price field.
    $carrierRateId = null;
    $carrierRateRaw = is_string($input['carrier_rate_id'] ?? null) ? trim($input['carrier_rate_id']) : '';
    if ($carrierRateRaw !== '') {
        $carrierRateIdCandidate = filter_var($carrierRateRaw, FILTER_VALIDATE_INT);
        if ($carrierRateIdCandidate === false || $carrierRateIdCandidate < 1) {
            $errors[] = 'Ongeldig carrier-tarief.';
        } elseif ((new CarrierRateRepository())->findById($carrierRateIdCandidate) === null) {
            $errors[] = 'Gekozen carrier-tarief bestaat niet (meer).';
        } else {
            $carrierRateId = $carrierRateIdCandidate;
        }
    }

    $priceRaw = is_string($input['price'] ?? null) ? trim(str_replace(',', '.', $input['price'])) : '';
    $price = 0.0;
    if ($carrierRateId !== null) {
        // Purely a harmless snapshot when linked (never read — see
        // ShippingRateRepository's effective-price CASE expression); no
        // error if left blank.
        if ($priceRaw !== '' && is_numeric($priceRaw)) {
            $price = (float) $priceRaw;
        }
    } elseif ($priceRaw === '' || !is_numeric($priceRaw)) {
        $errors[] = 'Prijs is verplicht en moet een geldig bedrag zijn (of kies een carrier-tarief).';
    } else {
        $price = (float) $priceRaw;
        if ($price < 0 || $price > 9999.99) {
            $errors[] = 'Prijs moet 0 of hoger en maximaal € 9.999,99 zijn.';
        }
    }

    $sortOrderRaw = is_string($input['sort_order'] ?? null) ? trim($input['sort_order']) : '';
    $sortOrder = ($sortOrderRaw !== '' && is_numeric($sortOrderRaw)) ? (int) $sortOrderRaw : 0;

    $enabled = ($input['enabled'] ?? null) === '1';

    $fields = [
        'shipping_profile' => $profile,
        'min_weight_grams' => $min,
        'max_weight_grams' => $max,
        'price' => $price,
        'sort_order' => $sortOrder,
        'enabled' => $enabled,
        'carrier_rate_id' => $carrierRateId,
    ];

    return [$errors, $fields];
}

/**
 * @param array<int, string> $errors
 */
function parseOptionalShippingWeight(mixed $value, array &$errors, string $label): ?int
{
    $raw = is_string($value) ? trim($value) : '';
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw) || (float) $raw < 0) {
        $errors[] = $label . ' moet leeg zijn (= geen grens), of 0 of hoger.';
        return null;
    }

    return (int) round((float) $raw);
}
