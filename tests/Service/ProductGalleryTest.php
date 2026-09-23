<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantImageRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\ProductDeletionService;
use App\Service\ProductGallery;
use App\Service\ShopLocalization;
use App\Service\ShopMediaUsage;
use PHPUnit\Framework\TestCase;

/**
 * A product has ONE pool of pictures, and a variant only chooses from it
 * (App\Service\ProductGallery, ProductVariantImageRepository; MODULES.md
 * "Shop"). What this pins down is the owner's complaint that started it:
 * adding a variant used to make a product's own pictures disappear.
 *
 * Runs against the test database. Every row it makes is its own and is
 * removed again, media rows included.
 */
final class ProductGalleryTest extends TestCase
{
    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->productIds as $productId) {
            $db->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
        }

        foreach ($this->mediaIds as $mediaId) {
            $db->prepare('DELETE FROM media WHERE id = ?')->execute([$mediaId]);
        }

        $this->productIds = [];
        $this->mediaIds = [];
        MediaService::clearCache();
        ShopLocalization::clearCache();
    }

    public function testAPictureChosenFromTheLibraryBecomesAProductPictureWithItsPath(): void
    {
        $productId = $this->product('library');
        $media = $this->media('library-a');

        $order = (new ProductGallery())->save($productId, ['media:' . $media]);

        $images = (new ProductImageRepository())->findByProductId($productId);
        $this->assertCount(1, $images);
        $this->assertSame($order, [(int) $images[0]['id']]);
        $this->assertSame($media, (int) $images[0]['media_id']);
        $this->assertSame('assets/media/' . $this->mediaPath('library-a'), $images[0]['image_path']);
        $this->assertSame(1, (int) $images[0]['is_primary']);

        // The product's own image_path follows its primary picture, for every
        // older reader (cards, the cart, order e-mails).
        $this->assertSame($images[0]['image_path'], (new ProductRepository())->findByIdForAdmin($productId)['image_path']);
    }

    public function testTheOrderIsTheOrderOfTheTokensAndTheFirstIsPrimary(): void
    {
        $productId = $this->product('order');
        $a = $this->media('order-a');
        $b = $this->media('order-b');
        $c = $this->media('order-c');
        $gallery = new ProductGallery();

        $gallery->save($productId, ['media:' . $a, 'media:' . $b, 'media:' . $c]);
        $ids = $this->imageIds($productId);

        // A reorder is the same pictures under image: tokens, never new rows.
        $gallery->save($productId, ['image:' . $ids[2], 'image:' . $ids[0], 'image:' . $ids[1]]);

        $images = (new ProductImageRepository())->findByProductId($productId);
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_map(static fn (array $r): int => (int) $r['id'], $images));
        $this->assertSame([1, 0, 0], array_map(static fn (array $r): int => (int) $r['is_primary'], $images));
        $this->assertSame($c, (int) $images[0]['media_id']);
    }

    public function testAddingAVariantKeepsEveryProductPicture(): void
    {
        $productId = $this->product('addvariant');
        (new ProductGallery())->save($productId, ['media:' . $this->media('add-a'), 'media:' . $this->media('add-b')]);
        $before = $this->imageIds($productId);

        (new ProductVariantRepository())->create($productId, [], null, true);

        $this->assertSame($before, $this->imageIds($productId));

        // And the public answer still carries them: a variant that chose no
        // picture shows the product's pool (api/product.php, shop.js).
        $variant = (new ProductVariantRepository())->findActiveByProductId($productId)[0];
        $this->assertSame([], $variant['images']);
    }

    public function testAssigningAPictureToAVariantKeepsItOnTheProduct(): void
    {
        $productId = $this->product('assign');
        $variantId = (new ProductVariantRepository())->create($productId, [], null, true);
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $this->media('assign-a'), 'media:' . $this->media('assign-b')]);
        $ids = $this->imageIds($productId);

        $gallery->save(
            $productId,
            ['image:' . $ids[0], 'image:' . $ids[1]],
            [$variantId => ['image:' . $ids[1]]]
        );

        $this->assertSame($ids, $this->imageIds($productId), 'both pictures are still the product\'s');
        $this->assertSame([$ids[1]], $this->variantImageIds($variantId));
    }

    public function testOnePictureMayServeTwoVariantsEachInItsOwnOrder(): void
    {
        $productId = $this->product('shared');
        $variants = new ProductVariantRepository();
        $red = $variants->create($productId, [], null, true);
        $blue = $variants->create($productId, [], null, true);
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $this->media('s-1'), 'media:' . $this->media('s-2'), 'media:' . $this->media('s-3')]);
        [$one, $two, $three] = $this->imageIds($productId);
        $pool = ['image:' . $one, 'image:' . $two, 'image:' . $three];

        $gallery->save($productId, $pool, [
            $red => ['image:' . $one, 'image:' . $two],
            $blue => ['image:' . $three, 'image:' . $one],
        ]);

        $this->assertSame([$one, $two], $this->variantImageIds($red));
        $this->assertSame([$three, $one], $this->variantImageIds($blue));
    }

    public function testAVariantShownWithNothingTickedGoesBackToThePool(): void
    {
        $productId = $this->product('untick');
        $variantId = (new ProductVariantRepository())->create($productId, [], null, true);
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $this->media('u-1')]);
        $ids = $this->imageIds($productId);
        $gallery->save($productId, ['image:' . $ids[0]], [$variantId => ['image:' . $ids[0]]]);

        $gallery->save($productId, ['image:' . $ids[0]], ProductGallery::variantTokens([(string) $variantId], []));

        $this->assertSame([], $this->variantImageIds($variantId));
    }

    public function testRemovingAPictureFromThePoolRemovesItFromEveryVariantButNotFromTheLibrary(): void
    {
        $productId = $this->product('remove');
        $variantId = (new ProductVariantRepository())->create($productId, [], null, true);
        $media = $this->media('remove-a');
        $keep = $this->media('remove-b');
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $media, 'media:' . $keep]);
        $ids = $this->imageIds($productId);
        $gallery->save($productId, ['image:' . $ids[0], 'image:' . $ids[1]], [$variantId => ['image:' . $ids[0], 'image:' . $ids[1]]]);

        $gallery->save($productId, ['image:' . $ids[1]]);

        $this->assertSame([$ids[1]], $this->imageIds($productId));
        $this->assertSame([$ids[1]], $this->variantImageIds($variantId));
        $this->assertNotNull((new MediaRepository())->findById($media), 'the library item stays');
    }

    public function testDeletingAVariantLeavesThePicturesAndTheMedia(): void
    {
        $productId = $this->product('deletevariant');
        $variants = new ProductVariantRepository();
        $variantId = $variants->create($productId, [], null, true);
        $media = $this->media('dv-a');
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $media]);
        $ids = $this->imageIds($productId);
        $gallery->save($productId, ['image:' . $ids[0]], [$variantId => ['image:' . $ids[0]]]);

        $variants->delete($variantId);

        $this->assertSame($ids, $this->imageIds($productId));
        $this->assertSame([], $this->variantImageIds($variantId));
        $this->assertNotNull((new MediaRepository())->findById($media));
    }

    public function testDeletingTheProductNeverDeletesALibraryItem(): void
    {
        $productId = $this->product('deleteproduct');
        $media = $this->media('dp-a');
        (new ProductGallery())->save($productId, ['media:' . $media]);

        $this->assertTrue((new ProductDeletionService())->delete($productId));

        $this->assertNotNull((new MediaRepository())->findById($media));
    }

    public function testATokenThatIsNotThisProductsIsIgnored(): void
    {
        $other = $this->product('other');
        (new ProductGallery())->save($other, ['media:' . $this->media('other-a')]);
        $foreign = $this->imageIds($other)[0];

        $productId = $this->product('forged');
        $order = (new ProductGallery())->save($productId, [
            'image:' . $foreign,          // another product's picture
            'media:999999999',            // no such library item
            'assets/images/x.webp',       // a path, never a token
        ]);

        $this->assertSame([], $order);
        $this->assertSame([], $this->imageIds($productId));
        $this->assertSame([$foreign], $this->imageIds($other), 'the other product is untouched');
    }

    public function testAVariantOfAnotherProductCannotBeGivenPictures(): void
    {
        $productId = $this->product('ownvariant');
        $otherProduct = $this->product('othervariant');
        $foreignVariant = (new ProductVariantRepository())->create($otherProduct, [], null, true);
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $this->media('ov-a')]);
        $ids = $this->imageIds($productId);

        $gallery->save($productId, ['image:' . $ids[0]], [$foreignVariant => ['image:' . $ids[0]]]);

        $this->assertSame([], $this->variantImageIds($foreignVariant));
    }

    public function testTheSameLibraryItemIsNeverAddedTwice(): void
    {
        $productId = $this->product('twice');
        $media = $this->media('twice-a');
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $media]);
        $ids = $this->imageIds($productId);

        $gallery->save($productId, ['image:' . $ids[0], 'media:' . $media]);

        $this->assertSame($ids, $this->imageIds($productId));
    }

    public function testTheLibraryReportsProductAndVariantUse(): void
    {
        $productId = $this->product('usage');
        ShopLocalization::saveProduct($productId, ShopLocalization::defaultLanguage(), [ShopLocalization::NAME => 'Galerijtest gebruik']);
        $variantId = (new ProductVariantRepository())->create($productId, [], null, true);
        $media = $this->media('usage-a');
        $unused = $this->media('usage-b');
        $gallery = new ProductGallery();
        $gallery->save($productId, ['media:' . $media]);
        $ids = $this->imageIds($productId);
        $gallery->save($productId, ['image:' . $ids[0]], [$variantId => ['image:' . $ids[0]]]);
        ShopLocalization::clearCache();

        $usages = (new ShopMediaUsage())->usagesFor([$media, $unused]);

        $this->assertArrayNotHasKey($unused, $usages);
        $this->assertCount(1, $usages[$media]);
        $this->assertSame('Product: Galerijtest gebruik (ook bij 1 variant)', $usages[$media][0]->label);
        $this->assertSame('/admin/product-form.php?id=' . $productId, $usages[$media][0]->editUrl);
        $this->assertSame('products.manage', $usages[$media][0]->permission);

        // The registry asks the running Shop, so the library refuses to
        // delete an item a product still shows.
        $this->assertNotSame([], MediaUsageRegistry::usagesFor([$media])[$media] ?? []);
    }

    public function testTokensAreCheckedForShapeAndDoubles(): void
    {
        $this->assertSame(
            ['image:3', 'media:4'],
            ProductGallery::tokens(['image:3', 'image:3', ' media:4 ', 'image:0', 'image:-1', 'file:2', 5, ['image:6']])
        );
        $this->assertSame([], ProductGallery::tokens('image:3'));
        $this->assertSame([7 => ['image:1'], 8 => []], ProductGallery::variantTokens(['7', '8', 'x', '-2'], ['7' => ['image:1']]));
    }

    /* ------------------------------------------------------------------ */

    private function product(string $suffix): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_gallery_' . $suffix . '__',
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        return $id;
    }

    private function mediaPath(string $name): string
    {
        return '__test_gallery_' . $name . '.png';
    }

    /** A media row for a file that does not exist: nothing here reads the disk. */
    private function media(string $name): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/' . $this->mediaPath($name),
            'original_filename' => $this->mediaPath($name),
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => '',
            'checksum' => null,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    /** @return list<int> */
    private function imageIds(int $productId): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new ProductImageRepository())->findByProductId($productId));
    }

    /** @return list<int> */
    private function variantImageIds(int $variantId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            (new ProductVariantImageRepository())->findByVariantIds([$variantId])[$variantId] ?? []
        );
    }
}
