<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\FeaturedProductRepository;
use App\Repository\OrderFieldRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductSpecificationRepository;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\BlockSamples;
use App\Service\FeaturedProductContent;
use App\Service\PageContent;
use App\Service\Personalization\ProductPersonalizationContent;
use App\Service\ProductDetail;
use App\Service\ProductGalleryTransition;
use App\Service\ProductSeo;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\ShopStockFixture;

/**
 * Uitgelicht product (featured_product) as a page renders it, on the test
 * database: real products, real stock, real order questions, one real page.
 *
 *   - a Shop block: registered by the Shop, filed under Shop, gone with the
 *     Shop off while its rows stay, and back with the same configuration;
 *   - a new block has no product and renders nothing; so do a hidden or
 *     missing row, a product that is inactive and one that was deleted;
 *   - the product is live: the block shows what the product is NOW, and no
 *     column of the block holds any of it;
 *   - the content switches, the intro and the button's label per language,
 *     with "Bekijk product" / "View product" when none is typed;
 *   - ordering: the cart only with "Direct bestellen" AND a product sold
 *     directly; "op aanvraag", a configurator and "not orderable right now"
 *     are the product page's answers (App\Service\ProductPurchasePath);
 *   - no price where the product hides it, in the markup or the payload,
 *     and no price at all when the block neither shows one nor sells;
 *   - stock and variants arrive exactly as api/product.php publishes them;
 *   - two blocks on one page never share an id or a radio group;
 *   - the gallery choices, the layout classes and the transition word;
 *   - the payload cannot end its script element, and the block adds no
 *     structured data of its own.
 */
