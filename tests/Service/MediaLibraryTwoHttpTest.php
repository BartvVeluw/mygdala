<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\MediaRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Media\MediaFolderService;
use App\Service\Media\MediaService;
use App\Service\PortfolioGalleryContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Media Library 2.0 as the admin really answers it over HTTP, with PHP's own
 * web server on this checkout (Tests\Support\BuiltInServer):
 *
 *   - the ONE save of a name and an alt text (api/admin/update-media.php):
 *     Post/Redirect/Get with "Opgeslagen", a refused save that keeps what
 *     was typed, the JSON the quick edit reads, and both values escaped
 *     wherever the screen prints them;
 *   - the folders: make, rename, move a selection, delete without deleting
 *     items, and a folder id that does not exist;
 *   - a real multipart upload, through every check, landing in the folder
 *     that was open — the way the picker and the library's queue send it;
 *   - the picker's listing by folder;
 *   - Portfolio choosing its picture from the library;
 *   - and the guards of every new write: login, media.manage, POST, CSRF.
 *
 * Skips itself when the server cannot start (TESTING.md). Everything it
 * creates — media rows and their files, folders, a Portfolio item, accounts,
 * sessions — is its own and removed by exact id in tearDown().
 */
final class MediaLibraryTwoHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $folderIds = [];

    /** @var list<int> */
    private array $portfolioItemIds = [];

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
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->accounts = new AdminTestSession();
        MediaService::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->portfolioItemIds as $id) {
            $db->prepare('DELETE FROM portfolio_gallery_items WHERE id = :id')->execute(['id' => $id]);
        }

        // Through the library's own delete, so a file an upload wrote goes
        // with its row.
        $service = new MediaService();
        foreach ($this->mediaIds as $id) {
            MediaService::clearCache();
            $service->delete($id);
            (new MediaRepository())->delete($id);
        }

        foreach ($this->folderIds as $id) {
            $db->prepare('DELETE FROM media_folders WHERE id = :id')->execute(['id' => $id]);
        }

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->accounts->forget();
        MediaService::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* One save for a name and an alt text                                 */
    /* ------------------------------------------------------------------ */

    public function testTheDetailsFormSavesNameAndAltTextTogetherAndRedirects(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.manage']);
        $id = $this->item('ml2-details');

        $response = self::$server->request('POST', '/api/admin/update-media.php', $session, [
            'csrf_token' => $csrf,
            'media_id' => (string) $id,
            'name' => 'zz Nieuwe naam',
            'alt_text' => 'Een gegraveerde houten snijplank',
            // Not a field of the form: never stored, whatever a request says.
            'path' => '../../evil.php',
            'folder_id' => '1',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/media.php?id=' . $id . '&saved=1', $response['location']);

        MediaService::clearCache();
        $item = MediaService::find($id);
        $this->assertSame('zz Nieuwe naam.png', $item?->displayName);
        $this->assertSame('Een gegraveerde houten snijplank', $item?->altText);
        $this->assertStringStartsWith('assets/media/__ml2_http_', (string) $item?->path, 'the path is not something a request can change');
        $this->assertNull($item?->folderId);

        $screen = self::$server->request('GET', '/admin/media.php?id=' . $id . '&saved=1', $session);
        $this->assertSame(200, $screen['status']);
        $this->assertSame(1, substr_count($screen['body'], 'action="/api/admin/update-media.php"'), 'one form on the item view');
        $this->assertStringNotContainsString('rename-media.php', $screen['body']);
        $this->assertStringContainsString('data-save-bar', $screen['body'], 'the item view has the shared save bar');
    }

    public function testARefusedSaveStoresNothingAndShowsWhatWasTyped(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.manage']);
        $id = $this->item('ml2-refused', 'Oude alt');

        $response = self::$server->request('POST', '/api/admin/update-media.php', $session, [
            'csrf_token' => $csrf,
            'media_id' => (string) $id,
            'name' => 'map/bestand',
            'alt_text' => '<script>alert("alt")</script>',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/media.php?id=' . $id, $response['location']);

        MediaService::clearCache();
        $this->assertSame('Oude alt', MediaService::find($id)?->altText, 'the alt text did not go through without the name');

        $screen = self::$server->request('GET', '/admin/media.php?id=' . $id, $session)['body'];
        $this->assertStringContainsString('value="map/bestand"', $screen, 'the typed name is back in its field');
        $this->assertStringContainsString('value="&lt;script&gt;alert(&quot;alt&quot;)&lt;/script&gt;"', $screen, 'the typed alt text is back, escaped');
        $this->assertStringNotContainsString('<script>alert("alt")', $screen);
        $this->assertStringContainsString('aria-invalid="true"', $screen);
        $this->assertStringContainsString('data-save-bar-unsaved', $screen);
    }

    public function testTheQuickEditGetsJsonFromTheSameEndpoint(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.manage']);
        $id = $this->item('ml2-quick');

        $ok = self::$server->request('POST', '/api/admin/update-media.php', $session, [
            'csrf_token' => $csrf, 'media_id' => (string) $id, 'name' => 'zz Snel', 'alt_text' => 'Snelle tekst', 'ajax' => '1',
        ]);
        $this->assertSame(200, $ok['status']);
        $answer = json_decode($ok['body'], true);
        $this->assertTrue($answer['ok']);
        $this->assertSame('zz Snel.png', $answer['item']['name']);
        $this->assertSame('Snelle tekst', $answer['item']['alt']);

        $refused = self::$server->request('POST', '/api/admin/update-media.php', $session, [
            'csrf_token' => $csrf, 'media_id' => (string) $id, 'name' => '', 'alt_text' => str_repeat('x', 300), 'ajax' => '1',
        ]);
        $this->assertSame(422, $refused['status']);
        $errors = json_decode($refused['body'], true)['errors'];
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('alt_text', $errors);

        $missing = self::$server->request('POST', '/api/admin/update-media.php', $session, [
            'csrf_token' => $csrf, 'media_id' => '999999999', 'name' => 'x', 'alt_text' => '', 'ajax' => '1',
        ]);
        $this->assertSame(404, $missing['status'], 'an id naming nothing is not a new item');
    }

    public function testTheGridPrintsNamesAndAltTextsEscapedAndOffersBothViews(): void
    {
        [$session] = $this->accounts->signIn(['media.manage']);
        $folder = $this->folder('ZZ ML2 <b>Map</b>');
        $id = $this->item('ml2-escape', '"><img src=x onerror=alert(1)>');
        (new MediaFolderService())->move([$id], (string) $folder);

        $grid = self::$server->request('GET', '/admin/media.php?folder=' . $folder, $session)['body'];

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $grid);
        $this->assertStringContainsString('data-media-alt="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $grid);
        $this->assertStringContainsString('ZZ ML2 &lt;b&gt;Map&lt;/b&gt;', $grid);
        $this->assertStringNotContainsString('<b>Map</b>', $grid);
        $this->assertStringContainsString('data-media-view-option="grid"', $grid);
        $this->assertStringContainsString('data-media-view-option="list"', $grid);
        $this->assertStringContainsString('data-media-active-folder="' . $folder . '"', $grid);
        $this->assertStringContainsString('name="folder_id" value="' . $folder . '"', $grid, 'the upload form files into the open folder');
    }

    /* ------------------------------------------------------------------ */
    /* Folders                                                             */
    /* ------------------------------------------------------------------ */

    public function testFoldersAreMadeRenamedAndDeletedWithoutDeletingTheirItems(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.manage']);

        $made = self::$server->request('POST', '/api/admin/create-media-folder.php', $session, [
            'csrf_token' => $csrf, 'name' => 'ZZ ML2 Kerst', 'return_q' => '', 'return_type' => '', 'return_folder' => '', 'return_page' => '1',
        ]);
        $this->assertSame(302, $made['status']);
        $this->assertMatchesRegularExpression('#^/admin/media\.php\?folder=(\d+)$#', $made['location']);
        preg_match('#folder=(\d+)#', $made['location'], $match);
        $folder = (int) $match[1];
        $this->folderIds[] = $folder;

        $renamed = self::$server->request('POST', '/api/admin/rename-media-folder.php', $session, [
            'csrf_token' => $csrf, 'folder_id' => (string) $folder, 'name' => 'ZZ ML2 Kerst 2026',
        ]);
        $this->assertSame(302, $renamed['status']);
        $this->assertSame('ZZ ML2 Kerst 2026', (new MediaFolderService())->find($folder)['name'] ?? null);

        $ids = [$this->item('ml2-map-1'), $this->item('ml2-map-2')];
        $moved = self::$server->request('POST', '/api/admin/move-media-items.php', $session, [
            'csrf_token' => $csrf, 'target_folder' => (string) $folder, 'ajax' => '1',
        ] + self::flat('media_ids', $ids));
        $this->assertSame(200, $moved['status'], $moved['body']);
        $this->assertSame(2, json_decode($moved['body'], true)['moved']);

        $deleted = self::$server->request('POST', '/api/admin/delete-media-folder.php', $session, [
            'csrf_token' => $csrf, 'folder_id' => (string) $folder,
        ]);
        $this->assertSame(302, $deleted['status']);
        $this->assertSame('/admin/media.php?folder=none', $deleted['location']);

        MediaService::clearCache();
        foreach ($ids as $id) {
            $this->assertNotNull(MediaService::find($id), 'the item stays');
            $this->assertNull(MediaService::find($id)?->folderId, 'in Geen map');
        }
    }

    public function testMovingToAFolderThatDoesNotExistIsRefused(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.manage']);
        $id = $this->item('ml2-nergens');

        $response = self::$server->request('POST', '/api/admin/move-media-items.php', $session, [
            'csrf_token' => $csrf, 'target_folder' => '999999999', 'ajax' => '1',
        ] + self::flat('media_ids', [$id]));

        $this->assertSame(422, $response['status']);
        $this->assertFalse(json_decode($response['body'], true)['ok']);
        MediaService::clearCache();
        $this->assertNull(MediaService::find($id)?->folderId);
    }

    /* ------------------------------------------------------------------ */
    /* Uploading into the library                                          */
    /* ------------------------------------------------------------------ */

    public function testAnUploadBecomesALibraryItemInTheOpenFolder(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.view']);
        $folder = $this->folder('ZZ ML2 Upload');

        $response = self::$server->request('POST', '/api/admin/media-upload.php', $session, [
            'csrf_token' => $csrf,
            'kind' => 'image',
            'folder_id' => (string) $folder,
        ], ['file' => new \CURLFile($this->png(), 'image/png', 'zz-ml2-upload.png')]);

        $this->assertSame(200, $response['status'], $response['body']);
        $answer = json_decode($response['body'], true);
        $this->mediaIds[] = (int) $answer['item']['id'];

        $this->assertSame($folder, $answer['item']['folder']);
        MediaService::clearCache();
        $item = MediaService::find((int) $answer['item']['id']);
        $this->assertNotNull($item, 'a normal media row');
        $this->assertSame($folder, $item->folderId);
        $this->assertMatchesRegularExpression('#^assets/media/[0-9a-f]{32}\.png$#', $item->path, 'the stored name is never the uploaded name');
        $this->assertSame('zz-ml2-upload.png', $item->originalFilename);

        $listed = json_decode(self::$server->request('GET', '/api/admin/media-list.php?type=image&folder=' . $folder, $session)['body'], true);
        $this->assertSame([(int) $answer['item']['id']], array_column($listed['items'], 'id'), 'the picker lists the folder it is showing');
        $this->assertContains($folder, array_column($listed['folders']['folders'], 'id'), 'and offers the folders on its first page');
    }

    public function testAnUploadIntoAFolderThatIsGoneLandsInNoFolder(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['media.view']);

        $response = self::$server->request('POST', '/api/admin/media-upload.php', $session, [
            'csrf_token' => $csrf, 'kind' => 'image', 'folder_id' => '999999999',
        ], ['file' => new \CURLFile($this->png(70, 40), 'image/png', 'zz-ml2-weg.png')]);

        $this->assertSame(200, $response['status'], $response['body']);
        $answer = json_decode($response['body'], true);
        $this->mediaIds[] = (int) $answer['item']['id'];
        $this->assertNull($answer['item']['folder']);
    }

    /* ------------------------------------------------------------------ */
    /* Portfolio chooses from the library                                  */
    /* ------------------------------------------------------------------ */

    public function testAPortfolioItemTakesALibraryPictureAndDeletingItKeepsTheFile(): void
    {
        if (!ModuleRegistry::isEnabled('portfolio')) {
            $this->markTestSkipped('the Portfolio module is off here');
        }

        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);
        $id = $this->item('ml2-portfolio', 'Bibliotheektekst');
        $media = MediaService::find($id);

        $refused = self::$server->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf, 'media_id' => '999999999', 'alt' => '', 'title' => 'ZZ ML2 geen beeld', 'subtitle' => '',
        ]);
        $this->assertSame('/admin/portfolio-item.php', $refused['location'], 'no picture, no item');

        $made = self::$server->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf, 'media_id' => (string) $id, 'alt' => '', 'title' => 'ZZ ML2 project', 'subtitle' => '',
        ]);
        $this->assertSame(302, $made['status']);
        $this->assertMatchesRegularExpression('#^/admin/portfolio-item\.php\?id=(\d+)&created=1$#', $made['location']);
        preg_match('#id=(\d+)#', $made['location'], $match);
        $itemId = (int) $match[1];
        $this->portfolioItemIds[] = $itemId;

        $row = (new PortfolioGalleryRepository())->findItemById($itemId);
        $this->assertSame($id, (int) $row['media_id']);
        $this->assertSame($media?->path, $row['image_path'], 'the path every public reader reads is written along');

        $this->assertSame(1, (new MediaService())->usageCountsFor([$media])[$id] ?? 0, 'the library knows the item uses it');

        PortfolioGalleryContent::clearCache();
        $cards = array_values(array_filter(
            PortfolioGalleryContent::catalogueItems(),
            static fn (array $card): bool => $card['image_path'] === $media?->path
        ));
        $this->assertSame('Bibliotheektekst', $cards[0]['alt'] ?? null, 'an item without its own alt text gets the library\'s');

        $deleted = self::$server->request('POST', '/api/admin/delete-portfolio-item.php', $session, [
            'csrf_token' => $csrf, 'item_id' => (string) $itemId,
        ]);
        $this->assertSame(302, $deleted['status']);
        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($id), 'deleting the item leaves the library item');
    }

    /* ------------------------------------------------------------------ */
    /* Guards                                                              */
    /* ------------------------------------------------------------------ */

    public function testEveryNewWriteNeedsTheRightPermissionPostAndAToken(): void
    {
        [$viewer, $viewerCsrf] = $this->accounts->signIn(['media.view']);
        [$manager, $managerCsrf] = $this->accounts->signIn(['media.manage']);
        $id = $this->item('ml2-guards', 'Blijft');
        $folder = $this->folder('ZZ ML2 Guards');

        $writes = [
            '/api/admin/update-media.php' => ['media_id' => (string) $id, 'name' => 'zz Gehackt', 'alt_text' => 'Gehackt'],
            '/api/admin/move-media-items.php' => ['target_folder' => (string) $folder] + self::flat('media_ids', [$id]),
            '/api/admin/create-media-folder.php' => ['name' => 'ZZ ML2 Gehackt'],
            '/api/admin/rename-media-folder.php' => ['folder_id' => (string) $folder, 'name' => 'ZZ ML2 Gehackt'],
            '/api/admin/delete-media-folder.php' => ['folder_id' => (string) $folder],
        ];

        foreach ($writes as $path => $fields) {
            $this->assertSame(401, self::$server->request('POST', $path, null, $fields)['status'], $path . ' without a login');
            $this->assertSame(403, self::$server->request('POST', $path, $viewer, ['csrf_token' => $viewerCsrf] + $fields)['status'], $path . ' with media.view only');
            $this->assertSame(405, self::$server->request('GET', $path, $manager)['status'], $path . ' with GET');
            $this->assertSame(403, self::$server->request('POST', $path, $manager, $fields)['status'], $path . ' without a token');
            $this->assertSame(403, self::$server->request('POST', $path, $manager, ['csrf_token' => 'x' . $managerCsrf] + $fields)['status'], $path . ' with a wrong token');
        }

        MediaService::clearCache();
        $this->assertSame('Blijft', MediaService::find($id)?->altText);
        $this->assertNull(MediaService::find($id)?->folderId);
        $this->assertSame('ZZ ML2 Guards', (new MediaFolderService())->find($folder)['name'] ?? null);
    }

    /* ------------------------------------------------------------------ */

    private function item(string $name, string $alt = ''): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__ml2_http_' . $name . '_' . bin2hex(random_bytes(4)) . '__.png',
            'thumbnail_path' => null,
            'original_filename' => $name . '.png',
            'display_name' => 'zz-' . $name . '-' . bin2hex(random_bytes(3)) . '.png',
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => $alt,
            'checksum' => null,
        ]);
        $this->mediaIds[] = $id;

        return $id;
    }

    private function folder(string $name): int
    {
        $result = (new MediaFolderService())->create($name);
        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->folderIds[] = (int) $result['id'];

        return (int) $result['id'];
    }

    /** A small real PNG, different every time so the library does not reuse one. */
    private function png(int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagesetpixel($image, 1, 1, (int) imagecolorallocate($image, random_int(0, 255), 7, 9));

        $file = (string) tempnam(sys_get_temp_dir(), 'ml2png');
        imagepng($image, $file);
        imagedestroy($image);
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * A list field the way a browser posts it (name[0]=…&name[1]=…), since
     * BuiltInServer::request() url-encodes a flat array.
     *
     * @param list<int> $ids
     * @return array<string, string>
     */
    private static function flat(string $name, array $ids): array
    {
        $fields = [];
        foreach (array_values($ids) as $index => $id) {
            $fields[$name . '[' . $index . ']'] = (string) $id;
        }

        return $fields;
    }
}
