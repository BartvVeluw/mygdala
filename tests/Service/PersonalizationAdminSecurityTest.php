<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminPermissions;
use App\Service\AdminNavigation;
use App\Module\PersonalizationModule;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * The CMS half of product personalization: the endpoints that build a
 * product's configuration (views and zones), and the endpoint that hands the
 * owner a customer's uploaded file.
 *
 * A mix of live HTTP (an anonymous request must not get through) and static
 * source inspection (the ORDER of the guards, and the absence of any
 * request-controlled path component) — the same technique as
 * tests/Service/InvoiceAdminSecurityTest.php and
 * tests/Service/ProductDeletionAdminSecurityTest.php, which exist because
 * this project has no way to authenticate a PHPUnit request as an admin.
 *
 * The endpoint list is deliberately explicit: Phase 2 turned one save-all
 * endpoint into nine small ones, and every one of them has to carry the same
 * guards. A tenth added later without them should fail
 * testEveryPersonalizationEndpointIsGuardedIdentically().
 */
final class PersonalizationAdminSecurityTest extends TestCase
{
    /**
     * Every write endpoint of the Personalisatie CMS section that addresses
     * something by an integer id.
     */
    private const WRITE_ENDPOINTS = [
        'api/admin/create-product-personalization.php',
        'api/admin/delete-product-personalization.php',
        'api/admin/update-product-personalization.php',
        'api/admin/create-personalization-view.php',
        'api/admin/update-personalization-view.php',
        'api/admin/delete-personalization-view.php',
        'api/admin/move-personalization-view.php',
        'api/admin/create-personalization-zone.php',
        'api/admin/update-personalization-zone.php',
        'api/admin/delete-personalization-zone.php',
        'api/admin/move-personalization-zone.php',
        'api/admin/update-personalization-font.php',
        'api/admin/delete-personalization-font.php',
        'api/admin/move-personalization-font.php',
    ];

    /**
     * The font UPLOAD endpoint. Listed apart only because it addresses
     * nothing by id — it carries every other guard identically.
     */
    private const UPLOAD_ENDPOINT = 'api/admin/create-personalization-font.php';

    private const FILE_ENDPOINT = 'api/admin/order-personalization-file.php';

    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array{status: int, body: string, headers: array<int, string>}|null
     */
    private function request(string $path, ?array $post = null): ?array
    {
        $options = ['ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0];

        if ($post !== null) {
            $options['method'] = 'POST';
            $options['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
            $options['content'] = http_build_query($post);
        }

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, stream_context_create(['http' => $options]));
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $headers = $http_response_header ?? [];
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
    }

    private function skipUnlessServerReachable(): void
    {
        if ($this->request('/product.php') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Anonymous access                                                    */
    /* ------------------------------------------------------------------ */

    public function testNoPersonalizationWriteEndpointAcceptsAnAnonymousRequest(): void
    {
        $this->skipUnlessServerReachable();

        foreach (self::WRITE_ENDPOINTS as $endpoint) {
            $response = $this->request('/' . $endpoint, ['product_id' => '1', 'view_id' => '1', 'zone_id' => '1']);

            $this->assertNotNull($response, $endpoint);
            $this->assertSame(401, $response['status'], $endpoint . ' must refuse an anonymous POST');
        }
    }

    public function testAnAnonymousRequestCannotReadACustomersUploadedFile(): void
    {
        $this->skipUnlessServerReachable();

        $response = $this->request('/' . self::FILE_ENDPOINT . '?id=1');

        $this->assertNotNull($response);
        $this->assertSame(302, $response['status'], 'an unauthenticated admin page must redirect to the login form');
        $this->assertStringContainsString('/admin/login.php', implode("\n", $response['headers']));
        $this->assertSame('', trim($response['body']));
    }

    /* ------------------------------------------------------------------ */
    /* Every write endpoint carries the same guards                        */
    /* ------------------------------------------------------------------ */

    public function testEveryPersonalizationEndpointIsGuardedIdentically(): void
    {
        foreach (self::WRITE_ENDPOINTS as $endpoint) {
            $source = self::sourceOf($endpoint);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $endpoint);
            $this->assertStringContainsString("AdminAuth::requirePermissionForApi('personalization.manage')", $source, $endpoint);
            $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source, $endpoint);
            $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source, $endpoint);
            $this->assertStringContainsString('FILTER_VALIDATE_INT', $source, $endpoint);

            $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
            $permissionPos = strpos($source, 'AdminAuth::requirePermissionForApi(');
            $methodPos = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
            $csrfPos = strpos($source, 'Csrf::validate(');
            $idPos = strpos($source, 'FILTER_VALIDATE_INT');

            $this->assertLessThan($permissionPos, $authPos, "{$endpoint}: login before permission");
            $this->assertLessThan($methodPos, $permissionPos, "{$endpoint}: permission before the method check");
            $this->assertLessThan($csrfPos, $methodPos, "{$endpoint}: POST-only before CSRF");
            $this->assertLessThan($idPos, $csrfPos, "{$endpoint}: CSRF before any id is used");
        }
    }

