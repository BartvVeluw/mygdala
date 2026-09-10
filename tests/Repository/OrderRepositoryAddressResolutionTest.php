<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\OrderRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers OrderRepository::resolveShippingAddress()/resolveBillingAddress() —
 * pure functions over a plain order row, no database needed. These are what
 * admin/order.php, src/Mail/OrderConfirmationBuilder.php and
 * api/order-status.php-adjacent code rely on to answer "what is this order's
 * shipping/billing address" for both new orders (order-level columns) and
 * orders placed before those columns existed (db/migrations/
 * 20260907150000_add_billing_and_structured_addresses_to_orders.php) —
 * MAIN.MD "Billing address", scenarios 5/6, and "existing orders must
 * continue to work".
 */
final class OrderRepositoryAddressResolutionTest extends TestCase
{
    private function baseOrder(array $overrides = []): array
    {
        return array_merge([
            'shipping_first_name' => 'Jan',
            'shipping_last_name' => 'Jansen',
            'shipping_company' => null,
            'shipping_country' => 'NL',
            'shipping_postal_code' => '6511AA',
            'shipping_house_number' => '12',
            'shipping_house_number_addition' => null,
            'shipping_street' => 'Nieuwe Marktstraat',
            'shipping_city' => 'Nijmegen',
            'billing_same_as_shipping' => 1,
            'billing_first_name' => null,
            'billing_last_name' => null,
            'billing_company' => null,
            'billing_country' => null,
            'billing_postal_code' => null,
            'billing_house_number' => null,
            'billing_house_number_addition' => null,
            'billing_street' => null,
            'billing_city' => null,
            // Legacy customer-join fallback columns:
            'address_line' => 'Oude Adreslijn 1',
            'postal_code' => '1234AB',
            'city' => 'Oudestad',
            'country' => 'NL',
        ], $overrides);
    }

    public function testResolvesOrderLevelShippingAddressWhenPresent(): void
    {
        $address = OrderRepository::resolveShippingAddress($this->baseOrder());

        $this->assertSame('Nieuwe Marktstraat', $address['street']);
        $this->assertSame('Nijmegen', $address['city']);
        $this->assertSame('12', $address['house_number']);
    }

    public function testFallsBackToLegacyCustomerAddressWhenNoOrderLevelAddressExists(): void
    {
        $order = $this->baseOrder([
            'shipping_street' => null,
            'shipping_postal_code' => null,
            'shipping_first_name' => null,
            'shipping_last_name' => null,
            'shipping_house_number' => null,
        ]);

        $address = OrderRepository::resolveShippingAddress($order);

        $this->assertSame('Oude Adreslijn 1', $address['street']);
        $this->assertSame('Oudestad', $address['city']);
        $this->assertSame('1234AB', $address['postal_code']);
        $this->assertNull($address['house_number']);
    }

    public function testBillingSameAsShippingResolvesToShippingAddress(): void
    {
        $address = OrderRepository::resolveBillingAddress($this->baseOrder(['billing_same_as_shipping' => 1]));

        $this->assertSame('Nieuwe Marktstraat', $address['street']);
        $this->assertSame('Nijmegen', $address['city']);
    }

    public function testExplicitBillingAddressIsUsedWhenDifferentFromShipping(): void
    {
        $order = $this->baseOrder([
            'billing_same_as_shipping' => 0,
            'billing_first_name' => 'Bedrijf',
            'billing_last_name' => 'Boekhouding',
            'billing_company' => 'Acme BV',
            'billing_country' => 'NL',
            'billing_postal_code' => '1012JS',
            'billing_house_number' => '1',
            'billing_house_number_addition' => null,
            'billing_street' => 'Dam',
            'billing_city' => 'Amsterdam',
        ]);

        $address = OrderRepository::resolveBillingAddress($order);

        $this->assertSame('Dam', $address['street']);
        $this->assertSame('Amsterdam', $address['city']);
        $this->assertSame('Acme BV', $address['company']);

        // The shipping address must remain untouched by the separate billing address.
        $shipping = OrderRepository::resolveShippingAddress($order);
        $this->assertSame('Nieuwe Marktstraat', $shipping['street']);
    }
}
