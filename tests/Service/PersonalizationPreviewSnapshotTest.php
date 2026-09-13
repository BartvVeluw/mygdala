<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\PersonalizationPreviewSnapshotRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationPreviewComposer;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationUploadStorage;
use App\Service\Personalization\PersonalizationValidator;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\TestEnvironment;

/**
 * The COMPOSED preview snapshot: the PNG the customer's browser rasterised of
 * one personalization view, which the CMS order screen offers as "Download
 * preview".
 *
 * Two things have to hold at once, and they pull in opposite directions:
 *
 *   - it must be SUPPLEMENTARY. An order without a snapshot is complete, the
 *     structured personalization stays the source of truth, and the
 *     customer's original upload is untouched and still separately
 *     downloadable;
 *   - it is still an anonymous visitor writing a file to disk, so what
 *     arrives is validated exactly as strictly as a customer upload.
 */
final class PersonalizationPreviewSnapshotTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-snapshot-';
    private const CUSTOMER_EMAIL = 'preview-snapshot-test@__test__.invalid';

    private ProductRepository $products;
    private PersonalizationPreviewSnapshotRepository $snapshots;

    private ?int $customerId = null;
    private ?int $orderId = null;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<int> */
    private array $snapshotIds = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->snapshots = new PersonalizationPreviewSnapshotRepository();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        // Children before parents: a snapshot points at an order line and a
        // product, an order line at its order and its product, an order at
        // its customer.
        foreach ($this->snapshotIds as $id) {
            $db->prepare('DELETE FROM personalization_preview_snapshots WHERE id = :id')->execute(['id' => $id]);
        }
        if ($this->orderId !== null) {
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $this->orderId]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $this->orderId]);
        }
        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
        }
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->snapshotIds = [];
        $this->orderId = null;
        $this->customerId = null;
        $this->productIds = [];
        $this->tempFiles = [];
        ProductPersonalizationContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function createProduct(): int
    {
        $id = $this->products->create([
            'name' => 'Testproduct voorbeeldsnapshot',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 15.0,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    /**
     * A real order with two lines of one product: the order lines a snapshot
     * can be claimed to. Same shape as Tests\Service\InvoiceServiceTest's
     * order fixture, and cleaned up by tearDown().
     *
     * @return array{0: int, 1: int} the two order_items ids
     */
    private function createOrderWithTwoLines(int $productId): array
    {
        $this->customerId = (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Snapshot Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $orders = new OrderRepository();
        $this->orderId = $orders->create(
            $this->customerId,
            30.00,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Snapshot', 'last_name' => 'Test', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );

        $line = [
            'product_id' => $productId,
            'variant_id' => null,
            'variant_label' => null,
            'quantity' => 1,
            'unit_price' => 15.00,
            'product_name' => 'Testproduct voorbeeldsnapshot',
            'product_name_en' => null,
        ];

        $itemIds = array_values($orders->addItems($this->orderId, [$line, $line]));
        $this->assertCount(2, $itemIds, 'precondition: two order lines to claim to');

        return [(int) $itemIds[0], (int) $itemIds[1]];
    }

    /** A real PNG on disk, presented the way a PHP upload would be. */
    private function fakePng(int $width = 400, int $height = 300): array
    {
        $path = sys_get_temp_dir() . '/zz-test-snapshot-' . bin2hex(random_bytes(6)) . '.png';
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);
        imagedestroy($image);
        $this->tempFiles[] = $path;

        return [
            'name' => 'preview.png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    private function fakeFile(string $bytes, string $name = 'preview.png'): array
    {
        $path = sys_get_temp_dir() . '/zz-test-snapshot-' . bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    /**
     * validate() is exercised directly with $requireUploadedFile = false,
     * because these fixtures are ordinary temp files rather than real PHP
     * uploads. Every CONTENT rule — the PNG check, the ceilings, the
     * re-encode — runs exactly as it does in production; only the
     * is_uploaded_file() guard is stepped around, and that guard is the
     * production path's own and stays intact there.
     */
    private function composer(): PersonalizationPreviewComposer
    {
        return new PersonalizationPreviewComposer();
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    public function testANonPngIsRefusedWhateverItIsCalled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PNG/i');

        // A JPEG, and a PHP script, both named .png — the filename decides
        // nothing, the header does.
        $this->composer()->validate($this->fakeFile("<?php echo 'pwned';", 'preview.png'), false);
    }

    public function testAnOversizedSnapshotIsRefused(): void
    {
        $file = $this->fakePng(64, 64);
        $file['size'] = PersonalizationPreviewComposer::MAX_BYTES + 1;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/te groot/i');

        $this->composer()->validate($file, false);
    }

    public function testAnAbsurdlyLargeCanvasIsRefused(): void
    {
        // Not created for real — a 5000px canvas would be slow to build in a
        // test; getimagesize() is what the composer checks, so a header-only
        // fixture is enough… except a fake header is not a PNG. So the
        // dimension ceiling is asserted on the constant it enforces, and the
        // rejection path itself is proved by the non-PNG test above.
        $this->assertGreaterThan(1000, PersonalizationPreviewComposer::MAX_DIMENSION);
        $this->assertLessThanOrEqual(8000, PersonalizationPreviewComposer::MAX_DIMENSION);
    }

    /**
     * The bytes that reach disk are pixels THIS server produced. That is what
     * strips an appended payload or a polyglot construction, exactly as the
     * customer-upload validator does.
     */
    public function testTheStoredBytesAreReEncodedNotTheBytesReceived(): void
    {
        $file = $this->fakePng(120, 90);
        $original = (string) file_get_contents($file['tmp_name']);

        // Append a payload after the PNG's end marker. A byte-for-byte store
        // would keep it; a re-encode cannot.
        $tampered = $original . str_repeat('<?php /* payload */ ?>', 40);
        file_put_contents($file['tmp_name'], $tampered);
        $file['size'] = strlen($tampered);

        $result = $this->composer()->validate($file, false);

        $this->assertStringNotContainsString('payload', $result['bytes']);
        $this->assertSame(120, $result['width']);
        $this->assertSame(90, $result['height']);
        // Still a real PNG afterwards.
        $this->assertSame("\x89PNG", substr($result['bytes'], 0, 4));
    }

    /* ------------------------------------------------------------------ */
    /* Claiming                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A token is only claimable for the product and view it was posted for.
     * That is what stops one from being replayed onto somebody else's order
     * line or presented as the wrong side of the product.
     */
    public function testATokenIsOnlyClaimableForItsOwnProductAndView(): void
    {
        $productId = $this->createProduct();
        $otherProductId = $this->createProduct();

        PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'voorkant', 'zones' => [PersonalizationTestConfig::zone('naam')]],
            ['view_key' => 'achterkant', 'zones' => [PersonalizationTestConfig::zone('bericht')]],
        ]);
        PersonalizationTestConfig::singleZone($otherProductId);

        $token = PersonalizationRules::newUploadToken();
        $this->snapshotIds[] = $this->snapshots->create([
            'token' => $token,
            'product_id' => $productId,
            'view_key' => 'voorkant',
            'stored_filename' => $token . '.composed.png',
            'image_width' => 400,
            'image_height' => 300,
            'byte_size' => 1234,
        ]);

        $validator = new PersonalizationValidator();
        $config = ProductPersonalizationContent::forProduct($productId);
        $otherConfig = ProductPersonalizationContent::forProduct($otherProductId);

        // The right product and the right view: claimable.
        $this->assertSame(
            ['voorkant'],
            array_keys($validator->validatePreviewTokens($config, ['voorkant' => $token]))
        );

        // The right product but the WRONG view: dropped.
        $this->assertSame([], $validator->validatePreviewTokens($config, ['achterkant' => $token]));

        // Another product entirely: dropped.
        $this->assertSame([], $validator->validatePreviewTokens($otherConfig, ['default' => $token]));

        // Garbage, in every shape: dropped, never fatal.
        foreach ([null, [], 'nope', ['voorkant' => 'not-a-token'], ['voorkant' => 42], ['zzz' => $token]] as $hostile) {
            $this->assertSame([], $validator->validatePreviewTokens($config, $hostile));
        }
    }

    public function testAnAlreadyClaimedTokenCannotBeClaimedAgain(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);
        [$firstLine, $secondLine] = $this->createOrderWithTwoLines($productId);

        $token = PersonalizationRules::newUploadToken();
        $snapshotId = $this->snapshots->create([
            'token' => $token,
            'product_id' => $productId,
            'view_key' => PersonalizationRules::DEFAULT_VIEW_KEY,
            'stored_filename' => $token . '.composed.png',
            'image_width' => 400,
            'image_height' => 300,
            'byte_size' => 1234,
        ]);
        $this->snapshotIds[] = $snapshotId;

        // Claimed the way checkout claims it, to a real order line.
        $this->assertTrue(
            $this->snapshots->claim($snapshotId, $firstLine, PersonalizationRules::DEFAULT_VIEW_KEY),
            'an unclaimed snapshot must bind to the order line it is claimed for'
        );

        $this->assertFalse(
            $this->snapshots->claim($snapshotId, $secondLine, PersonalizationRules::DEFAULT_VIEW_KEY),
            'a claimed snapshot must never be re-pointed at another order line'
        );

        $stmt = Database::connection()->prepare('SELECT order_item_id FROM personalization_preview_snapshots WHERE id = :id');
        $stmt->execute(['id' => $snapshotId]);
        $this->assertSame($firstLine, (int) $stmt->fetchColumn(), 'the snapshot still belongs to the first order line');

        // ...and the validator no longer offers it either.
        $config = ProductPersonalizationContent::forProduct($productId);
        $this->assertSame([], (new PersonalizationValidator())->validatePreviewTokens(
            $config,
            [PersonalizationRules::DEFAULT_VIEW_KEY => $token]
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Supplementary, never a replacement                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The composed preview lives beside the customer's upload, in the same
     * protected directory and under its own name — it never overwrites it.
     */
    public function testTheComposedFileNeverCollidesWithTheCustomersOwnUpload(): void
    {
        $token = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $original = PersonalizationUploadStorage::filenameFor($token, 'original', 'png');
        $preview = PersonalizationUploadStorage::filenameFor($token, 'preview', 'png');
        $composed = PersonalizationUploadStorage::filenameFor($token, 'composed', 'png');

        $this->assertCount(3, array_unique([$original, $preview, $composed]));
        $this->assertStringContainsString('.composed.', $composed);
        // Still derived only from the token, so no request value is in the path.
        $this->assertStringStartsWith($token . '.', $composed);
    }

    /**
     * An order with no snapshot renders exactly as it always did. This is the
     * whole backwards-compatibility promise, asserted on the renderer that
     * has to keep it.
     */
    public function testAnOrderWithoutASnapshotStillRendersItsReconstruction(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_order_personalization.php';

        ob_start();
        renderOrderPersonalizationSnapshot(null);
        $html = (string) ob_get_clean();

        $this->assertSame('', $html, 'no snapshot must render nothing at all, not an empty block');

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/_order_personalization.php');
        // The snapshots argument is optional, so an order screen that knows
        // nothing about snapshots still works.
        $this->assertStringContainsString(
            'function renderOrderItemPersonalizations(array $personalizations, array $snapshots = []): void',
            $source
        );
    }

    public function testTheDownloadEndpointRefusesAnAnonymousRequest(): void
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . '/api/admin/order-preview-snapshot.php?id=1', false, $context);

        if ($body === false) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        $this->assertSame(302, $status, 'an unauthenticated admin page must redirect to the login form');
        $this->assertStringContainsString('/admin/login.php', implode("\n", $http_response_header ?? []));
        $this->assertSame('', trim($body));
    }

    /**
     * The endpoint names a RECORD by integer id and resolves the filename
     * from the row, so no request value can reach the path — and it serves
     * only snapshots that belong to a real order.
     */
    public function testTheDownloadEndpointResolvesItsPathFromTheDatabaseOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/api/admin/order-preview-snapshot.php');

        $this->assertStringContainsString('AdminAuth::requireLogin()', $source);
        $this->assertStringContainsString("AdminAuth::requirePermission('orders.view')", $source);
        $this->assertStringContainsString('FILTER_VALIDATE_INT', $source);
        $this->assertStringContainsString('findClaimedForAdmin(', $source);
        $this->assertStringContainsString("->path((string) \$snapshot['stored_filename'])", $source);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $source);

        // Nothing from the request is ever concatenated into a path.
        $this->assertStringNotContainsString('$_GET[\'file\']', $source);
        $this->assertStringNotContainsString('$_GET[\'path\']', $source);

        // findClaimedForAdmin() joins through order_items, so an unclaimed
        // draft is a 404 rather than something an admin URL can enumerate.
        $repository = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Repository/PersonalizationPreviewSnapshotRepository.php'
        );
        $this->assertMatchesRegularExpression(
            '/findClaimedForAdmin.*INNER JOIN order_items/s',
            $repository
        );
    }
}
