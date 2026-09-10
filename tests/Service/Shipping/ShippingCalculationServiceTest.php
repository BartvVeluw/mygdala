<?php

declare(strict_types=1);

namespace Tests\Service\Shipping;

use App\Repository\ProductRepository;
use App\Repository\ShippingRateRepository;
use App\Repository\ShippingZoneRepository;
use App\Service\Shipping\ShippingCalculationService;
use App\Service\Shipping\ShippingUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * Covers MAIN.MD's "Shipping calculation system" test scenarios (1-6, 8, 11,
 * 12 — see MAIN.MD point 17). Scenarios 7 (pickup -> €0), 9 (a manipulated
 * frontend shipping amount is ignored) and 10 (the order total always uses
 * the server-calculated shipping cost) aren't exercised here because they
 * aren't decisions this class makes at all: api/checkout.php never reads a
 * shipping price from the request and short-circuits pickup to a hardcoded
 * €0 before ever constructing this service — there is no calculation logic
 * left to unit test for those, only request-handling code. They're verified
 * by reading api/checkout.php and by the manual/curl checks in MAIN.MD.
 *
 * The DB-backed pieces (zone/product/rate repositories) are stubbed with
 * lightweight subclasses that never touch a database, so
 * calculateForShipping() itself — the real orchestration under test — runs
 * exactly as it would in production, only its three collaborators are
 * canned. determineMethod()/pickRate()/totalWeightGrams() are also exercised
 * directly since they're the pure "brain" of the whole feature.
 */
final class ShippingCalculationServiceTest extends TestCase
{
    private const NL_ZONE = ['id' => 1, 'code' => 'nl', 'name' => 'Nederland'];
    private const BE_ZONE = ['id' => 2, 'code' => 'be', 'name' => 'België'];

    /** NL letter: up to 20g -> 1.40, up to 50g -> 2.80. NL parcel: flat 7.45. */
    private const NL_RATES = [
        ['shipping_profile' => 'letter', 'min_weight_grams' => null, 'max_weight_grams' => 20, 'price' => '1.40', 'enabled' => true],
        ['shipping_profile' => 'letter', 'min_weight_grams' => null, 'max_weight_grams' => 50, 'price' => '2.80', 'enabled' => true],
        ['shipping_profile' => 'parcel', 'min_weight_grams' => null, 'max_weight_grams' => null, 'price' => '7.45', 'enabled' => true],
    ];

    /** A completely different price for the same profile, to prove BE never reuses NL's rates. */
    private const BE_RATES = [
        ['shipping_profile' => 'letter', 'min_weight_grams' => null, 'max_weight_grams' => 50, 'price' => '3.30', 'enabled' => true],
    ];

    private function keychain(int $id): array
    {
        return ['shipping_profile' => 'letter', 'shipping_weight_grams' => 18, 'requires_parcel' => false];
    }

    private function calculator(?array $zone, array $rates, array $products): ShippingCalculationService
    {
        $productRepository = new class ($products) extends ProductRepository {
            public function __construct(private readonly array $products)
            {
            }

            public function findShippingDataByIds(array $ids): array
            {
                return array_intersect_key($this->products, array_flip($ids));
            }
        };

        $zoneRepository = new class ($zone) extends ShippingZoneRepository {
            public function __construct(private readonly ?array $zone)
            {
            }

            public function findZoneForCountry(string $countryCode): ?array
            {
                return $this->zone;
            }
        };

        $rateRepository = new class ($rates) extends ShippingRateRepository {
            public function __construct(private readonly array $rates)
            {
            }

            public function findEnabledForZone(int $zoneId): array
            {
                return $this->rates;
            }
        };

        return new ShippingCalculationService($productRepository, $zoneRepository, $rateRepository);
    }

