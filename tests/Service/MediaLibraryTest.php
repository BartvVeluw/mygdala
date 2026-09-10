<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\MediaRepository;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUploader;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestMediaUploader;

/**
 * The Media Library itself: what an upload records, what it refuses, how
 * search behaves, what deletion does to the files, and what happens when the
 * row and the disk disagree.
 *
 * Integration tests against the test database, because every one of these is
 * about the row and the file together — a mock of either would only prove
 * that the mock behaves as written.
 *
 * FIXTURES CLEAN UP AFTER THEMSELVES. Every media row this test creates is
 * remembered by id and deleted in tearDown(), together with the files behind
 * it. Nothing here touches an item the CMS already had: the adopted images of
 * the real site are read-only as far as this file is concerned.
 */
final class MediaLibraryTest extends TestCase
{
    /** @var list<int> media ids created by the running test */
    private array $created = [];

    /** @var list<string> absolute paths of temp files handed to the uploader */
    private array $tempFiles = [];

    private MediaService $service;
    private MediaRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new MediaRepository();
        // The real uploader with one seam opened; see Tests\Support\TestMediaUploader.
        $this->service = new MediaService($this->repository, new TestMediaUploader());
        MediaService::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $item = MediaService::find($id);

            if ($item !== null) {
                $uploader = new MediaUploader();
                $uploader->deleteFile($item->path);
                $uploader->deleteFile($item->thumbnailPath);
            }

