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
     * Iconen (MediaType::ICON): a section of the ONE library, not a second
     * one. An icon is an ordinary media item that is an SVG; the icon picker
     * lists and uploads nothing else, the library's type filter offers it,
     * and every other image field goes on listing every image.
     */
    public function testTheIconPickerListsAndUploadsOnlySvgAndTheLibraryFiltersOnIt(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::MEDIA_VIEW, AdminPermissions::MEDIA_MANAGE, AdminPermissions::PAGES_MANAGE]);
        $marker = 'zziconfilter' . bin2hex(random_bytes(3));
        foreach (['png' => 'image/png', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'] as $extension => $mime) {
            $this->mediaIds[] = (new MediaRepository())->create([
                'path' => 'assets/media/' . $marker . '-' . $extension . '.' . $extension,
                'display_name' => $marker . '-' . $extension . '.' . $extension,
                'mime_type' => $mime,
            ]);
        }

        $listed = function (string $type) use ($session, $marker): array {
            $response = self::$server->request('GET', '/api/admin/media-list.php?q=' . $marker . '&type=' . $type, $session);
            self::assertSame(200, $response['status']);

            return array_map(static fn (array $item): string => substr((string) $item['name'], strlen($marker) + 1), json_decode($response['body'], true)['items']);
        };

        self::assertSame(['svg.svg'], $listed(MediaType::ICON), 'the icon picker shows icons only');
        self::assertEqualsCanonicalizing(['png.png', 'svg.svg', 'webp.webp'], $listed(MediaType::IMAGE), 'an image picker does not suddenly show icons only');

        // The library's own filter offers the icons, as a section of the same screen.
        $library = self::$server->request('GET', '/admin/media.php?type=icon&q=' . $marker, $session)['body'];
        self::assertMatchesRegularExpression('/<option value="icon" selected>Iconen<\/option>/', $library);
        self::assertStringContainsString($marker . '-svg.svg', $library);
        self::assertStringNotContainsString($marker . '-png.png', $library);

        // Uploading from the icon picker: an SVG, sanitized, or nothing.
        $png = $this->upload($session, $csrf, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'zz-icoon.png', 'image/png', MediaType::ICON);
        self::assertSame(422, $png['status']);
        self::assertStringContainsString('SVG', (string) json_decode($png['body'], true)['error']);

        $evil = $this->upload($session, $csrf, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0h24v24H0z"/></svg>', 'zz-icoon-kwaad.svg', 'image/svg+xml', MediaType::ICON);
        self::assertSame(422, $evil['status'], 'the one SvgSanitizer decides, as for every SVG');

        $safe = $this->upload($session, $csrf, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>', 'zz-icoon.svg', 'image/svg+xml', MediaType::ICON);
        self::assertSame(200, $safe['status'], $safe['body']);
        $item = MediaService::find((int) json_decode($safe['body'], true)['item']['id']);
        $this->mediaIds[] = (int) $item?->id;
        self::assertSame('image/svg+xml', $item?->mimeType, 'afterwards an ordinary media item');
        self::assertSame((int) $item?->id, MediaService::findIcon((int) $item?->id)?->id);
        self::assertSame((int) $item?->id, MediaService::findImage((int) $item?->id)?->id, 'and still an image anywhere else');
    }

    public function testTheIconFilterIsAnSvgImageAndNothingElse(): void
    {
        self::assertTrue(MediaType::isPickerFilter(MediaType::ICON));
        self::assertSame(MediaType::IMAGE, MediaType::kindOfFilter(MediaType::ICON));
        self::assertSame([MediaUploader::SVG_MIME], MediaType::mimesOfFilter(MediaType::ICON));
        self::assertTrue(MediaType::filterAccepts(MediaType::ICON, 'image/svg+xml'));
        self::assertFalse(MediaType::filterAccepts(MediaType::ICON, 'image/png'));
        self::assertFalse(MediaType::filterAccepts(MediaType::ICON, 'video/mp4'));
        self::assertTrue(MediaType::filterAccepts(MediaType::IMAGE, 'image/svg+xml'), 'an image field keeps taking SVG');

        self::assertSame(['image', 'video', 'icon'], MediaType::libraryFilters());
        self::assertSame(['image', 'video'], MediaType::all(), 'an icon is no third kind');
        self::assertFalse(MediaType::isLibraryFilter(MediaType::SOCIAL_IMAGE), 'the share-image filter stays a picker filter only');

        self::assertTrue(MediaUploader::nameFitsFilter('logo.svg', MediaType::ICON));
        self::assertFalse(MediaUploader::nameFitsFilter('logo.png', MediaType::ICON));
        self::assertSame('.svg,image/svg+xml', MediaUploader::acceptAttribute(MediaType::ICON));
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
