<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\OrderFieldRepository;
use App\Repository\OrderFieldUploadRepository;
use App\Service\OrderFields\OrderFieldUploadPolicy;
use App\Service\OrderFields\OrderFields;
use App\Service\OrderFields\OrderFieldUploadStorage;
use App\Service\OrderFields\ProductOrderFieldEditor;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\ShopStockFixture;
use Tests\Support\TestEnvironment;

/**
 * The customer's picture for an "Afbeelding uploaden" order question over
 * the real Apache of php_test and php_cms (MODULES.md "Bestelvelden";
 * TESTING.md, "De HTTP-tier").
 *
 * OrderFieldImageUploadTest proves the upload's rules in-process, with a
 * folder of its own. What only the deployment shows is proven here: a
 * genuine multipart POST through Apache and mod_php, the answer that names
 * no file, the ModuleGuard's 404 with the Shop off, the stored file out of
 * reach of any URL (.htaccess and the storage outside the webroot), and the
 * admin file route that serves it only to a signed-in account with
 * orders.view.
 *
 * FILES. The storage folder is created by whoever first needs it. Apache
 * (www-data) must be able to write it, so this class never lets the root
 * PHPUnit process create it: the storage is only opened in-process after an
 * upload over HTTP has made it. Every file and row a test made is removed in
 * tearDown().
 */
final class OrderFieldUploadHttpTest extends TestCase
{
    private const ENDPOINT = '/api/order-field-upload.php';
    private const ADMIN_FILE = '/api/admin/order-field-upload.php';

