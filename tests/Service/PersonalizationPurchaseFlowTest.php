<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\TestEnvironment;

/**
 * How a personalized product is BOUGHT, as a visitor meets it on
 * /product.php — rendered over real HTTP, because "there is exactly one
 * purchase action on this page and it is below the configurator" is precisely
 * what a unit test of the partial cannot prove.
 *
 * The server-side half of the same rule (an empty personalization is rejected
 * for a required product, whatever the browser sent) lives in
 * tests/Service/PersonalizationValidationTest.php — that is the one that
 * actually decides what an order may contain. This file is about the flow the
 * customer is guided through.
 *
 * Same helper and the same skip-when-unreachable guard as
 * tests/Service/PersonalizationProductPageTest.php.
 */
final class PersonalizationPurchaseFlowTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-personalization-purchase-';

    private ProductRepository $products;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<string> */
    private array $previewFiles = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
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

        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    private function createProduct(): int
    {
        $id = $this->products->create([
            'name' => 'Testproduct aankoopstroom',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 24.5,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    /** A real file, because the resolver only offers a view whose image exists. */
    private function createPreviewImage(): string
    {
        $dir = dirname(__DIR__, 2) . '/assets/images/personalization/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'zz-test-purchase-' . bin2hex(random_bytes(6)) . '.png';
        $path = $dir . $filename;

        $image = imagecreatetruecolor(120, 90);
        imagepng($image, $path);
        imagedestroy($image);

        $this->previewFiles[] = $path;

        return 'assets/images/personalization/' . $filename;
    }

    private function configure(int $productId, string $mode): void
    {
        PersonalizationTestConfig::configure(
            $productId,
            [[
                'view_key' => 'front',
                'image' => $this->createPreviewImage(),
                'zones' => [PersonalizationTestConfig::zone('name', ['is_required' => true, 'allow_image' => false])],
            ]],
            ['personalization_mode' => $mode]
        );
    }

    /** How many add-to-cart buttons the page renders. */
    private function addToCartCount(string $body): int
    {
        return substr_count($body, 'data-product-add-to-cart');
    }

    /* ------------------------------------------------------------------ */
    /* A normal product is completely unaffected                           */
    /* ------------------------------------------------------------------ */

    public function testANormalProductKeepsExactlyOneOrdinaryAddToCart(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertSame(1, $this->addToCartCount($body));
        $this->assertStringNotContainsString('data-personalizer', $body);
        $this->assertStringNotContainsString('personalization.js', $body);
        $this->assertStringNotContainsString('product-detail__personalize-cue', $body);

        // ...and it sits where it always sat: in the product column.
        $addPos = strpos($body, 'data-product-add-to-cart');
        $backPos = strpos($body, 'Terug naar producten');
        $this->assertLessThan($backPos, $addPos);
    }

    /* ------------------------------------------------------------------ */
    /* Personalization REQUIRED: one purchase action, below the editor     */
    /* ------------------------------------------------------------------ */

    public function testARequiredProductHasExactlyOnePurchaseActionAndItIsInsideTheConfigurator(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, PersonalizationRules::PURCHASE_REQUIRED);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertSame(
            1,
            $this->addToCartCount($body),
            'a required product must not offer two competing purchase flows'
        );

        $personalizerPos = strpos($body, 'data-personalizer-section');
        $addPos = strpos($body, 'data-product-add-to-cart');
        $summaryPos = strpos($body, 'personalizer__summary');

        $this->assertIsInt($personalizerPos);
        $this->assertIsInt($addPos);
        $this->assertIsInt($summaryPos);

        $this->assertGreaterThan($personalizerPos, $addPos, 'the only add-to-cart must live inside the configurator');
        $this->assertGreaterThan($summaryPos, $addPos, 'and inside its summary, after the configuration');
    }

    public function testARequiredProductPointsTheCustomerAtTheConfigurator(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, PersonalizationRules::PURCHASE_REQUIRED);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('product-detail__personalize-cue', $body);
        $this->assertStringContainsString('href="#personaliseren"', $body);
        $this->assertStringContainsString('id="personaliseren"', $body);
        $this->assertStringContainsString('"is_required":true', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Personalization OPTIONAL keeps the ordinary flow                    */
    /* ------------------------------------------------------------------ */

    /**
     * An OPTIONAL personalized product now follows the same rule as a
     * required one: the single purchase action sits at the END of the
     * configurator, so the customer never has to scroll back up after
     * finishing. It is still one button and still the same validated flow —
     * only its position changed.
     */
    public function testAnOptionalProductAlsoBuysFromTheEndOfTheConfigurator(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $this->configure($productId, PersonalizationRules::PURCHASE_OPTIONAL);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertSame(1, $this->addToCartCount($body), 'still exactly one purchase action');

        $personalizerPos = strpos($body, 'data-personalizer-section');
        $summaryPos = strpos($body, 'personalizer__summary');
        $addPos = strpos($body, 'data-product-add-to-cart');

        $this->assertIsInt($personalizerPos);
        $this->assertIsInt($summaryPos);
        $this->assertIsInt($addPos);
        $this->assertGreaterThan($personalizerPos, $addPos, 'the button lives inside the configurator');
        $this->assertGreaterThan($summaryPos, $addPos, 'and at its end, in the summary');

        // The product column points down at it instead of offering a second
        // button — but says "can" rather than "must", because personalizing
        // this one is optional.
        $this->assertStringContainsString('product-detail__personalize-cue', $body);
        $this->assertStringContainsString('Dit product kun je personaliseren.', $body);
        $this->assertStringContainsString('"is_required":false', $body);
    }

    /* ------------------------------------------------------------------ */
    /* The browser-side check explains itself                              */
    /* ------------------------------------------------------------------ */

    /**
     * The customer is told WHAT is missing, never merely handed a dead
     * button — and the message names the zone.
     */
    public function testTheEditorNamesWhatIsMissingRatherThanJustBlocking(): void
    {
        $editor = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/personalization.js');

        $this->assertStringContainsString('if (config.is_required && usedZoneKeys().length === 0)', $editor);
        $this->assertStringContainsString('Vul eerst je personalisatie in', $editor);
        $this->assertStringContainsString('dat is verplicht voor dit product', $editor);
        // ...and it brings the customer to the view holding the unfilled zone.
        $this->assertStringContainsString('switchView(viewOfZone[key].view_key);', $editor);
    }

    /**
     * The ordinary add-to-cart path runs through the personalizer's check and
     * then through the server's — there is no branch that skips either.
     */
    public function testTheAddToCartPathAlwaysConsultsThePersonalizer(): void
    {
        $main = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/shop/shop.js');
        $checkout = (string) file_get_contents(dirname(__DIR__, 2) . '/api/checkout.php');

        $this->assertStringContainsString('var personalizer = window.VVLPersonalization || null;', $main);
        $this->assertStringContainsString('personalizer.validate();', $main);
        $this->assertStringContainsString('personalizer.showError(personalizationError);', $main);

        // And checkout re-validates every line against the LIVE configuration.
        $this->assertStringContainsString('$personalizationValidator->validate(', $checkout);
    }
}
