<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PersonalizationUploadRepository;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationUploadStorage;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\TestEnvironment;

/**
 * The customer upload endpoint and the public preview endpoint, exercised
 * over REAL HTTP with real multipart bodies.
 *
 * This is the one place where a genuine PHP upload happens, which is exactly
 * what makes it the right place to prove the upload security model: the
 * browser's MIME type and filename are ignored, only real PNG/JPEG content
 * is accepted, SVG is refused, the original never leaves the private store,
 * and no request value can ever become part of a path.
 */
final class PersonalizationUploadHttpTest extends TestCase
{
    private const UPLOAD_ENDPOINT = '/api/personalization-upload.php';
    private const IMAGE_ENDPOINT = '/api/personalization-image.php';
    private const SLUG_PREFIX = 'zz-test-personalization-upload-';

    private ProductRepository $products;
    private ProductPersonalizationRepository $personalization;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<string> */
    private array $tempFiles = [];
    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->personalization = new ProductPersonalizationRepository();
        ProductPersonalizationContent::clearCache();
        $this->clearUploadThrottle();
    }

    /**
     * The upload endpoint has an abuse ceiling (App\Service\ContactRateLimiter),
     * and this class legitimately posts more uploads in a few seconds than any
     * real customer ever would. `contact_rate_limit_hits` is a throttling
     * scratch table that already prunes itself on every check and that no
     * other test reads, so emptying it here costs nothing and keeps these
     * tests independent of how many ran before them.
     *
     * The ceiling itself is exercised deliberately by
     * testTheUploadEndpointHasAnAbuseCeiling().
     */
    private function clearUploadThrottle(): void
    {
        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $uploads = new PersonalizationUploadRepository($db);
        $storage = new PersonalizationUploadStorage();

        foreach ($this->tokens as $token) {
            $row = $uploads->findByToken($token);
            if ($row === null) {
                continue;
            }
            $storage->delete((string) $row['stored_filename']);
            $storage->delete((string) $row['preview_filename']);
            $db->prepare('DELETE FROM personalization_uploads WHERE id = :id')->execute(['id' => $row['id']]);
        }

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tokens = [];
        $this->productIds = [];
        $this->tempFiles = [];
        ProductPersonalizationContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function skipUnlessServerReachable(): void
    {
        if ($this->get('/product.php') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function get(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Posts a genuine multipart/form-data upload — the only way to make PHP
     * populate $_FILES and is_uploaded_file() the way a real browser does.
     *
     * @param array<string, string> $fields
     * @return array{status: int, data: array<string, mixed>}
     */
    private function postUpload(array $fields, ?string $fileContents, string $filename, string $clientMime): array
    {
        $boundary = '----zztest' . bin2hex(random_bytes(8));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        if ($fileContents !== null) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: {$clientMime}\r\n\r\n"
                . $fileContents . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);

        $response = @file_get_contents(TestEnvironment::baseUrl() . self::UPLOAD_ENDPOINT, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        $data = json_decode((string) $response, true);

        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }

    /**
     * A product with one image-capable zone, which is all this endpoint
     * cares about. `zones` lets a test add more — the upload endpoint checks
     * the ONE zone the request names, not the product as a whole.
     *
     * @param array<string, mixed> $overrides
     */
    private function createConfiguredProduct(array $overrides = []): int
    {
        $id = $this->products->create([
            'name' => 'ZZ Upload testproduct',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 11.0,
            'image_path' => null,
            'active' => $overrides['product_active'] ?? true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        $zones = $overrides['zones'] ?? [PersonalizationTestConfig::zone(
            PersonalizationRules::DEFAULT_ZONE_KEY,
            ['allow_image' => $overrides['allow_image'] ?? true, 'max_text_length' => 20]
        )];

        PersonalizationTestConfig::configure(
            $id,
            [[
                'view_key' => PersonalizationRules::DEFAULT_VIEW_KEY,
                // Any path works here: the upload endpoint only needs the
                // configuration to resolve, and the file itself is read by
                // the product page, not by this endpoint.
                'image' => 'assets/images/products/zz-upload-test.png',
                'zones' => $zones,
            ]],
            ['is_enabled' => $overrides['is_enabled'] ?? true]
        );

        return $id;
    }

    private function pngBytes(int $width = 320, int $height = 240): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 210, 70, 60));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function jpegBytes(int $width = 320, int $height = 240): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
        ob_start();
        imagejpeg($image, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function rememberToken(array $result): string
    {
        $this->assertArrayHasKey('token', $result['data'], 'expected an upload token in: ' . json_encode($result['data']));
        $this->tokens[] = $result['data']['token'];

        return $result['data']['token'];
    }

    /* ------------------------------------------------------------------ */
    /* The happy path                                                      */
    /* ------------------------------------------------------------------ */

    public function testAValidPngIsAcceptedAndAnsweredWithATokenOnly(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $result = $this->postUpload(
            ['product_id' => (string) $productId, 'zone_key' => 'default'],
            $this->pngBytes(),
            'mijn logo.png',
            'image/png'
        );

        $this->assertSame(200, $result['status']);
        $token = $this->rememberToken($result);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertSame('mijn logo.png', $result['data']['original_filename']);
        $this->assertSame(320, $result['data']['width']);
        $this->assertSame(240, $result['data']['height']);

        // The response must never leak where the file went.
        $this->assertArrayNotHasKey('stored_filename', $result['data']);
        $this->assertArrayNotHasKey('path', $result['data']);
        $this->assertStringNotContainsString('/storage/', json_encode($result['data']));
        $this->assertStringNotContainsString('/var/www', json_encode($result['data']));
    }

    public function testAValidJpegIsAccepted(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $result = $this->postUpload(
            ['product_id' => (string) $productId],
            $this->jpegBytes(),
            'foto.jpg',
            'image/jpeg'
        );

        $this->assertSame(200, $result['status']);
        $this->rememberToken($result);
    }

    public function testTheUploadIsRecordedUnclaimedAndBoundToItsProduct(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $token = $this->rememberToken($this->postUpload(
            ['product_id' => (string) $productId],
            $this->pngBytes(),
            'logo.png',
            'image/png'
        ));

        $row = (new PersonalizationUploadRepository())->findByToken($token);

        $this->assertNotNull($row);
        $this->assertSame($productId, (int) $row['product_id']);
        $this->assertNull($row['claimed_at'], 'a fresh upload is disposable until an order claims it');
        $this->assertSame('image/png', $row['mime_type']);
    }

    /* ------------------------------------------------------------------ */
    /* Upload security                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * A PHP script named .png and announced as image/png. Everything the
     * browser said is a lie; only the file's own contents decide.
     */
    public function testASpoofedMimeTypeAndExtensionAreRejected(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $result = $this->postUpload(
            ['product_id' => (string) $productId],
            "<?php system(\$_GET['c']); ?>\n",
            'harmless.png',
            'image/png'
        );

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('PNG', (string) ($result['data']['error'] ?? ''));
    }

    /**
     * SVG is intentionally unsupported in Version 1 — it is an XML document
     * that can carry scripts and external references. Refused even when the
     * browser claims it is a PNG.
     */
    public function testSvgIsRejectedInVersionOne(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<script>alert(1)</script><rect width="10" height="10"/></svg>';

        foreach ([['logo.svg', 'image/svg+xml'], ['logo.png', 'image/png']] as [$filename, $mime]) {
            $result = $this->postUpload(['product_id' => (string) $productId], $svg, $filename, $mime);
            $this->assertSame(422, $result['status'], "SVG posted as {$filename}/{$mime} must be refused");
        }
    }

    /**
     * A PNG with a PHP payload appended after the image data — a classic
     * polyglot. The upload is refused or, if the header still parses, the
     * SERVED file is a GD re-encode that cannot contain the payload.
     */
    public function testAPolyglotImageNeverReachesTheBrowserWithItsPayload(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $payload = "<?php system('id'); ?>";
        $result = $this->postUpload(
            ['product_id' => (string) $productId],
            $this->pngBytes() . $payload,
            'polyglot.png',
            'image/png'
        );

        if ($result['status'] !== 200) {
            $this->assertSame(422, $result['status']);
            return;
        }

        $token = $this->rememberToken($result);
        $served = $this->get(self::IMAGE_ENDPOINT . '?token=' . $token);

        $this->assertNotNull($served);
        $this->assertSame(200, $served['status']);
        $this->assertStringNotContainsString($payload, $served['body'], 'the re-encoded preview must not carry the payload');
    }

    public function testAnEmptyFileIsRejected(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $result = $this->postUpload(['product_id' => (string) $productId], '', 'leeg.png', 'image/png');

        $this->assertSame(422, $result['status']);
    }

    public function testAnOversizedUploadIsRejected(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();

        // Real PNG bytes padded past the 5 MB ceiling — large enough to be
        // refused on size, without depending on any php.ini limit.
        $oversized = $this->pngBytes() . str_repeat("\x00", PersonalizationRules::MAX_UPLOAD_BYTES);

        $result = $this->postUpload(['product_id' => (string) $productId], $oversized, 'groot.png', 'image/png');

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('groot', (string) ($result['data']['error'] ?? ''));
    }

    /* ------------------------------------------------------------------ */
    /* Only where personalization is actually offered                      */
    /* ------------------------------------------------------------------ */

    public function testUploadingIsRefusedWhenPersonalizationIsDisabled(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct(['is_enabled' => false]);
        $result = $this->postUpload(['product_id' => (string) $productId], $this->pngBytes(), 'logo.png', 'image/png');

        $this->assertSame(422, $result['status']);
    }

    public function testUploadingIsRefusedWhenImagesAreNotAllowed(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct(['allow_image' => false]);
        $result = $this->postUpload(['product_id' => (string) $productId], $this->pngBytes(), 'logo.png', 'image/png');

        $this->assertSame(422, $result['status']);
    }

    public function testUploadingIsRefusedForAnInactiveProduct(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct(['product_active' => false]);
        $result = $this->postUpload(['product_id' => (string) $productId], $this->pngBytes(), 'logo.png', 'image/png');

        $this->assertSame(404, $result['status']);
    }

    public function testUploadingIsRefusedForAnUnknownProduct(): void
    {
        $this->skipUnlessServerReachable();

        $result = $this->postUpload(['product_id' => '999999999'], $this->pngBytes(), 'logo.png', 'image/png');

        $this->assertSame(404, $result['status']);
    }

    public function testAMissingOrInvalidProductIdIsRejectedBeforeAnythingIsStored(): void
    {
        $this->skipUnlessServerReachable();

        $this->assertSame(400, $this->postUpload([], $this->pngBytes(), 'logo.png', 'image/png')['status']);
        $this->assertSame(400, $this->postUpload(['product_id' => 'abc'], $this->pngBytes(), 'logo.png', 'image/png')['status']);
    }

    /**
     * A product can now have several zones with different rules, so the
     * endpoint must judge the ONE zone the request names — not the product.
     * Uploading into a text-only zone is refused even though the product's
     * other zone happily accepts images.
     */
    public function testUploadingIsJudgedPerZoneNotPerProduct(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct(['zones' => [
            PersonalizationTestConfig::zone('logo', ['allow_text' => false, 'allow_image' => true]),
            PersonalizationTestConfig::zone('naam', ['allow_text' => true, 'allow_image' => false]),
        ]]);

        $allowed = $this->postUpload(
            ['product_id' => (string) $productId, 'zone_key' => 'logo'],
            $this->pngBytes(),
            'logo.png',
            'image/png'
        );
        $this->assertSame(200, $allowed['status']);
        $this->rememberToken($allowed);

        $refused = $this->postUpload(
            ['product_id' => (string) $productId, 'zone_key' => 'naam'],
            $this->pngBytes(),
            'logo.png',
            'image/png'
        );
        $this->assertSame(422, $refused['status']);

        $unknownZone = $this->postUpload(
            ['product_id' => (string) $productId, 'zone_key' => 'bestaat-niet'],
            $this->pngBytes(),
            'logo.png',
            'image/png'
        );
        $this->assertSame(422, $unknownZone['status']);
    }

    /**
     * The endpoint writes files to disk for anonymous callers, so it needs a
     * ceiling. Exercised with INVALID uploads on purpose: the throttle runs
     * before any file is validated or stored, so this proves the limit
     * without creating thirty files to clean up afterwards.
     */
    public function testTheUploadEndpointHasAnAbuseCeiling(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $throttled = false;

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $result = $this->postUpload(
                ['product_id' => (string) $productId],
                'not an image at all',
                'spam.png',
                'image/png'
            );

            if ($result['status'] === 429) {
                $throttled = true;
                break;
            }

            $this->assertSame(422, $result['status'], 'until the ceiling is hit, the file itself is what fails');
        }

        $this->assertTrue($throttled, 'the upload endpoint must refuse an unbounded stream of uploads');

        // Leave the counter clean for whatever runs next.
        $this->clearUploadThrottle();
    }

    public function testTheUploadEndpointRejectsGet(): void
    {
        $this->skipUnlessServerReachable();

        $response = $this->get(self::UPLOAD_ENDPOINT);

        $this->assertNotNull($response);
        $this->assertSame(405, $response['status']);
    }

    /* ------------------------------------------------------------------ */
    /* The public preview endpoint                                         */
    /* ------------------------------------------------------------------ */

    public function testThePreviewEndpointServesTheReEncodedCopyNeverTheOriginal(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $originalBytes = $this->jpegBytes(1800, 1200);
        $token = $this->rememberToken($this->postUpload(
            ['product_id' => (string) $productId],
            $originalBytes,
            'groot.jpg',
            'image/jpeg'
        ));

        $served = $this->get(self::IMAGE_ENDPOINT . '?token=' . $token);
        $this->assertNotNull($served);
        $this->assertSame(200, $served['status']);
        $this->assertNotSame('', $served['body']);

        $row = (new PersonalizationUploadRepository())->findByToken($token);
        $storage = new PersonalizationUploadStorage();

        $storedOriginal = (string) file_get_contents($storage->path((string) $row['stored_filename']));

        $this->assertSame($originalBytes, $storedOriginal, 'the customer original must be stored untouched');
        $this->assertNotSame($storedOriginal, $served['body'], 'the served preview must not be the original file');
        $this->assertNotSame(
            (string) $row['stored_filename'],
            (string) $row['preview_filename'],
            'original and preview are two separate files'
        );
    }

    public function testTheOriginalIsStoredOutsideTheWebRoot(): void
    {
        $this->skipUnlessServerReachable();

        $storageDir = realpath((new PersonalizationUploadStorage())->directory());
        $projectRoot = realpath(dirname(__DIR__, 2));

        $this->assertNotFalse($storageDir);
        $this->assertNotFalse($projectRoot);
        $this->assertStringStartsNotWith(
            $projectRoot . DIRECTORY_SEPARATOR,
            $storageDir,
            'customer uploads must not live anywhere the web server can serve them'
        );
    }

    public function testAMalformedTokenNeverReachesTheFilesystem(): void
    {
        $this->skipUnlessServerReachable();

        foreach ([
            '../../../etc/passwd',
            '..%2f..%2fetc%2fpasswd',
            'abc',
            '',
            str_repeat('a', 31),
            str_repeat('a', 32) . '.png',
        ] as $token) {
            $response = $this->get(self::IMAGE_ENDPOINT . '?token=' . rawurlencode($token));

            $this->assertNotNull($response);
            $this->assertSame(400, $response['status'], "token '{$token}' must be refused on shape alone");
            $this->assertStringNotContainsString('root:', $response['body']);
        }
    }

    public function testAnUnknownButWellFormedTokenIsAPlainNotFound(): void
    {
        $this->skipUnlessServerReachable();

        $response = $this->get(self::IMAGE_ENDPOINT . '?token=' . str_repeat('f', 32));

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        // Never a stack trace, a path or a class name.
        $this->assertStringNotContainsString('/var/www', $response['body']);
        $this->assertStringNotContainsString('storage', $response['body']);
    }

    /**
     * A missing file on disk (deleted by hand, a failed sync) must be a clean
     * 404 for the customer, not a fatal error on the product page.
     */
    public function testAMissingPreviewFileFailsGracefully(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createConfiguredProduct();
        $token = $this->rememberToken($this->postUpload(
            ['product_id' => (string) $productId],
            $this->pngBytes(),
            'logo.png',
            'image/png'
        ));

        $row = (new PersonalizationUploadRepository())->findByToken($token);
        $storage = new PersonalizationUploadStorage();
        $storage->delete((string) $row['preview_filename']);

        $response = $this->get(self::IMAGE_ENDPOINT . '?token=' . $token);

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
    }
}