            $this->repository->delete($id);
        }

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->created = [];
        $this->tempFiles = [];
        MediaService::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* What an upload records                                              */
    /* ------------------------------------------------------------------ */

    public function testAnUploadCreatesARowThatKnowsTheFileItStored(): void
    {
        $item = $this->upload($this->pngFixture(320, 200), 'vakantiefoto.png', 'Een blauw vlak');

        $this->assertGreaterThan(0, $item->id);
        $this->assertStringStartsWith(MediaUploader::PUBLIC_PREFIX, $item->path);
        $this->assertSame('vakantiefoto.png', $item->originalFilename);
        $this->assertSame('image/png', $item->mimeType);
        $this->assertSame(320, $item->width);
        $this->assertSame(200, $item->height);
        $this->assertGreaterThan(0, (int) $item->fileSize);
        $this->assertSame('Een blauw vlak', $item->altText);
        $this->assertNotNull($item->checksum);
        $this->assertTrue($item->fileExists(), 'the stored file must actually be on disk');
    }

    /**
     * The client's filename is a label, never a filesystem name. This is the
     * whole reason a crafted name cannot place a file the web server would
     * execute, or escape the upload folder.
     */
    public function testTheStoredNameIsRandomAndTheSubmittedNameIsOnlyALabel(): void
    {
        $item = $this->upload($this->pngFixture(), '../../evil.php.png', '');

        $this->assertMatchesRegularExpression(
            '#^assets/media/[0-9a-f]{32}\.png$#',
            $item->path,
            'the stored path must be a random name in the library folder'
        );
        $this->assertStringNotContainsString('..', $item->originalFilename);
        $this->assertStringNotContainsString('/', $item->originalFilename);
    }

    /** JPEG, PNG and WebP are re-encoded and get a thumbnail for the grid. */
    public function testAnOptimizableUploadGetsAThumbnailOfItsOwn(): void
    {
        $item = $this->upload($this->pngFixture(1200, 900), 'groot.png', '');

        $this->assertNotNull($item->thumbnailPath);
        $this->assertStringStartsWith(MediaUploader::THUMBNAIL_PREFIX, (string) $item->thumbnailPath);
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . $item->thumbnailPath);
        $this->assertSame('/' . $item->thumbnailPath, $item->displayPath());
    }

    /* ------------------------------------------------------------------ */
    /* What it refuses                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The type is decided by the file HEADER. A PHP script named .png is the
     * exact attack this check exists for, and it must not depend on the
     * extension, the name or the Content-Type the browser claimed.
     */
    public function testAScriptRenamedToAnImageExtensionIsRefused(): void
    {
        $file = $this->tempFile("<?php echo 'pwned';", 'shell.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Alleen JPG, PNG, WEBP of GIF');

        (new TestMediaUploader())->store([
            'name' => 'shell.png',
            'type' => 'image/png',
            'tmp_name' => $file,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($file),
        ]);
    }

    /**
     * SVG can carry script and this project has no sanitizer for it, so the
     * library refuses one however it is labelled. An SVG already deployed and
     * adopted in place keeps working — that is a different thing, and
     * Tests\Service\BrandingTest covers it.
     */
    public function testAnSvgIsRefusedWhateverItIsNamed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $file = $this->tempFile($svg, 'logo.png');

        $this->expectException(\RuntimeException::class);

        (new TestMediaUploader())->store([
            'name' => 'logo.png',
            'type' => 'image/svg+xml',
            'tmp_name' => $file,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($file),
        ]);
    }

    public function testAnEmptySlotIsRefusedWithSomethingAnEditorCanRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Geen bestand geselecteerd.');

        (new TestMediaUploader())->store(['error' => UPLOAD_ERR_NO_FILE]);
    }

    public function testAnOversizedUploadIsRefusedBeforeAnythingIsStored(): void
    {
        $file = $this->tempFile('x', 'huge.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('te groot');

        (new TestMediaUploader())->store([
            'name' => 'huge.png',
            'tmp_name' => $file,
            'error' => UPLOAD_ERR_OK,
            'size' => MediaUploader::MAX_BYTES + 1,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Metadata                                                            */
    /* ------------------------------------------------------------------ */

    public function testAltTextPersistsAndIsTheOnlyThingAnEditorMayChange(): void
    {
        $item = $this->upload($this->pngFixture(), 'foto.png', 'Eerste omschrijving');

        $this->assertTrue($this->service->updateAltText($item->id, '  Tweede omschrijving  '));

        MediaService::clearCache();
        $reloaded = MediaService::find($item->id);

        $this->assertNotNull($reloaded);
        $this->assertSame('Tweede omschrijving', $reloaded->altText, 'stored trimmed');
        $this->assertSame($item->path, $reloaded->path, 'the path describes the file and is not editable');
        $this->assertSame($item->checksum, $reloaded->checksum);
    }

    public function testUpdatingAnUnknownItemReportsFailureRatherThanCreatingOne(): void
    {
        $before = $this->repository->countAll();

        $this->assertFalse($this->service->updateAltText(9_999_999, 'nope'));
        $this->assertSame($before, $this->repository->countAll());
    }

    /* ------------------------------------------------------------------ */
    /* Deduplication                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * A PNG is RE-ENCODED on the way in, so its stored bytes are not the bytes
     * that arrived — which is exactly the case a checksum taken before storage
     * misses. The second pass, on what was actually written, is what catches
     * it, and it is the case that matters: almost every real upload is a
     * JPEG, PNG or WebP.
     */
    public function testUploadingTheSamePhotoAgainReusesItEvenThoughItIsReEncoded(): void
    {
        $bytes = $this->pngFixture(500, 320);

        $first = $this->upload($bytes, 'zelfde-foto.png', 'Een foto');

        $before = $this->repository->countAll();

        $second = $this->service->upload([
            'name' => 'zelfde-foto-kopie.png',
            'tmp_name' => $this->tempFile($bytes, 'zelfde-foto-kopie.png'),
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bytes),
        ], '');

        $this->assertTrue($second['reused']);
        $this->assertSame($first->id, $second['item']->id);
        $this->assertSame($before, $this->repository->countAll(), 'no second row');

        // And no second file left behind either.
        $this->assertSame(
            1,
            count(array_filter(
                (array) glob(dirname(__DIR__, 2) . '/' . MediaUploader::PUBLIC_PREFIX . '*'),
                static fn (string $path): bool => is_file($path) && hash_file('sha256', $path) === $first->checksum
            )),
            'the duplicate must not stay behind on disk'
        );
    }

    /**
     * The same bytes uploaded twice give back the item that is already there.
     * A GIF is stored verbatim, so this is the pass that catches it before
     * anything is written at all. Only an exact checksum match counts —
     * nothing here compares images for visual similarity, and nothing should.
     */
    public function testUploadingTheExactSameFileAgainReusesTheExistingItem(): void
    {
        $bytes = $this->gifFixture();

        $first = $this->upload($bytes, 'kaartje.gif', 'Een kaartje');

        $second = $this->service->upload([
            'name' => 'kaartje-kopie.gif',
            'tmp_name' => $this->tempFile($bytes, 'kaartje-kopie.gif'),
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bytes),
        ], '');

        $this->assertTrue($second['reused']);
        $this->assertSame($first->id, $second['item']->id);
        $this->assertSame('Een kaartje', $second['item']->altText, 'the existing alt text is not overwritten');
    }

    /* ------------------------------------------------------------------ */
    /* Search                                                              */
    /* ------------------------------------------------------------------ */

    public function testSearchMatchesOnTheOriginalFilename(): void
    {
        $item = $this->upload($this->pngFixture(11, 11), 'zzz-zoekterm-uniek.png', '');

        $found = $this->service->browse('zoekterm-uniek');

        $this->assertSame(1, $found['total']);
        $this->assertSame($item->id, $found['items'][0]->id);
    }

    public function testSearchMatchesOnTheAltText(): void
    {
        $item = $this->upload($this->pngFixture(12, 12), 'anonieme-naam.png', 'zzz alt tekst uniek');

        $found = $this->service->browse('alt tekst uniek');

        $this->assertSame(1, $found['total']);
        $this->assertSame($item->id, $found['items'][0]->id);
    }

    /**
     * `_` is a LIKE wildcard, and treating it as one has already cost this
     * project real data once. A search for "foto_1" means the underscore.
     */
    public function testAnUnderscoreInASearchTermIsALiteralUnderscore(): void
    {
        $this->upload($this->pngFixture(13, 13), 'zzzfotoX1uniek.png', '');
        $wanted = $this->upload($this->pngFixture(14, 14), 'zzzfoto_1uniek.png', '');

        $found = $this->service->browse('zzzfoto_1uniek');

        $this->assertSame(1, $found['total'], 'the underscore must not match any character');
        $this->assertSame($wanted->id, $found['items'][0]->id);
    }

    public function testAnEmptySearchReturnsTheWholeLibraryNewestFirst(): void
    {
        $newest = $this->upload($this->pngFixture(15, 15), 'nieuwste.png', '');

        $found = $this->service->browse('');

        $this->assertGreaterThanOrEqual(1, $found['total']);
        $this->assertSame($newest->id, $found['items'][0]->id);
    }

    /* ------------------------------------------------------------------ */
    /* Deletion, and the row/disk mismatch                                 */
    /* ------------------------------------------------------------------ */

    public function testAnUnusedItemIsDeletedTogetherWithItsFileAndThumbnail(): void
    {
        $item = $this->upload($this->pngFixture(600, 400), 'weg.png', '');

        $file = dirname(__DIR__, 2) . '/' . $item->path;
        $thumbnail = dirname(__DIR__, 2) . '/' . $item->thumbnailPath;

        $this->assertFileExists($file);
        $this->assertFileExists($thumbnail);

        $result = $this->service->delete($item->id);

        $this->assertTrue($result['deleted']);
        $this->assertSame('ok', $result['reason']);
        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($thumbnail);
        $this->assertNull(MediaService::find($item->id));
    }

    public function testDeletingSomethingThatIsNotThereIsReportedRatherThanPretended(): void
    {
        $result = $this->service->delete(9_999_999);

        $this->assertFalse($result['deleted']);
        $this->assertSame('not_found', $result['reason']);
    }

    /**
     * A row whose file has gone must degrade, never fatal: the admin shows it
     * as broken and it can still be deleted, which is exactly how somebody
     * cleans the mess up.
     */
    public function testARowWhoseFileIsGoneIsStillUsableAndStillDeletable(): void
    {
        $item = $this->upload($this->pngFixture(), 'verdwijnt.png', 'Weg');

        @unlink(dirname(__DIR__, 2) . '/' . $item->path);

        MediaService::clearCache();
        $reloaded = MediaService::find($item->id);

        $this->assertNotNull($reloaded, 'the row survives the file');
        $this->assertFalse($reloaded->fileExists());
        $this->assertSame('Weg', $reloaded->altText);
        $this->assertSame('/' . $item->path, $reloaded->publicPath(), 'still answers, does not throw');

        $this->assertTrue($this->service->delete($item->id)['deleted']);
    }

    /**
     * A file the library did not create is never removed by it. Legacy images
     * were adopted where they lay, and something else on this site may still
     * point at them by path.
     */
    public function testDeletingAnAdoptedItemLeavesTheFileWhereItWasFound(): void
    {
        $relative = 'assets/images/__media_test_adopted__.png';
        $absolute = dirname(__DIR__, 2) . '/' . $relative;
        file_put_contents($absolute, $this->pngFixture(20, 20));

        try {
            $id = $this->repository->create([
                'path' => $relative,
                'original_filename' => '__media_test_adopted__.png',
                'mime_type' => 'image/png',
                'width' => 20,
                'height' => 20,
                'file_size' => filesize($absolute),
                'alt_text' => '',
                'checksum' => hash_file('sha256', $absolute),
            ]);
            $this->created[] = $id;
            MediaService::clearCache();

            $result = $this->service->delete($id);

            $this->assertTrue($result['deleted']);
            $this->assertFalse($result['file_removed'], 'the library must not claim it removed a file it did not own');
            $this->assertFileExists($absolute, 'an adopted legacy file stays on disk');
        } finally {
            @unlink($absolute);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function upload(string $bytes, string $name, string $altText): MediaItem
    {
        $result = $this->service->upload([
            'name' => $name,
            'tmp_name' => $this->tempFile($bytes, $name),
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bytes),
        ], $altText);

        $this->created[] = $result['item']->id;

        return $result['item'];
    }

    /**
     * A file on disk for the uploader to take. Tests\Support\TestMediaUploader
     * is what makes PHP accept it; every other rule stays the real one.
     */
    private function tempFile(string $bytes, string $name): string
    {
        $path = sys_get_temp_dir() . '/media-test-' . bin2hex(random_bytes(8)) . '-' . basename($name);
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function pngFixture(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 32, 96, 200));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function gifFixture(): string
    {
        $image = imagecreatetruecolor(24, 24);
        imagefilledrectangle($image, 0, 0, 24, 24, imagecolorallocate($image, 200, 32, 96));

        ob_start();
        imagegif($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
