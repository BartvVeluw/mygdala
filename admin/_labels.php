<?php

/**
 * Small display helpers shared by the admin order list and detail pages.
 */

use App\Service\Shipping\ShippingProfile;
use App\Repository\OrderRepository;

function adminShippingMethodLabel(?string $shippingMethod, string $shippingCost): string
{
    // shipping_method is stored on the order since 2026-09-04 (see MAIN.MD).
    // Since the shipping calculation system (2026-09-06) it holds either
    // "afhalen" or one of ShippingProfile::ALL (letter/letterbox/parcel — the
    // profile the order actually shipped under). Older pre-2026-09-06 paid
    // orders still say the old generic "verzenden"; the cost-based guess
    // below only exists for the (practically nonexistent, migration-backfilled)
    // case where the column is somehow still null.
    if ($shippingMethod === 'afhalen') {
        return 'Afhalen';
    }
    if ($shippingMethod !== null && ShippingProfile::isValid($shippingMethod)) {
        return ShippingProfile::label($shippingMethod);
    }
    if ($shippingMethod === 'verzenden') {
        return 'Verzenden';
    }
    return ((float) $shippingCost) > 0 ? 'Verzenden' : 'Afhalen';
}

function adminPaymentStatusLabel(string $status): string
{
    return match ($status) {
        'paid' => 'Betaald',
        'pending' => 'In afwachting',
        'failed' => 'Mislukt',
        'canceled' => 'Geannuleerd',
        'expired' => 'Verlopen',
        default => $status,
    };
}

function adminContactAudienceLabel(string $audience): string
{
    return $audience === 'zakelijk' ? 'Zakelijk' : 'Particulier';
}

function adminContactStatusLabel(string $status): string
{
    return $status === 'gelezen' ? 'Gelezen' : 'Nieuw';
}

function adminWithdrawalStatusLabel(string $status): string
{
    return match ($status) {
        'in_behandeling' => 'In behandeling',
        'afgehandeld' => 'Afgehandeld',
        default => 'Nieuw',
    };
}

/**
 * "none"/"partial"/"full" purely from the two amounts already on the order
 * row (refunded_amount vs total) — no separate stored status to keep in
 * sync. A small epsilon absorbs decimal rounding, not a meaningful amount.
 */
function adminRefundStatus(float $refundedAmount, float $total): string
{
    if ($refundedAmount <= 0.0) {
        return 'none';
    }
    return $refundedAmount >= $total - 0.005 ? 'full' : 'partial';
}

function adminRefundStatusLabel(float $refundedAmount, float $total): string
{
    return match (adminRefundStatus($refundedAmount, $total)) {
        'full' => 'Volledig terugbetaald',
        'partial' => 'Deels terugbetaald',
        default => 'Niet terugbetaald',
    };
}

function adminRefundStatusLabelFor(string $mollieRefundStatus): string
{
    return match ($mollieRefundStatus) {
        'refunded' => 'Terugbetaald',
        'pending' => 'In afwachting',
        'processing' => 'Wordt verwerkt',
        'queued' => 'In wachtrij',
        'failed' => 'Mislukt',
        'canceled' => 'Geannuleerd',
        default => $mollieRefundStatus,
    };
}

/**
 * Badge modifier for an order's handling status on the admin order list and
 * detail page. "Open" gets the same gold accent an unread contact request
 * gets — it is the owner's working list — while "Afgehandeld" is deliberately
 * muted: still perfectly readable, just visually out of the way. See MAIN.MD
 * "Afhandelingsstatus".
 */
function adminFulfilmentBadgeModifier(string $fulfilmentStatus): string
{
    return $fulfilmentStatus === OrderRepository::FULFILMENT_HANDLED ? 'muted' : 'info';
}
