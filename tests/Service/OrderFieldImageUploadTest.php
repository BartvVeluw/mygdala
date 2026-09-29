<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Mail\OrderConfirmationBuilder;
use App\Repository\OrderFieldRepository;
use App\Repository\OrderFieldUploadRepository;
use App\Repository\OrderItemFieldRepository;
use App\Service\ContactRateLimiter;
use App\Service\OrderFields\OrderFields;
use App\Service\OrderFields\OrderFieldUploadException;
use App\Service\OrderFields\OrderFieldUploadPolicy;
use App\Service\OrderFields\OrderFieldUploads;
use App\Service\OrderFields\OrderFieldUploadStorage;
use App\Service\OrderFields\OrderFieldUploadValidator;
use App\Service\OrderFields\OrderFieldValidationException;
use App\Service\OrderFields\ProductOrderFieldEditor;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShopStockFixture;

/**
 * "Afbeelding uploaden" order questions (Shop Admin UX & Order Fields 2.0,
 * MODULES.md "Bestelvelden"), against the real database and a private folder
 * of the test's own:
 *
 *   - the question: stored as `image` with its size choice, required or
 *     not, translated, reordered; a changed type keeps nothing stale;
 *   - the upload: JPEG, PNG and WebP taken; too large, SVG, text or HTML
 *     with a picture's name, a lying header, an empty file and a pixel bomb
 *     refused; the filename is display text only;
 *   - the token: 256 random bits, only its hash stored; a forged, expired,
 *     claimed or foreign token (other question, other product) refused;
 *   - the life: temporary, private, replaced and removed at once, swept
 *     when expired; claimed once in the order's transaction, rolled back
 *     with it, never twice, never swept after;
 *   - the cart: required and optional, part of the line's identity;
 *   - the order: a snapshot with the filename and the private file bound to
 *     it, readable after the question is gone; both mails name the picture
 *     and link nothing.
 */