    /**
     * The font upload carries the same guards in the same order; it simply
     * has no id to validate.
     */
    public function testTheFontUploadEndpointIsGuardedTheSameWay(): void
    {
        $source = self::sourceOf(self::UPLOAD_ENDPOINT);

        $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source);
        $this->assertStringContainsString("AdminAuth::requirePermissionForApi('personalization.manage')", $source);
        $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source);
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source);

        $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
        $permissionPos = strpos($source, 'AdminAuth::requirePermissionForApi(');
        $methodPos = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $csrfPos = strpos($source, 'Csrf::validate(');
        $storePos = strpos($source, '->store(');

        $this->assertLessThan($permissionPos, $authPos);
        $this->assertLessThan($methodPos, $permissionPos);
        $this->assertLessThan($csrfPos, $methodPos);
        $this->assertLessThan($storePos, $csrfPos, 'CSRF before anything is written to disk');
    }

    /**
     * An uploaded font file never keeps a name the browser chose, and the
     * file's own signature — not its extension or its Content-Type — decides
     * whether it is a font at all.
     */
    public function testAnUploadedFontFileIsRenamedAndSniffed(): void
    {
        $uploader = self::sourceOf('src/Service/Personalization/PersonalizationFontUploader.php');

        $this->assertStringContainsString('bin2hex(random_bytes(16))', $uploader);
        $this->assertStringContainsString('is_uploaded_file(', $uploader);
        $this->assertStringContainsString('move_uploaded_file(', $uploader);
        $this->assertStringContainsString('self::signatureMatches(', $uploader);
        $this->assertStringContainsString('MAX_BYTES', $uploader);

        // The client-supplied name reaches exactly one thing: pathinfo() for
        // the extension, and displayName() for a printable label.
        $this->assertStringContainsString("pathinfo((string) (\$file['name'] ?? ''), PATHINFO_EXTENSION)", $uploader);
        $this->assertStringContainsString('basename(str_replace(', $uploader);

        // And delete() cannot reach outside its own folder.
        $this->assertStringContainsString('str_starts_with($filePath, self::PUBLIC_PREFIX)', $uploader);
        $this->assertStringContainsString('basename($filePath)', $uploader);
    }

    /**
     * Personalisatie is its own CMS section with its own permission — holding
     * products.manage is no longer enough, and holding personalization.manage
     * does not hand out the product catalogue either.
     */
    public function testPersonalizationHasItsOwnPermissionAndNavigationEntry(): void
    {
        // Both are the module's own now (App\Module\PersonalizationModule),
        // not entries Core keeps a copy of — which is exactly why they
        // disappear when the module is switched off. Asserted on the merged
        // result as well as the source, so the wiring is proven and not just
        // the text.
        $module = self::sourceOf('src/Module/PersonalizationModule.php');

        $this->assertStringContainsString("PERSONALIZATION_MANAGE = 'personalization.manage'", $module);
        $this->assertStringContainsString("'key' => 'personalization'", $module);
        $this->assertStringContainsString("'url' => '/admin/personalization.php'", $module);

        foreach (['personalization.php', 'personalization-product.php', 'personalization-fonts.php'] as $script) {
            $this->assertStringContainsString("'" . $script . "'", $module, $script . ' must highlight the section');
        }

        $this->assertContains(PersonalizationModule::PERSONALIZATION_MANAGE, AdminPermissions::all());
        $this->assertContains(PersonalizationModule::PERSONALIZATION_MANAGE, AdminPermissions::enabled());

        $entry = null;
        foreach (AdminNavigation::items() as $item) {
            if ($item['key'] === 'personalization') {
                $entry = $item;
            }
        }

        $this->assertNotNull($entry, 'the sidebar must carry the Personalisatie section');
        $this->assertSame('/admin/personalization.php', $entry['url']);
        $this->assertSame(PersonalizationModule::PERSONALIZATION_MANAGE, $entry['permission']);
    }

    /**
     * The CMS pages behind that section guard themselves, exactly like every
     * other admin screen — hiding a menu entry is not access control.
     */
    public function testEveryPersonalizationAdminPageGuardsItself(): void
    {
        foreach (['admin/personalization.php', 'admin/personalization-product.php', 'admin/personalization-fonts.php'] as $page) {
            $source = self::sourceOf($page);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, $page);
            $this->assertStringContainsString("AdminAuth::requirePermission('personalization.manage')", $source, $page);
        }
    }

    /**
     * Removing a product's personalization removes the CONFIGURATION and
     * nothing else — the shop product itself is never touched.
     */
    public function testRemovingAConfigurationCannotDeleteTheProduct(): void
    {
        $endpoint = self::sourceOf('api/admin/delete-product-personalization.php');
        $repository = self::sourceOf('src/Repository/ProductPersonalizationRepository.php');

        $this->assertStringContainsString('deleteForProduct(', $endpoint);
        $this->assertStringNotContainsString('DELETE FROM products', $endpoint);
        $this->assertStringNotContainsString('DELETE FROM products', $repository);
        $this->assertStringContainsString(
            'DELETE FROM product_personalization_settings WHERE id = :id',
            $repository
        );
    }

    /**
     * Every one of these ends in a redirect back into the Personalisatie
     * section, and that target is always built from literals plus a
     * server-resolved integer id — so none of them can become an open
     * redirect.
     */
    public function testEveryRedirectTargetIsALocalAdminPath(): void
    {
        $sources = array_map([self::class, 'sourceOf'], self::WRITE_ENDPOINTS);
        $sources[] = self::sourceOf(self::UPLOAD_ENDPOINT);
        $sources[] = self::sourceOf('api/admin/_personalization_validation.php');

        $found = 0;
        foreach ($sources as $source) {
            preg_match_all('/header\(\s*.Location: ([^\'"]*)/', $source, $matches);
            foreach ($matches[1] as $target) {
                $found++;
                $this->assertStringStartsWith('/admin/', $target, "redirect target must be local, got: {$target}");
            }
        }

        $this->assertGreaterThan(0, $found, 'expected at least one Location redirect');
    }

    /**
     * The product id a view/zone endpoint redirects to (and authorises
     * against) comes from the DATABASE row, never from the request — so a
     * forged product_id cannot aim the result at another product.
     */
    public function testViewAndZoneEndpointsResolveTheirProductFromTheDatabase(): void
    {
        foreach ([
            'api/admin/update-personalization-view.php',
            'api/admin/delete-personalization-view.php',
            'api/admin/move-personalization-view.php',
            'api/admin/create-personalization-zone.php',
        ] as $endpoint) {
            $source = self::sourceOf($endpoint);
            $this->assertStringContainsString('findViewById(', $source, $endpoint);
            $this->assertStringContainsString("\$view['product_id']", $source, $endpoint);
            $this->assertStringNotContainsString("\$_POST['product_id']", $source, $endpoint);
        }

        foreach ([
            'api/admin/update-personalization-zone.php',
            'api/admin/delete-personalization-zone.php',
            'api/admin/move-personalization-zone.php',
        ] as $endpoint) {
            $source = self::sourceOf($endpoint);
            $this->assertStringContainsString('findZoneById(', $source, $endpoint);
            $this->assertStringContainsString("\$zone['product_id']", $source, $endpoint);
            $this->assertStringNotContainsString("\$_POST['product_id']", $source, $endpoint);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Non-destructive editing                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Personalization has its own endpoints precisely so an ordinary product
     * save cannot rewrite it, and vice versa. If either side ever starts
     * touching the other's data, this is the test that should fail.
     */
    public function testTheProductAndPersonalizationEndpointsStayOutOfEachOthersData(): void
    {
        $productEndpoint = self::sourceOf('api/admin/update-product.php');

        $this->assertStringNotContainsString('ProductPersonalizationRepository', $productEndpoint);
        $this->assertStringNotContainsString('personalization_enabled', $productEndpoint);

        foreach (self::WRITE_ENDPOINTS as $endpoint) {
            $source = self::sourceOf($endpoint);
            $this->assertStringNotContainsString('$productRepository->update(', $source, $endpoint);
            $this->assertStringNotContainsString('setProductCollections', $source, $endpoint);
        }
    }

    /**
     * A view's preview image is what every zone coordinate on that view is
     * measured against, so renaming a view must not be able to drop it:
     * updateView() may not write that column at all.
     */
    public function testRenamingAViewCannotDropItsPreviewImage(): void
    {
        $repository = self::sourceOf('src/Repository/ProductPersonalizationRepository.php');

        $start = strpos($repository, 'public function updateView(');
        $end = strpos($repository, 'public function updateViewPreviewImagePath(');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertGreaterThan($start, $end);

        $body = substr($repository, $start, $end - $start);
        $this->assertStringNotContainsString('preview_image_path', $body);
    }

    /**
     * Editing one zone must post only that zone. Two zones sharing a form is
     * exactly how "I renamed the back and the front lost its coordinates"
     * happens.
     */
    public function testEachZoneIsEditedThroughItsOwnFormAndItsOwnId(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        $this->assertStringContainsString('action="/api/admin/update-personalization-zone.php"', $builder);
        $this->assertStringContainsString('name="zone_id" value="<?= $zoneId ?>"', $builder);
        // The zone key is never READ from an update request: order rows point
        // at it, so it is set once at creation and never rewritten. (The
        // endpoint's docblock says so in prose; what matters is that no code
        // path takes it from $_POST.)
        $update = self::sourceOf('api/admin/update-personalization-zone.php');
        $this->assertStringNotContainsString("\$_POST['zone_key']", $update);
        $this->assertStringNotContainsString('normalizePersonalizationKey(', $update);

        $repository = self::sourceOf('src/Repository/ProductPersonalizationRepository.php');
        $start = strpos($repository, 'public function updateZone(');
        $end = strpos($repository, 'public function deleteZone(');
        $this->assertNotFalse($start);
        $this->assertStringNotContainsString('zone_key', substr($repository, $start, $end - $start));
    }

    /* ------------------------------------------------------------------ */
    /* Pricing can only come from the server                               */
    /* ------------------------------------------------------------------ */

    /**
     * The single most important pricing rule of Phase 2: a surcharge is read
     * from the product's own zone configuration and never from the customer's
     * request. Checkout must not so much as look at a submitted amount.
     */
    public function testCheckoutNeverReadsASurchargeFromTheRequest(): void
    {
        $validator = self::sourceOf('src/Service/Personalization/PersonalizationValidator.php');

        // The only assignment of a surcharge comes from the resolved zone.
        $this->assertStringContainsString("\$surchargeCents = \$zone['surcharge_cents'];", $validator);
        $this->assertStringNotContainsString("\$entry['surcharge", $validator);
        $this->assertStringNotContainsString("\$submitted['surcharge", $validator);

        $checkout = self::sourceOf('api/checkout.php');
        $this->assertStringContainsString("\$line['personalization']['surcharge_cents'] ?? 0", $checkout);
        $this->assertStringNotContainsString("\$item['surcharge", $checkout);
        $this->assertStringNotContainsString("\$body['surcharge", $checkout);
    }

    /**
     * Money that is summed across zones and lines must never pass through a
     * float — see App\Service\Personalization\Money.
     */
    public function testCheckoutPricesLinesInWholeCents(): void
    {
        $checkout = self::sourceOf('api/checkout.php');

        $this->assertStringContainsString('Money::toCents(', $checkout);
        $this->assertStringContainsString('$subtotalCents', $checkout);
        $this->assertStringContainsString('Money::format(', $checkout);
        $this->assertStringNotContainsString('$subtotal +=', $checkout);
        $this->assertStringNotContainsString('round($subtotal', $checkout);
    }

    /* ------------------------------------------------------------------ */
    /* The file endpoint                                                   */
    /* ------------------------------------------------------------------ */

    public function testTheFileEndpointRequiresLoginAndTheOrdersPermission(): void
    {
        $source = self::sourceOf(self::FILE_ENDPOINT);

        $this->assertStringContainsString('AdminAuth::requireLogin()', $source);
        $this->assertStringContainsString("AdminAuth::requirePermission('orders.view')", $source);

        $this->assertLessThan(
            strpos($source, 'readfile('),
            strpos($source, "AdminAuth::requirePermission('orders.view')"),
            'authorisation must be decided before a single byte is served'
        );
    }

    public function testTheFileEndpointNeverBuildsAPathFromTheRequest(): void
    {
        $source = self::sourceOf(self::FILE_ENDPOINT);

        $this->assertStringContainsString('FILTER_VALIDATE_INT', $source);
        $this->assertStringContainsString("(\$_GET['variant'] ?? 'original') === 'preview'", $source);
        $this->assertStringContainsString('PersonalizationUploadStorage', $source);
        $this->assertMatchesRegularExpression(
            '/\$storedFilename = \(string\) \(\$variant === \'preview\'/',
            $source
        );
        $this->assertStringNotContainsString('$_GET[\'file', $source);
        $this->assertStringNotContainsString('$_GET[\'path', $source);
        $this->assertStringNotContainsString('$_GET[\'filename', $source);
    }

    public function testTheStorageResolverStripsAnyDirectoryComponent(): void
    {
        $storage = self::sourceOf('src/Service/Personalization/PersonalizationUploadStorage.php');

        $pathPos = strpos($storage, 'public function path(');
        $this->assertNotFalse($pathPos);
        $this->assertStringContainsString('basename($storedFilename)', substr($storage, $pathPos, 300));
    }

    public function testTheFileEndpointServesOnlyImageContentTypes(): void
    {
        $source = self::sourceOf(self::FILE_ENDPOINT);

        $this->assertStringContainsString("['image/png', 'image/jpeg']", $source);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $source);
        $this->assertStringContainsString('Cache-Control: private, no-store', $source);
    }

    public function testAMissingFileFailsAsACleanNotFound(): void
    {
        $source = self::sourceOf(self::FILE_ENDPOINT);

        $this->assertStringContainsString('if (!is_file($path))', $source);
        $this->assertStringContainsString('http_response_code(404)', $source);
        $this->assertStringContainsString('error_log(', $source);
        $this->assertStringNotContainsString('exit($path', $source);
    }

    /* ------------------------------------------------------------------ */
    /* The public endpoints never touch the original                       */
    /* ------------------------------------------------------------------ */

    public function testThePublicImageEndpointCanOnlyEverServeThePreviewCopy(): void
    {
        $source = self::sourceOf('api/personalization-image.php');

        $this->assertStringContainsString("\$upload['preview_filename']", $source);
        $this->assertStringNotContainsString('stored_filename', $source);
        $this->assertStringContainsString('isValidUploadToken', $source);

        $this->assertLessThan(
            strpos($source, 'findByToken('),
            strpos($source, 'isValidUploadToken('),
            'the token shape must be checked before any database lookup'
        );
    }

    public function testTheUploadEndpointNeverEchoesAStoredFilename(): void
    {
        $source = self::sourceOf('api/personalization-upload.php');

        $responsePos = strrpos($source, 'echo json_encode(');
        $this->assertNotFalse($responsePos);

        $response = substr($source, $responsePos);
        $this->assertStringNotContainsString('stored_filename', $response);
        $this->assertStringNotContainsString('preview_filename', $response);
        $this->assertStringContainsString("'token' => \$token", $response);
    }

    /**
     * Phase 2 did not relax any Phase 1 upload rule.
     */
    public function testTheUploadSecurityModelIsUnchanged(): void
    {
        $rules = self::sourceOf('src/Service/Personalization/PersonalizationRules.php');
        $validator = self::sourceOf('src/Service/Personalization/PersonalizationUploadValidator.php');

        $this->assertStringContainsString('IMAGETYPE_JPEG', $rules);
        $this->assertStringContainsString('IMAGETYPE_PNG', $rules);
        $this->assertStringNotContainsString('IMAGETYPE_WEBP', $rules);
        $this->assertStringNotContainsString('svg', strtolower(substr($rules, strpos($rules, 'ALLOWED_UPLOAD_TYPES'), 400)));

        $this->assertStringContainsString('is_uploaded_file($tmpName)', $validator);
        $this->assertStringContainsString('@getimagesize($tmpName)', $validator);
        $this->assertStringContainsString('MAX_UPLOAD_BYTES', $validator);
        $this->assertStringContainsString('ImageOptimizer::process(', $validator);
    }
}
