<?php

declare(strict_types=1);

namespace Tests\Service\Address;

use App\Service\Address\AddressValidationException;
use App\Service\Address\DutchAddressLookupService;
use PHPUnit\Framework\TestCase;

/**
 * Covers MAIN.MD's "Dutch address validation" test scenarios: a valid
 * address, an invalid postcode/house-number combination, an address with a
 * house-number addition, and PDOK being unavailable. httpGet() (the only
 * method that touches the network) is faked via an anonymous subclass — same
 * convention as tests/Service/TurnstileVerifierTest.php — so these tests
 * never hit the real PDOK API.
 *
 * The sample PDOK response fixtures below mirror the real "free" endpoint
 * response shape, verified by hand against the live API while building this
 * feature (see DutchAddressLookupService's class docblock) — not invented
 * from documentation examples.
 */
final class DutchAddressLookupServiceTest extends TestCase
{
    private function serviceReturning(string $jsonResponse): DutchAddressLookupService
    {
        return new class ($jsonResponse) extends DutchAddressLookupService {
            public function __construct(private readonly string $jsonResponse)
            {
            }

            protected function httpGet(string $url): string
            {
                return $this->jsonResponse;
            }
        };
    }

    private function serviceThrowing(): DutchAddressLookupService
    {
        return new class () extends DutchAddressLookupService {
            protected function httpGet(string $url): string
            {
                throw new \RuntimeException('simulated network failure');
            }
        };
    }

    private function pdokResponse(array $docs): string
    {
        return json_encode(['response' => ['numFound' => count($docs), 'docs' => $docs]]);
    }

    public function testValidAddressWithoutAdditionIsFound(): void
    {
        $service = $this->serviceReturning($this->pdokResponse([[
            'type' => 'adres',
            'straatnaam' => 'Nieuwe Marktstraat',
            'woonplaatsnaam' => 'Nijmegen',
            'postcode' => '6511AA',
            'huisnummer' => 12,
            'huis_nlt' => '12',
        ]]));

        $result = $service->lookup('6511 aa', '12', null);

        $this->assertTrue($result->found);
        $this->assertSame('Nieuwe Marktstraat', $result->street);
        $this->assertSame('Nijmegen', $result->city);
        $this->assertSame('6511AA', $result->postalCode);
        $this->assertSame('12', $result->houseNumber);
        $this->assertNull($result->houseNumberAddition);
    }

    public function testAddressWithMatchingAdditionIsFound(): void
    {
        $service = $this->serviceReturning($this->pdokResponse([
            [
                'type' => 'adres',
                'straatnaam' => 'Vondelstraat',
                'woonplaatsnaam' => 'Nijmegen',
                'postcode' => '6524BA',
                'huisnummer' => 1,
                'huisletter' => 'A',
                'huisnummertoevoeging' => 'A',
                'huis_nlt' => '1A-A',
            ],
        ]));

        $result = $service->lookup('6524BA', '1', 'a-a');

        $this->assertTrue($result->found);
        $this->assertSame('Vondelstraat', $result->street);
        $this->assertSame('A-A', $result->houseNumberAddition);
    }

    public function testAdditionFormattingDifferencesStillMatch(): void
    {
        // Same address as above, but the customer typed the addition with
        // different casing/spacing/dashes — must still match (MAIN.MD: "Be
        // careful not to incorrectly reject legitimate addresses").
        $service = $this->serviceReturning($this->pdokResponse([
            [
                'type' => 'adres',
                'straatnaam' => 'Damstraat',
                'woonplaatsnaam' => 'Amsterdam',
                'postcode' => '1012JL',
                'huisnummer' => 1,
                'huisletter' => 'A',
                'huis_nlt' => '1A',
            ],
        ]));

        $result = $service->lookup('1012JL', '1', ' a ');

        $this->assertTrue($result->found);
        $this->assertSame('A', $result->houseNumberAddition);
    }

    public function testAmbiguousAdditionAmongMultipleUnitsIsNotFound(): void
    {
        // Two distinct registered units at the same house number ("1A" and
        // "1B") — an addition that doesn't match either must not be guessed.
        $service = $this->serviceReturning($this->pdokResponse([
            ['type' => 'adres', 'straatnaam' => 'Damstraat', 'woonplaatsnaam' => 'Amsterdam', 'postcode' => '1012JL', 'huisnummer' => 1, 'huisletter' => 'A', 'huis_nlt' => '1A'],
            ['type' => 'adres', 'straatnaam' => 'Damstraat', 'woonplaatsnaam' => 'Amsterdam', 'postcode' => '1012JL', 'huisnummer' => 1, 'huisletter' => 'B', 'huis_nlt' => '1B'],
        ]));

        $result = $service->lookup('1012JL', '1', 'C');

        $this->assertFalse($result->found);
    }

    public function testSingleUnambiguousUnitIsAcceptedEvenWithoutMatchingAddition(): void
    {
        // Only one registered unit exists at this house number, even though
        // BAG happens to record an addition for it — the customer leaving
        // the addition blank must not be rejected as "not found".
        $service = $this->serviceReturning($this->pdokResponse([
            ['type' => 'adres', 'straatnaam' => 'Vondelstraat', 'woonplaatsnaam' => 'Nijmegen', 'postcode' => '6524BA', 'huisnummer' => 2, 'huisletter' => 'A', 'huisnummertoevoeging' => 'A', 'huis_nlt' => '2A-A'],
        ]));

        $result = $service->lookup('6524BA', '2', null);

        $this->assertTrue($result->found);
        $this->assertSame('Vondelstraat', $result->street);
    }

    public function testNoMatchingDocumentsIsNotFound(): void
    {
        $service = $this->serviceReturning($this->pdokResponse([]));

        $result = $service->lookup('6511AA', '9999', null);

        $this->assertFalse($result->found);
    }

    public function testMalformedPostcodeIsNotFoundWithoutCallingPdok(): void
    {
        $service = new class () extends DutchAddressLookupService {
            protected function httpGet(string $url): string
            {
                throw new \RuntimeException('must not be called for a malformed postcode');
            }
        };

        $result = $service->lookup('NOTAPOSTCODE', '12', null);

        $this->assertFalse($result->found);
    }

    public function testMalformedHouseNumberIsNotFoundWithoutCallingPdok(): void
    {
        $service = new class () extends DutchAddressLookupService {
            protected function httpGet(string $url): string
            {
                throw new \RuntimeException('must not be called for a malformed house number');
            }
        };

        $result = $service->lookup('6511AA', 'twelve', null);

        $this->assertFalse($result->found);
    }

    public function testUnreadableResponseThrowsUnavailable(): void
    {
        $service = $this->serviceReturning('not json at all');

        $this->expectException(AddressValidationException::class);

        try {
            $service->lookup('6511AA', '12', null);
        } catch (AddressValidationException $e) {
            $this->assertSame('unavailable', $e->reason);

            throw $e;
        }
    }

    public function testNetworkFailureThrowsUnavailable(): void
    {
        $service = $this->serviceThrowing();

        $this->expectException(AddressValidationException::class);

        try {
            $service->lookup('6511AA', '12', null);
        } catch (AddressValidationException $e) {
            $this->assertSame('unavailable', $e->reason);

            throw $e;
        }
    }
}