final class OrderFieldImageUploadTest extends TestCase
{
    private ShopStockFixture $fixture;
    private string $dir;
    private string $tmp;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is needed to make and read test pictures.');
        }
        $this->fixture = new ShopStockFixture();
        $this->dir = sys_get_temp_dir() . '/mygdala-ofu-' . bin2hex(random_bytes(4));
        $this->tmp = $this->dir . '-tmp';
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
        ShopLocalization::clearCache();
        foreach ([$this->dir, $this->tmp] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /* ---- the question ------------------------------------------------ */

    public function testTheEditorStoresAnImageQuestionWithItsSizeRequiredAndPerLanguage(): void
    {
        $product = $this->fixture->product('ZZ Beeld Editor');
        $this->save($product, 'nl', ['order_fields' => [
            'new0' => ['type' => 'text', 'label' => 'Naam', 'help' => '', 'required' => '1', 'max_length' => ''],
            'new1' => ['type' => 'image', 'label' => 'Foto huisdier', 'help' => 'Een scherpe foto', 'required' => '1', 'max_length' => '40', 'max_file_size_mb' => '5'],
            'new2' => ['type' => 'image', 'label' => 'Logo', 'help' => '', 'required' => '0', 'max_length' => '', 'max_file_size_mb' => ''],
        ]]);

        $fields = (new OrderFieldRepository())->fieldsForProduct($product);
        self::assertSame(['text', 'image', 'image'], array_column($fields, 'field_type'));
        self::assertSame([true, true, false], array_column($fields, 'is_required'));
        self::assertSame([null, 5, null], array_column($fields, 'max_file_size_mb'));
        self::assertNull($fields[1]['max_length'], 'a length belongs to a text question only');
        self::assertSame([], $fields[1]['options']);

        // English words, the Dutch stay; then the image questions move up.
        $this->save($product, 'en', ['order_fields' => [
            (string) $fields[1]['id'] => ['type' => 'image', 'label' => 'Photo of your pet', 'help' => 'A sharp photo', 'required' => '1', 'max_file_size_mb' => '5'],
            (string) $fields[2]['id'] => ['type' => 'image', 'label' => 'Logo', 'help' => '', 'required' => '0', 'max_file_size_mb' => '10'],
            (string) $fields[0]['id'] => ['type' => 'text', 'label' => 'Name', 'help' => '', 'required' => '1', 'max_length' => ''],
        ]]);
        self::assertSame('Foto huisdier', ShopLocalization::rawOrderField($fields[1]['id'], ShopLocalization::LABEL, 'nl'));
        self::assertSame('Photo of your pet', ShopLocalization::rawOrderField($fields[1]['id'], ShopLocalization::LABEL, 'en'));
        $after = (new OrderFieldRepository())->fieldsForProduct($product);
        self::assertSame([$fields[1]['id'], $fields[2]['id'], $fields[0]['id']], array_column($after, 'id'));
        self::assertSame(10, $after[1]['max_file_size_mb']);

        $questions = (new OrderFields())->questions($product, 'en');
        self::assertSame('image', $questions[0]['type']);
        self::assertSame(min(5 * 1024 * 1024, OrderFieldUploadPolicy::serverMaxBytes()), $questions[0]['max_bytes']);
        self::assertSame(0, $questions[2]['max_bytes'], 'only an image question has a file size');
    }

    public function testAChangedTypeKeepsNothingStaleAndASizeMustBeAChoice(): void
    {
        $product = $this->fixture->product('ZZ Beeld Type');
        $this->save($product, 'nl', [
            'order_fields' => ['new0' => ['type' => 'radio', 'label' => 'Hout', 'help' => '', 'required' => '1', 'max_length' => '']],
            'order_field_options' => ['new0' => ['new0' => ['label' => 'Eiken'], 'new1' => ['label' => 'Noten']]],
        ]);
        $field = (new OrderFieldRepository())->fieldsForProduct($product)[0];
        self::assertCount(2, $field['options']);

        // Radio → image: the choices go, even though the screen still sent them.
        $this->save($product, 'nl', [
            'order_fields' => [(string) $field['id'] => ['type' => 'image', 'label' => 'Foto', 'help' => '', 'required' => '1', 'max_length' => '12', 'max_file_size_mb' => '2']],
            'order_field_options' => [(string) $field['id'] => [(string) $field['options'][0]['id'] => ['label' => 'Eiken']]],
        ]);
        $image = (new OrderFieldRepository())->fieldsForProduct($product)[0];
        self::assertSame('image', $image['field_type']);
        self::assertSame([], $image['options']);
        self::assertNull($image['max_length']);
        self::assertSame(2, $image['max_file_size_mb']);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM product_order_field_options WHERE field_id = ' . $field['id'])->fetchColumn());

        // Image → dropdown: new choices as usual, and the file size is gone.
        $this->save($product, 'nl', [
            'order_fields' => [(string) $field['id'] => ['type' => 'select', 'label' => 'Maat', 'help' => '', 'required' => '0', 'max_file_size_mb' => '2']],
            'order_field_options' => [(string) $field['id'] => ['new0' => ['label' => 'S']]],
        ]);
        $select = (new OrderFieldRepository())->fieldsForProduct($product)[0];
        self::assertSame('select', $select['field_type']);
        self::assertCount(1, $select['options']);
        self::assertNull($select['max_file_size_mb']);

        $editor = ProductOrderFieldEditor::fromRequest([
            'order_fields_present' => '1', 'order_fields_enabled' => '1',
            'order_fields' => ['new0' => ['type' => 'image', 'label' => 'Foto', 'max_file_size_mb' => '50']],
        ], $product, 'nl', Database::connection());
        self::assertArrayHasKey('order_fields[new0][max_file_size_mb]', $editor->validate(), 'no free size, only the offered choices');
    }

    public function testTheEffectiveLimitNeverExceedsWhatPhpAccepts(): void
    {
        self::assertSame(OrderFieldUploadPolicy::DEFAULT_MB, OrderFieldUploadPolicy::chosenMb(null));
        self::assertSame(OrderFieldUploadPolicy::DEFAULT_MB, OrderFieldUploadPolicy::chosenMb(7), 'not a choice: the default');
        foreach ([2, 5, 10, null] as $mb) {
            self::assertLessThanOrEqual(OrderFieldUploadPolicy::serverMaxBytes(), OrderFieldUploadPolicy::effectiveMaxBytes($mb));
        }
        self::assertSame('image/jpeg,image/png,image/webp', OrderFieldUploadPolicy::acceptAttribute());
    }

    /* ---- the upload -------------------------------------------------- */

    public function testJpegPngAndWebpAreTakenAndStoredPrivatelyWithTheirMetadata(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Formaten');
        $service = $this->service();

        foreach (['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP] as $extension => $type) {
            $stored = $service->upload($product, $field, $this->file($this->picture($type, 640, 480), 'Luna.' . $extension), 'nl');

            self::assertTrue(OrderFieldUploadPolicy::isToken($stored['token']));
            self::assertSame('Luna.' . $extension, $stored['original_filename']);
            self::assertSame([640, 480], [$stored['width'], $stored['height']]);

            $row = (new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($stored['token']));
            self::assertNotNull($row);
            self::assertSame($extension, $row['extension']);
            self::assertSame(image_type_to_mime_type($type), $row['mime_type']);
            self::assertNull($row['claimed_at']);
            self::assertNull($row['order_item_field_id']);
            self::assertStringNotContainsString('Luna', (string) $row['storage_name'], 'the stored name is random, not the customer\'s');
            self::assertFileExists($this->dir . '/' . $row['storage_name'] . '.orig.' . $extension);
            self::assertFileExists($this->dir . '/' . $row['storage_name'] . '.thumb.' . $extension);
            self::assertLessThanOrEqual(480, getimagesize($this->dir . '/' . $row['storage_name'] . '.thumb.' . $extension)[0]);
        }

        // Only the hash of a token is ever in the database.
        $raw = Database::connection()->query('SELECT token_hash FROM order_field_uploads WHERE product_id = ' . $product)->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(3, $raw);
        self::assertNotContains($stored['token'], $raw);
        self::assertContains(OrderFieldUploadPolicy::tokenHash($stored['token']), $raw);

        // Not in the Media Library, and by default one level ABOVE the
        // project root (the site root): never inside the webroot.
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM media WHERE path LIKE '%Luna%'")->fetchColumn());
        $source = (string) file_get_contents(__DIR__ . '/../../src/Service/OrderFields/OrderFieldUploadStorage.php');
        self::assertStringContainsString("dirname(__DIR__, 4) . '/storage'", $source, 'src/Service/OrderFields is four levels below the project root\'s parent');
        $_ENV['ORDER_FIELD_UPLOADS_PATH'] = $this->dir . '-base';
        try {
            self::assertSame($this->dir . '-base/order-field-uploads/', (new OrderFieldUploadStorage())->directory());
        } finally {
            unset($_ENV['ORDER_FIELD_UPLOADS_PATH']);
            @rmdir($this->dir . '-base/order-field-uploads');
            @rmdir($this->dir . '-base');
        }
    }

    public function testWhatIsNotARealPictureWithinTheLimitsIsRefused(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Weigeren', 2);
        $service = $this->service();
        $refused = function (array $entry, string $kind, string $why) use ($service, $product, $field): void {
            try {
                $service->upload($product, $field, $entry, 'nl');
                self::fail('refused: ' . $why);
            } catch (OrderFieldUploadException $e) {
                self::assertSame($kind, $e->kind, $why);
                self::assertNotSame('', $e->getMessage());
            }
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>';
        $refused($this->file($svg, 'logo.svg', 'image/svg+xml'), 'type', 'SVG');
        $refused($this->file($svg, 'logo.png', 'image/png'), 'type', 'SVG named .png');
        $refused($this->file('just some text, not a picture', 'foto.jpg', 'image/jpeg'), 'type', 'text named .jpg');
        $refused($this->file('<html><script>alert(1)</script></html>', 'foto.png', 'image/png'), 'type', 'HTML named .png');
        $refused($this->file("\xFF\xD8\xFF\xE0<?php echo 1; ?>" . str_repeat('A', 64), 'foto.jpg', 'image/jpeg'), 'type', 'a JPEG header on a script');
        $refused($this->file('', 'leeg.jpg', 'image/jpeg'), 'empty', 'an empty file');
        $refused($this->file(random_bytes(3 * 1024 * 1024), 'groot.jpg', 'image/jpeg'), 'too_large', 'over the question\'s 2 MB');
        $refused($this->file($this->bomb(), 'bom.png', 'image/png'), 'pixels', 'a small file that claims 20000 × 20000 pixels');
        $refused(['name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0], 'too_large', 'PHP\'s own size refusal');
        $refused(['name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], 'none', 'no file');
        $refused(['name' => ['a.jpg', 'b.jpg'], 'tmp_name' => ['x', 'y'], 'error' => [0, 0]], 'one', 'two files for one question');

        // The browser's MIME type is never believed: a real PNG sent as
        // image/jpeg is stored as the PNG it is.
        $stored = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_PNG, 20, 20), 'foto.jpg', 'image/jpeg'), 'nl');
        $row = (new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($stored['token']));
        self::assertSame(['png', 'image/png'], [$row['extension'], $row['mime_type']]);

        // A path the request names is never an upload.
        $validator = new OrderFieldUploadValidator(static fn (string $path): bool => false);
        try {
            $validator->validate(['name' => 'passwd.jpg', 'tmp_name' => '/etc/passwd', 'error' => UPLOAD_ERR_OK, 'size' => 10], 1_000_000, 'nl');
            self::fail('not an uploaded file');
        } catch (OrderFieldUploadException $e) {
            self::assertSame('failed', $e->kind);
        }

        self::assertSame(1, (int) Database::connection()->query('SELECT COUNT(*) FROM order_field_uploads WHERE product_id = ' . $product)->fetchColumn(), 'nothing refused was stored');
        self::assertCount(2, glob($this->dir . '/*') ?: []);
    }

    public function testOnlyAnImageQuestionOfAProductForSaleTakesAPicture(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Alleen');
        $text = (new OrderFieldRepository())->createField($product, 'text', false, null, 5);
        $service = $this->service();
        $picture = $this->picture(IMAGETYPE_JPEG, 10, 10);

        foreach ([[$product, $text, 'a text question'], [$product, 999999, 'no such question'], [$this->fixture->product('ZZ Beeld Ander'), $field, 'another product\'s question']] as [$p, $f, $why]) {
            try {
                $service->upload($p, $f, $this->file($picture, 'a.jpg'), 'nl');
                self::fail($why);
            } catch (OrderFieldUploadException $e) {
                self::assertSame('question', $e->kind, $why);
            }
        }

        Database::connection()->prepare("UPDATE products SET purchase_mode = 'inquiry' WHERE id = :id")->execute(['id' => $product]);
        $this->expectException(OrderFieldUploadException::class);
        $service->upload($product, $field, $this->file($picture, 'a.jpg'), 'nl');
    }

    public function testTheDisplayNameIsNeverAPath(): void
    {
        self::assertSame('luna.jpg', OrderFieldUploadValidator::displayName('C:\\Users\\klant\\luna.jpg', 'jpg'));
        self::assertSame('passwd', OrderFieldUploadValidator::displayName('../../etc/passwd', 'jpg'));
        self::assertSame('fotoevil.png', OrderFieldUploadValidator::displayName("foto\r\n\"evil.png", 'png'));
        self::assertSame('afbeelding.webp', OrderFieldUploadValidator::displayName('..', 'webp'));
        self::assertSame('afbeelding.jpg', OrderFieldUploadValidator::displayName(null, 'jpg'));
        self::assertSame('fotogpj.hta', OrderFieldUploadValidator::displayName("foto\u{202E}gpj.hta", 'jpg'), 'no right-to-left override to disguise a name');
        self::assertSame('afbeelding.png', OrderFieldUploadValidator::displayName("\xFF\xFE", 'png'), 'not UTF-8: not a name');

        $route = (string) file_get_contents(__DIR__ . '/../../api/admin/order-field-upload.php');
        self::assertStringContainsString('PATHINFO_FILENAME', $route);
        self::assertStringContainsString("'.' . \$upload['extension']", $route, 'a download ends in the VERIFIED type\'s extension, never the customer\'s');

        self::assertFalse(OrderFieldUploadStorage::isSafeBase('storage'), 'relative');
        self::assertFalse(OrderFieldUploadStorage::isSafeBase(dirname(__DIR__, 2) . '/assets'), 'inside the webroot');
        self::assertTrue(OrderFieldUploadStorage::isSafeBase(sys_get_temp_dir()));

        $storage = new OrderFieldUploadStorage($this->dir);
        self::assertNull($storage->path('../../etc/passwd', 'jpg', 'orig'));
        self::assertNull($storage->path(str_repeat('a', 32), 'php', 'orig'));
        self::assertNull($storage->path(str_repeat('a', 32), 'jpg', '../orig'));
        self::assertSame($this->dir . '/' . str_repeat('a', 32) . '.orig.jpg', $storage->path(str_repeat('a', 32), 'jpg', 'orig'));
    }

    public function testTheUploadEndpointIsRateLimited(): void
    {
        $limiter = new ContactRateLimiter(Database::connection(), ContactRateLimiter::ORDER_FIELD_UPLOAD_SALT, 3, 600);
        $ip = '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(3));
        self::assertTrue($limiter->allow($ip));
        self::assertTrue($limiter->allow($ip));
        self::assertTrue($limiter->allow($ip));
        self::assertFalse($limiter->allow($ip), 'the fourth in the window is refused');
        self::assertTrue((new ContactRateLimiter(Database::connection(), ContactRateLimiter::CONTACT_SALT, 3, 600))->allow($ip), 'its own counter');

        $endpoint = (string) file_get_contents(__DIR__ . '/../../api/order-field-upload.php');
        self::assertStringContainsString('ContactRateLimiter::ORDER_FIELD_UPLOAD_SALT', $endpoint);
        self::assertStringContainsString("ModuleGuard::requireApi('shop')", $endpoint);
        self::assertLessThan(strpos($endpoint, '$uploads->upload('), strpos($endpoint, '$limiter->allow('), 'limited before anything is checked or stored');
    }

    /* ---- the token ------------------------------------------------------ */

    public function testAForgedExpiredClaimedOrForeignTokenIsRefused(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Token');
        [$other, $otherField] = $this->imageProduct('ZZ Beeld Token Ander');
        $second = (new OrderFieldRepository())->createField($product, 'image', false, null, 1);
        $service = $this->service();
        $token = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'a.jpg'), 'nl')['token'];

        $kind = function (mixed $token, int $p, int $f) use ($service): string {
            try {
                $service->resolve($token, $p, $f, 'Foto', 'nl');

                return 'ok';
            } catch (OrderFieldUploadException $e) {
                return $e->kind;
            }
        };

        self::assertSame('ok', $kind($token, $product, $field));
        self::assertSame('missing', $kind(OrderFieldUploadPolicy::newToken(), $product, $field), 'a guessed token');
        self::assertSame('missing', $kind('../../etc/passwd', $product, $field));
        self::assertSame('missing', $kind(['x'], $product, $field));
        self::assertSame('missing', $kind(OrderFieldUploadPolicy::tokenHash($token), $product, $field), 'the hash is not the key');
        self::assertSame('mismatch', $kind($token, $product, $second), 'another question of the same product');
        self::assertSame('mismatch', $kind($token, $other, $otherField), 'another product');

        $row = (new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token));
        Database::connection()->prepare('UPDATE order_field_uploads SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = :id')->execute(['id' => $row['id']]);
        self::assertSame('expired', $kind($token, $product, $field));

        $fresh = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'b.jpg'), 'nl')['token'];
        $this->claim($product, [$field => $fresh], $service);
        self::assertSame('claimed', $kind($fresh, $product, $field));

        // A row whose file is gone cannot be ordered either.
        $gone = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'c.jpg'), 'nl')['token'];
        $goneRow = (new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($gone));
        unlink($this->dir . '/' . $goneRow['storage_name'] . '.orig.jpg');
        self::assertSame('expired', $kind($gone, $product, $field));
    }

    /* ---- the life of an upload ----------------------------------------- */

    public function testReplacingOrRemovingDiscardsTheTemporaryUploadAtOnce(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Vervangen');
        $service = $this->service();
        $first = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'eerste.jpg'), 'nl')['token'];
        $second = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_PNG, 10, 10), 'tweede.png'), 'nl')['token'];
        self::assertCount(4, glob($this->dir . '/*') ?: []);

        self::assertTrue($service->discard($first), 'replaced: the old one goes');
        self::assertNull((new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($first)));
        self::assertCount(2, glob($this->dir . '/*') ?: [], 'with both its files');
        self::assertFalse($service->discard($first), 'nothing twice');
        self::assertFalse($service->discard('not-a-token'));

        self::assertTrue($service->discard($second), 'removed');
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testTheSweepRemovesExpiredTemporaryUploadsOnly(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Opruimen');
        $service = $this->service();
        $abandoned = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'weg.jpg'), 'nl')['token'];
        $waiting = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'wacht.jpg'), 'nl')['token'];
        $ordered = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'besteld.jpg'), 'nl')['token'];
        $this->claim($product, [$field => $ordered], $service);

        $repository = new OrderFieldUploadRepository();
        $db = Database::connection();
        foreach ([$abandoned, $ordered] as $token) {
            $db->prepare('UPDATE order_field_uploads SET expires_at = NOW() - INTERVAL 1 HOUR WHERE token_hash = :h')->execute(['h' => OrderFieldUploadPolicy::tokenHash($token)]);
        }

        self::assertGreaterThanOrEqual(1, $service->sweep());
        self::assertNull($repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($abandoned)), 'abandoned and expired: gone');
        self::assertNotNull($repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($waiting)), 'still within its time');
        $orderedRow = $repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($ordered));
        self::assertNotNull($orderedRow, 'a picture of an order is never swept');
        self::assertFileExists($this->dir . '/' . $orderedRow['storage_name'] . '.orig.jpg');
        self::assertCount(4, glob($this->dir . '/*') ?: [], 'the abandoned picture\'s two files went with it');
        self::assertFalse($repository->deleteUnclaimed((int) $orderedRow['id']), 'not even by hand');

        // A file no row names any more, older than the lifetime, goes too;
        // a young one may be an upload in progress and stays.
        $orphan = $this->dir . '/' . str_repeat('ab', 16) . '.orig.jpg';
        $young = $this->dir . '/' . str_repeat('cd', 16) . '.orig.jpg';
        file_put_contents($orphan, 'x');
        file_put_contents($young, 'x');
        touch($orphan, time() - OrderFieldUploadPolicy::TTL_HOURS * 3600 - 60);
        touch($this->dir . '/' . $orderedRow['storage_name'] . '.orig.jpg', time() - OrderFieldUploadPolicy::TTL_HOURS * 3600 - 60);
        $service->sweep();
        self::assertFileDoesNotExist($orphan);
        self::assertFileExists($young);
        self::assertFileExists($this->dir . '/' . $orderedRow['storage_name'] . '.orig.jpg', 'an old file of an order stays');
        unlink($young);

        $script = (string) file_get_contents(__DIR__ . '/../../scripts/prune-order-field-uploads.php');
        self::assertStringContainsString('->sweep(', $script);
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $script);
    }

    public function testTheClaimHappensOnceAndRollsBackWithItsOrder(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Claim');
        $service = $this->service();
        $token = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'luna.jpg'), 'nl')['token'];
        $repository = new OrderFieldUploadRepository();
        $db = Database::connection();

        // The order fails after the claim: nothing of it stays, and the
        // picture is a temporary one again.
        $order = $this->fixture->order([['product_id' => $product, 'quantity' => 1]]);
        $item = $this->itemOf($order);
        $orderFields = new OrderFields(null, $service);
        $answers = $orderFields->validate($product, [(string) $field => $token], 'nl');
        $db->beginTransaction();
        $orderFields->record($item, $product, $answers, 'nl');
        self::assertNotNull($repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token))['claimed_at']);
        $db->rollBack();
        $row = $repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token));
        self::assertNull($row['claimed_at']);
        self::assertNull($row['order_item_field_id']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM order_item_fields WHERE order_item_id = ' . $item)->fetchColumn());

        // The real order claims it.
        $claimedItem = $this->claim($product, [$field => $token], $service);
        $row = $repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token));
        self::assertNotNull($row['claimed_at']);
        $answerId = (int) $db->query('SELECT id FROM order_item_fields WHERE order_item_id = ' . $claimedItem)->fetchColumn();
        self::assertSame($answerId, (int) $row['order_item_field_id']);

        // A second order with the same token, even one that got past its
        // check a moment earlier, cannot take it: the whole order rolls back.
        $secondOrder = $this->fixture->order([['product_id' => $product, 'quantity' => 1]]);
        $secondItem = $this->itemOf($secondOrder);
        $db->beginTransaction();
        try {
            (new OrderFields(null, $service))->record($secondItem, $product, [$field => $token], 'nl');
            self::fail('claimed twice');
        } catch (OrderFieldUploadException $e) {
            self::assertSame('claimed', $e->kind);
            self::assertStringContainsString('"Foto huisdier"', $e->getMessage());
        } finally {
            $db->rollBack();
        }
        self::assertSame($answerId, (int) $repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token))['order_item_field_id'], 'still the first order\'s');

        // The claimed picture's line cannot lose its answer while the file exists.
        try {
            $db->prepare('DELETE FROM order_item_fields WHERE id = :id')->execute(['id' => $answerId]);
            self::fail('RESTRICT');
        } catch (\PDOException $e) {
            self::assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
    }

    public function testAPaymentThatEndsWithoutMoneyGivesThePictureBackToTheCart(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Terug');
        $service = $this->service();
        $repository = new OrderFieldUploadRepository();
        $db = Database::connection();

        // The payment could not even start: the order fails, the picture is
        // temporary again and the same cart can be checked out again.
        $token = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'luna.jpg'), 'nl')['token'];
        $item = $this->claim($product, [$field => $token], $service);
        $order = (int) $db->query('SELECT order_id FROM order_items WHERE id = ' . $item)->fetchColumn();
        \App\Service\OrderPaymentStartFailure::handle($order, $db);
        self::assertSame('failed', $this->fixture->orderRow($order)['status']);
        $row = $repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token));
        self::assertNull($row['claimed_at']);
        self::assertSame(0, (int) $row['expired']);
        self::assertSame('luna.jpg', (new OrderItemFieldRepository())->findByOrderIdGrouped($order)[$item][0]['value'], 'the failed order still says what was sent');
        $retry = $this->claim($product, [$field => $token], $service);
        self::assertNotNull($repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token))['claimed_at'], 'the retry orders it');

        // A paid order keeps its picture, whatever is asked.
        $retryOrder = (int) $db->query('SELECT order_id FROM order_items WHERE id = ' . $retry)->fetchColumn();
        $db->prepare("UPDATE orders SET status = 'paid' WHERE id = :id")->execute(['id' => $retryOrder]);
        self::assertSame(0, $repository->releaseForOrder($retryOrder, OrderFieldUploadPolicy::TTL_HOURS));
        $db->prepare("UPDATE orders SET status = 'canceled' WHERE id = :id")->execute(['id' => $retryOrder]);
        self::assertSame(1, $repository->releaseForOrder($retryOrder, OrderFieldUploadPolicy::TTL_HOURS), 'canceled at the payment provider: back to the cart');

        $sync = (string) file_get_contents(__DIR__ . '/../../src/Service/OrderPaymentSync.php');
        self::assertStringContainsString('OrderFieldUploadRepository())->releaseForOrder(', $sync, 'the webhook gives pictures back next to the stock');
    }

    /* ---- the cart --------------------------------------------------------- */

    public function testRequiredOptionalAndIdentityInTheCart(): void
    {
        $product = $this->fixture->product('ZZ Beeld Winkelwagen');
        $this->save($product, 'nl', ['order_fields' => [
            'new0' => ['type' => 'text', 'label' => 'Naam', 'help' => '', 'required' => '1', 'max_length' => ''],
            'new1' => ['type' => 'image', 'label' => 'Foto huisdier', 'help' => '', 'required' => '1'],
            'new2' => ['type' => 'image', 'label' => 'Logo', 'help' => '', 'required' => '0'],
        ]]);
        [$name, $photo, $logo] = array_column((new OrderFieldRepository())->fieldsForProduct($product), 'id');
        $service = $this->service();
        $orderFields = new OrderFields(null, $service);

        try {
            $orderFields->validate($product, [(string) $name => 'Luna'], 'nl');
            self::fail('a required picture is missing');
        } catch (OrderFieldValidationException $e) {
            self::assertSame($photo, $e->fieldId);
            self::assertSame('Kies een afbeelding bij "Foto huisdier".', $e->getMessage());
        }

        try {
            $orderFields->validate($product, [(string) $name => 'Luna', (string) $photo => OrderFieldUploadPolicy::newToken()], 'nl');
            self::fail('a forged picture');
        } catch (OrderFieldValidationException $e) {
            self::assertSame($photo, $e->fieldId);
            self::assertStringContainsString('"Foto huisdier"', $e->getMessage());
        }

        $luna = $service->upload($product, $photo, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'Luna.jpg'), 'nl')['token'];
        $kyra = $service->upload($product, $photo, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'Kyra.jpg'), 'nl')['token'];

        $withLuna = $orderFields->validate($product, [(string) $name => 'Luna', (string) $photo => $luna], 'nl');
        self::assertSame([$name => 'Luna', $photo => $luna], $withLuna, 'the optional logo may stay empty');
        $withKyra = $orderFields->validate($product, [(string) $name => 'Luna', (string) $photo => $kyra], 'nl');

        self::assertNotSame(OrderFields::fingerprint($withLuna), OrderFields::fingerprint($withKyra), 'two pictures are two lines');
        self::assertSame(OrderFields::fingerprint($withLuna), OrderFields::fingerprint($orderFields->validate($product, [(string) $photo => $luna, (string) $name => 'Luna'], 'nl')), 'the same picture and answers add up');

        // The snapshot names the picture by its filename, whatever the language.
        $snapshot = $orderFields->snapshot($product, $withLuna);
        self::assertSame(['Naam', 'Foto huisdier'], array_column($snapshot, 'label'));
        self::assertSame(['Luna', 'Luna.jpg'], array_column($snapshot, 'value'));
        self::assertSame('image', $snapshot[1]['field_type']);
        self::assertNotNull($snapshot[1]['upload_id']);
        self::assertNull($snapshot[0]['upload_id']);

        $cartCheck = (string) file_get_contents(__DIR__ . '/../../api/cart-check.php');
        self::assertStringContainsString('$orderFields->validate(', $cartCheck, 'the cart check asks the same question');
        $cart = (string) file_get_contents(__DIR__ . '/../../assets/js/shop/cart.js');
        self::assertStringContainsString('function sameOrderFields', $cart, 'a line merges only with the same answers, the token included');
    }

    public function testAVariantWithAPictureKeepsItsOwnStockCheck(): void
    {
        $variant = $this->fixture->variantProduct('ZZ Beeld Variant', ['Rood' => 2, 'Blauw' => 0]);
        $this->save($variant['product'], 'nl', ['order_fields' => ['new0' => ['type' => 'image', 'label' => 'Foto', 'help' => '', 'required' => '1']]]);
        $field = (new OrderFieldRepository())->fieldsForProduct($variant['product'])[0]['id'];
        $token = $this->service()->upload($variant['product'], $field, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 'a.jpg'), 'nl')['token'];

        $check = (new \App\Service\CartAvailability())->check([
            ['id' => $variant['product'], 'variant_id' => $variant['variants']['Rood'], 'qty' => 3, 'order_fields' => [(string) $field => $token]],
            ['id' => $variant['product'], 'variant_id' => $variant['variants']['Blauw'], 'qty' => 1, 'order_fields' => [(string) $field => $token]],
        ]);
        self::assertSame(\App\Service\CartAvailability::INSUFFICIENT, $check[0]['status'], 'three of two, picture or not');
        self::assertSame(\App\Service\CartAvailability::SOLD_OUT, $check[1]['status']);
    }

    /* ---- the order -------------------------------------------------------- */

    public function testTheOrderKeepsThePictureAfterTheQuestionIsGoneAndTheMailsNameIt(): void
    {
        [$product, $field] = $this->imageProduct('ZZ Beeld Bestelling');
        $service = $this->service();
        $token = $service->upload($product, $field, $this->file($this->picture(IMAGETYPE_JPEG, 800, 600), 'luna.jpg'), 'nl')['token'];
        $item = $this->claim($product, [$field => $token], $service);
        $order = (int) Database::connection()->query('SELECT order_id FROM order_items WHERE id = ' . $item)->fetchColumn();

        // The owner removes the question and switches the questions off.
        $this->save($product, 'nl', ['order_fields' => []]);
        self::assertSame([], (new OrderFieldRepository())->fieldsForProduct($product));

        $answers = (new OrderItemFieldRepository())->findByOrderIdGrouped($order)[$item];
        self::assertCount(1, $answers);
        self::assertSame('Foto huisdier', $answers[0]['label']);
        self::assertSame('luna.jpg', $answers[0]['value']);
        self::assertSame('image', $answers[0]['field_type']);
        self::assertSame('luna.jpg', $answers[0]['upload']['original_filename']);
        self::assertSame([800, 600], [$answers[0]['upload']['width'], $answers[0]['upload']['height']]);

        $admin = (new OrderFieldUploadRepository())->findClaimedForAdmin($answers[0]['upload']['id']);
        self::assertSame($order, (int) $admin['order_id']);
        $temporary = $service->upload($this->imageProduct('ZZ Beeld Tijdelijk')[0], $this->lastField, $this->file($this->picture(IMAGETYPE_JPEG, 10, 10), 't.jpg'), 'nl')['token'];
        $temporaryRow = (new OrderFieldUploadRepository())->findByTokenHash(OrderFieldUploadPolicy::tokenHash($temporary));
        self::assertNull((new OrderFieldUploadRepository())->findClaimedForAdmin((int) $temporaryRow['id']), 'a visitor\'s picture that is not an order has no route');

        $orderRow = ['id' => $order, 'order_number' => 'ORD-2026-000001', 'created_at' => '2026-09-29 10:00:00', 'total' => '25.00',
            'shipping_cost' => '0.00', 'shipping_method' => 'afhalen', 'billing_same_as_shipping' => 1, 'currency' => 'EUR'];
        $customer = ['name' => 'Klant', 'email' => 'klant@example.com', 'phone' => null, 'address_line' => 'Straat 1', 'postal_code' => '1234AB', 'city' => 'Stad', 'country' => 'NL'];
        $mails = OrderConfirmationBuilder::build($orderRow, $customer, [[
            'id' => $item, 'name' => 'Portret', 'variant_label' => null, 'quantity' => 2, 'unit_price' => '25.00', 'order_fields' => $answers,
        ]], []);
        foreach (['customer', 'shop'] as $kind) {
            self::assertStringContainsString('Foto huisdier: luna.jpg', $mails[$kind]['html'], $kind);
            self::assertStringContainsString('    Foto huisdier: luna.jpg', $mails[$kind]['text'], $kind);
            self::assertStringNotContainsString('order-field-upload', $mails[$kind]['html'], $kind . ': no link to the private file');
            self::assertStringNotContainsString($token, $mails[$kind]['html'] . $mails[$kind]['text'], $kind);
        }

        $confirmation = (string) file_get_contents(__DIR__ . '/../../src/Service/OrderConfirmationService.php');
        self::assertStringNotContainsString('order_field_uploads', $confirmation, 'the mails attach the invoice only, never a customer picture');
        self::assertStringNotContainsString('OrderFieldUpload', (string) file_get_contents(__DIR__ . '/../../src/Service/PdfInvoiceRenderer.php'), 'no picture on the invoice');
    }

    public function testTheAdminFileRouteIsGuardedAndNeverTakesAPath(): void
    {
        $route = (string) file_get_contents(__DIR__ . '/../../api/admin/order-field-upload.php');

        $login = strpos($route, 'AdminAuth::requireLogin()');
        $permission = strpos($route, "AdminAuth::requirePermission('orders.view')");
        $lookup = strpos($route, 'findClaimedForAdmin(');
        self::assertNotFalse($login);
        self::assertNotFalse($permission);
        self::assertTrue($login < $permission && $permission < $lookup, 'login, permission, then the record');
        self::assertStringContainsString("FILTER_VALIDATE_INT", $route, 'an integer id, never a file name');
        self::assertStringNotContainsString('$_GET[\'file', $route);
        self::assertStringNotContainsString('$_GET[\'path', $route);
        foreach (['X-Content-Type-Options: nosniff', 'Cache-Control: private, no-store', "Content-Security-Policy: default-src 'none'", 'Content-Disposition: ', 'application/octet-stream'] as $header) {
            self::assertStringContainsString($header, $route);
        }

        $screen = (string) file_get_contents(__DIR__ . '/../../admin/order.php');
        self::assertStringContainsString('/api/admin/order-field-upload.php?id=', $screen);
        self::assertStringContainsString("&variant=thumb", $screen);
        self::assertStringContainsString("&mode=download", $screen);
        self::assertStringNotContainsString('storage_name', $screen, 'the order screen never names a stored file');
    }

    /* ------------------------------------------------------------------ */

    private int $lastField = 0;

    /** @return array{0: int, 1: int} product id, image question id */
    private function imageProduct(string $name, ?int $mb = null): array
    {
        $product = $this->fixture->product($name);
        $this->save($product, 'nl', ['order_fields' => [
            'new0' => ['type' => 'image', 'label' => 'Foto huisdier', 'help' => '', 'required' => '1', 'max_file_size_mb' => $mb !== null ? (string) $mb : ''],
        ]]);
        $this->lastField = (new OrderFieldRepository())->fieldsForProduct($product)[0]['id'];

        return [$product, $this->lastField];
    }

    private function service(): OrderFieldUploads
    {
        return new OrderFieldUploads(
            Database::connection(),
            new OrderFieldUploadStorage($this->dir, static fn (string $from, string $to): bool => rename($from, $to)),
            new OrderFieldUploadValidator(static fn (string $path): bool => is_file($path))
        );
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function file(string $bytes, string $name, string $type = 'image/jpeg'): array
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);

        return ['name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    private function picture(int $type, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        match ($type) {
            IMAGETYPE_PNG => imagepng($image),
            IMAGETYPE_WEBP => imagewebp($image),
            default => imagejpeg($image),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** A tiny real PNG whose header claims 20000 × 20000 pixels. */
    private function bomb(): string
    {
        $png = $this->picture(IMAGETYPE_PNG, 1, 1);

        // IHDR data starts at byte 16: width and height, 4 bytes each.
        return substr($png, 0, 16) . pack('N', 20000) . pack('N', 20000) . substr($png, 24);
    }

    private function itemOf(int $order): int
    {
        return (int) Database::connection()->query('SELECT id FROM order_items WHERE order_id = ' . $order)->fetchColumn();
    }

    /**
     * An order line for this product whose answers are recorded and claimed.
     *
     * @param array<int, string> $answers
     */
    private function claim(int $product, array $answers, OrderFieldUploads $service): int
    {
        $order = $this->fixture->order([['product_id' => $product, 'quantity' => 1]]);
        $item = $this->itemOf($order);
        $orderFields = new OrderFields(null, $service);
        $checked = $orderFields->validate($product, array_combine(array_map('strval', array_keys($answers)), array_values($answers)), 'nl');

        $db = Database::connection();
        $db->beginTransaction();
        $orderFields->record($item, $product, $checked, 'nl');
        $db->commit();

        return $item;
    }

    /** @param array<string, mixed> $post */
    private function save(int $product, string $language, array $post): void
    {
        $db = Database::connection();
        $editor = ProductOrderFieldEditor::fromRequest($post + ['order_fields_present' => '1', 'order_fields_enabled' => '1'], $product, $language, $db);
        self::assertSame([], $editor->validate());

        $db->beginTransaction();
        $editor->save();
        $db->commit();
        ShopLocalization::clearCache();
    }
}
