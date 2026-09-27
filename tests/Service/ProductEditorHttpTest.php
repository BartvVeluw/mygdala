<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The product editor as ONE dynamic editor (admin/product-form.php,
 * api/admin/update-product.php, admin/_admin_editor.php), over real HTTP
 * against PHP's built-in server with the Shop on:
 *
 *  - the editor script's request (Accept: application/json) gets the one
 *    answer shape of App\Service\AdminEditorResponse — 200 and stored, or
 *    422 with every message under the field or section it is about and
 *    NOTHING stored, the product's own fields included;
 *  - the same request without the script still gets its redirect;
 *  - the whole Varianten section, pictures and descriptions included, is
 *    stored by that one request, and the endpoints that did it one row at a
 *    time are gone;
 *  - a new product lands in its own editor;
 *  - the screen is built the way the script expects it: two sections that
 *    fold, the variant pictures inside Varianten and not in Afbeeldingen.
 *
 * Its products and accounts are its own and are removed in tearDown().
 * Without a server the test skips itself, like the HTTP tier does.
 */
final class ProductEditorHttpTest extends TestCase
{
    private const JSON = ['Accept: application/json'];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $productIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM product_variants WHERE product_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        $this->accounts->forget();
        $this->productIds = [];
        ShopLocalization::clearCache();
    }

    public function testTheScriptsSaveStoresTheWholeProductAndAnswersInJson(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Editor Json');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'price' => '31,50',
            'options_present' => '1',
            'options' => ['new0' => ['name' => 'Kleur', 'display_type' => 'color']],
            'option_values' => ['new0' => ['new0' => ['value' => 'Noten', 'hex_color' => '#7B4A2A'], 'new1' => ['value' => 'Berken']]],
            'variants_present' => '1',
            'variants' => ['new0' => ['values' => ['new0' => 'new1'], 'price' => '', 'active' => '1']],
            'variants_submitted' => ['new0'],
            'variant_description_own' => ['new0' => '1'],
            'variant_description' => ['new0' => '<p>Eigen tekst</p>'],
        ]), [], self::JSON);

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('application/json', BuiltInServer::header($response, 'Content-Type'));
        $body = json_decode($response['body'], true);
        $this->assertSame(true, $body['ok']);
        $this->assertSame('Opgeslagen', $body['message']);
        $this->assertSame([], $body['errors']);
        $this->assertStringNotContainsString('<html', $response['body']);

        $this->assertSame('31.50', (string) (new ProductRepository())->findByIdForAdmin($productId)['price']);
        $variants = (new ProductVariantRepository())->findByProductId($productId);
        $this->assertCount(1, $variants);
        $this->assertSame('Berken', $variants[0]['values'][0]['value']);

        ShopLocalization::clearCache();
        $this->assertSame('<p>Eigen tekst</p>', ShopLocalization::variantOwnDescription((int) $variants[0]['id'], 'nl'), "a new variant's own text is stored under the id it just got");
    }

    /**
     * A refused save stores NOTHING — not the valid price next to the invalid
     * option — and says what is wrong under the name of each field.
     */
    public function testARefusedSaveStoresNothingAndNamesEveryField(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Editor Refused');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'price' => '99',
            'shipping_weight_grams' => 'zwaar',
            'options_present' => '1',
            'options' => ['new0' => ['name' => '', 'display_type' => 'standard']],
            'option_values' => ['new0' => ['new0' => ['value' => 'L']]],
        ]), [], self::JSON);

        $this->assertSame(422, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertFalse($body['ok']);
        $this->assertNotSame('', $body['message']);
        $this->assertArrayHasKey('shipping_weight_grams', $body['errors']);
        $this->assertArrayHasKey('options[new0][name]', $body['errors']);
        $this->assertIsList($body['errors']['options[new0][name]']);

        $this->assertSame('10.00', (string) (new ProductRepository())->findByIdForAdmin($productId)['price'], 'the valid price was not stored either');
        $this->assertSame([], (new ProductOptionRepository())->findByProductId($productId));
    }

    /** Without the script: the redirect and the session flash, as always. */
    public function testTheSameSaveWithoutTheScriptStillRedirects(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Editor Plain');

        $saved = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, ['price' => '11']));
        $this->assertSame(302, $saved['status']);
        $this->assertSame('/admin/product-form.php?id=' . $productId . '&updated=1', $saved['location']);

        $refused = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, ['price' => '']));
        $this->assertSame(302, $refused['status']);
        $this->assertSame('/admin/product-form.php?id=' . $productId, $refused['location']);
        $errors = $this->accounts->read($session, 'admin_product_errors');
        $this->assertIsList($errors);
        $this->assertNotEmpty($errors);
    }

    /** The guards answer before the contract does; the script reads their status codes. */
    public function testTheGuardsStillComeFirst(): void
    {
        [$session] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Editor Guards');

        $forged = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, 'wrong', ['price' => '50']), [], self::JSON);
        $this->assertSame(403, $forged['status']);

        [$viewer, $viewerCsrf] = $this->accounts->signIn([ShopModule::PRODUCTS_VIEW]);
        $forbidden = self::$server->request('POST', '/api/admin/update-product.php', $viewer, $this->fields($productId, $viewerCsrf, ['price' => '50']), [], self::JSON);
        $this->assertSame(403, $forbidden['status']);

        $signedOut = self::$server->request('POST', '/api/admin/update-product.php', null, $this->fields($productId, 'x', ['price' => '50']), [], self::JSON);
        $this->assertSame(401, $signedOut['status']);

        $this->assertSame('10.00', (string) (new ProductRepository())->findByIdForAdmin($productId)['price']);
    }

    public function testANewProductIsHandedToItsOwnEditor(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/create-product.php', $session, [
            'csrf_token' => $csrf,
            'name' => 'ZZ Editor Nieuw',
            'price' => '19,95',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ], [], self::JSON);

        $this->assertSame(200, $response['status']);
        $body = json_decode($response['body'], true);
        $id = (int) Database::connection()->query("SELECT id FROM products WHERE slug = 'zz-editor-nieuw'")->fetchColumn();
        $this->productIds[] = $id;
        $this->assertGreaterThan(0, $id);
        $this->assertSame('/admin/product-form.php?id=' . $id . '&created=1', $body['data']['redirect']);

        $refused = self::$server->request('POST', '/api/admin/create-product.php', $session, [
            'csrf_token' => $csrf, 'name' => 'ZZ Editor Geen Prijs', 'price' => '', 'shipping_profile' => 'letter', 'shipping_weight_grams' => '20',
        ], [], self::JSON);
        $this->assertSame(422, $refused['status']);
        $this->assertArrayHasKey('price', json_decode($refused['body'], true)['errors']);
    }

    /** One endpoint for the whole editor: the per-row ones are gone. */
    public function testTheOneRowAtATimeEndpointsAreGone(): void
    {
        foreach (['product-option', 'product-option-value', 'product-variant'] as $thing) {
            foreach (['create', 'update', 'delete', 'move'] as $verb) {
                $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/api/admin/' . $verb . '-' . $thing . '.php');
            }
        }
    }

    public function testTheEditorIsBuiltTheWayTheScriptReadsIt(): void
    {
        [$session] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->product('ZZ Editor Screen');
        $options = new ProductOptionRepository();
        $kleur = $options->createOption($productId, 'Kleur', 'color');
        $noten = $options->createValue($kleur, 'Noten', '#7B4A2A');
        $variant = (new ProductVariantRepository())->create($productId, [$noten], null, true);

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $productId, $session);
        $this->assertSame(200, $page['status']);
        $html = $page['body'];
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)/', $html);

        $this->assertMatchesRegularExpression('/<form[^>]+action="\/api\/admin\/update-product.php"[^>]+data-admin-editor/', $html);
        $this->assertSame(1, substr_count($html, '<form method="post" action="/api/admin/update-product.php"'), 'one form, one save');
        $this->assertStringNotContainsString('product-option.php', $html);
        $this->assertStringNotContainsString('product-variant.php', $html);

        // Two sections that fold, each on its own; folding is a <details>.
        $images = $this->between($html, 'data-admin-editor-section="images"', 'data-admin-editor-section="variants"');
        $variants = $this->between($html, 'data-admin-editor-section="variants"', 'data-admin-editor-section="seo"');
        $this->assertMatchesRegularExpression('/<details class="admin-collapse admin-collapse--card"[^>]*data-admin-collapse-id="images"/', $images);
        $this->assertMatchesRegularExpression('/<details class="admin-collapse admin-collapse--card"[^>]*data-admin-collapse-id="variants"/', $variants);

        // The product's own pictures in Afbeeldingen; a variant's pictures and
        // text only inside its row in Varianten.
        $this->assertStringContainsString('data-product-gallery', $images);
        $this->assertStringNotContainsString('data-variant-gallery', $images);
        $this->assertStringNotContainsString('variant_images[', $images);
        $this->assertStringContainsString('data-variant-gallery data-variant-id="' . $variant . '"', $variants);
        $this->assertStringContainsString('name="variant_description[' . $variant . ']"', $variants);

        // Rows by key, and the templates new rows are made from.
        $this->assertStringContainsString('name="options[' . $kleur . '][name]"', $variants);
        $this->assertStringContainsString('name="option_values[' . $kleur . '][' . $noten . '][value]"', $variants);
        $this->assertStringContainsString('<template data-row-list-template="product-options">', $variants);
        $this->assertStringContainsString('data-row-list-template="product-option-values-__KEY__" data-row-list-key="__VKEY__"', $variants);
        $this->assertStringContainsString('<template data-row-list-template="product-variants">', $variants);

        // A value a variant uses cannot be removed, and the screen says so.
        $this->assertMatchesRegularExpression('/data-value-key="' . $noten . '".*?data-value-remove disabled/s', $variants);

        // The editor's bar, dialog and script; no save bar of the old kind.
        $this->assertStringContainsString('data-admin-editor-bar', $html);
        $this->assertStringContainsString('data-admin-editor-leave-dialog', $html);
        $this->assertStringContainsString('/admin/assets/admin-editor.js', $html);
        $this->assertStringNotContainsString('/admin/assets/save-bar.js', $html);
    }

    /** @param array<string, mixed> $overrides */
    private function fields(int $productId, string $csrf, array $overrides): array
    {
        return $overrides + [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'nl',
            'name' => 'ZZ Editor',
            'description' => '',
            'price' => '10',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ];
    }

    private function product(string $name): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_editor_http_' . bin2hex(random_bytes(4)),
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name]);
        $this->productIds[] = $id;

        return $id;
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, $from);
        $end = strpos($html, $to, (int) $start);
        $this->assertNotFalse($end, $to);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
