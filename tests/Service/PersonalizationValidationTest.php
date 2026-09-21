<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PersonalizationUploadRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationValidationException;
use App\Service\Personalization\PersonalizationValidator;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationFontFixture;
use Tests\Support\PersonalizationTestConfig;

/**
 * The server-side gate every personalized cart line passes through at
 * checkout (App\Service\Personalization\PersonalizationValidator), exercised
 * against real product configurations in the database.
 *
 * The frontend editor is not a security boundary and these tests are written
 * as if it does not exist: every payload here is what a crafted request could
 * send, not what the editor would produce. The pricing tests matter most —
 * a surcharge must come from the product and only from the product.
 */
final class PersonalizationValidationTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-personalization-validate-';

    private ProductRepository $products;
    private PersonalizationValidator $validator;
    private PersonalizationFontFixture $fonts;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<int> */
    private array $uploadIds = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->validator = new PersonalizationValidator();
        $this->fonts = new PersonalizationFontFixture();
        PersonalizationFonts::clearCache();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $this->fonts->remove();

        $db = Database::connection();
        foreach ($this->uploadIds as $id) {
            $db->prepare('DELETE FROM personalization_uploads WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        $this->uploadIds = [];
        $this->productIds = [];
        ProductPersonalizationContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function createProduct(): int
    {
        $id = $this->products->create([
            'name' => 'ZZ Validatie testproduct',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 19.5,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        return $id;
    }

    /** A front "name" text zone (required, 10 chars) + a back "message" zone at €7.50. */
    private function createTwoViewProduct(): int
    {
        $productId = $this->createProduct();

        PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'front', 'label' => 'Voorkant',
                'zones' => [
                    PersonalizationTestConfig::zone('name', [
                        'label' => 'Naam', 'allow_image' => false, 'is_required' => true,
                        'max_text_length' => 10,
                        'allowed_fonts' => ['trirong', 'georgia'], 'default_font' => 'trirong',
                    ]),
                    PersonalizationTestConfig::zone('logo', [
                        'label' => 'Logo', 'allow_text' => false, 'surcharge_cents' => 500,
                    ]),
                ],
            ],
            [
                'view_key' => 'back', 'label' => 'Achterkant',
                'zones' => [
                    PersonalizationTestConfig::zone('message', [
                        'label' => 'Bericht', 'allow_image' => false, 'surcharge_cents' => 750,
                    ]),
                ],
            ],
        ]);

        return $productId;
    }

    private function createUpload(?int $productId): string
    {
        $token = PersonalizationRules::newUploadToken();
        $this->uploadIds[] = (new PersonalizationUploadRepository())->create([
            'token' => $token,
            'product_id' => $productId,
            'stored_filename' => $token . '.orig.png',
            'preview_filename' => $token . '.preview.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'image_width' => 100,
            'image_height' => 80,
            'byte_size' => 1234,
        ]);

        return $token;
    }

    /** @param array<int, array<string, mixed>> $zones */
    private function payload(array $zones): array
    {
        return ['zones' => $zones];
    }

    /* ------------------------------------------------------------------ */
    /* Nothing submitted is never an error                                 */
    /* ------------------------------------------------------------------ */

    public function testAnOrdinaryCartLineWithoutPersonalizationValidatesToNothing(): void
    {
        $productId = $this->createProduct();

        $this->assertNull($this->validator->validate($productId, null));
        $this->assertNull($this->validator->validate($productId, []));
        $this->assertNull($this->validator->validate($productId, 'nonsense'));
        $this->assertNull($this->validator->validate($productId, ['zones' => []]));
    }

    public function testAPersonalizableProductWithoutRequiredZonesMayBeBoughtPlain(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);

        $this->assertNull($this->validator->validate($productId, $this->payload([
            ['zone_key' => 'default', 'text' => '   '],
        ])));
    }

    /* ------------------------------------------------------------------ */
    /* Multiple zones                                                      */
    /* ------------------------------------------------------------------ */

    public function testEachZoneKeepsItsOwnTextIndependently(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Bedankt voor alles'],
        ]));

        $this->assertNotNull($result);
        $this->assertCount(2, $result['zones']);

        $byKey = array_column($result['zones'], null, 'zone_key');
        $this->assertSame('Bart', $byKey['name']['text_value']);
        $this->assertSame('Bedankt voor alles', $byKey['message']['text_value']);
        $this->assertSame('front', $byKey['name']['view_key']);
        $this->assertSame('back', $byKey['message']['view_key']);
    }

    /**
     * The zones come back in the product's own configured order, not in the
     * order the browser happened to send them — so an order reads the way the
     * administrator arranged it, and two identical personalizations always
     * serialise identically.
     */
    public function testZonesAreReturnedInTheProductsConfiguredOrder(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'message', 'text' => 'Later'],
            ['zone_key' => 'name', 'text' => 'Bart'],
        ]));

        $this->assertSame(['name', 'message'], array_column($result['zones'], 'zone_key'));
    }

    public function testTheSameZoneTwiceIsRejected(): void
    {
        $productId = $this->createTwoViewProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'name', 'text' => 'Inge'],
        ]));
    }

    public function testAnUnknownZoneIsRejected(): void
    {
        $productId = $this->createTwoViewProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'zijkant', 'text' => 'Hoi'],
        ]));
    }

    public function testADisabledZoneIsRejectedLikeAnUnknownOne(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'front',
            'zones' => [
                PersonalizationTestConfig::zone('name'),
                PersonalizationTestConfig::zone('hidden', ['is_enabled' => false]),
            ],
        ]]);

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([['zone_key' => 'hidden', 'text' => 'Hoi']]));
    }

    /* ------------------------------------------------------------------ */
    /* Required zones                                                      */
    /* ------------------------------------------------------------------ */

    public function testARequiredZoneLeftEmptyIsRejected(): void
    {
        $productId = $this->createTwoViewProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->expectExceptionMessageMatches('/missing required personalization/');
        $this->validator->validate($productId, $this->payload([['zone_key' => 'message', 'text' => 'Bedankt']]));
    }

    /**
     * A product with a required zone can no longer be bought plain at all —
     * sending nothing is exactly as incomplete as sending the other zones.
     */
    public function testAProductWithARequiredZoneCannotBeOrderedWithoutPersonalization(): void
    {
        $productId = $this->createTwoViewProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([]));
    }

    public function testTheRejectionNamesTheZoneTheCustomerMustComplete(): void
    {
        $productId = $this->createTwoViewProduct();

        try {
            $this->validator->validate($productId, $this->payload([]));
            $this->fail('a missing required zone should have been rejected');
        } catch (PersonalizationValidationException $e) {
            $this->assertStringContainsString('Naam', $e->getMessage());
        }
    }

    public function testAnOptionalZoneMayStayEmpty(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([['zone_key' => 'name', 'text' => 'Bart']]));

        $this->assertNotNull($result);
        $this->assertCount(1, $result['zones']);
        $this->assertSame(0, $result['surcharge_cents'], 'an unused optional zone adds nothing');
    }

    /**
     * "Filled" follows the zone's own content mode: an image-only required
     * zone is not satisfied by text.
     */
    public function testARequiredImageZoneIsNotSatisfiedByText(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, [
            'allow_text' => false, 'allow_image' => true, 'is_required' => true,
        ]);

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([['zone_key' => 'default', 'text' => 'Bart']]));
    }

    public function testARequiredEitherZoneIsSatisfiedByEitherInput(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['is_required' => true]);

        $withText = $this->validator->validate($productId, $this->payload([['zone_key' => 'default', 'text' => 'Bart']]));
        $this->assertNotNull($withText);

        $token = $this->createUpload($productId);
        $withImage = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'default', 'upload_token' => $token],
        ]));
        $this->assertNotNull($withImage);
    }

    /* ------------------------------------------------------------------ */
    /* Fonts                                                               */
    /* ------------------------------------------------------------------ */

    public function testAnAllowedFontIsKept(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart', 'font' => 'georgia'],
        ]));

        $this->assertSame('georgia', $result['zones'][0]['font_key']);
    }

    /* ------------------------------------------------------------------ */
    /* Required vs optional personalization                                */
    /* ------------------------------------------------------------------ */

    /**
     * THE rule this refactor exists for: a product configured as
     * personalization-REQUIRED cannot be bought plain. The ordinary
     * add-to-cart path runs through this validator like every other path, so
     * "just send no personalization" is not a way around it.
     */
    public function testARequiredProductCannotBeBoughtWithoutPersonalization(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone(
            $productId,
            [],
            ['personalization_mode' => PersonalizationRules::PURCHASE_REQUIRED]
        );

        foreach ([null, [], ['zones' => []], ['zone_key' => 'default', 'text' => '   ']] as $submitted) {
            try {
                $this->validator->validate($productId, $submitted);
                $this->fail('an empty personalization must be rejected for a required product: ' . var_export($submitted, true));
            } catch (PersonalizationValidationException $e) {
                $this->assertStringContainsString('personalized', $e->getMessage());
            }
        }
    }

    public function testARequiredProductIsAcceptedOncePersonalizationIsFilledIn(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone(
            $productId,
            [],
            ['personalization_mode' => PersonalizationRules::PURCHASE_REQUIRED]
        );

        $result = $this->validator->validate($productId, ['zone_key' => 'default', 'text' => 'Bart']);

        $this->assertNotNull($result);
        $this->assertSame('Bart', $result['zones'][0]['text_value']);
        $this->assertSame(
            PersonalizationRules::PURCHASE_REQUIRED,
            $result['zones'][0]['config_snapshot']['purchase_mode']
        );
    }

    /**
     * The other half of the same rule: an OPTIONAL product keeps behaving
     * exactly as it always did — it may be bought plain, and that is not an
     * error.
     */
    public function testAnOptionalProductMayStillBeBoughtWithoutPersonalization(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone(
            $productId,
            [],
            ['personalization_mode' => PersonalizationRules::PURCHASE_OPTIONAL]
        );

        $this->assertNull($this->validator->validate($productId, null));
        $this->assertNull($this->validator->validate($productId, ['zones' => []]));
    }

    /**
     * A font that is not in the shop's ACTIVE library must never end up on an
     * order — a made-up key, a wrong type, or a real library font that has
     * since been deactivated. All of them resolve to the shop's default
     * instead of being taken as given.
     */
    public function testAFontOutsideTheActiveLibraryFallsBackToTheDefault(): void
    {
        $productId = $this->createTwoViewProduct();
        $retired = $this->fonts->create('Fixture Ingetrokken', false);
        $expected = PersonalizationFonts::fallbackKey();

        foreach (['comic_sans_hacker', $retired['font_key'], '', null, ['array'], 42] as $submitted) {
            $result = $this->validator->validate($productId, $this->payload([
                ['zone_key' => 'name', 'text' => 'Bart', 'font' => $submitted],
            ]));

            $this->assertSame(
                $expected,
                $result['zones'][0]['font_key'],
                'font ' . var_export($submitted, true) . ' must not survive'
            );
        }
    }

    /**
     * The order keeps its OWN copy of the font, so the library stays safely
     * editable: what a placed order was engraved in can never be changed by
     * renaming, deactivating or deleting a library row afterwards.
     */
    public function testAnOrderRecordsTheFontItWasEngravedIn(): void
    {
        $productId = $this->createTwoViewProduct();
        $font = $this->fonts->create('Fixture Gravure', true);

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart', 'font' => $font['font_key']],
        ]));

        $zone = $result['zones'][0];

        $this->assertSame($font['font_key'], $zone['font_key']);
        $this->assertSame('Fixture Gravure', $zone['font_label']);
        $this->assertNotSame('', (string) $zone['font_stack']);

        // ...and the same facts in the immutable snapshot.
        $this->assertSame($font['font_key'], $zone['config_snapshot']['font']['key']);
        $this->assertSame('Fixture Gravure', $zone['config_snapshot']['font']['label']);

        // Deactivating the font afterwards cannot rewrite any of it.
        $this->fonts->setActive($font['id'], false);
        $this->assertSame('Fixture Gravure', $zone['font_label']);
    }

    public function testAnImageOnlyZoneStoresNoFont(): void
    {
        $productId = $this->createTwoViewProduct();
        $token = $this->createUpload($productId);

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token, 'font' => 'georgia'],
        ]));

        $byKey = array_column($result['zones'], null, 'zone_key');
        $this->assertNull($byKey['logo']['font_key']);
    }

    /* ------------------------------------------------------------------ */
    /* Pricing — the server decides, always                                */
    /* ------------------------------------------------------------------ */

    public function testAnUnusedSurchargeZoneAddsNothing(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([['zone_key' => 'name', 'text' => 'Bart']]));

        $this->assertSame(0, $result['surcharge_cents']);
    }

    public function testAUsedSurchargeZoneAddsExactlyItsConfiguredAmount(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Bedankt'],
        ]));

        $this->assertSame(750, $result['surcharge_cents']);

        $byKey = array_column($result['zones'], null, 'zone_key');
        $this->assertSame(0, $byKey['name']['surcharge_cents']);
        $this->assertSame(750, $byKey['message']['surcharge_cents']);
    }

    public function testSeveralUsedSurchargeZonesAddUpExactly(): void
    {
        $productId = $this->createTwoViewProduct();
        $token = $this->createUpload($productId);

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token],
            ['zone_key' => 'message', 'text' => 'Bedankt'],
        ]));

        $this->assertSame(1250, $result['surcharge_cents'], '500 + 750, to the cent');
    }

    /**
     * The single most important pricing rule: the request is never even
     * consulted for an amount.
     */
    public function testASurchargeSubmittedByTheClientIsIgnoredEntirely(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, [
            'surcharge_cents' => -99999,
            'surcharge' => '0.00',
            'zones' => [
                ['zone_key' => 'name', 'text' => 'Bart', 'surcharge_cents' => 999999, 'surcharge' => '999.99'],
                ['zone_key' => 'message', 'text' => 'Hoi', 'surcharge_cents' => 0, 'surcharge' => '0.00'],
            ],
        ]);

        $this->assertSame(750, $result['surcharge_cents']);
        $this->assertSame(0, array_column($result['zones'], null, 'zone_key')['name']['surcharge_cents']);
    }

    /**
     * The surcharge applies once per zone, however much is put in it — Phase
     * 2 pricing is deliberately not per character or per pixel.
     */
    public function testASurchargeAppliesOncePerZoneRegardlessOfContent(): void
    {
        $productId = $this->createTwoViewProduct();

        $short = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Hi'],
        ]));
        $long = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Bedankt voor alles nog'],
        ]));

        $this->assertSame($short['surcharge_cents'], $long['surcharge_cents']);
    }

    /* ------------------------------------------------------------------ */
    /* Text and image rules (unchanged from Phase 1, per zone now)         */
    /* ------------------------------------------------------------------ */

    public function testTextIsRejectedWhereTextIsNotAllowed(): void
    {
        $productId = $this->createTwoViewProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->expectExceptionMessageMatches('/Text personalization is not available/');
        $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'text' => 'sneaky'],
        ]));
    }

    public function testOverlongTextIsRejectedPerZoneLimit(): void
    {
        $productId = $this->createTwoViewProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->expectExceptionMessageMatches('/maximum 10 characters/');
        $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'veel te lange naam'],
        ]));
    }

    public function testAnImageIsRejectedWhereUploadsAreNotAllowed(): void
    {
        $productId = $this->createTwoViewProduct();
        $token = $this->createUpload($productId);

        $this->expectException(PersonalizationValidationException::class);
        $this->expectExceptionMessageMatches('/Image personalization is not available/');
        $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart', 'upload_token' => $token],
        ]));
    }

    public function testAnUploadFromAnotherProductIsRejected(): void
    {
        $productA = $this->createTwoViewProduct();
        $productB = $this->createProduct();
        PersonalizationTestConfig::singleZone($productB);

        $tokenForB = $this->createUpload($productB);

        $this->expectException(PersonalizationValidationException::class);
        $this->expectExceptionMessageMatches('/does not belong to that product/');
        $this->validator->validate($productA, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $tokenForB],
        ]));
    }

    public function testAMalformedUploadTokenIsRejectedBeforeAnyLookup(): void
    {
        $productId = $this->createTwoViewProduct();

        foreach (['../../etc/passwd', 'abc', str_repeat('z', 32)] as $token) {
            try {
                $this->validator->validate($productId, $this->payload([
                    ['zone_key' => 'name', 'text' => 'Bart'],
                    ['zone_key' => 'logo', 'upload_token' => $token],
                ]));
                $this->fail("token '{$token}' should have been rejected");
            } catch (PersonalizationValidationException $e) {
                $this->assertStringNotContainsString($token, $e->getMessage());
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Transforms and rotation                                             */
    /* ------------------------------------------------------------------ */

    public function testTransformValuesOutsideTheZoneAreClampedRatherThanRejected(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([[
            'zone_key' => 'name',
            'text' => 'Bart',
            'transform' => [
                'text' => ['x' => 12, 'y' => -8, 'scale' => 500, 'rotation' => 4000],
                'image' => ['x' => 'left'],
            ],
        ]]));

        $transform = $result['zones'][0]['transform'];
        $this->assertSame(1.0, $transform['text']['x']);
        $this->assertSame(0.0, $transform['text']['y']);
        $this->assertSame(PersonalizationRules::MAX_SCALE, $transform['text']['scale']);
        $this->assertSame(PersonalizationRules::MAX_ROTATION, $transform['text']['rotation']);
        $this->assertSame(PersonalizationRules::defaultTransform(), $transform['image']);
    }

    public function testAValidRotationIsKept(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([[
            'zone_key' => 'name', 'text' => 'Bart',
            'transform' => ['text' => ['rotation' => -45]],
        ]]));

        $this->assertSame(-45.0, $result['zones'][0]['transform']['text']['rotation']);
    }

    /**
     * A zone whose administrator disabled rotation must come out unrotated,
     * whatever the request claims — its slider being hidden is not the
     * enforcement, this is.
     */
    public function testRotationIsForcedToZeroWhereTheZoneForbidsIt(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['allow_rotation' => false]);

        $result = $this->validator->validate($productId, $this->payload([[
            'zone_key' => 'default', 'text' => 'Bart',
            'transform' => ['text' => ['rotation' => 90], 'image' => ['rotation' => -90]],
        ]]));

        $this->assertSame(0.0, $result['zones'][0]['transform']['text']['rotation']);
        $this->assertSame(0.0, $result['zones'][0]['transform']['image']['rotation']);
    }

    /* ------------------------------------------------------------------ */
    /* Text handling                                                       */
    /* ------------------------------------------------------------------ */

    public function testControlCharactersAreStrippedFromTheCustomersText(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['max_text_length' => 40]);

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'default', 'text' => "Jan\r\nde\tJong"],
        ]));

        $this->assertSame('Jan de Jong', $result['zones'][0]['text_value']);
    }

    public function testTheCustomersExactCharactersArePreserved(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['max_text_length' => 60]);

        $text = 'Renée & Jörg <3 "2026"';
        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'default', 'text' => $text],
        ]));

        $this->assertSame($text, $result['zones'][0]['text_value']);
    }

    public function testARejectionNeverEchoesTheSubmittedValue(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['max_text_length' => 3]);

        try {
            $this->validator->validate($productId, $this->payload([
                ['zone_key' => 'default', 'text' => '<script>alert(1)</script>'],
            ]));
            $this->fail('overlong text should have been rejected');
        } catch (PersonalizationValidationException $e) {
            $this->assertStringNotContainsString('<script>', $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* The order snapshot                                                  */
    /* ------------------------------------------------------------------ */

    public function testEachZoneCarriesItsOwnConfigurationSnapshot(): void
    {
        $productId = $this->createTwoViewProduct();

        $result = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart', 'font' => 'georgia'],
            ['zone_key' => 'message', 'text' => 'Bedankt'],
        ]));

        $byKey = array_column($result['zones'], null, 'zone_key');

        $name = $byKey['name']['config_snapshot'];
        $this->assertSame(4, $name['version']);
        $this->assertSame('front', $name['view']['view_key']);
        $this->assertSame('Voorkant', $name['view']['label']);
        $this->assertSame('Naam', $name['zone']['label']);
        $this->assertArrayNotHasKey('label_en', $name['view'], 'since version 4 a snapshot records one label, in the language ordered in');
        $this->assertArrayNotHasKey('label_en', $name['zone']);
        $this->assertTrue($name['zone']['is_required']);
        $this->assertSame(10, $name['zone']['max_text_length']);
        $this->assertSame(PersonalizationFonts::activeKeys(), $name['zone']['allowed_fonts']);
        $this->assertSame(PersonalizationRules::PURCHASE_OPTIONAL, $name['purchase_mode']);
        $this->assertSame('0.00', $name['surcharge_charged']);
        $this->assertArrayHasKey('text_base_height_ratio', $name['render']);

        $message = $byKey['message']['config_snapshot'];
        $this->assertSame('back', $message['view']['view_key']);
        $this->assertSame('7.50', $message['zone']['surcharge']);
        $this->assertSame('7.50', $message['surcharge_charged']);
    }

    /* ------------------------------------------------------------------ */
    /* Cart line identity                                                  */
    /* ------------------------------------------------------------------ */

    public function testDifferentTextInAnyZoneProducesADifferentFingerprint(): void
    {
        $productId = $this->createTwoViewProduct();

        $a = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Hoi'],
        ]));
        $b = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Dag'],
        ]));

        $this->assertNotSame(
            PersonalizationValidator::fingerprint($a),
            PersonalizationValidator::fingerprint($b)
        );
    }

    public function testIdenticalPersonalizationsProduceTheSameFingerprintWhateverTheSubmissionOrder(): void
    {
        $productId = $this->createTwoViewProduct();

        $a = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Hoi'],
        ]));
        $b = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'message', 'text' => 'Hoi'],
            ['zone_key' => 'name', 'text' => 'Bart'],
        ]));

        $this->assertSame(
            PersonalizationValidator::fingerprint($a),
            PersonalizationValidator::fingerprint($b)
        );
    }

    public function testUsingAnExtraZoneProducesADifferentFingerprint(): void
    {
        $productId = $this->createTwoViewProduct();

        $one = $this->validator->validate($productId, $this->payload([['zone_key' => 'name', 'text' => 'Bart']]));
        $two = $this->validator->validate($productId, $this->payload([
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Hoi'],
        ]));

        $this->assertNotSame(
            PersonalizationValidator::fingerprint($one),
            PersonalizationValidator::fingerprint($two)
        );
    }

    public function testADifferentFontProducesADifferentFingerprint(): void
    {
        $productId = $this->createTwoViewProduct();

        $a = $this->validator->validate($productId, $this->payload([['zone_key' => 'name', 'text' => 'Bart', 'font' => 'trirong']]));
        $b = $this->validator->validate($productId, $this->payload([['zone_key' => 'name', 'text' => 'Bart', 'font' => 'georgia']]));

        $this->assertNotSame(
            PersonalizationValidator::fingerprint($a),
            PersonalizationValidator::fingerprint($b)
        );
    }

    public function testADifferentRotationProducesADifferentFingerprint(): void
    {
        $productId = $this->createTwoViewProduct();

        $a = $this->validator->validate($productId, $this->payload([['zone_key' => 'name', 'text' => 'Bart']]));
        $b = $this->validator->validate($productId, $this->payload([[
            'zone_key' => 'name', 'text' => 'Bart', 'transform' => ['text' => ['rotation' => 30]],
        ]]));

        $this->assertNotSame(
            PersonalizationValidator::fingerprint($a),
            PersonalizationValidator::fingerprint($b)
        );
    }

    public function testAnUnpersonalizedLineHasTheEmptyFingerprint(): void
    {
        $this->assertSame('', PersonalizationValidator::fingerprint(null));
    }

    public function testTheFingerprintIgnoresTheSnapshotTimestamp(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);

        $first = $this->validator->validate($productId, $this->payload([['zone_key' => 'default', 'text' => 'Bart']]));
        sleep(1);
        $second = $this->validator->validate($productId, $this->payload([['zone_key' => 'default', 'text' => 'Bart']]));

        $this->assertNotSame(
            $first['zones'][0]['config_snapshot']['captured_at'],
            $second['zones'][0]['config_snapshot']['captured_at']
        );
        $this->assertSame(
            PersonalizationValidator::fingerprint($first),
            PersonalizationValidator::fingerprint($second)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Phase 1 payload compatibility                                       */
    /* ------------------------------------------------------------------ */

    /**
     * A cart saved in a customer's browser before zones existed sends ONE
     * flat zone object. It must still check out — the shape is normalised,
     * not rejected.
     */
    public function testAPhase1FlatPayloadStillValidates(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['max_text_length' => 30]);

        $result = $this->validator->validate($productId, [
            'zone_key' => 'default',
            'text' => 'Bart',
            'transform' => ['text' => ['x' => 0.25, 'y' => 0.75, 'scale' => 1.4]],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['zones']);
        $this->assertSame('Bart', $result['zones'][0]['text_value']);
        $this->assertSame(0.25, $result['zones'][0]['transform']['text']['x']);
        $this->assertSame(0, $result['surcharge_cents']);
        // It still gets a current-generation snapshot: the order is placed now.
        $this->assertSame(4, $result['zones'][0]['config_snapshot']['version']);
    }

    public function testAPhase1FlatPayloadWithoutAZoneKeyUsesTheDefaultZone(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);

        $result = $this->validator->validate($productId, ['text' => 'Bart']);

        $this->assertNotNull($result);
        $this->assertSame(PersonalizationRules::DEFAULT_ZONE_KEY, $result['zones'][0]['zone_key']);
    }

    /* ------------------------------------------------------------------ */
    /* Product no longer personalizable                                    */
    /* ------------------------------------------------------------------ */

    public function testPersonalizationIsRejectedForAProductThatOffersNone(): void
    {
        $productId = $this->createProduct();

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([['zone_key' => 'default', 'text' => 'Bart']]));
    }

    public function testPersonalizationIsRejectedWhenTheProductHasItSwitchedOff(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, [], ['is_enabled' => false]);

        $this->expectException(PersonalizationValidationException::class);
        $this->validator->validate($productId, $this->payload([['zone_key' => 'default', 'text' => 'Bart']]));
    }

    /* ------------------------------------------------------------------ */
    /* The font registry itself                                            */
    /* ------------------------------------------------------------------ */

    public function testTheFontRegistryOnlyEverAnswersWithRealFonts(): void
    {
        $this->assertContains(PersonalizationFonts::FALLBACK, PersonalizationFonts::keys());
        $this->assertSame([], PersonalizationFonts::sanitize(['nope', 42, null, ['x']]));
        $this->assertSame(['trirong'], PersonalizationFonts::sanitize('trirong,nope'));
        // Registry order, never submission order, so stored data is canonical.
        $this->assertSame(
            PersonalizationFonts::sanitize(['georgia', 'trirong']),
            PersonalizationFonts::sanitize(['trirong', 'georgia'])
        );
    }
}