final class FeaturedProductBlockTest extends TestCase
{
    private const KEY = 'zz-featured-product-test';

    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $specifications = [];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        $this->shop = new ShopStockFixture();
        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Uitgelicht-test');
        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        $this->removePage();
        $this->shop->cleanUp();
        foreach ($this->specifications as $id) {
            (new ProductSpecificationRepository())->delete($id);
        }
        SiteSettings::overrideForTests(null);
        RequestLanguage::reset();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        $this->clearCaches();
    }

    // ------------------------------------------------------------ the block

    public function testItIsAShopBlockFiledUnderShop(): void
    {
        $definition = BlockDefinitions::get('featured_product');

        self::assertNotNull($definition);
        self::assertSame('shop', BlockDefinitions::moduleOwnerOf('featured_product'));
        self::assertSame(BlockCategories::SHOP, $definition->category());
        self::assertSame('featured_products', $definition->contentTable());
        self::assertTrue($definition->meta()['manual_add']);
        self::assertTrue($definition->meta()['allow_multiple'], 'more than one product per page');
        self::assertSame(['assets/css/shop/shop.css', 'assets/css/shop/featured-product.css'], $definition->styles());
        self::assertSame(['assets/js/shop/product-gallery.js', 'assets/js/shop/shop.js'], $definition->scripts(), 'the product page\'s own scripts, the gallery controller first');
        self::assertSame(['featured_products' => ['intro', 'link_label']], array_map(
            static fn (array $fields): array => array_map(static fn ($field): string => $field->key, $fields),
            $definition->translatableFields()
        ));
        foreach ($definition->translatableFields()['featured_products'] as $field) {
            self::assertFalse($field->required, $field->key . ' is optional: a block may be saved without words');
        }
    }

    public function testWithTheShopOffTheBlockIsGoneAndItsRowsStayAndComeBack(): void
    {
        $product = $this->shop->product('Wolvenpenning', null, 24.50);
        $row = $this->place(['product_id' => $product]);

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'portfolio' => true, 'multilingual' => true]);
        BlockDefinitions::reset();

        self::assertFalse(BlockDefinitions::has('featured_product'));
        self::assertFalse(SectionRegistry::exists('featured_product'));
        self::assertSame('shop', SectionRegistry::disabledModuleFor('featured_product'));
        self::assertNotContains(BlockCategories::SHOP, array_map(
            static fn ($definition): string => $definition->category(),
            array_values(BlockDefinitions::all())
        ), 'no Shop heading in the picker: nothing is filed under it');

        ob_start();
        SectionRegistry::renderPage(self::KEY);
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('featured-product', $html, 'the page skips it without an error');

        self::assertSame($product, (int) (new FeaturedProductRepository())->findById((int) $row['section_id'])['product_id'], 'the row is kept');

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        $this->clearCaches();

        self::assertStringContainsString('Wolvenpenning', $this->render($row), 'back with the same configuration');
    }

    // ---------------------------------------------------- nothing to show

    public function testANewBlockHasNoProductAndShowsNothingUntilOneIsChosen(): void
    {
        $row = $this->place([]);
        $stored = (new FeaturedProductRepository())->findById((int) $row['section_id']);

        self::assertNull($stored['product_id']);
        self::assertSame(
            ['show_name' => 1, 'show_price' => 1, 'show_description' => 1, 'show_specifications' => 0, 'image_mode' => 'gallery', 'image_position' => 'left', 'image_size' => 'medium', 'content_align' => 'left', 'ordering' => 'direct', 'show_product_link' => 1, 'is_active' => 1],
            array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, array_intersect_key($stored, array_flip(['show_name', 'show_price', 'show_description', 'show_specifications', 'image_mode', 'image_position', 'image_size', 'content_align', 'ordering', 'show_product_link', 'is_active'])))
        );
        self::assertSame('', trim($this->render($row)), 'no empty frame, no room');
    }

    public function testAHiddenOrMissingRowShowsNothing(): void
    {
        $product = $this->shop->product('Verborgen', null, 5.00);
        $row = $this->place(['product_id' => $product]);
        Database::connection()->prepare('UPDATE featured_products SET is_active = 0 WHERE id = :id')->execute(['id' => (int) $row['section_id']]);
        $this->clearCaches();

        self::assertSame(FeaturedProductContent::STATE_HIDDEN, FeaturedProductContent::forSection(self::KEY, (string) $row['section_key'])['state']);
        self::assertSame('', trim($this->render($row)));

        $missing = FeaturedProductContent::forSection(self::KEY, 'custom-nothere');
        self::assertSame(FeaturedProductContent::STATE_FALLBACK, $missing['state']);
        self::assertNull($missing['product']);
    }

    public function testAnInactiveProductShowsNothingAndADeletedOneLeavesAnEmptyBlock(): void
    {
        $product = $this->shop->product('Straks inactief', null, 5.00);
        $row = $this->place(['product_id' => $product]);
        self::assertStringContainsString('Straks inactief', $this->render($row));

        (new ProductRepository())->setActive($product, false);
        $this->clearCaches();
        self::assertSame('', trim($this->render($row)), 'nothing of a product a visitor may not see');
        self::assertSame(FeaturedProductContent::PRODUCT_UNAVAILABLE, FeaturedProductContent::productStatus($product), 'the editor says so');

        (new ProductRepository())->delete($product);
        $this->clearCaches();
        self::assertNull((new FeaturedProductRepository())->findById((int) $row['section_id'])['product_id'], 'ON DELETE SET NULL: the block is empty, never in the way of the delete');
        self::assertSame('', trim($this->render($row)));
        self::assertSame(FeaturedProductContent::PRODUCT_NONE, FeaturedProductContent::productStatus($product));
    }

    // ------------------------------------------------------ live product

    public function testTheProductIsLiveAndNothingOfItIsCopiedIntoTheBlock(): void
    {
        $product = $this->shop->product('Oude naam', null, 10.00);
        $row = $this->place(['product_id' => $product]);
        self::assertStringContainsString('Oude naam', $this->render($row));

        ShopLocalization::saveProduct($product, 'nl', [ShopLocalization::NAME => 'Nieuwe naam', ShopLocalization::DESCRIPTION => '<p>Nieuwe tekst</p>', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);
        (new ProductImageRepository())->create($product, 'assets/images/products/zz-featured-live.png');
        $this->clearCaches();

        $html = $this->render($row);
        self::assertStringContainsString('>Nieuwe naam</h2>', $html);
        self::assertStringContainsString('Nieuwe tekst', $html);
        self::assertSame('assets/images/products/zz-featured-live.png', $this->payload($html)['images'][0]['image_path'], 'a new picture is in the block at once');

        $columns = array_column(Database::connection()->query('SHOW COLUMNS FROM featured_products')->fetchAll(), 'Field');
        foreach (['name', 'price', 'image_path', 'media_id', 'description', 'stock'] as $productData) {
            self::assertNotContains($productData, $columns, 'no copy of the product: ' . $productData);
        }
    }

    public function testThePayloadIsTheProductPagesOwn(): void
    {
        $product = $this->shop->product('Zelfde als de API', 3, 12.00);
        $row = $this->place(['product_id' => $product]);

        self::assertSame(
            json_decode((string) json_encode(ProductDetail::forPublic($product, 'nl')), true),
            $this->payload($this->render($row)),
            'the block draws exactly what GET /api/product.php answers'
        );
    }

    // --------------------------------------------------- content switches

    public function testEachPartCanBeSwitchedOff(): void
    {
        $product = $this->shop->product('Alles aan', null, 19.95);
        ShopLocalization::saveProduct($product, 'nl', [ShopLocalization::NAME => 'Alles aan', ShopLocalization::DESCRIPTION => '<p>De eigen tekst van het product</p>', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);
        $this->specification($product, 'Dikte', 'mm', '3');

        $all = $this->render($this->place(['product_id' => $product, 'show_specifications' => true]));
        self::assertStringContainsString('data-product-name>Alles aan</h2>', $all);
        self::assertStringContainsString('data-product-price', $all);
        self::assertStringContainsString('De eigen tekst van het product', $all);
        self::assertStringContainsString('<dt>Dikte</dt>', $all);
        self::assertStringContainsString('<dd>3 mm</dd>', $all);
        self::assertStringContainsString('>Bekijk product<span class="visually-hidden">: Alles aan</span></a>', $all, 'a screen reader hears which product');

        $none = $this->render($this->place([
            'product_id' => $product,
            'show_name' => false,
            'show_price' => false,
            'show_description' => false,
            'show_specifications' => false,
            'show_product_link' => false,
        ]));
        $shown = $this->withoutPayload($none);
        self::assertStringNotContainsString('data-product-name', $shown);
        self::assertStringNotContainsString('data-product-price', $shown);
        self::assertStringNotContainsString('De eigen tekst van het product', $shown, 'only the payload still knows it, for a variant\'s own text');
        self::assertStringNotContainsString('product-specs', $shown);
        self::assertStringNotContainsString('featured-product__link', $shown);
        self::assertStringContainsString('data-product-add-to-cart', $none, 'the cart does not depend on the text switches');

        self::assertStringNotContainsString('product-specs', $this->render($this->place(['product_id' => $product])), 'specifications start switched off');
    }

    public function testTheIntroAndTheButtonAreTheBlocksOwnWordsPerLanguage(): void
    {
        $product = $this->shop->product('Wolvenpenning', null, 24.50);
        ShopLocalization::saveProduct($product, 'en', [ShopLocalization::NAME => 'Wolf token', ShopLocalization::DESCRIPTION => '', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);

        $plain = $this->place(['product_id' => $product]);
        $own = $this->place(['product_id' => $product], [
            'nl' => ['intro' => 'Een van onze populairste wolvenpenningen.', 'link_label' => 'Meer informatie'],
            'en' => ['intro' => 'One of our most popular wolf tokens.', 'link_label' => 'More information'],
        ]);
        $dutchOnly = $this->place(['product_id' => $product], ['nl' => ['intro' => 'Alleen in het Nederlands']]);

        $nl = $this->render($own);
        self::assertStringContainsString('<p class="featured-product__intro">Een van onze populairste wolvenpenningen.</p>', $nl);
        self::assertStringContainsString('>Meer informatie<span class="visually-hidden">: ', $nl);
        self::assertStringContainsString('>Bekijk product<span class="visually-hidden">: ', $this->render($plain), 'the default label');
        self::assertStringNotContainsString('featured-product__intro', $this->render($plain), 'no intro, no element');

        RequestLanguage::set('en', true);
        $this->clearCaches();
        $en = $this->render($own);
        self::assertStringContainsString('One of our most popular wolf tokens.', $en);
        self::assertStringContainsString('>More information<span class="visually-hidden">: ', $en);
        self::assertStringContainsString('>Wolf token</h2>', $en, 'the product\'s own words in the language of the page');
        self::assertStringContainsString('>View product<span class="visually-hidden">: ', $this->render($plain));
        self::assertStringContainsString('Alleen in het Nederlands', $this->render($dutchOnly), 'an empty translation falls back to the default language, like every block');
        self::assertStringContainsString('href="' . htmlspecialchars(ProductSeo::publicPath($product, 'en'), ENT_QUOTES) . '"', $en, 'the button leads to the product page in the language being read');
    }

    // ------------------------------------------------------------ ordering

    public function testDirectOrderingOffersTheProductPagesCartAndViewOnlyDoesNot(): void
    {
        $product = $this->shop->product('Te koop', null, 15.00);

        $direct = $this->render($this->place(['product_id' => $product]));
        foreach (['data-product-add-row', 'data-product-qty', 'data-product-add-to-cart', 'data-product-sold-out', 'data-product-notify'] as $hook) {
            self::assertStringContainsString($hook, $direct, $hook);
        }

        $view = $this->render($this->place(['product_id' => $product, 'ordering' => 'view']));
        foreach (['data-product-add-row', 'data-product-qty', 'data-product-add-to-cart', 'data-product-notify', 'data-product-order-fields'] as $hook) {
            self::assertStringNotContainsString($hook, $view, $hook . ' is not offered by a view-only block');
        }
        self::assertStringContainsString('data-product-sold-out', $view, '"Uitverkocht" still says what is available');
        self::assertStringContainsString('data-product-variants', $view, 'the variants can still be looked at');
        self::assertStringContainsString('featured-product__link', $view, 'the product page is one click away');
    }

    public function testAnInquiryProductNeverShowsAPriceOrACartWhateverTheBlockSays(): void
    {
        ['product' => $product] = $this->shop->variantProduct('Maatwerk', ['Eiken' => 0, 'Noten' => 0], false, 99.00);
        Database::connection()->prepare('UPDATE product_variants SET price = 120.00 WHERE product_id = :id')->execute(['id' => $product]);
        (new ProductRepository())->updatePurchaseMode($product, 'inquiry');
        $this->clearCaches();

        $direct = $this->render($this->place(['product_id' => $product, 'show_price' => true]));
        $view = $this->render($this->place(['product_id' => $product, 'show_price' => true, 'ordering' => 'view']));

        foreach ([$direct, $view] as $html) {
            self::assertStringNotContainsString('data-product-price', $html);
            self::assertStringNotContainsString('data-product-add-to-cart', $html);
            self::assertStringNotContainsString('data-product-qty', $html);
            self::assertStringNotContainsString('99.00', $html);
            self::assertStringNotContainsString('120.00', $html);
            self::assertStringNotContainsString('99,00', $html);
            $payload = $this->payload($html);
            self::assertNull($payload['price']);
            self::assertTrue($payload['inquiry']);
            foreach ($payload['variants'] as $variant) {
                self::assertNull($variant['price'], 'no variant price either');
            }
            self::assertStringContainsString('data-product-variants', $html, 'the variant picker stays');
            self::assertStringContainsString('featured-product__link', $html, 'the button to the product page stays');
        }

        self::assertStringContainsString('data-product-inquiry', $direct, 'the product page\'s own "op aanvraag" box');
        self::assertStringContainsString('featured-product__price--inquiry">Op aanvraag</p>', $view, 'the cards\' own words where the price would be');
    }

    /**
     * REGRESSION: "Prijs tonen" off meant only "not shown" while the block
     * sold, and the product's real price still travelled in the payload for
     * the cart line. Now the real price — the product's and a variant's — is
     * nowhere in the block's output: not in the markup, not in a data-*
     * attribute, not in the payload, whether the block sells or not. The
     * cart line asks GET /api/product.php when a visitor adds
     * (Tests\Service\FeaturedProductHttpTest, FeaturedProductContractTest).
     */
    public function testWithThePriceOffTheRealPriceIsNowhereInTheBlock(): void
    {
        ['product' => $plate, 'variants' => $variants] = $this->shop->variantProduct('Geen prijs zichtbaar', ['A' => 3, 'B' => 5], true, 73.19);
        Database::connection()->prepare('UPDATE product_variants SET price = 81.37 WHERE id = :id')->execute(['id' => $variants['B']]);
        $fields = new OrderFieldRepository();
        $fields->setEnabled($plate, true);
        $question = $fields->createField($plate, 'text', true, 20, 0);
        ShopLocalization::saveOrderField($question, 'nl', [ShopLocalization::LABEL => 'Naam op het bord']);
        $this->clearCaches();

        $blocks = [
            'direct' => $this->render($this->place(['product_id' => $plate, 'show_price' => false])),
            'view' => $this->render($this->place(['product_id' => $plate, 'show_price' => false, 'ordering' => 'view'])),
        ];

        foreach ($blocks as $mode => $html) {
            // The amounts in every form a page could carry them; a bare
            // "73" could be part of an id or a random slug.
            foreach (['73.19', '73,19', '81.37', '81,37'] as $amount) {
                self::assertStringNotContainsString($amount, $html, $mode . ': ' . $amount);
            }
            self::assertStringNotContainsString('data-product-price', $html, $mode);
            self::assertDoesNotMatchRegularExpression('/data-[a-z-]*price/', $html, $mode . ': no price attribute of any name');

            $payload = $this->payload($html);
            self::assertNull($payload['price'], $mode);
            foreach ($payload['variants'] as $variant) {
                self::assertNull($variant['price'], $mode . ': variant ' . $variant['id']);
            }
        }

        // Selling still works: the whole purchase area is there.
        foreach (['data-product-add-to-cart', 'data-product-qty', 'data-product-variants', 'Naam op het bord'] as $part) {
            self::assertStringContainsString($part, $blocks['direct'], $part);
        }

        // And with "Prijs tonen" on, the price is there to be shown.
        self::assertSame('73.19', $this->payload($this->render($this->place(['product_id' => $plate])))['price']);
    }

    public function testAPersonalizableProductIsBoughtThroughItsConfiguratorOnly(): void
    {
        $product = $this->shop->product('Met personalisatie', null, 25.00);
        PersonalizationTestConfig::singleZone($product);
        ProductPersonalizationContent::clearCache();
        $this->clearCaches();

        $html = $this->render($this->place(['product_id' => $product]));

        self::assertStringNotContainsString('data-product-add-to-cart', $html, 'never a plain add-to-cart that skips the configurator');
        self::assertStringContainsString('product-detail__personalize-cue', $html);
        self::assertStringContainsString('href="' . htmlspecialchars(ProductSeo::publicPath($product), ENT_QUOTES) . '#personaliseren"', $html, 'to the configurator on the product page');
    }

    public function testAProductWithNoWayToOrderRightNowOffersNoCart(): void
    {
        $product = (new ProductRepository())->create([
            'slug' => '__test_featured_' . bin2hex(random_bytes(4)),
            'price' => 9.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        $this->shop->trackProduct($product);
        ShopLocalization::saveProduct($product, 'nl', [ShopLocalization::NAME => 'Alleen personaliseren', ShopLocalization::DESCRIPTION => '', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);
        $this->clearCaches();

        $html = $this->render($this->place(['product_id' => $product]));

        self::assertStringContainsString('Alleen personaliseren', $html);
        self::assertStringNotContainsString('data-product-add-to-cart', $html);
        self::assertStringContainsString('Dit product is op dit moment niet te bestellen.', $html);
    }

    // --------------------------------------------------------- stock

    public function testStockArrivesAsTheProductPagePublishesIt(): void
    {
        $two = $this->shop->product('Nog twee', 2, 12.00);
        $none = $this->shop->product('Op', 0, 8.00);
        ['product' => $plate, 'variants' => $variants] = $this->shop->variantProduct('Naamplaat', ['A' => 0, 'B' => 5]);

        $payload = $this->payload($this->render($this->place(['product_id' => $two])));
        self::assertFalse($payload['sold_out']);
        self::assertSame(2, $payload['max_quantity'], 'at most what is left, never the figure itself beyond that');

        self::assertTrue($this->payload($this->render($this->place(['product_id' => $none])))['sold_out']);

        $variantsById = array_column($this->payload($this->render($this->place(['product_id' => $plate])))['variants'], null, 'id');
        self::assertTrue($variantsById[$variants['A']]['sold_out']);
        self::assertFalse($variantsById[$variants['B']]['sold_out']);
        self::assertSame(5, $variantsById[$variants['B']]['max_quantity']);
    }

    // ------------------------------------------------ questions and ids

    public function testTwoBlocksOfOneProductShareNoIdAndNoRadioGroup(): void
    {
        $product = $this->shop->product('Naambord', null, 19.95);
        $fields = new OrderFieldRepository();
        $fields->setEnabled($product, true);
        $name = $fields->createField($product, 'text', true, 20, 0);
        $wood = $fields->createField($product, 'radio', true, null, 1);
        $oak = $fields->createOption($wood, 0);
        ShopLocalization::saveOrderField($name, 'nl', [ShopLocalization::LABEL => 'Naam op het bord']);
        ShopLocalization::saveOrderField($wood, 'nl', [ShopLocalization::LABEL => 'Houtsoort']);
        ShopLocalization::saveOrderFieldOption($oak, 'nl', 'Eiken');
        $this->clearCaches();

        $first = $this->place(['product_id' => $product]);
        $second = $this->place(['product_id' => $product]);
        $html = $this->render($first) . $this->render($second);

        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        self::assertNotEmpty($ids[1]);
        self::assertSame($ids[1], array_values(array_unique($ids[1])), 'every id once on the page');

        preg_match_all('/type="radio" name="([^"]+)"/', $html, $radios);
        self::assertCount(2, array_unique($radios[1]), 'one radio group per block');
        self::assertStringContainsString('Naam op het bord', $html);
        self::assertStringContainsString('data-order-field-required', $html);

        $view = $this->render($this->place(['product_id' => $product, 'ordering' => 'view']));
        self::assertStringNotContainsString('Naam op het bord', $view, 'no questions without a cart');
    }

    // ------------------------------------------------ pictures and layout

    public function testThePicturesAndTheLayoutAreWordsFromClosedLists(): void
    {
        $product = $this->shop->product('Beeld', null, 10.00);
        foreach (['een', 'twee', 'drie'] as $name) {
            (new ProductImageRepository())->create($product, 'assets/images/products/zz-featured-' . $name . '.png');
        }
        $this->clearCaches();

        $default = $this->render($this->place(['product_id' => $product]));
        self::assertStringContainsString('<div class="featured-product" data-product-detail', $default, 'the defaults add no class');
        self::assertStringContainsString('data-product-thumbs', $default, 'a gallery by default');
        self::assertStringNotContainsString('data-gallery-main-only', $default);
        self::assertCount(3, $this->payload($default)['images'], 'every picture of the product, from the product');

        $chosen = $this->render($this->place(['product_id' => $product, 'image_mode' => 'main', 'image_position' => 'right', 'image_size' => 'large', 'content_align' => 'center']));
        self::assertStringContainsString('class="featured-product featured-product--image-right featured-product--image-large featured-product--align-center"', $chosen);
        self::assertStringContainsString('data-gallery-main-only', $chosen);
        self::assertStringNotContainsString('data-product-thumbs', $chosen, 'only the main picture: no thumbnail row');

        $unknown = $this->place(['product_id' => $product]);
        Database::connection()->prepare("UPDATE featured_products SET image_size = 'huge', image_position = 'top', ordering = 'sell' WHERE id = :id")->execute(['id' => (int) $unknown['section_id']]);
        $this->clearCaches();
        $html = $this->render($unknown);
        self::assertStringContainsString('<div class="featured-product" data-product-detail', $html, 'an unknown word reads as the default');
        self::assertStringContainsString('data-product-add-to-cart', $html, 'and an unknown ordering as "Direct bestellen"');
    }

    public function testTheGalleryTransitionIsTheProductsOwnElseTheShops(): void
    {
        $product = $this->shop->product('Overgang', null, 10.00);
        $row = $this->place(['product_id' => $product]);

        SiteSettings::overrideForTests([ProductGalleryTransition::SETTING_KEY => 'slide']);
        $this->clearCaches();
        self::assertStringContainsString('data-gallery-transition="slide"', $this->render($row), 'the Shop\'s default');

        (new ProductRepository())->updateGalleryTransition($product, 'none');
        $this->clearCaches();
        self::assertStringContainsString('data-gallery-transition="none"', $this->render($row), 'the product\'s own choice wins');
    }

    // ------------------------------------------------------------ safety

    public function testThePayloadCannotEndItsScriptElement(): void
    {
        $product = $this->shop->product('Naam </script><script>alert(1)</script>', null, 10.00);
        $html = $this->render($this->place(['product_id' => $product]));

        self::assertSame(1, substr_count($html, '</script>'), 'only the payload\'s own end tag');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertSame('Naam </script><script>alert(1)</script>', $this->payload($html)['name'], 'the words themselves survive intact');
        self::assertStringContainsString('Naam &lt;/script&gt;', $html, 'and the heading escapes them');
    }

    public function testTheBlockAddsNoStructuredDataOfItsOwn(): void
    {
        $product = $this->shop->product('Geen tweede schema', null, 10.00);
        $html = $this->render($this->place(['product_id' => $product])) . $this->render($this->place(['product_id' => $product]));

        self::assertStringNotContainsString('application/ld+json', $html, 'Product JSON-LD belongs to product.php alone');
        self::assertStringNotContainsString('itemprop', $html);
    }

    public function testThePageBuilderNamesTheProduct(): void
    {
        $product = $this->shop->product('Herkenbaar', null, 10.00);
        $row = $this->place(['product_id' => $product]);

        self::assertSame('Herkenbaar', BlockDefinitions::get('featured_product')->instanceTitle($row));
        self::assertSame('', BlockDefinitions::get('featured_product')->instanceTitle($this->place([])));
    }

    public function testTheLibrarySampleShowsNoPriceAndHasNoCart(): void
    {
        $definition = BlockDefinitions::get('featured_product');
        $sample = $definition->sampleContent(new BlockSamples());

        self::assertIsArray($sample);
        self::assertSame('view', $sample['ordering']);
        self::assertFalse($sample['show_price']);
        self::assertNull($sample['product']['payload']['price']);

        ob_start();
        $definition->renderSample($sample, 'featured_product-preview');
        $html = (string) ob_get_clean();

        self::assertSame(BlockSamples::IMAGE_PATH, $this->payload($html)['images'][0]['image_path'], 'the library\'s own picture');
        self::assertStringNotContainsString('data-product-add-to-cart', $html);
        self::assertStringNotContainsString('data-product-price', $html);
        self::assertStringContainsString('href="' . BlockSamples::LINK . '"', $html);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Places a block on the test page with these settings and words, as the
     * page builder and the editor would store them.
     *
     * @param array<string, mixed> $settings
     * @param array<string, array<string, string>> $words language => field => words
     * @return array<string, mixed> its page_sections row
     */
    private function place(array $settings, array $words = []): array
    {
        [$id, $key] = SectionRegistry::create('featured_product', self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        $sectionId = (new PageSectionRepository())->create((int) $page['id'], self::KEY, 'featured_product', $key, (int) $id);
        (new FeaturedProductRepository())->update((int) $id, $settings);
        foreach ($words as $language => $values) {
            BlockLocalization::save('featured_products', (int) $id, $language, $values);
        }
        $this->clearCaches();

        return (new PageSectionRepository())->findById((int) $sectionId) ?? throw new \RuntimeException('no page section');
    }

    /** @param array<string, mixed> $row a page_sections row */
    private function render(array $row): string
    {
        ob_start();
        try {
            BlockDefinitions::get('featured_product')->render($row, false, 'featured_product-' . $row['id']);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** What a visitor reads: the markup without the payload the script draws from. */
    private function withoutPayload(string $html): string
    {
        return (string) preg_replace('#<script type="application/json" data-product-payload>.*?</script>#s', '', $html);
    }

    /** @return array<string, mixed> the product payload a rendered block carries */
    private function payload(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" data-product-payload>(.*?)</script>#s', $html, $match), 'the block carries its product');

        return (array) json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function specification(int $product, string $name, string $unit, string $value): void
    {
        $specifications = new ProductSpecificationRepository();
        $id = $specifications->create($unit, 990);
        $this->specifications[] = $id;
        ShopLocalization::saveSpecification($id, 'nl', $name);
        $valueId = $specifications->createValue($product, $id, 0);
        ShopLocalization::saveSpecificationValue($valueId, 'nl', $value);
        $this->clearCaches();
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            if (SectionRegistry::exists((string) $row['section_type'])) {
                SectionRegistry::delete($row, $sections);
            }
        }
        Database::connection()->prepare('DELETE FROM featured_products WHERE page_slug = :slug')->execute(['slug' => self::KEY]);
        Database::connection()->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $page['id']]);
    }

    private function clearCaches(): void
    {
        FeaturedProductContent::clearCache();
        ShopLocalization::clearCache();
        BlockLocalization::clearCache();
        ProductSeo::clearCache();
        ProductPersonalizationContent::clearCache();
    }
}