    public function testOneEighteenGramLetterProductInNlCostsOneFortyCosts(): void
    {
        $calculator = $this->calculator(self::NL_ZONE, self::NL_RATES, [1 => $this->keychain(1)]);

        $result = $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 1]], 'NL');

        $this->assertSame('letter', $result->method);
        $this->assertSame(18, $result->weightGrams);
        $this->assertSame(1.40, $result->price);
    }

    public function testTwoEighteenGramProductsInNlTotalThirtySixGramsCostsTwoEighty(): void
    {
        $calculator = $this->calculator(self::NL_ZONE, self::NL_RATES, [1 => $this->keychain(1)]);

        // Same product, quantity 2 — not simply 2 x the single-item price.
        $result = $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 2]], 'NL');

        $this->assertSame(36, $result->weightGrams);
        $this->assertSame(2.80, $result->price);
    }

    public function testQuantityIsIncludedInTotalWeight(): void
    {
        $shippingDataById = [1 => ['shipping_profile' => 'letter', 'shipping_weight_grams' => 18, 'requires_parcel' => false]];

        $weight = ShippingCalculationService::totalWeightGrams(
            [['product_id' => 1, 'quantity' => 3]],
            $shippingDataById
        );

        $this->assertSame(54, $weight);
    }

    public function testParcelRequiredProductForcesParcelForTheWholeOrder(): void
    {
        $products = [
            1 => $this->keychain(1),
            2 => ['shipping_profile' => 'letter', 'shipping_weight_grams' => 10, 'requires_parcel' => true],
        ];
        $calculator = $this->calculator(self::NL_ZONE, self::NL_RATES, $products);

        $result = $calculator->calculateForShipping(
            [['product_id' => 1, 'quantity' => 1], ['product_id' => 2, 'quantity' => 1]],
            'NL'
        );

        $this->assertSame('parcel', $result->method);
        $this->assertSame(7.45, $result->price);
    }

    public function testMixedLetterAndParcelCartUsesOneParcelRateNotBoth(): void
    {
        $products = [
            1 => $this->keychain(1), // letter, 2x
            2 => ['shipping_profile' => 'parcel', 'shipping_weight_grams' => 300, 'requires_parcel' => true],
        ];
        $calculator = $this->calculator(self::NL_ZONE, self::NL_RATES, $products);

        $result = $calculator->calculateForShipping(
            [['product_id' => 1, 'quantity' => 2], ['product_id' => 2, 'quantity' => 1]],
            'NL'
        );

        $this->assertSame('parcel', $result->method);
        // Exactly the flat parcel rate — never letter (2.80) + parcel (7.45) added together.
        $this->assertSame(7.45, $result->price);
    }

    public function testBelgiumUsesItsOwnRatesNotNetherlandsRates(): void
    {
        $calculator = $this->calculator(self::BE_ZONE, self::BE_RATES, [1 => $this->keychain(1)]);

        $result = $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 1]], 'BE');

        $this->assertSame('be', $result->zoneCode);
        $this->assertSame(3.30, $result->price);
    }

    public function testMissingRateBlocksCalculation(): void
    {
        // No rates configured at all for this zone (e.g. Belgium before the
        // owner has entered any prices) — must fail hard, never fall back to €0.
        $calculator = $this->calculator(self::BE_ZONE, [], [1 => $this->keychain(1)]);

        $this->expectException(ShippingUnavailableException::class);

        $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 1]], 'BE');
    }

    public function testUnknownDestinationCountryBlocksCalculation(): void
    {
        $calculator = $this->calculator(null, self::NL_RATES, [1 => $this->keychain(1)]);

        $this->expectException(ShippingUnavailableException::class);

        $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 1]], 'DE');
    }

    public function testDisabledRateIsNeverSelected(): void
    {
        $rates = [
            ['shipping_profile' => 'letter', 'min_weight_grams' => null, 'max_weight_grams' => 20, 'price' => '1.40', 'enabled' => false],
            ['shipping_profile' => 'letter', 'min_weight_grams' => null, 'max_weight_grams' => 50, 'price' => '2.80', 'enabled' => true],
        ];
        $calculator = $this->calculator(self::NL_ZONE, $rates, [1 => $this->keychain(1)]);

        // 18g would match the disabled 20g bracket first if it were eligible;
        // it must skip straight to the next enabled bracket instead.
        $result = $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 1]], 'NL');

        $this->assertSame(2.80, $result->price);
    }

    public function testDisabledOnlyMatchingRateBlocksCalculationEntirely(): void
    {
        $rates = [
            ['shipping_profile' => 'letter', 'min_weight_grams' => null, 'max_weight_grams' => 20, 'price' => '1.40', 'enabled' => false],
        ];
        $calculator = $this->calculator(self::NL_ZONE, $rates, [1 => $this->keychain(1)]);

        $this->expectException(ShippingUnavailableException::class);

        $calculator->calculateForShipping([['product_id' => 1, 'quantity' => 1]], 'NL');
    }

    public function testWeightBoundaryExactlyTwentyGramsUsesTheTwentyGramBracket(): void
    {
        $rate = ShippingCalculationService::pickRate(self::NL_RATES, 'letter', 20);

        $this->assertNotNull($rate);
        $this->assertSame('1.40', $rate['price']);
    }

    public function testWeightBoundaryTwentyOneGramsUsesTheFiftyGramBracket(): void
    {
        $rate = ShippingCalculationService::pickRate(self::NL_RATES, 'letter', 21);

        $this->assertNotNull($rate);
        $this->assertSame('2.80', $rate['price']);
    }

    public function testWeightAboveEveryBracketHasNoMatch(): void
    {
        $rate = ShippingCalculationService::pickRate(self::NL_RATES, 'letter', 51);

        $this->assertNull($rate);
    }

    public function testCartWithNoLinesIsUnavailable(): void
    {
        $calculator = $this->calculator(self::NL_ZONE, self::NL_RATES, []);

        $this->expectException(ShippingUnavailableException::class);

        $calculator->calculateForShipping([], 'NL');
    }

    public function testCartLineForUnknownProductIsUnavailableRatherThanAssumingAWeight(): void
    {
        $calculator = $this->calculator(self::NL_ZONE, self::NL_RATES, [1 => $this->keychain(1)]);

        $this->expectException(ShippingUnavailableException::class);

        // product_id 2 is absent from the fake product repository's data.
        $calculator->calculateForShipping([['product_id' => 2, 'quantity' => 1]], 'NL');
    }
}
