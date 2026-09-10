<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderItemPersonalizationRepository;
use App\Repository\OrderRepository;
use App\Repository\PersonalizationUploadRepository;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationValidator;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;

/**
 * Personalization as ORDER DATA, against the real dev database — the same
 * convention and reasoning as tests/Repository/OrderSnapshotIntegrationTest.php,
 * extended to the personalization half of an order line.
 *
 * The property under test throughout is immutability: once an order exists,
 * nothing an administrator does to the product afterwards — renaming a zone,
 * moving the engraving area, changing a surcharge, deleting a whole view,
 * switching personalization off, deleting the product — may change what the
 * customer submitted or what they were charged.
 *
 * Everything created here uses an obviously-fake slug/customer email so it
 * can never collide with real content; tearDown() removes it all.
 */
final class OrderPersonalizationIntegrationTest extends TestCase
{
    private const SLUG_PREFIX = '__test_order_personalization_';
    private const CUSTOMER_EMAIL = 'order-personalization@__test__.invalid';

    private ?int $productId = null;
    private ?int $customerId = null;
    private ?int $orderId = null;
    /** @var list<int> */
    private array $uploadIds = [];

    protected function setUp(): void
    {
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        if ($this->orderId !== null) {
            $db->prepare('DELETE FROM order_refunds WHERE order_id = :id')->execute(['id' => $this->orderId]);
            $db->prepare(
                'DELETE oip FROM order_item_personalizations oip
                 INNER JOIN order_items oi ON oi.id = oip.order_item_id
                 WHERE oi.order_id = :id'
            )->execute(['id' => $this->orderId]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $this->orderId]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $this->orderId]);
        }
        foreach ($this->uploadIds as $id) {
            $db->prepare('DELETE FROM personalization_uploads WHERE id = :id')->execute(['id' => $id]);
        }
        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
        }
        if ($this->productId !== null) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
        }

        $this->orderId = null;
        $this->customerId = null;
        $this->productId = null;
        $this->uploadIds = [];
        ProductPersonalizationContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A keychain personalized on two sides: a required name on the front (no
     * surcharge), an optional logo on the front (€5,00), an optional message
     * on the back (€7,50).
     */
    private function createConfiguredProduct(): int
    {
        $this->productId = (new ProductRepository())->create([
            'name' => 'Houten sleutelhanger',
            'name_en' => 'Wooden keychain',
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)) . '__',
            'description' => null,
            'description_en' => null,
            'price' => 25.00,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 15,
            'requires_parcel' => false,
        ]);

        PersonalizationTestConfig::configure($this->productId, [
            [
                'view_key' => 'front', 'label' => 'Voorkant', 'label_en' => 'Front',
                'zones' => [
                    PersonalizationTestConfig::zone('name', [
                        'label' => 'Naam', 'allow_image' => false, 'is_required' => true,
                        'max_text_length' => 20,
                        'allowed_fonts' => ['trirong', 'georgia'], 'default_font' => 'trirong',
                    ]),
                    PersonalizationTestConfig::zone('logo', [
                        'label' => 'Logo', 'allow_text' => false, 'surcharge_cents' => 500,
                    ]),
                ],
            ],
            [
                'view_key' => 'back', 'label' => 'Achterkant', 'label_en' => 'Back',
                'zones' => [
                    PersonalizationTestConfig::zone('message', [
                        'label' => 'Bericht', 'allow_image' => false, 'surcharge_cents' => 750,
                    ]),
                ],
            ],
        ]);

        return $this->productId;
    }

    private function createUpload(int $productId): string
    {
        $token = PersonalizationRules::newUploadToken();
        $this->uploadIds[] = (new PersonalizationUploadRepository())->create([
            'token' => $token,
            'product_id' => $productId,
            'stored_filename' => $token . '.orig.png',
            'preview_filename' => $token . '.preview.png',
            'original_filename' => 'mijn-logo.png',
            'mime_type' => 'image/png',
            'image_width' => 640,
            'image_height' => 480,
            'byte_size' => 20480,
        ]);

        return $token;
    }

    /**
     * Places an order exactly the way api/checkout.php does: validate every
     * personalization server-side, price the line in whole cents from the
     * product's own price plus the validated surcharge, then write the line
     * and its per-zone personalization rows in one transaction.
     *
     * @param list<array{qty:int, personalization:array<string,mixed>|null}> $lines
     * @return list<int> the new order_items ids
     */
    private function placeOrder(int $productId, array $lines, int $basePriceCents = 2500): array
    {
        $this->customerId = (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Personalisatie Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $address = [
            'first_name' => 'Personalisatie', 'last_name' => 'Test', 'company' => null,
            'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
            'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
        ];

        $items = [];
        $subtotalCents = 0;
        foreach ($lines as $line) {
            $surchargeCents = $line['personalization']['surcharge_cents'] ?? 0;
            $unitCents = $basePriceCents + $surchargeCents;
            $subtotalCents += $unitCents * $line['qty'];

            $items[] = [
                'product_id' => $productId,
                'variant_id' => null,
                'variant_label' => null,
                'quantity' => $line['qty'],
                'unit_price' => Money::format($unitCents),
                'base_unit_price' => Money::format($basePriceCents),
                'personalization_surcharge' => Money::format($surchargeCents),
                'product_name' => 'Houten sleutelhanger',
                'product_name_en' => 'Wooden keychain',
            ];
        }

        $orders = new OrderRepository();
        $this->orderId = $orders->create(
            $this->customerId,
            Money::format($subtotalCents),
            '0.00',
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            $address,
            null
        );

        $itemIds = $orders->addItems($this->orderId, $items);

        $itemPersonalizations = new OrderItemPersonalizationRepository();
        $uploads = new PersonalizationUploadRepository();

        foreach ($lines as $index => $line) {
            if ($line['personalization'] === null) {
                continue;
            }

            foreach ($line['personalization']['zones'] as $zone) {
                $itemPersonalizations->create($itemIds[$index], [
                    'zone_key' => $zone['zone_key'],
                    'view_key' => $zone['view_key'],
                    'upload_id' => $zone['upload_id'],
                    'text_value' => $zone['text_value'],
                    'font_key' => $zone['font_key'],
                    'font_label' => $zone['font_label'],
                    'font_stack' => $zone['font_stack'],
                    'font_file_path' => $zone['font_file_path'],
                    'surcharge_cents' => $zone['surcharge_cents'],
                    'transform' => $zone['transform'],
                    'config_snapshot' => $zone['config_snapshot'],
                ]);

                if ($zone['upload_id'] !== null) {
                    $uploads->markClaimed($zone['upload_id']);
                }
            }
        }

        return $itemIds;
    }

    /** @param array<int, array<string, mixed>> $zones */
    private function validate(int $productId, array $zones): array
    {
        return (new PersonalizationValidator())->validate($productId, ['zones' => $zones]);
    }

    /** @return array<string, array<string, mixed>> the order's personalization rows keyed by zone */
    private function storedZones(): array
    {
        $grouped = (new OrderItemPersonalizationRepository())->findByOrderIdGrouped($this->orderId);
        $rows = [];
        foreach ($grouped as $lineRows) {
            foreach ($lineRows as $row) {
                $rows[(string) $row['zone_key']] = $row;
            }
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Persistence                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * An order records the font it was engraved in AND its own copy of what
     * that key rendered as — label, CSS stack and (for an uploaded face) the
     * file. Without that copy, editing the shop's font library would change
     * what a historical order shows.
     */
    public function testAnOrderStoresItsOwnCopyOfTheFont(): void
    {
        $productId = $this->createConfiguredProduct();

        $personalization = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart', 'font' => PersonalizationFonts::fallbackKey()],
        ]);

        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $personalization]]);

        $stored = $this->storedZones()['name'];
        $expected = PersonalizationFonts::fallbackKey();

        $this->assertSame($expected, $stored['font_key']);
        $this->assertSame(PersonalizationFonts::label($expected), $stored['font_label']);
        $this->assertSame(PersonalizationFonts::stack($expected), $stored['font_stack']);
        // A built-in family has no file; an uploaded one would.
        $this->assertNull($stored['font_file_path']);

        // ...and the immutable snapshot carries the same facts.
        $snapshot = json_decode((string) $stored['config_snapshot_json'], true);
        $this->assertSame($expected, $snapshot['font']['key']);
        $this->assertSame(PersonalizationFonts::label($expected), $snapshot['font']['label']);
    }

    /**
     * The guard that would have caught the real omission this test file was
     * modelling: api/checkout.php has to hand the repository EVERY field the
     * validator produces, not a subset. A field the validator computes and
     * checkout silently drops is invisible until an order is inspected.
     */
    public function testCheckoutWritesEveryPersonalizationFieldTheValidatorProduces(): void
    {
        $checkout = (string) file_get_contents(dirname(__DIR__, 2) . '/api/checkout.php');

        foreach ([
            'zone_key',
            'view_key',
            'upload_id',
            'text_value',
            'font_key',
            'font_label',
            'font_stack',
            'font_file_path',
            'surcharge_cents',
            'transform',
            'config_snapshot',
        ] as $field) {
            $this->assertStringContainsString(
                "'" . $field . "' => \$zone['" . $field . "']",
                $checkout,
                'api/checkout.php must persist ' . $field
            );
        }
    }

    public function testEveryPersonalizedZoneBecomesItsOwnOrderRow(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart', 'font' => 'georgia'],
            ['zone_key' => 'message', 'text' => 'Bedankt!'],
        ]);

        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $zones = $this->storedZones();

        $this->assertCount(2, $zones);
        $this->assertSame('Bart', $zones['name']['text_value']);
        $this->assertSame('georgia', $zones['name']['font_key']);
        $this->assertSame('front', $zones['name']['view_key']);
        $this->assertSame('Bedankt!', $zones['message']['text_value']);
        $this->assertSame('back', $zones['message']['view_key']);
    }

    public function testTheSurchargeActuallyChargedIsStoredPerZoneAndOnTheLine(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Bedankt!'],
        ]);

        $this->placeOrder($productId, [['qty' => 2, 'personalization' => $validated]]);

        $zones = $this->storedZones();
        $this->assertSame('0.00', (string) $zones['name']['surcharge']);
        $this->assertSame('7.50', (string) $zones['message']['surcharge']);

        $item = (new OrderRepository())->findItems($this->orderId)[0];
        $this->assertSame('25.00', (string) $item['base_unit_price']);
        $this->assertSame('7.50', (string) $item['personalization_surcharge']);
        $this->assertSame('32.50', (string) $item['unit_price'], 'unit_price stays the authoritative line price');
    }

    /**
     * The whole order must add up exactly, in cents — the reason personalization
     * pricing never goes through a float.
     */
    public function testTheOrderTotalIsExactToTheCent(): void
    {
        $productId = $this->createConfiguredProduct();
        $token = $this->createUpload($productId);

        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token],
            ['zone_key' => 'message', 'text' => 'Bedankt!'],
        ]);

        $this->assertSame(1250, $validated['surcharge_cents']);
        $this->placeOrder($productId, [['qty' => 3, 'personalization' => $validated]]);

        $order = (new OrderRepository())->findById($this->orderId);
        // (25.00 + 12.50) * 3
        $this->assertSame('112.50', (string) $order['total']);
    }

    public function testAnUnusedOptionalZoneAddsNothingToTheLine(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [['zone_key' => 'name', 'text' => 'Bart']]);

        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $item = (new OrderRepository())->findItems($this->orderId)[0];
        $this->assertSame('0.00', (string) $item['personalization_surcharge']);
        $this->assertSame('25.00', (string) $item['unit_price']);
    }

    public function testTheUploadedFileIsRetainedAndItsMetadataIsReadableFromTheOrder(): void
    {
        $productId = $this->createConfiguredProduct();
        $token = $this->createUpload($productId);

        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token],
        ]);

        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $logo = $this->storedZones()['logo'];
        $this->assertSame($token, $logo['upload_token']);
        $this->assertSame('mijn-logo.png', $logo['original_filename']);
        $this->assertSame('image/png', $logo['mime_type']);
        $this->assertSame(640, (int) $logo['image_width']);
    }

    public function testOrderingClaimsTheUploadSoItCanNeverBeSweptAway(): void
    {
        $productId = $this->createConfiguredProduct();
        $token = $this->createUpload($productId);

        $uploads = new PersonalizationUploadRepository();
        $this->assertNull($uploads->findByToken($token)['claimed_at']);

        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token],
        ]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $claimed = $uploads->findByToken($token);
        $this->assertNotNull($claimed['claimed_at']);
        $this->assertFalse($uploads->deleteUnclaimed((int) $claimed['id']));
        $this->assertNotNull($uploads->findByToken($token));
    }

    public function testTheTransformIsStoredPerZoneAsResolutionIndependentNumbers(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart', 'transform' => ['text' => ['x' => 0.25, 'y' => 0.75, 'scale' => 1.4, 'rotation' => -30]]],
            ['zone_key' => 'message', 'text' => 'Hoi', 'transform' => ['text' => ['x' => 0.9, 'y' => 0.1, 'scale' => 0.8]]],
        ]);

        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $zones = $this->storedZones();

        $name = json_decode((string) $zones['name']['transform_json'], true);
        $this->assertSame(0.25, $name['text']['x']);
        $this->assertSame(1.4, $name['text']['scale']);
        // assertEquals: a whole-degree rotation survives the JSON round trip as an int.
        $this->assertEquals(-30.0, $name['text']['rotation']);
        $this->assertArrayHasKey('image', $name);

        $message = json_decode((string) $zones['message']['transform_json'], true);
        $this->assertSame(0.9, $message['text']['x']);
        $this->assertSame(0.8, $message['text']['scale']);
    }

    /* ------------------------------------------------------------------ */
    /* Immutability                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The specification's own scenario, extended to Phase 2: next month the
     * engraving area moves, the surcharge changes, the labels change — and an
     * existing order still describes exactly what was submitted and charged.
     */
    public function testLaterProductChangesNeverRewriteAnExistingOrder(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Bedankt!'],
        ]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        // The owner reworks the product completely.
        $repository = new ProductPersonalizationRepository();
        $stored = $repository->findForProduct($productId);
        foreach ($stored['views'] as $view) {
            foreach ($view['zones'] as $zone) {
                $repository->updateZone((int) $zone['id'], PersonalizationTestConfig::zone(
                    (string) $zone['zone_key'],
                    [
                        'label' => 'Heel andere naam',
                        'max_text_length' => 3,
                        'surcharge_cents' => 9999,
                        'area_x' => 5.0, 'area_y' => 5.0, 'area_width' => 90.0, 'area_height' => 90.0,
                    ]
                ));
            }
        }
        ProductPersonalizationContent::clearCache();

        $zones = $this->storedZones();

        $this->assertSame('Bart', $zones['name']['text_value']);
        $this->assertSame('7.50', (string) $zones['message']['surcharge'], 'what was charged must not follow the new price');

        $snapshot = json_decode((string) $zones['message']['config_snapshot_json'], true);
        $this->assertSame('Bericht', $snapshot['zone']['label'], 'the label in force at purchase must survive');
        $this->assertSame('7.50', $snapshot['zone']['surcharge']);
        $this->assertSame('7.50', $snapshot['surcharge_charged']);
        $this->assertSame(30, $snapshot['zone']['max_text_length']);
        $this->assertEquals(25.0, $snapshot['zone']['area']['x'], 'the original engraving area must survive');
        $this->assertSame('Achterkant', $snapshot['view']['label']);
    }

    public function testDeletingAWholeViewLeavesItsOrdersIntact(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Bedankt!'],
        ]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $repository = new ProductPersonalizationRepository();
        $stored = $repository->findForProduct($productId);
        foreach ($stored['views'] as $view) {
            if ($view['view_key'] === 'back') {
                $repository->deleteView((int) $view['id']);
            }
        }
        ProductPersonalizationContent::clearCache();

        $zones = $this->storedZones();
        $this->assertArrayHasKey('message', $zones);
        $this->assertSame('Bedankt!', $zones['message']['text_value']);
        $this->assertSame('back', $zones['message']['view_key']);
    }

    public function testSwitchingPersonalizationOffNeverHidesAnExistingOrdersPersonalization(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [['zone_key' => 'name', 'text' => 'Inge']]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        (new ProductPersonalizationRepository())->saveSettings($productId, [
            'is_enabled' => false, 'instructions' => null, 'instructions_en' => null,
        ]);
        ProductPersonalizationContent::clearCache();

        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
        $this->assertSame('Inge', $this->storedZones()['name']['text_value']);
    }

    public function testDeletingTheProductLeavesTheOrdersPersonalizationIntact(): void
    {
        $productId = $this->createConfiguredProduct();
        $token = $this->createUpload($productId);
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token],
        ]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
        $this->productId = null;

        $zones = $this->storedZones();
        $this->assertCount(2, $zones);
        $this->assertSame('Bart', $zones['name']['text_value']);
        $this->assertSame('mijn-logo.png', $zones['logo']['original_filename']);
        $this->assertNotNull($zones['name']['config_snapshot_json']);
    }

    /* ------------------------------------------------------------------ */
    /* Separate lines                                                      */
    /* ------------------------------------------------------------------ */

    public function testTwoDifferentPersonalizationsOfOneProductAreTwoOrderLines(): void
    {
        $productId = $this->createConfiguredProduct();

        $itemIds = $this->placeOrder($productId, [
            ['qty' => 1, 'personalization' => $this->validate($productId, [['zone_key' => 'name', 'text' => 'Bart']])],
            ['qty' => 1, 'personalization' => $this->validate($productId, [['zone_key' => 'name', 'text' => 'Inge']])],
        ]);

        $this->assertCount(2, $itemIds);

        $grouped = (new OrderItemPersonalizationRepository())->findByOrderIdGrouped($this->orderId);
        $this->assertCount(2, $grouped, 'each personalized line keeps its own personalization');

        $texts = [];
        foreach ($grouped as $rows) {
            $texts[] = (string) $rows[0]['text_value'];
        }
        sort($texts);
        $this->assertSame(['Bart', 'Inge'], $texts);
    }

    /**
     * Two lines that differ only in a zone the other one did not use are
     * still two different purchases.
     */
    public function testLinesDifferingOnlyInAnExtraZoneStaySeparate(): void
    {
        $productId = $this->createConfiguredProduct();
        $validator = new PersonalizationValidator();

        $one = $validator->validate($productId, ['zones' => [['zone_key' => 'name', 'text' => 'Bart']]]);
        $two = $validator->validate($productId, ['zones' => [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Hoi'],
        ]]);

        $this->assertNotSame(
            PersonalizationValidator::fingerprint($one),
            PersonalizationValidator::fingerprint($two)
        );

        $itemIds = $this->placeOrder($productId, [
            ['qty' => 1, 'personalization' => $one],
            ['qty' => 1, 'personalization' => $two],
        ]);

        $this->assertCount(2, $itemIds);
        $grouped = (new OrderItemPersonalizationRepository())->findByOrderIdGrouped($this->orderId);
        $this->assertCount(1, $grouped[$itemIds[0]]);
        $this->assertCount(2, $grouped[$itemIds[1]]);
    }

    /* ------------------------------------------------------------------ */
    /* Orders without personalization                                      */
    /* ------------------------------------------------------------------ */

    public function testAnOrderWithoutPersonalizationSimplyHasNone(): void
    {
        $productId = $this->createConfiguredProduct();

        $this->placeOrder($productId, [['qty' => 2, 'personalization' => null]]);

        $this->assertSame([], (new OrderItemPersonalizationRepository())->findByOrderIdGrouped($this->orderId));

        $items = (new OrderRepository())->findItems($this->orderId);
        $this->assertCount(1, $items);
        $this->assertSame(2, (int) $items[0]['quantity']);
        $this->assertSame('25.00', (string) $items[0]['unit_price']);
        $this->assertArrayHasKey('id', $items[0], 'the admin page needs the line id to attach personalization');
    }

    /**
     * An order line written before personalization surcharges existed has no
     * base/surcharge columns at all. It must still read correctly.
     */
    public function testAnOrderLineWithoutPriceBreakdownColumnsStillReads(): void
    {
        $productId = $this->createConfiguredProduct();
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => null]]);

        $itemId = (int) (new OrderRepository())->findItems($this->orderId)[0]['id'];
        Database::connection()
            ->prepare('UPDATE order_items SET base_unit_price = NULL, personalization_surcharge = NULL WHERE id = :id')
            ->execute(['id' => $itemId]);

        $item = (new OrderRepository())->findItems($this->orderId)[0];

        $this->assertNull($item['base_unit_price']);
        $this->assertNull($item['personalization_surcharge']);
        $this->assertSame('25.00', (string) $item['unit_price']);
    }

    public function testDeletingTheOrderRemovesItsPersonalizationRows(): void
    {
        $productId = $this->createConfiguredProduct();
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'message', 'text' => 'Hoi'],
        ]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $db = Database::connection();
        $countStmt = $db->prepare(
            'SELECT COUNT(*) FROM order_item_personalizations oip
             INNER JOIN order_items oi ON oi.id = oip.order_item_id
             WHERE oi.order_id = :id'
        );
        $countStmt->execute(['id' => $this->orderId]);
        $this->assertSame(2, (int) $countStmt->fetchColumn());

        $orderId = $this->orderId;
        $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
        $this->orderId = null;

        $countStmt->execute(['id' => $orderId]);
        $this->assertSame(0, (int) $countStmt->fetchColumn());
    }

    /* ------------------------------------------------------------------ */
    /* The admin file lookup                                               */
    /* ------------------------------------------------------------------ */

    public function testTheAdminFileLookupResolvesStoredFilenamesFromTheDatabaseOnly(): void
    {
        $productId = $this->createConfiguredProduct();
        $token = $this->createUpload($productId);
        $validated = $this->validate($productId, [
            ['zone_key' => 'name', 'text' => 'Bart'],
            ['zone_key' => 'logo', 'upload_token' => $token],
        ]);
        $this->placeOrder($productId, [['qty' => 1, 'personalization' => $validated]]);

        $repository = new OrderItemPersonalizationRepository();
        $personalizationId = (int) $this->storedZones()['logo']['id'];

        $record = $repository->findWithUploadForAdmin($personalizationId);

        $this->assertNotNull($record);
        $this->assertSame($this->orderId, (int) $record['order_id']);
        $this->assertSame($token . '.orig.png', $record['stored_filename']);
        $this->assertSame($token . '.preview.png', $record['preview_filename']);

        $this->assertNull($repository->findWithUploadForAdmin(999999999), 'an unknown id must resolve to nothing');
    }
}
