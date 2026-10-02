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
use App\Service\ProductDeletionService;
use App\Service\ProductDetail;
use App\Service\ProductGallery;
use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use App\Service\ShopMediaUsage;
use PHPUnit\Framework\TestCase;

/**
 * Pictures meant for variants only (v0.1.15 phase 12.1, MODULES.md "Shop"):
 * product_images.variant_only on the product's ONE pool.
 *
 *   - general (0): in the gallery, may also be linked to variants;
 *   - variant-only (1): still the product's, but only a variant that links
 *     to it shows it — never in the general gallery, never primary, never
 *     the share image or the card's picture.
 *
 * Nothing is inferred from the links: the two lists ProductGallery::save()
 * gets decide. Runs against the test database; every row it makes is its own
 * and is removed again, media rows included.
 */
final class ProductVariantOnlyPicturesTest extends TestCase
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

    public function testAnExistingPictureIsGeneral(): void
    {
        $productId = $this->product('existing');
        (new ProductImageRepository())->create($productId, 'assets/images/products/__test_vo_legacy.webp');
        (new ProductGallery())->save($productId, ['media:' . $this->media('existing-a')]);

        $rows = Database::connection()->prepare('SELECT variant_only FROM product_images WHERE product_id = ?');
        $rows->execute([$productId]);
        $this->assertSame([0], array_map('intval', $rows->fetchAll(\PDO::FETCH_COLUMN)), 'only the general list was sent; nothing becomes variant-only by itself');
    }

    public function testAVariantOnlyPictureIsThePoolsButNotTheGallerys(): void
    {
        [$productId, $ids, $variants] = $this->scenario('lists');

        $images = new ProductImageRepository();
        $this->assertSame([$ids['A'], $ids['B'], $ids['C']], $this->idsOf($images->findByProductId($productId)));
        $this->assertSame([$ids['A'], $ids['B'], $ids['C'], $ids['D'], $ids['E']], $this->idsOf($images->findPoolByProductId($productId)));
        $this->assertSame($ids['A'], (int) $images->findPrimary($productId)['id']);
        $this->assertSame($ids['A'], (int) $images->primaryForProducts([$productId])[$productId]['id']);

        $this->assertSame([$ids['B'], $ids['D']], $this->variantIds($variants['red']));
        $this->assertSame([$ids['E']], $this->variantIds($variants['blue']));
        $this->assertSame([$ids['D']], $this->variantIds($variants['redxl']), 'one variant-only picture serves two variants');
        $this->assertSame([], $this->variantIds($variants['green']));

        // One row per picture: D is linked twice, stored once.
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
        $count->execute([$productId]);
        $this->assertSame(5, (int) $count->fetchColumn());
    }

    public function testThePublicPayloadKeepsVariantOnlyPicturesOutOfTheDefaultGallery(): void
    {
        [$productId, $ids, $variants] = $this->scenario('payload');

        $payload = ProductDetail::forPublic($productId, 'nl');
        $this->assertNotNull($payload);
        $this->assertSame([$ids['A'], $ids['B'], $ids['C']], array_column($payload['images'], 'id'), 'the default gallery: general pictures only');
        $this->assertTrue($payload['images'][0]['is_primary']);

        $byVariant = [];
        foreach ($payload['variants'] as $variant) {
            $byVariant[(int) $variant['id']] = array_column($variant['images'], 'id');
        }
        $this->assertSame([$ids['B'], $ids['D']], $byVariant[$variants['red']], 'Rood: B and D, B once');
        $this->assertSame([$ids['E']], $byVariant[$variants['blue']]);
        // A variant without links gets [] and shop.js shows the general
        // pictures again — the way back from a variant-only picture.
        $this->assertSame([], $byVariant[$variants['green']]);
    }

    /**
     * Product Gallery 2.1: every picture in the payload carries the path of
     * its small version for the thumbnail row — the library's thumbnail when
     * the item has one, else the picture itself — and the big picture keeps
     * the original. General and variant-only pictures alike.
     */
    public function testThePayloadCarriesAThumbnailPathWithTheOriginalAsFallback(): void
    {
        $productId = $this->product('thumbs');
        $variant = (new ProductVariantRepository())->create($productId, [], null, true);
        $withThumb = $this->media('thumbs-with', 'assets/media/thumbs/__test_variant_only_thumbs-with.png');
        $withoutThumb = $this->media('thumbs-without');
        $variantOnly = $this->media('thumbs-variant', 'assets/media/thumbs/__test_variant_only_thumbs-variant.png');
        $legacy = (new ProductImageRepository())->create($productId, 'assets/images/products/__test_vo_thumbs_legacy.webp');

        (new ProductGallery())->save(
            $productId,
            ['media:' . $withThumb, 'media:' . $withoutThumb, 'image:' . $legacy],
            [$variant => ['media:' . $variantOnly, 'media:' . $withThumb]],
            ['media:' . $variantOnly]
        );

        $payload = ProductDetail::forPublic($productId, 'nl');
        $this->assertNotNull($payload);

        $this->assertSame(
            [
                ['assets/media/__test_variant_only_thumbs-with.png', 'assets/media/thumbs/__test_variant_only_thumbs-with.png'],
                ['assets/media/__test_variant_only_thumbs-without.png', 'assets/media/__test_variant_only_thumbs-without.png'],
                ['assets/images/products/__test_vo_thumbs_legacy.webp', 'assets/images/products/__test_vo_thumbs_legacy.webp'],
            ],
            array_map(static fn (array $image): array => [$image['image_path'], $image['thumbnail_path']], $payload['images']),
            'the library thumbnail when there is one, the original otherwise; the big picture stays the original'
        );

        $this->assertSame(
            [
                ['assets/media/__test_variant_only_thumbs-variant.png', 'assets/media/thumbs/__test_variant_only_thumbs-variant.png'],
                ['assets/media/__test_variant_only_thumbs-with.png', 'assets/media/thumbs/__test_variant_only_thumbs-with.png'],
            ],
            array_map(static fn (array $image): array => [$image['image_path'], $image['thumbnail_path']], $payload['variants'][0]['images']),
            'a variant\'s pictures, variant-only included, get the same thumbnail rule'
        );
    }

    public function testAVariantOnlyPictureIsNeverPrimaryWhateverTheCallerSends(): void
    {
        $productId = $this->product('primary');
        $gallery = new ProductGallery();
        $gallery->save($productId, [], [], ['media:' . $this->media('primary-d')]);
        $d = $this->poolIds($productId)[0];

        $this->assertSame(0, $this->flag($d, 'is_primary'), 'the only picture, and still not primary');
        $this->assertNull((new ProductImageRepository())->findPrimary($productId));
        $this->assertNull((new ProductRepository())->findByIdForAdmin($productId)['image_path'], 'products.image_path does not follow it');

        // The repository on its own: marking clears is_primary.
        $gallery->save($productId, ['image:' . $d], [], []);
        $this->assertSame(1, $this->flag($d, 'is_primary'));
        (new ProductImageRepository())->markVariantOnly($productId, [$d]);
        $this->assertSame(0, $this->flag($d, 'is_primary'));
        $this->assertSame(1, $this->flag($d, 'variant_only'));
    }

    public function testAGeneralPictureCanBecomeVariantOnlyAndKeepsItsLinks(): void
    {
        [$productId, $ids, $variants] = $this->scenario('togeneral');

        // A (the primary) goes to the variants-only list; B becomes primary.
        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids['B'], 'image:' . $ids['C']],
            [],
            ['image:' . $ids['A'], 'image:' . $ids['D'], 'image:' . $ids['E']]
        );

        $images = new ProductImageRepository();
        $this->assertSame([$ids['B'], $ids['C']], $this->idsOf($images->findByProductId($productId)));
        $this->assertSame([0, 1], array_map(static fn (array $r): int => (int) $r['sort_order'], $images->findByProductId($productId)), 'the general order stays 0..n-1');
        $this->assertSame($ids['B'], (int) $images->findPrimary($productId)['id']);
        $this->assertSame(0, $this->flag($ids['A'], 'is_primary'));
        $this->assertSame(1, $this->flag($ids['A'], 'variant_only'));
        $this->assertSame([$ids['B'], $ids['D']], $this->variantIds($variants['red']), 'links were not touched');
    }

    public function testAVariantOnlyPictureCanBecomeGeneralAgainWithoutANewRow(): void
    {
        [$productId, $ids, $variants] = $this->scenario('back');
        $media = (int) $this->row($ids['D'])['media_id'];

        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids['A'], 'image:' . $ids['B'], 'image:' . $ids['C'], 'image:' . $ids['D']],
            [],
            ['image:' . $ids['E']]
        );

        $this->assertSame([$ids['A'], $ids['B'], $ids['C'], $ids['D']], $this->idsOf((new ProductImageRepository())->findByProductId($productId)));
        $this->assertSame(3, $this->flag($ids['D'], 'sort_order'), 'last in the general order');
        $this->assertSame($media, (int) $this->row($ids['D'])['media_id'], 'the same row and media item');
        $this->assertSame([$ids['D']], $this->variantIds($variants['redxl']));

        // Chosen again from the library in the general list: also the same row.
        (new ProductGallery())->save($productId, ['image:' . $ids['A'], 'media:' . (int) $this->row($ids['E'])['media_id']], [], []);
        $this->assertSame([$ids['A'], $ids['E']], $this->idsOf((new ProductImageRepository())->findByProductId($productId)));
    }

    public function testARequestWithoutTheVariantOnlyListKeepsThoseAsTheyAre(): void
    {
        [$productId, $ids, $variants] = $this->scenario('nolist');

        // An older form: only the general pictures and the selections.
        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids['C'], 'image:' . $ids['B'], 'image:' . $ids['A']],
            [$variants['red'] => ['image:' . $ids['D']]]
        );

        $this->assertSame([$ids['C'], $ids['B'], $ids['A']], $this->idsOf((new ProductImageRepository())->findByProductId($productId)));
        $this->assertSame(1, $this->flag($ids['D'], 'variant_only'));
        $this->assertSame(1, $this->flag($ids['E'], 'variant_only'));
        $this->assertSame([$ids['D']], $this->variantIds($variants['red']));
    }

    public function testRemovingTheLastLinkKeepsThePictureAndItsMedia(): void
    {
        [$productId, $ids, $variants] = $this->scenario('lastlink');
        $media = (int) $this->row($ids['E'])['media_id'];

        // Blue unticks E: E is variant-only and linked nowhere now.
        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids['A'], 'image:' . $ids['B'], 'image:' . $ids['C']],
            [$variants['blue'] => []],
            ['image:' . $ids['D'], 'image:' . $ids['E']]
        );

        $this->assertSame([], $this->variantIds($variants['blue']));
        $this->assertContains($ids['E'], $this->poolIds($productId), 'the picture stays the product\'s');
        $this->assertNotNull((new MediaRepository())->findById($media), 'and the library item stays');

        // Removed on purpose (left out of both lists): the row goes, the media item stays.
        (new ProductGallery())->save($productId, ['image:' . $ids['A'], 'image:' . $ids['B'], 'image:' . $ids['C']], [], ['image:' . $ids['D']]);
        $this->assertNotContains($ids['E'], $this->poolIds($productId));
        $this->assertNotNull((new MediaRepository())->findById($media));
    }

    public function testDeletingAVariantKeepsThePictureAndTheOtherLinks(): void
    {
        [$productId, $ids, $variants] = $this->scenario('deletevariant');

        (new ProductVariantRepository())->delete($variants['red']);

        $this->assertContains($ids['D'], $this->poolIds($productId));
        $this->assertSame([$ids['D']], $this->variantIds($variants['redxl']), 'Rood XL keeps D');
        $this->assertSame([], $this->variantIds($variants['red']));
    }

    public function testDeletingTheProductLeavesNoRowsAndNoLibraryLoss(): void
    {
        [$productId, $ids] = $this->scenario('deleteproduct');
        $media = (int) $this->row($ids['D'])['media_id'];

        $this->assertTrue((new ProductDeletionService())->delete($productId));

        $db = Database::connection();
        $images = $db->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
        $images->execute([$productId]);
        $this->assertSame(0, (int) $images->fetchColumn());
        $links = $db->prepare('SELECT COUNT(*) FROM product_variant_images WHERE product_image_id IN (' . implode(',', array_map('intval', $ids)) . ')');
        $links->execute();
        $this->assertSame(0, (int) $links->fetchColumn());
        $this->assertNotNull((new MediaRepository())->findById($media));
    }

    public function testTheDefaultImageIsNeverVariantOnly(): void
    {
        [$productId, $ids, $variants] = $this->scenario('seo');
        $path = fn (string $key): string => (string) $this->row($ids[$key])['image_path'];

        // The default variant (Rood, first by sort_order) links B and D: only B stands for the product.
        $this->assertSame([$path('B')], ProductSeo::imagePaths($productId));

        // A default variant that links to variant-only pictures alone: the product's general ones.
        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids['A'], 'image:' . $ids['B'], 'image:' . $ids['C']],
            [$variants['red'] => ['image:' . $ids['D']]],
            ['image:' . $ids['D'], 'image:' . $ids['E']]
        );
        $this->assertSame([$path('A'), $path('B'), $path('C')], ProductSeo::imagePaths($productId));

        // The card's and the admin's helper: general links only, in the variant's order.
        $red = (new ProductVariantImageRepository())->findByVariantIds([$variants['red']])[$variants['red']];
        $this->assertSame([], ProductVariantImageRepository::generalOnly($red));
    }

    public function testADefaultVariantWithoutLinksKeepsTheLegacyImageFallback(): void
    {
        // Backward compatibility: nothing changes for a product whose default
        // variant chose no picture (the legacy products.image_path, as before).
        $productId = $this->product('legacy-seo');
        (new ProductVariantRepository())->create($productId, [], null, true);
        (new ProductGallery())->save($productId, ['media:' . $this->media('legacy-a'), 'media:' . $this->media('legacy-b')]);
        $primary = (string) (new ProductImageRepository())->findPrimary($productId)['image_path'];

        $this->assertSame([$primary], ProductSeo::imagePaths($productId, $primary));
    }

    public function testTheLibraryCountsAVariantOnlyPictureAsTheProductsOnce(): void
    {
        [$productId, $ids] = $this->scenario('usage');
        ShopLocalization::saveProduct($productId, ShopLocalization::defaultLanguage(), [ShopLocalization::NAME => 'Variantbeeld gebruik']);
        ShopLocalization::clearCache();
        $media = (int) $this->row($ids['D'])['media_id'];

        $usages = (new ShopMediaUsage())->usagesFor([$media]);

        $this->assertCount(1, $usages[$media], 'one use: the product\'s picture, with its variant links');
        $this->assertSame('Product: Variantbeeld gebruik (ook bij 2 varianten)', $usages[$media][0]->label);
    }

    public function testAForeignPictureOrUnknownTokenNeverJoinsTheVariantOnlyList(): void
    {
        $other = $this->product('foreign-other');
        (new ProductGallery())->save($other, ['media:' . $this->media('foreign-x')]);
        $foreign = $this->poolIds($other)[0];

        [$productId, $ids] = $this->scenario('foreign');
        (new ProductGallery())->save(
            $productId,
            ['image:' . $ids['A'], 'image:' . $ids['B'], 'image:' . $ids['C']],
            [],
            ['image:' . $ids['D'], 'image:' . $ids['E'], 'image:' . $foreign, 'image:999999999', 'media:999999999']
        );

        $this->assertSame([$ids['A'], $ids['B'], $ids['C'], $ids['D'], $ids['E']], $this->poolIds($productId));
        $this->assertSame([$foreign], $this->poolIds($other), 'the other product is untouched');
        $this->assertSame(0, $this->flag($foreign, 'variant_only'));

        // And another product cannot mark this product's picture.
        (new ProductImageRepository())->markVariantOnly($other, [$ids['A']]);
        $this->assertSame(0, $this->flag($ids['A'], 'variant_only'));
        $this->assertSame(1, $this->flag($ids['A'], 'is_primary'));
    }

    /* ------------------------------------------------------------------ */

    /**
     * The brief's product: A (primary), B, C general; D and E variant-only;
     * Rood → B + D, Blauw → E, Rood XL → D, Groen → nothing.
     *
     * @return array{0: int, 1: array<string, int>, 2: array<string, int>}
     */
    private function scenario(string $suffix): array
    {
        $productId = $this->product($suffix);
        $variantRepository = new ProductVariantRepository();
        $variants = [
            'red' => $variantRepository->create($productId, [], null, true),
            'blue' => $variantRepository->create($productId, [], null, true),
            'redxl' => $variantRepository->create($productId, [], null, true),
            'green' => $variantRepository->create($productId, [], null, true),
        ];

        $media = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
            $media[$key] = $this->media($suffix . '-' . $key);
        }

        (new ProductGallery())->save(
            $productId,
            ['media:' . $media['A'], 'media:' . $media['B'], 'media:' . $media['C']],
            [
                $variants['red'] => ['media:' . $media['B'], 'media:' . $media['D']],
                $variants['blue'] => ['media:' . $media['E']],
                $variants['redxl'] => ['media:' . $media['D']],
                $variants['green'] => [],
            ],
            ['media:' . $media['D'], 'media:' . $media['E']]
        );

        $ids = [];
        foreach ((new ProductImageRepository())->findPoolByProductId($productId) as $row) {
            $ids[(string) array_search((int) $row['media_id'], $media, true)] = (int) $row['id'];
        }

        return [$productId, $ids, $variants];
    }

    private function product(string $suffix): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_variant_only_' . $suffix . '__',
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

    /** A media row for a file that does not exist: nothing here reads the disk. */
    private function media(string $name, ?string $thumbnailPath = null): int
    {
        $file = '__test_variant_only_' . $name . '.png';
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/' . $file,
            'thumbnail_path' => $thumbnailPath,
            'original_filename' => $file,
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

    /** @return array<string, mixed> */
    private function row(int $imageId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);

        return $stmt->fetch() ?: [];
    }

    private function flag(int $imageId, string $column): int
    {
        return (int) $this->row($imageId)[$column];
    }

    /** @return list<int> */
    private function poolIds(int $productId): array
    {
        return $this->idsOf((new ProductImageRepository())->findPoolByProductId($productId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @return list<int> */
    private function variantIds(int $variantId): array
    {
        return $this->idsOf((new ProductVariantImageRepository())->findByVariantIds([$variantId])[$variantId] ?? []);
    }
}
