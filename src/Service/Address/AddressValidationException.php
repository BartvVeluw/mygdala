<?php

declare(strict_types=1);

namespace App\Service\Address;

/**
 * Thrown by App\Service\Address\CheckoutAddressResolver when a Dutch address
 * can't be used for checkout. `reason` lets callers (api/checkout.php) map
 * this to the right HTTP status without string-matching the message:
 *
 *  - 'not_found': PDOK was reached fine, but no address matches the given
 *    postcode + house number (+ addition) — the customer must correct the
 *    input. Maps to a 422.
 *  - 'unavailable': PDOK itself couldn't be reached/queried right now — a
 *    temporary problem, not proof the address is wrong. Never treated as "ok
 *    to accept unverified". Maps to a 503.
 *
 * getMessage() is always the exact, generic, customer-safe text to show —
 * never a technical detail (those are error_log()'d separately by whichever
 * service throws this).
 */
final class AddressValidationException extends \RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(
            'not_found',
            "We couldn't verify this address. Please check your postal code and house number."
        );
    }

    public static function unavailable(): self
    {
        return new self(
            'unavailable',
            'We could not verify this address right now due to a temporary problem. Please try again shortly.'
        );
    }
}
