<?php


require_once __DIR__ . '/_translate.php';
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
        return admin_t('status.shipping_pickup');
    }
    if ($shippingMethod !== null && ShippingProfile::isValid($shippingMethod)) {
        return ShippingProfile::label($shippingMethod);
    }
    if ($shippingMethod === 'verzenden') {
        return admin_t('status.shipping_ship');
    }
    return admin_t(((float) $shippingCost) > 0 ? 'status.shipping_ship' : 'status.shipping_pickup');
}

function adminPaymentStatusLabel(string $status): string
{
    // The DISPLAY word only. 'paid' stays 'paid' in the database, in every
    // query and in the CSV export; nothing about a stored value changes
    // because somebody reads the CMS in English.
    return match ($status) {
        'paid' => admin_t('status.payment_paid'),
        'pending' => admin_t('status.payment_pending'),
        'failed' => admin_t('status.payment_failed'),
        'canceled' => admin_t('status.payment_canceled'),
        'expired' => admin_t('status.payment_expired'),
        default => $status,
    };
}

function adminContactAudienceLabel(string $audience): string
{
    return admin_t($audience === 'zakelijk' ? 'status.audience_business' : 'status.audience_private');
}

function adminContactStatusLabel(string $status): string
{
    return admin_t($status === 'gelezen' ? 'status.contact_read' : 'status.contact_new');
}

function adminWithdrawalStatusLabel(string $status): string
{
    return match ($status) {
        'in_behandeling' => admin_t('status.withdrawal_in_progress'),
        'afgehandeld' => admin_t('status.withdrawal_handled'),
        default => admin_t('status.withdrawal_new'),
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
        'full' => admin_t('status.refund_full'),
        'partial' => admin_t('status.refund_partial'),
        default => admin_t('status.refund_none'),
    };
}

function adminRefundStatusLabelFor(string $mollieRefundStatus): string
{
    return match ($mollieRefundStatus) {
        'refunded' => admin_t('status.refund_refunded'),
        'pending' => admin_t('status.refund_pending'),
        'processing' => admin_t('status.refund_processing'),
        'queued' => admin_t('status.refund_queued'),
        'failed' => admin_t('status.refund_failed'),
        'canceled' => admin_t('status.refund_canceled'),
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
