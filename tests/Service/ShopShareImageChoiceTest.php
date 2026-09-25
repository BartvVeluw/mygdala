<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\MediaRepository;
use App\Service\Media\MediaService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api/admin/_shop_share_image.php';

/**
 * What a product or collection form means by its share image since Media
 * Library 2.0 (shop_share_image_choice(), api/admin/_shop_share_image.php):
 * a library image, resolved against the library before anything is written;
 * an old own file kept until another image is chosen or it is removed on
 * purpose; and a file deleted only when it was the Shop's own.
 *
 * The media rows are this test's own, without files, removed in tearDown().
 */
final class ShopShareImageChoiceTest extends TestCase
{
    /** @var list<int> */
    private array $mediaIds = [];

    protected function tearDown(): void
    {
        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }

        MediaService::clearCache();
    }

    public function testALibraryImageIsChosenAndAnOldOwnFileIsLetGo(): void
    {
        $raster = $this->media('image/png');

        $choice = shop_share_image_choice(['og_media_id' => (string) $raster], ['og_image_path' => 'assets/images/products/oud.webp']);

        $this->assertTrue($choice['change']);
        $this->assertSame($raster, $choice['media']?->id);
        $this->assertSame('assets/images/products/oud.webp', $choice['old_path'], 'the old own file was the Shop\'s alone');
        $this->assertNull($choice['error']);
    }

    public function testTheSameImageAgainChangesNothingAndALibraryFileIsNeverLetGo(): void
    {
        $first = $this->media('image/png');
        $second = $this->media('image/jpeg');
        $stored = ['og_media_id' => $first, 'og_image_path' => 'assets/media/x.png'];

        $this->assertFalse(shop_share_image_choice(['og_media_id' => (string) $first], $stored)['change']);

        $replaced = shop_share_image_choice(['og_media_id' => (string) $second], $stored);
        $this->assertTrue($replaced['change']);
        $this->assertNull($replaced['old_path'], 'a library file may be used elsewhere');

        $cleared = shop_share_image_choice(['og_media_id' => ''], $stored);
        $this->assertTrue($cleared['change']);
        $this->assertNull($cleared['media']);
        $this->assertNull($cleared['old_path']);
    }

    public function testAnOldOwnFileStaysUntilItIsRemovedOnPurpose(): void
    {
        $legacy = ['og_media_id' => null, 'og_image_path' => 'assets/images/products/oud.webp'];

        $this->assertFalse(shop_share_image_choice(['og_media_id' => ''], $legacy)['change'], 'an empty picker says nothing about an old file');
        $this->assertFalse(shop_share_image_choice([], $legacy)['change'], 'a form without the field changes nothing');

        $removed = shop_share_image_choice(['og_media_id' => '', 'remove_og_image' => '1'], $legacy);
        $this->assertTrue($removed['change']);
        $this->assertNull($removed['media']);
        $this->assertSame('assets/images/products/oud.webp', $removed['old_path']);
    }

    public function testAnSvgOrAnIdNamingNothingIsRefused(): void
    {
        $svg = $this->media('image/svg+xml');

        foreach ([(string) $svg, '999999999', 'abc', '-3'] as $posted) {
            $choice = shop_share_image_choice(['og_media_id' => $posted], []);
            $this->assertFalse($choice['change'], $posted);
            $this->assertNotNull($choice['error'], $posted);
        }
    }

    private function media(string $mime): int
    {
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'][$mime];
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__share_choice_' . bin2hex(random_bytes(5)) . '__.' . $extension,
            'original_filename' => 'deel.' . $extension,
            'display_name' => 'zz-deel-' . bin2hex(random_bytes(3)) . '.' . $extension,
            'mime_type' => $mime,
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
}
