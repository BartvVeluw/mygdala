<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductImageRepository;
use App\Repository\ProductVariantImageRepository;
use App\Service\Inventory\StockSummary;
use App\Service\Language\AdminLocale;
use App\Service\ProductAdminOverview;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShopStockFixture;

/**
 * Shop → Producten (admin/products.php, Shop Admin UX & Order Fields 2.0):
 * every product shows its stock in one line and its thumbnail, in a fixed
 * number of queries, and the screen offers grid and list as one card markup.
 *
 *   - stock: unlimited, in stock, sold out; variants in stock, one or all
 *     sold out, untracked variants, no active variant; op aanvraag never
 *     shows a stock number;
 *   - thumbnail: the default variant's first picture, else the product's
 *     primary picture, else the legacy image_path, else nothing (the screen
 *     draws its placeholder);
 *   - no query per product;
 *   - the screen: one card markup, a hidden switch with aria-pressed buttons,
 *     a script that remembers the view in localStorage only.
 */
final class ProductAdminOverviewTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private ShopStockFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ShopStockFixture();
        AdminLocale::overrideForTests('nl');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
        AdminLocale::overrideForTests(null);
    }

    public function testASimpleProductSaysUnlimitedInStockOrSoldOut(): void
    {
        $unlimited = $this->fixture->product('ZZ Overzicht Onbeperkt');
        $inStock = $this->fixture->product('ZZ Overzicht Voorraad', 12);
        $soldOut = $this->fixture->product('ZZ Overzicht Uitverkocht', 0);

        $rows = $this->rows();

        self::assertSame('Onbeperkt', $rows[$unlimited]['stock']->text());
        self::assertSame(StockSummary::TONE_MUTED, $rows[$unlimited]['stock']->tone);
        self::assertSame('12 op voorraad', $rows[$inStock]['stock']->text());
        self::assertSame(StockSummary::TONE_OK, $rows[$inStock]['stock']->tone);
        self::assertSame('Uitverkocht', $rows[$soldOut]['stock']->text());
        self::assertSame(StockSummary::TONE_ERROR, $rows[$soldOut]['stock']->tone);
    }

    public function testAVariantProductCountsItsActiveVariantsAndWhatIsSoldOut(): void
    {
        $all = $this->fixture->variantProduct('ZZ Overzicht Varianten', ['Rood' => 5, 'Blauw' => 3, 'Groen' => 15, 'Geel' => 0]);
        $none = $this->fixture->variantProduct('ZZ Overzicht Leeg', ['Rood' => 0, 'Blauw' => 0]);
        $fine = $this->fixture->variantProduct('ZZ Overzicht Vol', ['Rood' => 2, 'Blauw' => 1]);
        $free = $this->fixture->variantProduct('ZZ Overzicht Vrij', ['Rood' => 0, 'Blauw' => 0], false);

        // An inactive variant is never sold, so it is not counted either.
        Database::connection()->prepare('UPDATE product_variants SET active = 0 WHERE id = :id')->execute(['id' => $fine['variants']['Blauw']]);

        $rows = $this->rows();

        self::assertSame('4 varianten · 1 uitverkocht', $rows[$all['product']]['stock']->text());
        self::assertSame(StockSummary::TONE_WARNING, $rows[$all['product']]['stock']->tone);
        self::assertSame(23, $rows[$all['product']]['stock']->inStock, 'every variant is its own unit, so the pieces add up');
        self::assertSame('Uitverkocht · 2 varianten', $rows[$none['product']]['stock']->text());
        self::assertSame(StockSummary::TONE_ERROR, $rows[$none['product']]['stock']->tone);
        self::assertSame('1 variant · 2 op voorraad', $rows[$fine['product']]['stock']->text());
        self::assertSame('2 varianten · Onbeperkt', $rows[$free['product']]['stock']->text());

        Database::connection()->prepare('UPDATE product_variants SET active = 0 WHERE product_id = :id')->execute(['id' => $fine['product']]);
        self::assertSame('Geen actieve varianten', $this->rows()[$fine['product']]['stock']->text());
    }

    public function testOpAanvraagShowsNoStockNumber(): void
    {
        $product = $this->fixture->product('ZZ Overzicht Aanvraag', 7);
        Database::connection()->prepare("UPDATE products SET purchase_mode = 'inquiry' WHERE id = :id")->execute(['id' => $product]);

        $stock = $this->rows()[$product]['stock'];
        self::assertSame('inquiry', $stock->kind);
        self::assertSame('Niet direct te bestellen', $stock->text());
        self::assertNull($stock->inStock);
    }

    public function testTheThumbnailFollowsTheShopCard(): void
    {
        $bare = $this->fixture->product('ZZ Overzicht Kaal');
        $legacy = $this->fixture->product('ZZ Overzicht Oud');
        Database::connection()->prepare("UPDATE products SET image_path = 'assets/images/products/oud.jpg' WHERE id = :id")->execute(['id' => $legacy]);
        $own = $this->fixture->product('ZZ Overzicht Eigen');
        (new ProductImageRepository())->create($own, 'assets/images/products/eigen-1.jpg');
        (new ProductImageRepository())->create($own, 'assets/images/products/eigen-2.jpg');
        $variant = $this->fixture->variantProduct('ZZ Overzicht Variantfoto', ['Rood' => 1, 'Blauw' => 1]);
        $images = new ProductImageRepository();
        $images->create($variant['product'], 'assets/images/products/product.jpg');
        $blue = $images->create($variant['product'], 'assets/images/products/blauw.jpg');
        (new ProductVariantImageRepository())->replaceForVariant($variant['variants']['Rood'], [$blue]);

        $rows = $this->rows();

        self::assertNull($rows[$bare]['thumbnail'], 'no picture: the screen draws its placeholder');
        self::assertSame('assets/images/products/oud.jpg', $rows[$legacy]['thumbnail']);
        self::assertSame('assets/images/products/eigen-1.jpg', $rows[$own]['thumbnail']);
        self::assertSame('assets/images/products/blauw.jpg', $rows[$variant['product']]['thumbnail'], 'the default variant\'s first picture');
    }

    public function testTheOverviewNeedsNoQueryPerProduct(): void
    {
        $this->fixture->variantProduct('ZZ Overzicht Tellen A', ['Rood' => 1, 'Blauw' => 2]);
        $this->fixture->product('ZZ Overzicht Tellen B', 3);
        $few = $this->queriesFor();

        for ($i = 0; $i < 4; $i++) {
            $this->fixture->variantProduct('ZZ Overzicht Meer ' . $i, ['Rood' => 1, 'Blauw' => 0]);
            $this->fixture->product('ZZ Overzicht Nog ' . $i, $i);
        }
        $many = $this->queriesFor();

        self::assertSame($few, $many, 'eight more products, not one query more');
        self::assertLessThanOrEqual(6, $many);
    }

    public function testTheScreenDrawsOneCardMarkupWithAnAccessibleSwitch(): void
    {
        $screen = (string) file_get_contents(self::ROOT . '/admin/products.php');
        self::assertStringContainsString('data-product-view-toggle hidden', $screen, 'without JavaScript the grid stays and the switch stays hidden');
        self::assertSame(2, substr_count($screen, 'data-product-view-option="'));
        self::assertStringContainsString('aria-pressed="true"', $screen);
        self::assertStringContainsString('data-product-view="grid"', $screen, 'grid is the default');
        self::assertStringContainsString('admin-product-card__stock', $screen);
        self::assertStringContainsString("admin_te('shop.no_photo')", $screen);
        self::assertSame(1, substr_count($screen, '<article class="admin-product-card"'), 'one card markup for both views');
        self::assertStringNotContainsString('findDefaultForProduct', $screen);

        $script = (string) file_get_contents(self::ROOT . '/admin/assets/product-overview.js');
        self::assertStringContainsString('localStorage', $script);
        self::assertStringContainsString('aria-pressed', $script);
        self::assertStringNotContainsString('fetch(', $script, 'a view preference, not data');

        $css = (string) file_get_contents(self::ROOT . '/admin/assets/admin.css');
        self::assertStringContainsString('[data-product-view="list"] .admin-product-card', $css);
        self::assertStringContainsString('.admin-view-toggle__option:focus-visible', $css);
        self::assertMatchesRegularExpression('/@media \(max-width: 700px\)\{\s*\[data-product-view="list"\] \.admin-product-card\{ flex-wrap: wrap;/', $css, 'a phone folds the row instead of scrolling a table');
    }

    /** @return array<int, array<string, mixed>> the overview rows by product id */
    private function rows(): array
    {
        $rows = [];
        foreach ((new ProductAdminOverview())->rows() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    private function queriesFor(): int
    {
        $db = Database::connection();
        $before = (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
        (new ProductAdminOverview($db))->rows();
        $after = (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];

        // The two SHOW statements count themselves once.
        return $after - $before - 1;
    }
}
