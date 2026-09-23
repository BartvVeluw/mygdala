<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Repository\MediaRepository;
use App\Service\AdminPermissions;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\Media\MediaUploader;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The picker's own routes over real HTTP (api/admin/media-upload.php,
 * api/admin/media-list.php): the only way an SVG reaches the logo, the second
 * logo, the favicon or a share image, since those are library items chosen
 * with the picker.
 *
 *  - a malicious SVG is refused through the route an image field uses; a
 *    safe one is stored, rebuilt by SvgSanitizer;
 *  - an ordinary image picker lists SVG; the share-image picker
 *    (MediaType::SOCIAL_IMAGE) does not, and refuses an SVG upload; PNG,
 *    JPEG and WebP are in both;
 *  - the share-image endpoints keep a new SVG out (MediaService::findSocialImage()).
 *
 * The media rows and files are this test's own and are removed in tearDown().
 */
final class MediaPickerSvgHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<string> */
    private array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start();
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
        MediaService::clearCache();
        foreach ($this->mediaIds as $id) {
            $item = MediaService::find($id);
            if ($item !== null) {
                (new MediaUploader())->deleteFile($item->path);
                (new MediaUploader())->deleteFile($item->thumbnailPath);
            }
            (new MediaRepository())->delete($id);
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        MediaService::clearCache();
        $this->accounts->forget();
    }

    public function testAMaliciousSvgIsRefusedThroughTheImageFieldRouteAndASafeOneIsSanitized(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $before = (int) \App\Database::connection()->query('SELECT COUNT(*) FROM media')->fetchColumn();

        $evil = $this->upload($session, $csrf, '<svg xmlns="http://www.w3.org/2000/svg" onload="fetch(\'/admin/\')"><script>alert(document.cookie)</script></svg>', 'zz-logo-kwaad.svg', 'image/svg+xml', MediaType::IMAGE);
        self::assertSame(422, $evil['status']);
        self::assertStringContainsString('SVG', (string) json_decode($evil['body'], true)['error']);
        self::assertSame($before, (int) \App\Database::connection()->query('SELECT COUNT(*) FROM media')->fetchColumn(), 'nothing stored');

        $safe = $this->upload($session, $csrf, '<?xml version="1.0"?><!-- editor --><svg xmlns="http://www.w3.org/2000/svg" width="40" height="10"><rect width="40" height="10"/></svg>', 'zz-logo-veilig.svg', 'image/svg+xml', MediaType::IMAGE);
        self::assertSame(200, $safe['status'], $safe['body']);
        $item = MediaService::find((int) json_decode($safe['body'], true)['item']['id']);
        $this->mediaIds[] = (int) $item?->id;
        self::assertSame('image/svg+xml', $item?->mimeType);
        self::assertStringNotContainsString('editor', (string) file_get_contents((string) $item?->absolutePath()), 'stored as the sanitizer rebuilt it');

        // A share image may not be an SVG, not even this safe one.
        $social = $this->upload($session, $csrf, '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="10"><rect width="40" height="10"/></svg>', 'zz-deel.svg', 'image/svg+xml', MediaType::SOCIAL_IMAGE);
        self::assertSame(422, $social['status']);
        self::assertNull(MediaService::findSocialImage((int) $item?->id), 'the share-image endpoints refuse a new SVG');
        self::assertSame((int) $item?->id, MediaService::findSocialImage((int) $item?->id, (int) $item?->id)?->id, 'the one a page already had stays acceptable');
        self::assertSame((int) $item?->id, MediaService::findImage((int) $item?->id)?->id, 'the logo may be an SVG');
    }

    public function testTheShareImagePickerListsNoSvgWhileAnOrdinaryImagePickerDoes(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $marker = 'zzpickfilter' . bin2hex(random_bytes(3));
        $rows = [
            'png' => ['image/png', 'png'],
            'jpeg' => ['image/jpeg', 'jpg'],
            'webp' => ['image/webp', 'webp'],
            'svg' => ['image/svg+xml', 'svg'],
            'mp4' => ['video/mp4', 'mp4'],
        ];
        foreach ($rows as $name => [$mime, $extension]) {
            $this->mediaIds[] = (new MediaRepository())->create([
                'path' => 'assets/media/' . $marker . '-' . $name . '.' . $extension,
                'display_name' => $marker . '-' . $name . '.' . $extension,
                'mime_type' => $mime,
            ]);
        }

        $listed = function (string $type) use ($session, $marker): array {
            $response = self::$server->request('GET', '/api/admin/media-list.php?q=' . $marker . '&type=' . $type, $session);
            self::assertSame(200, $response['status']);

            return array_map(static fn (array $item): string => substr((string) $item['name'], strlen($marker) + 1), json_decode($response['body'], true)['items']);
        };

        self::assertEqualsCanonicalizing(['png.png', 'jpeg.jpg', 'webp.webp', 'svg.svg'], $listed(MediaType::IMAGE), 'an ordinary image picker shows SVG');
        self::assertEqualsCanonicalizing(['png.png', 'jpeg.jpg', 'webp.webp'], $listed(MediaType::SOCIAL_IMAGE), 'the share-image picker does not');
        self::assertSame(['mp4.mp4'], $listed(MediaType::VIDEO));

        // The fields ask for the right filter.
        $settings = self::$server->request('GET', '/admin/settings.php', $session)['body'];
        self::assertMatchesRegularExpression('/data-media-picker-kind="social_image"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*<input type="hidden" name="og_image_media_id"/', $settings);
        self::assertMatchesRegularExpression('/data-media-picker-kind="image"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*<input type="hidden" name="logo_media_id"/', $settings);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function upload(string $session, string $csrf, string $bytes, string $name, string $type, string $kind): array
    {
        $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return self::$server->request('POST', '/api/admin/media-upload.php', $session, ['csrf_token' => $csrf, 'kind' => $kind], ['file' => new \CURLFile($path, $type, $name)]);
    }
}
