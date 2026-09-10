<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationFontFixture;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\TestEnvironment;

/**
 * The personalization panel as a visitor meets it on /product.php — rendered
 * over real HTTP, because "this product page shows a personalization editor
 * and that one does not" is exactly the thing a unit test of the partial
 * cannot prove.
 *
 * Same helper and the same skip-when-unreachable guard as
 * tests/Service/RelatedProductsProductPageTest.php.
 *
 * The most important test in this file is the FIRST one: a product without
 * personalization must render byte-for-byte the page it rendered before this
 * feature existed — no panel, no configuration, not even the JavaScript.
 */
final class PersonalizationProductPageTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-personalization-page-';

    private ProductRepository $products;
    private ProductPersonalizationRepository $personalization;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<string> */
    private array $previewFiles = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->personalization = new ProductPersonalizationRepository();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->previewFiles as $path) {
            @unlink($path);
        }

        $this->productIds = [];
        $this->previewFiles = [];
        ProductPersonalizationContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function skipUnlessServerReachable(): void
    {
        if ($this->request('/product.php') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
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

    private function createProduct(bool $active = true): int
    {
        $id = $this->products->create([
            'name' => 'ZZ Personalisatie testproduct',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 9.95,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        return $id;
    }

    /** Writes a real PNG into the products upload folder and returns its stored path. */
    private function createPreviewImage(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 45, 30));

        $name = 'zz-test-personalization-' . bin2hex(random_bytes(6)) . '.png';
        $absolute = dirname(__DIR__, 2) . '/assets/images/products/' . $name;
        imagepng($image, $absolute);
        imagedestroy($image);

        $this->previewFiles[] = $absolute;

        return 'assets/images/products/' . $name;
    }

    /**
     * One view, one zone — the shape a Phase 1 product has, and still the
     * commonest case.
     *
     * @param array<string, mixed> $overrides
     */
    private function configure(int $productId, array $overrides = []): void
    {
        $withPreview = ($overrides['with_preview'] ?? true) === true;
        unset($overrides['with_preview']);

        $settings = [];
        foreach (['is_enabled', 'instructions', 'instructions_en'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $settings[$key] = $overrides[$key];
                unset($overrides[$key]);
            }
        }

        $zone = PersonalizationTestConfig::zone(
            \App\Service\Personalization\PersonalizationRules::DEFAULT_ZONE_KEY,
            $overrides + ['max_text_length' => 24]
        );

        PersonalizationTestConfig::configure($productId, [[
            'view_key' => \App\Service\Personalization\PersonalizationRules::DEFAULT_VIEW_KEY,
            'image' => $withPreview ? $this->createPreviewImage() : null,
            'zones' => [$zone],
        ]], $settings);
    }

    /**
     * Two views with a zone each, for the multi-view behaviour.
     *
     * @return array<string, mixed>
     */
    private function configureTwoViews(int $productId): array
    {
        return PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'front', 'label' => 'Voorkant', 'label_en' => 'Front',
                'image' => $this->createPreviewImage(),
                'zones' => [PersonalizationTestConfig::zone('name', [
                    'label' => 'Naam', 'label_en' => 'Name', 'allow_image' => false,
                    'is_required' => true, 'max_text_length' => 20,
                    'allowed_fonts' => ['trirong', 'georgia'], 'default_font' => 'trirong',
                ])],
            ],
            [
                'view_key' => 'back', 'label' => 'Achterkant', 'label_en' => 'Back',
                'image' => $this->createPreviewImage(),
                'zones' => [PersonalizationTestConfig::zone('message', [
                    'label' => 'Bericht', 'allow_image' => false, 'surcharge_cents' => 750,
                ])],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Backwards compatibility                                             */
    /* ------------------------------------------------------------------ */

    public function testANormalProductPageContainsNoPersonalizationAtAll(): void
    {
        $this->skipUnlessServerReachable();

        $response = $this->request('/product.php?id=' . $this->createProduct());

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('data-personalizer', $response['body']);
        $this->assertStringNotContainsString('personalization.js', $response['body']);
        $this->assertStringNotContainsString('Personaliseer je product', $response['body']);
    }

    public function testAProductPageStillRendersItsNormalContentAlongsideThePanel(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId);

        $response = $this->request('/product.php?id=' . $productId);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        // The page's own machinery is untouched by the panel living inside it.
        $this->assertStringContainsString('data-product-detail', $response['body']);
        $this->assertStringContainsString('data-product-add-to-cart', $response['body']);
        $this->assertStringContainsString('data-product-qty', $response['body']);
    }

    /* ------------------------------------------------------------------ */
    /* The panel appears exactly when it should                            */
    /* ------------------------------------------------------------------ */

    public function testThePanelAppearsForAConfiguredProduct(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId);

        $response = $this->request('/product.php?id=' . $productId);

        $this->assertNotNull($response);
        $this->assertStringContainsString('data-personalizer', $response['body']);
        $this->assertStringContainsString('data-personalizer-stage', $response['body']);
        $this->assertStringContainsString('assets/js/personalization.js', $response['body']);
    }

    public function testThePanelDisappearsAgainWhenPersonalizationIsSwitchedOff(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId);
        $this->assertStringContainsString('data-personalizer', $this->request('/product.php?id=' . $productId)['body']);

        // The way the CMS switches it off: the product-level flag, leaving the
        // views and zones exactly where they are.
        $this->personalization->saveSettings($productId, [
            'is_enabled' => false, 'instructions' => null, 'instructions_en' => null,
        ]);
        ProductPersonalizationContent::clearCache();

        $this->assertStringNotContainsString('data-personalizer', $this->request('/product.php?id=' . $productId)['body']);
    }

    public function testThePanelIsAbsentWithoutAPreviewImage(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, ['with_preview' => false]);

        $this->assertStringNotContainsString('data-personalizer', $this->request('/product.php?id=' . $productId)['body']);
    }

    /**
     * A deactivated product answers 404 and must not keep advertising a
     * personalization editor for something nobody can buy.
     */
    public function testAnInactiveProductRendersNoPanel(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct(false);
        $this->configure($productId);

        $response = $this->request('/product.php?id=' . $productId);

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('data-personalizer', $response['body']);
    }

    /* ------------------------------------------------------------------ */
    /* Only what is allowed is offered                                     */
    /* ------------------------------------------------------------------ */

    public function testTheTextFieldOnlyAppearsWhenTextIsAllowed(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, ['allow_text' => true, 'allow_image' => false]);

        $body = $this->request('/product.php?id=' . $productId)['body'];
        $this->assertStringContainsString('data-personalizer-text-input', $body);
        $this->assertStringNotContainsString('data-personalizer-file-input', $body);
    }

    public function testTheUploadFieldOnlyAppearsWhenImagesAreAllowed(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, ['allow_text' => false, 'allow_image' => true]);

        $body = $this->request('/product.php?id=' . $productId)['body'];
        $this->assertStringContainsString('data-personalizer-file-input', $body);
        $this->assertStringNotContainsString('data-personalizer-text-input', $body);
    }

    /* ------------------------------------------------------------------ */
    /* The configuration reaches the browser correctly                     */
    /* ------------------------------------------------------------------ */

    public function testTheEngravingAreaIsRenderedAsRelativePercentages(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, [
            'area_x' => 12.5,
            'area_y' => 20.0,
            'area_width' => 55.0,
            'area_height' => 18.75,
        ]);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('left:12.5%', $body);
        $this->assertStringContainsString('top:20%', $body);
        $this->assertStringContainsString('width:55%', $body);
        $this->assertStringContainsString('height:18.75%', $body);
    }

    public function testTheConfigurationBlockCarriesTheZoneRulesTheEditorNeeds(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, ['max_text_length' => 17]);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $matched = preg_match(
            '#<script type="application/json" data-personalizer-config>(.*?)</script>#s',
            $body,
            $matches
        );
        $this->assertSame(1, $matched, 'the personalization config block must be present');

        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true);

        $this->assertIsArray($config);
        $this->assertSame($productId, $config['product_id']);
        $this->assertSame('/api/personalization-upload.php', $config['upload_endpoint']);
        $this->assertCount(1, $config['views']);
        $this->assertCount(1, $config['views'][0]['zones']);

        $zone = $config['views'][0]['zones'][0];
        $this->assertSame(17, $zone['max_text_length']);
        $this->assertTrue($zone['allow_text']);
        $this->assertTrue($zone['allow_image']);
        $this->assertSame('both', $zone['mode']);
        $this->assertFalse($zone['is_required']);
        $this->assertSame(0, $zone['surcharge_cents']);
        // Fonts are GLOBAL as of Phase 3: they travel once, for the whole
        // configuration, as full definitions — so the editor can render the
        // preview in the face the customer picks without a second lookup, and
        // a zone carries no font list of its own at all.
        $this->assertArrayNotHasKey('fonts', $zone);
        $this->assertNotEmpty($config['fonts']);
        $this->assertArrayHasKey('stack', $config['fonts'][0]);
        $this->assertArrayHasKey('key', $config['fonts'][0]);
        $this->assertSame($config['fonts'][0]['key'], $config['default_font']);
        // assertEquals, not assertSame: a whole percentage survives the JSON
        // round trip as an int, which is the same coordinate either way.
        $this->assertEquals(
            ['x' => 25.0, 'y' => 35.0, 'width' => 50.0, 'height' => 30.0],
            $zone['area']
        );
        // The scale ratios must travel with the configuration, so the editor
        // and the server can never disagree about what "scale 1.0" means.
        $this->assertSame(
            PersonalizationRules::TEXT_BASE_HEIGHT_RATIO,
            $config['render']['text_base_height_ratio']
        );
        $this->assertSame(
            PersonalizationRules::IMAGE_BASE_WIDTH_RATIO,
            $config['render']['image_base_width_ratio']
        );
    }

    public function testTheMaximumTextLengthIsAlsoEnforcedByTheInputItself(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, ['max_text_length' => 9]);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('maxlength="9"', $body);
        $this->assertStringContainsString('/9', $body, 'the character counter must show the same limit');
    }

    /* ------------------------------------------------------------------ */
    /* Multiple views                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * A one-view product must keep the Phase 1 experience exactly: no tab
     * strip to navigate, because there is nothing to navigate between.
     */
    public function testASingleViewProductRendersNoTabs(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('data-personalizer', $body);
        $this->assertStringNotContainsString('personalizer__tabs', $body);
        $this->assertStringNotContainsString('data-personalizer-tab', $body);
    }

    public function testAMultiViewProductRendersOneTabPerViewWithItsOwnStage(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configureTwoViews($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('personalizer__tabs', $body);
        $this->assertStringContainsString('data-personalizer-tab="front"', $body);
        $this->assertStringContainsString('data-personalizer-tab="back"', $body);
        $this->assertStringContainsString('data-personalizer-stage="front"', $body);
        $this->assertStringContainsString('data-personalizer-stage="back"', $body);
        $this->assertStringContainsString('data-personalizer-panel="front"', $body);
        $this->assertStringContainsString('data-personalizer-panel="back"', $body);

        // Customer-facing labels, in both languages, never the internal keys.
        $this->assertStringContainsString('Voorkant', $body);
        $this->assertStringContainsString('Achterkant', $body);
        $this->assertStringContainsString('data-en="Front"', $body);
        $this->assertStringContainsString('data-en="Back"', $body);
    }

    /**
     * Only the first view starts visible; the others are in the DOM (so their
     * state survives switching) but hidden.
     */
    public function testOnlyTheFirstViewStartsVisible(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configureTwoViews($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertMatchesRegularExpression('/data-personalizer-stage="front"\s*>/', $body);
        $this->assertMatchesRegularExpression('/data-personalizer-stage="back"\s+hidden/', $body);
        $this->assertStringContainsString('aria-selected="true"', $body);
        $this->assertStringContainsString('aria-selected="false"', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Multiple zones                                                      */
    /* ------------------------------------------------------------------ */

    public function testEachZoneGetsItsOwnIndependentlyKeyedControls(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configureTwoViews($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        // Each text field is addressed by its own zone key, which is what
        // keeps two text zones from ever sharing one value.
        $this->assertStringContainsString('data-personalizer-text-input="name"', $body);
        $this->assertStringContainsString('data-personalizer-text-input="message"', $body);
        $this->assertStringContainsString('data-personalizer-zone="name"', $body);
        $this->assertStringContainsString('data-personalizer-zone="message"', $body);
        $this->assertStringContainsString('data-personalizer-text-count="name"', $body);
        $this->assertStringContainsString('data-personalizer-text-count="message"', $body);
    }

    public function testAZoneOnlyOffersTheControlsItsModeAllows(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'front',
            'image' => $this->createPreviewImage(),
            'zones' => [
                PersonalizationTestConfig::zone('naam', ['allow_image' => false]),
                PersonalizationTestConfig::zone('logo', ['allow_text' => false]),
            ],
        ]]);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('data-personalizer-text-input="naam"', $body);
        $this->assertStringNotContainsString('data-personalizer-file-input="naam"', $body);

        $this->assertStringContainsString('data-personalizer-file-input="logo"', $body);
        $this->assertStringNotContainsString('data-personalizer-text-input="logo"', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Fonts                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Every text zone offers the whole ACTIVE library — the customer chooses,
     * and nothing is assigned per zone. An inactive font is not merely hidden
     * from one product: it is absent from the page entirely.
     */
    public function testEveryTextZoneOffersTheGlobalFontLibrary(): void
    {
        $this->skipUnlessServerReachable();

        $fonts = new PersonalizationFontFixture();

        try {
            $active = $fonts->create('Fixture Zichtbaar', true);
            $inactive = $fonts->create('Fixture Verborgen', false);

            $productId = $this->createProduct();
            PersonalizationTestConfig::configure($productId, [[
                'view_key' => 'front',
                'image' => $this->createPreviewImage(),
                'zones' => [
                    PersonalizationTestConfig::zone('een'),
                    PersonalizationTestConfig::zone('twee'),
                ],
            ]]);

            $body = $this->request('/product.php?id=' . $productId)['body'];

            // Both zones get a selector, because the library has more than
            // one font — there is no per-zone font configuration any more.
            $this->assertStringContainsString('data-personalizer-font="een"', $body);
            $this->assertStringContainsString('data-personalizer-font="twee"', $body);

            $this->assertStringContainsString('Fixture Zichtbaar', $body);
            $this->assertStringNotContainsString('Fixture Verborgen', $body);
            $this->assertStringNotContainsString($inactive['font_key'], $body);
            $this->assertStringContainsString($active['font_key'], $body);
        } finally {
            $fonts->remove();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Required, rotation and pricing                                      */
    /* ------------------------------------------------------------------ */

    public function testARequiredZoneIsMarkedAsSuchForTheCustomer(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configureTwoViews($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('personalizer__required', $body);
        $this->assertStringContainsString('personalizer__optional', $body);
    }

    public function testRotationControlsAppearOnlyWhereTheZoneAllowsThem(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'front',
            'image' => $this->createPreviewImage(),
            'zones' => [
                PersonalizationTestConfig::zone('draaibaar', ['allow_rotation' => true]),
                PersonalizationTestConfig::zone('vast', ['allow_rotation' => false]),
            ],
        ]]);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('data-personalizer-rotation-zone="draaibaar"', $body);
        $this->assertStringNotContainsString('data-personalizer-rotation-zone="vast"', $body);
    }

    public function testAZoneSurchargeIsShownToTheCustomer(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configureTwoViews($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('personalizer__surcharge-tag', $body);
        $this->assertStringContainsString('7,50', $body);
        $this->assertStringContainsString('data-personalizer-price', $body);
    }

    /**
     * The panel may DISPLAY a surcharge, but the page must never carry
     * anything a browser could submit back as a price.
     */
    public function testThePageCarriesNoPriceFieldAClientCouldSubmit(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configureTwoViews($productId);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringNotContainsString('name="surcharge"', $body);
        $this->assertStringNotContainsString('name="price"', $body);
    }

    /**
     * The instructions are free text an administrator types into the CMS, so
     * they must reach the page escaped — never as markup.
     */
    public function testAdministratorInstructionsAreEscapedInTheMarkup(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, ['instructions' => 'Let op: <script>alert(1)</script> & "quotes"']);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }
}
