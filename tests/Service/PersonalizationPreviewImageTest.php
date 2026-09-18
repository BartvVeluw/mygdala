<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationLocalization;
use App\Service\Personalization\PersonalizationPreviewImageUploader;
use App\Service\Personalization\ProductPersonalizationContent;
use App\Service\ProductImageUploader;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;

/**
 * Personalization preview images are DEDICATED: uploaded for personalization,
 * stored in their own folder, owned by one view, and never interchangeable
 * with a product photo.
 *
 * This is the rule that most needed proving, because the failure mode is
 * silent and ugly: a product photo may already show an engraving, a mock-up
 * or a styled scene, so composing a customer's text on top of one produces
 * nonsense — and a zone's coordinates are percentages of ONE specific
 * picture, which cannot be allowed to follow gallery ordering or a variant
 * switch.
 *
 * So there is NO fallback anywhere. A view without its own image is dropped
 * by the resolver and flagged as a configuration error in the CMS, rather
 * than borrowing anything.
 */
final class PersonalizationPreviewImageTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-personalization-preview-';

    private const FRONT_IMAGE = 'assets/images/personalization/zz-test-front.png';
    private const BACK_IMAGE = 'assets/images/personalization/zz-test-back.png';

    private ProductRepository $products;
    private ProductPersonalizationRepository $personalization;

    /** @var list<int> */
    private array $productIds = [];

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

        $this->productIds = [];
        ProductPersonalizationContent::clearCache();
    }

    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** A product WITH a gallery photo, so a fallback would have something to grab. */
    private function createProduct(): int
    {
        $id = $this->products->create([
            'name' => 'Testproduct voorbeeldafbeelding',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 12.0,
            'image_path' => PersonalizationTestConfig::PRODUCT_GALLERY_IMAGE,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* Every preview has its own image                                     */
    /* ------------------------------------------------------------------ */

    public function testEachPreviewCarriesItsOwnDedicatedImage(): void
    {
        $productId = $this->createProduct();

        PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'front', 'label' => 'Voorkant', 'image' => self::FRONT_IMAGE,
                'zones' => [PersonalizationTestConfig::zone('name')],
            ],
            [
                'view_key' => 'back', 'label' => 'Achterkant', 'image' => self::BACK_IMAGE,
                'zones' => [PersonalizationTestConfig::zone('message')],
            ],
        ]);

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertCount(2, $config['views']);
        $this->assertSame(self::FRONT_IMAGE, $config['views'][0]['preview_image_path']);
        $this->assertSame(self::BACK_IMAGE, $config['views'][1]['preview_image_path']);
        $this->assertNotSame(
            $config['views'][0]['preview_image_path'],
            $config['views'][1]['preview_image_path'],
            'a second preview must never reuse the first one'
        );
    }

    public function testZonesBelongToTheirOwnPreviewAndNoOther(): void
    {
        $productId = $this->createProduct();

        PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'front', 'image' => self::FRONT_IMAGE,
                'zones' => [PersonalizationTestConfig::zone('name'), PersonalizationTestConfig::zone('logo')],
            ],
            [
                'view_key' => 'back', 'image' => self::BACK_IMAGE,
                'zones' => [PersonalizationTestConfig::zone('message')],
            ],
        ]);

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertSame(
            ['name', 'logo'],
            array_column($config['views'][0]['zones'], 'zone_key')
        );
        $this->assertSame(['message'], array_column($config['views'][1]['zones'], 'zone_key'));

        // locateZone() pairs a zone with the view it really sits on, which is
        // what makes the order snapshot record the right image.
        $located = ProductPersonalizationContent::locateZone($config, 'message');
        $this->assertSame('back', $located['view']['view_key']);
        $this->assertSame(self::BACK_IMAGE, $located['view']['preview_image_path']);
    }

    /* ------------------------------------------------------------------ */
    /* No fallback to the product gallery, ever                            */
    /* ------------------------------------------------------------------ */

    public function testAPreviewWithoutItsOwnImageIsDroppedRatherThanBorrowingAProductPhoto(): void
    {
        $productId = $this->createProduct();

        PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'front', 'image' => self::FRONT_IMAGE,
                'zones' => [PersonalizationTestConfig::zone('name')],
            ],
            [
                'view_key' => 'back', 'image' => null,
                'zones' => [PersonalizationTestConfig::zone('message')],
            ],
        ]);

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertCount(1, $config['views'], 'a view without its own image must not be offered');
        $this->assertSame('front', $config['views'][0]['view_key']);

        // The product's own photo exists and is still nowhere near the
        // personalization configuration.
        $product = $this->products->findByIdForAdmin($productId);
        $this->assertSame(PersonalizationTestConfig::PRODUCT_GALLERY_IMAGE, $product['image_path']);
        $this->assertStringNotContainsString(
            'assets/images/products/',
            json_encode($config, JSON_UNESCAPED_SLASHES),
            'no product gallery path may appear in a resolved personalization configuration'
        );
    }

    public function testAProductWhoseOnlyPreviewHasNoImageOffersNoPersonalizationAtAll(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, [], [], null);

        $this->assertNull(
            ProductPersonalizationContent::forProduct($productId),
            'personalization with nothing to draw on must render nothing'
        );
    }

    /* ------------------------------------------------------------------ */
    /* The two uploaders cannot reach each other's files                   */
    /* ------------------------------------------------------------------ */

    public function testThePreviewUploaderWritesToItsOwnFolder(): void
    {
        $this->assertSame(
            'assets/images/personalization/',
            PersonalizationPreviewImageUploader::PUBLIC_PREFIX
        );

        $uploader = self::sourceOf('src/Service/Personalization/PersonalizationPreviewImageUploader.php');

        // Both halves — where a file is written and where one may be deleted
        // — are that one constant, so neither can drift into another folder.
        $this->assertStringContainsString("dirname(__DIR__, 3) . '/' . self::PUBLIC_PREFIX", $uploader);
        $this->assertStringContainsString('return self::PUBLIC_PREFIX . $filename;', $uploader);
        $this->assertStringContainsString('str_starts_with($imagePath, self::PUBLIC_PREFIX)', $uploader);
    }

    /**
     * Deleting a preview can never delete a product photo — including the one
     * a Phase 1/2 view may still point at, which a historical order's
     * snapshot refers to.
     */
    public function testDeletingAPreviewCannotTouchAProductPhoto(): void
    {
        $root = dirname(__DIR__, 2);
        $galleryDir = $root . '/assets/images/products/';
        $filename = 'zz-test-preview-guard-' . bin2hex(random_bytes(6)) . '.png';
        $galleryFile = $galleryDir . $filename;

        if (!is_dir($galleryDir)) {
            mkdir($galleryDir, 0755, true);
        }
        file_put_contents($galleryFile, 'not really a png');

        try {
            (new PersonalizationPreviewImageUploader())->delete('assets/images/products/' . $filename);

            $this->assertFileExists(
                $galleryFile,
                'the preview uploader must be a no-op outside its own folder'
            );
        } finally {
            @unlink($galleryFile);
        }
    }

    /**
     * ...and the reverse: the product-photo uploader has no reach into the
     * personalization folder either.
     */
    public function testTheProductPhotoUploaderCannotTouchAPreviewImage(): void
    {
        $root = dirname(__DIR__, 2);
        $previewDir = $root . '/assets/images/personalization/';
        $filename = 'zz-test-gallery-guard-' . bin2hex(random_bytes(6)) . '.png';
        $previewFile = $previewDir . $filename;

        if (!is_dir($previewDir)) {
            mkdir($previewDir, 0755, true);
        }
        file_put_contents($previewFile, 'not really a png');

        try {
            (new ProductImageUploader())->delete('assets/images/personalization/' . $filename);

            $this->assertFileExists($previewFile);
        } finally {
            @unlink($previewFile);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The CMS says so out loud                                            */
    /* ------------------------------------------------------------------ */

    public function testTheCmsReportsAMissingPreviewImageAsAConfigurationError(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        $this->assertStringContainsString('personalization.configuratiefout_voorbeeld_eigen_afbeelding', $builder);
        $this->assertStringContainsString('personalization.configuratiefout_personalisatie_staat_maar', $builder);
        // The sentence itself lives in the catalogue (MULTILINGUAL.md), so the
        // promise it makes is asserted where the words are.
        $messages = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';
        $this->assertStringContainsString(
            'nooit teruggevallen op een gewone productfoto',
            (string) ($messages['personalization.configuratiefout_personalisatie_staat_maar'] ?? ''),
            'the CMS must state that there is no fallback'
        );

        // The view editor uploads through the DEDICATED uploader.
        $endpoint = self::sourceOf('api/admin/update-personalization-view.php');
        $this->assertStringContainsString('PersonalizationPreviewImageUploader', $endpoint);
        $this->assertStringNotContainsString('ProductImageUploader', $endpoint);
    }

    /**
     * A view's preview image is written only by its own explicit action —
     * never as a side effect of renaming it, which would move every engraving
     * area on the product.
     */
    public function testRenamingAPreviewNeverTouchesItsImage(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'front', 'image' => self::FRONT_IMAGE,
            'zones' => [PersonalizationTestConfig::zone('name')],
        ]]);

        $viewId = $built['view_ids']['front'];

        // Renaming a view is now two writes in one transaction: the row is
        // touched, the label is a word (Multilingual 2.0 phase 5 wave D).
        // Neither may go near the image.
        $this->personalization->touchView($viewId);
        PersonalizationLocalization::saveViewLabel($viewId, 'nl', 'Nieuwe naam');
        PersonalizationLocalization::clearCache();

        $view = $this->personalization->findViewById($viewId);

        $this->assertSame('Nieuwe naam', PersonalizationLocalization::viewName($viewId));
        $this->assertSame(self::FRONT_IMAGE, $view['preview_image_path']);
    }
}
