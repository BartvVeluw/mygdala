<?php

declare(strict_types=1);

namespace Tests\Service\Address;

use App\Service\Address\AddressValidationException;
use App\Service\Address\CheckoutAddressResolver;
use App\Service\Address\DutchAddressLookupResult;
use App\Service\Address\DutchAddressLookupService;
use PHPUnit\Framework\TestCase;

/**
 * Covers what api/checkout.php actually relies on: for "NL" the posted
 * street/city are always discarded in favour of PDOK's canonical values (or
 * the whole address is rejected), while for any other country the posted
 * fields pass through untouched (Belgium/international addresses aren't in
 * BAG — see MAIN.MD "Dutch address validation", "Other countries"). The
 * Dutch lookup itself is faked via an anonymous subclass so this test never
 * hits the real PDOK API — see DutchAddressLookupServiceTest for that logic.
 */
final class CheckoutAddressResolverTest extends TestCase
{
    private function lookupReturning(DutchAddressLookupResult $result): DutchAddressLookupService
    {
        return new class ($result) extends DutchAddressLookupService {
            public function __construct(private readonly DutchAddressLookupResult $result)
            {
            }

            public function lookup(string $postalCode, string $houseNumber, ?string $addition): DutchAddressLookupResult
            {
                return $this->result;
            }
        };
    }

    private function lookupThrowing(AddressValidationException $exception): DutchAddressLookupService
    {
        return new class ($exception) extends DutchAddressLookupService {
            public function __construct(private readonly AddressValidationException $exception)
            {
            }

            public function lookup(string $postalCode, string $houseNumber, ?string $addition): DutchAddressLookupResult
            {
                throw $this->exception;
            }
        };
    }

    public function testDutchAddressUsesPdoksCanonicalStreetAndCityNeverThePosted(): void
    {
        $lookup = $this->lookupReturning(DutchAddressLookupResult::found(
            'Nieuwe Marktstraat',
            'Nijmegen',
            '6511AA',
            '12',
            null
        ));
        $resolver = new CheckoutAddressResolver($lookup);

        $address = $resolver->resolve(
            'Jan',
            'Jansen',
            null,
            'NL',
            '6511AA',
            '12',
            null,
            'Fake Street', // a manipulated/incorrect posted street …
            'Amsterdam'    // … and city — both must be discarded for NL
        );

        $this->assertSame('Nieuwe Marktstraat', $address['street']);
        $this->assertSame('Nijmegen', $address['city']);
        $this->assertSame('NL', $address['country']);
        $this->assertSame('6511AA', $address['postal_code']);
        $this->assertSame('12', $address['house_number']);
    }

    public function testUnverifiableDutchAddressThrowsNotFound(): void
    {
        $lookup = $this->lookupReturning(DutchAddressLookupResult::notFound());
        $resolver = new CheckoutAddressResolver($lookup);

        $this->expectException(AddressValidationException::class);

        try {
            $resolver->resolve('Jan', 'Jansen', null, 'NL', '9999ZZ', '1', null, 'Straat', 'Stad');
        } catch (AddressValidationException $e) {
            $this->assertSame('not_found', $e->reason);

            throw $e;
        }
    }

    public function testPdokUnavailablePropagatesAsUnavailable(): void
    {
        $lookup = $this->lookupThrowing(AddressValidationException::unavailable());
        $resolver = new CheckoutAddressResolver($lookup);

        $this->expectException(AddressValidationException::class);

        try {
            $resolver->resolve('Jan', 'Jansen', null, 'NL', '6511AA', '12', null, 'Straat', 'Stad');
        } catch (AddressValidationException $e) {
            $this->assertSame('unavailable', $e->reason);

            throw $e;
        }
    }

    public function testBelgianAddressSkipsDutchLookupAndUsesPostedFieldsAsIs(): void
    {
        $lookup = new class () extends DutchAddressLookupService {
            public function lookup(string $postalCode, string $houseNumber, ?string $addition): DutchAddressLookupResult
            {
                throw new \RuntimeException('must not be called for a non-NL address');
            }
        };
        $resolver = new CheckoutAddressResolver($lookup);

        $address = $resolver->resolve(
            'Jean',
            'Dupont',
            'Dupont BVBA',
            'be',
            '1000',
            '12',
            'bus 3',
            'Rue de la Loi',
            'Brussel'
        );

        $this->assertSame('BE', $address['country']);
        $this->assertSame('Rue de la Loi', $address['street']);
        $this->assertSame('Brussel', $address['city']);
        $this->assertSame('1000', $address['postal_code']);
        $this->assertSame('bus 3', $address['house_number_addition']);
        $this->assertSame('Dupont BVBA', $address['company']);
    }
}
