<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Language\SiteLanguages;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;

/**
 * A variant's own description is an OVERRIDE, never a copy (MODULES.md
 * "Shop"; App\Service\ShopLocalization::variantDescription()):
 *
 *     effective description = the variant's own text in this language
 *                             ?? the product's description in this language
 *
 * so a variant without text of its own keeps following the product, and an
 * override in one language leaves every other language alone.
 */
final class VariantDescriptionTest extends TestCase
{
    private ?int $productId = null;

    protected function setUp(): void
    {
        if (!SiteLanguages::exists('en')) {
            $this->markTestSkipped('This database has no English website language.');
        }

        $this->productId = (new ProductRepository())->create([
            'slug' => '__test_variant_description__',
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($this->productId, 'nl', [
            ShopLocalization::NAME => 'Plank',
            ShopLocalization::DESCRIPTION => '<p>Productbeschrijving</p>',
        ]);
        ShopLocalization::saveProduct($this->productId, 'en', [
            ShopLocalization::DESCRIPTION => '<p>Product description</p>',
        ]);
        ShopLocalization::clearCache();
    }

    protected function tearDown(): void
    {
        if ($this->productId !== null) {
            $db = Database::connection();
            $db->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$this->productId]);
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$this->productId]);
        }
        ShopLocalization::clearCache();
    }

    public function testAVariantWithoutItsOwnTextShowsTheProductsInEachLanguage(): void
    {
        $variantId = $this->variant();

        $this->assertSame('<p>Productbeschrijving</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'nl'));
        $this->assertSame('<p>Product description</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'en'));
        $this->assertSame(0, $this->rows($variantId), 'nothing is copied for a variant that follows the product');
    }

    public function testAnOverrideIsTheVariantsOwnText(): void
    {
        $variantId = $this->variant();

        ShopLocalization::saveVariantDescription($variantId, 'nl', '<p>Rode variant</p>');

        $this->assertSame('<p>Rode variant</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'nl'));
        $this->assertSame('<p>Rode variant</p>', ShopLocalization::variantOwnDescription($variantId, 'nl'));
    }

    public function testRemovingTheOverrideFollowsTheProductAgainIncludingLaterChanges(): void
    {
        $variantId = $this->variant();
        ShopLocalization::saveVariantDescription($variantId, 'nl', '<p>Rode variant</p>');

        ShopLocalization::saveVariantDescription($variantId, 'nl', null);
        ShopLocalization::saveProduct($this->productId, 'nl', [ShopLocalization::DESCRIPTION => '<p>Nieuwe producttekst</p>']);
        ShopLocalization::clearCache();

        $this->assertSame('<p>Nieuwe producttekst</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'nl'));
        $this->assertSame(0, $this->rows($variantId));
    }

    public function testAnOverrideInOneLanguageLeavesTheOtherAlone(): void
    {
        $variantId = $this->variant();
        ShopLocalization::saveVariantDescription($variantId, 'en', '<p>Red variant</p>');

        ShopLocalization::saveVariantDescription($variantId, 'nl', '<p>Rode variant</p>');
        ShopLocalization::saveVariantDescription($variantId, 'nl', null);

        $this->assertSame('<p>Red variant</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'en'));
        $this->assertSame('<p>Productbeschrijving</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'nl'));
    }

    public function testAnOverrideInOnlyOneLanguageNeverShowsInAnother(): void
    {
        $variantId = $this->variant();
        ShopLocalization::saveVariantDescription($variantId, 'nl', '<p>Alleen Nederlands</p>');

        $this->assertSame('<p>Product description</p>', ShopLocalization::variantDescription($variantId, $this->productId, 'en'));
    }

    public function testTheOverrideIsSanitizedOnTheWayOut(): void
    {
        $variantId = $this->variant();
        ShopLocalization::saveVariantDescription($variantId, 'nl', '<p onclick="x()">Tekst<script>alert(1)</script></p>');

        $html = ShopLocalization::variantDescription($variantId, $this->productId, 'nl');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('Tekst', $html);
    }

    public function testDeletingTheVariantTakesItsTextWithIt(): void
    {
        $variantId = $this->variant();
        ShopLocalization::saveVariantDescription($variantId, 'nl', '<p>Rode variant</p>');

        (new ProductVariantRepository())->delete($variantId);

        $this->assertSame(0, $this->rows($variantId));
    }

    public function testTheEditorsValidationRefusesAnEmptyOverrideAndNeverCopies(): void
    {
        require_once dirname(__DIR__, 2) . '/api/admin/_product_validation.php';

        $errors = [];
        [$store, $old] = validateVariantDescriptions([
            'variants_submitted' => ['5', '6', '7'],
            'variant_description_own' => ['5' => '1', '7' => '1'],
            'variant_description' => ['5' => '<p>Eigen</p>', '6' => '<p>Genegeerd</p>', '7' => '<p> </p>'],
        ], $errors);

        $this->assertSame('<p>Eigen</p>', $store[5]);
        $this->assertNull($store[6], 'off means "follow the product", whatever text was left in the editor');
        $this->assertArrayNotHasKey(7, $store, 'on with nothing in it is refused, not stored');
        $this->assertCount(1, $errors);
        $this->assertTrue($old[7]['own']);
    }

    private function variant(): int
    {
        return (new ProductVariantRepository())->create((int) $this->productId, [], null, true);
    }

    private function rows(int $variantId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM product_variant_translations WHERE variant_id = ?');
        $stmt->execute([$variantId]);

        return (int) $stmt->fetchColumn();
    }
}