    private ShopStockFixture $shop;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is needed to make test pictures.');
        }

        $this->shop = new ShopStockFixture();
        $this->accounts = new AdminTestSession();

        // The upload endpoint's abuse ceiling (30 per ten minutes) would
        // count every run of this class against the next. The throttle table
        // is scratch data no test reads (PersonalizationUploadHttpTest).
        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $rows = [];
        foreach ($this->productIds as $id) {
            $statement = $db->prepare('SELECT storage_name, extension FROM order_field_uploads WHERE product_id = :id');
            $statement->execute(['id' => $id]);
            $rows = array_merge($rows, $statement->fetchAll());
        }
        if ($rows !== []) {
            // An upload over HTTP made the folder, so opening it creates nothing.
            $storage = new OrderFieldUploadStorage();
            foreach ($rows as $row) {
                $storage->delete((string) $row['storage_name'], (string) $row['extension']);
            }
        }

        // Claimed rows first (RESTRICT on their order line), then orders,
        // temporary rows and the products.
        $this->shop->cleanUp();
        $this->productIds = [];
        $this->accounts->forget();
        ShopLocalization::clearCache();
    }

    public function testAPictureIsAcceptedAndAnsweredWithATokenAndNoFile(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Http foto');

        $result = $this->upload(TestEnvironment::baseUrl(), $product, $field, $this->jpeg(), 'Luna.jpg', 'image/jpeg');

        $this->assertSame(200, $result['status'], $result['body']);
        $this->assertSame(['token', 'filename', 'width', 'height', 'size'], array_keys($result['data']));
        $this->assertTrue(OrderFieldUploadPolicy::isToken($result['data']['token']));
        $this->assertSame('Luna.jpg', $result['data']['filename']);
        $this->assertSame([64, 48], [$result['data']['width'], $result['data']['height']]);

        $row = $this->row($result['data']['token']);
        $this->assertSame($product, (int) $row['product_id']);
        $this->assertNull($row['claimed_at']);
        $this->assertStringNotContainsString((string) $row['storage_name'], $result['body'], 'the stored name never leaves the server');
        $this->assertStringNotContainsString('storage', $result['body']);
        $this->assertStringNotContainsString('/var/www', $result['body']);
        $this->assertSame('private, no-store', $result['headers']['cache-control'] ?? null);
    }

    public function testAnSvgIsRefusedWhateverItIsCalled(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Http svg');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';

        foreach ([['logo.svg', 'image/svg+xml'], ['logo.jpg', 'image/jpeg']] as [$name, $mime]) {
            $result = $this->upload(TestEnvironment::baseUrl(), $product, $field, $svg, $name, $mime);

            $this->assertSame(422, $result['status'], $name . ': ' . $result['body']);
            $this->assertSame('type', $result['data']['reason'] ?? null, $name);
            $this->assertArrayNotHasKey('token', $result['data']);
        }

        $this->assertSame(0, $this->uploadCount($product), 'nothing stored');
    }

    public function testWithTheShopOffTheEndpointIsNotFoundAndStoresNothing(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        [$product, $field] = $this->imageProduct('ZZ Http shop uit');

        $result = $this->upload(TestEnvironment::cmsOnlyBaseUrl(), $product, $field, $this->jpeg(), 'luna.jpg', 'image/jpeg');

        $this->assertSame(404, $result['status']);
        $this->assertArrayNotHasKey('token', $result['data']);
        $this->assertSame(0, $this->uploadCount($product));
        $this->assertSame(404, $this->request(TestEnvironment::cmsOnlyBaseUrl(), self::ADMIN_FILE . '?id=1')['status'], 'the admin route too');
    }

    public function testTheStoredFileIsOutOfReachOfAnyUrl(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Http buiten bereik');
        $result = $this->upload(TestEnvironment::baseUrl(), $product, $field, $this->jpeg(), 'luna.jpg', 'image/jpeg');
        $this->assertSame(200, $result['status'], $result['body']);
        $row = $this->row($result['data']['token']);

        $path = (new OrderFieldUploadStorage())->path((string) $row['storage_name'], (string) $row['extension'], OrderFieldUploadStorage::ORIGINAL);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $root = (string) realpath(dirname(__DIR__, 2));
        $this->assertStringStartsNotWith($root . '/', (string) realpath($path), 'outside the webroot');

        $name = $row['storage_name'] . '.orig.' . $row['extension'];
        foreach ([
            '/storage/order-field-uploads/' . $name,
            '/storage/' . $name,
            '/../storage/order-field-uploads/' . $name,
            '/%2e%2e/storage/order-field-uploads/' . $name,
            '/order-field-uploads/' . $name,
        ] as $url) {
            $response = $this->request(TestEnvironment::baseUrl(), $url);
            $this->assertContains($response['status'], [400, 403, 404], $url . ' answered ' . $response['status']);
            $this->assertStringNotContainsString("\xFF\xD8\xFF", $response['body'], $url . ' served JPEG bytes');
        }
    }

    public function testTheAdminFileRouteServesTheClaimedPictureOnlyToOrdersView(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Http beheer');
        $bytes = $this->jpeg();
        $result = $this->upload(TestEnvironment::baseUrl(), $product, $field, $bytes, 'Luna.jpg', 'image/jpeg');
        $this->assertSame(200, $result['status'], $result['body']);
        $token = $result['data']['token'];
        $uploadId = (int) $this->row($token)['id'];
        $url = self::ADMIN_FILE . '?id=' . $uploadId;

        [$viewer] = $this->accounts->signIn(['orders.view']);
        [$outsider] = $this->accounts->signIn(['pages.manage']);

        // Not yet ordered: no route at all, not even for orders.view.
        $this->assertSame(404, $this->request(TestEnvironment::baseUrl(), $url, $viewer)['status']);

        $this->claim($product, $field, $token);

        $anonymous = $this->request(TestEnvironment::baseUrl(), $url);
        $this->assertSame(302, $anonymous['status']);
        $this->assertSame('/admin/login.php', $anonymous['headers']['location'] ?? null);
        $this->assertStringNotContainsString("\xFF\xD8\xFF", $anonymous['body']);

        $refused = $this->request(TestEnvironment::baseUrl(), $url, $outsider);
        $this->assertSame(403, $refused['status']);
        $this->assertStringNotContainsString("\xFF\xD8\xFF", $refused['body']);

        $served = $this->request(TestEnvironment::baseUrl(), $url, $viewer);
        $this->assertSame(200, $served['status'], 'the signed-in session reaches Apache (AdminTestSession::shareWithWebServer)');
        $this->assertSame('image/jpeg', $served['headers']['content-type'] ?? null);
        $this->assertSame('nosniff', $served['headers']['x-content-type-options'] ?? null);
        $this->assertSame($bytes, $served['body'], 'the stored original, byte for byte');

        $download = $this->request(TestEnvironment::baseUrl(), $url . '&mode=download', $viewer);
        $this->assertSame(200, $download['status']);
        $this->assertStringStartsWith('attachment; filename="Luna.jpg"', $download['headers']['content-disposition'] ?? '');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: int, 1: int} product id, field id */
    private function imageProduct(string $name): array
    {
        $product = $this->shop->product($name);
        $this->productIds[] = $product;

        $db = Database::connection();
        $editor = ProductOrderFieldEditor::fromRequest([
            'order_fields_present' => '1',
            'order_fields_enabled' => '1',
            'order_fields' => ['new0' => ['type' => 'image', 'label' => 'Foto huisdier', 'help' => '', 'required' => '1', 'max_file_size_mb' => '']],
        ], $product, 'nl', $db);
        $this->assertSame([], $editor->validate());
        $db->beginTransaction();
        $editor->save();
        $db->commit();
        ShopLocalization::clearCache();

        return [$product, (int) (new OrderFieldRepository())->fieldsForProduct($product)[0]['id']];
    }

    /** An order line for the product whose answer claims the upload, as checkout does. */
    private function claim(int $product, int $field, string $token): void
    {
        $order = $this->shop->order([['product_id' => $product, 'quantity' => 1]]);
        $db = Database::connection();
        $item = (int) $db->query('SELECT id FROM order_items WHERE order_id = ' . $order)->fetchColumn();

        $fields = new OrderFields();
        $checked = $fields->validate($product, [(string) $field => $token], 'nl');
        $db->beginTransaction();
        $fields->record($item, $product, $checked, 'nl');
        $db->commit();

        $this->assertNotNull($this->row($token)['claimed_at']);
    }

    /** @return array<string, mixed> */
    private function row(string $token): array
    {
        $row = (new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token));
        $this->assertNotNull($row, 'an upload row for the token');

        return $row;
    }

    private function uploadCount(int $product): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM order_field_uploads WHERE product_id = :id');
        $statement->execute(['id' => $product]);

        return (int) $statement->fetchColumn();
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(64, 48);
        imagefilledrectangle($image, 0, 0, 63, 47, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * A genuine multipart/form-data POST, the only way to make PHP fill
     * $_FILES and is_uploaded_file() the way a browser does.
     *
     * @return array{status: int, body: string, data: array<string, mixed>, headers: array<string, string>}
     */
    private function upload(string $base, int $product, int $field, string $bytes, string $filename, string $mime): array
    {
        $boundary = '----zzofu' . bin2hex(random_bytes(8));
        $body = '';
        foreach (['action' => 'upload', 'product_id' => (string) $product, 'field_id' => (string) $field, 'language' => 'nl'] as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$mime}\r\n\r\n" . $bytes . "\r\n--{$boundary}--\r\n";

        $response = $this->request($base, self::ENDPOINT, null, $body, 'multipart/form-data; boundary=' . $boundary);
        $data = json_decode($response['body'], true);

        return $response + ['data' => is_array($data) ? $data : []];
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function request(string $base, string $path, ?string $session = null, ?string $postBody = null, ?string $contentType = null): array
    {
        $headers = [];
        $handle = curl_init($base . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($session !== null) {
            $options[CURLOPT_COOKIE] = AdminTestSession::COOKIE . '=' . $session;
        }
        if ($postBody !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postBody;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: ' . $contentType];
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $headers];
    }
}
