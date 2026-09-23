<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\MediaRepository;
use App\Service\Media\MediaFilename;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\Media\MediaUploader;
use App\Service\Media\BlockImage;
use App\Service\Media\VideoFormat;
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
        $this->expectExceptionMessage('geen afbeelding');

        (new TestMediaUploader())->store([
            'name' => 'shell.png',
            'type' => 'image/png',
            'tmp_name' => $file,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($file),
        ]);
    }

    /**
     * An SVG under a raster name is judged as a raster image, and an SVG is
     * not one: it never gets past the header check to be stored unsanitized.
     */
    public function testAnSvgUnderARasterNameIsRefused(): void
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

    /**
     * The NAME must promise an image too. An .exe or a .php is refused for
     * what it says it is, even when its bytes are a perfectly good PNG — and
     * nothing is stored for it.
     */
    public function testAFileWhoseNameIsNotAnImageIsRefusedWhateverItContains(): void
    {
        $filesBefore = $this->libraryFiles();

        foreach (['setup.exe', 'shell.php', 'pagina.phtml', 'archief.zip', 'geen-extensie'] as $name) {
            try {
                (new TestMediaUploader())->store($this->uploadedFile($this->pngFixture(), $name));
                $this->fail($name . ' must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Dit type bestand kan niet', $e->getMessage(), $name);
            }
        }

        $this->assertSame($filesBefore, $this->libraryFiles(), 'nothing may be stored for a refused file');
    }

    /**
     * A safe SVG is stored as the sanitizer wrote it back: no resizing, no
     * thumbnail, its size from its own attributes.
     */
    public function testASafeSvgIsStoredSanitizedWithItsSize(): void
    {
        $item = $this->upload(
            '<?xml version="1.0"?><!-- Inkscape --><svg xmlns="http://www.w3.org/2000/svg" width="80" height="20"><metadata>x</metadata><rect width="80" height="20" fill="#123"/></svg>',
            'logo.svg',
            'Logo van de site'
        );

        $this->assertSame('image/svg+xml', $item->mimeType);
        $this->assertSame(MediaType::IMAGE, $item->kind());
        $this->assertMatchesRegularExpression('#^assets/media/[0-9a-f]{32}\.svg$#', $item->path);
        $this->assertNull($item->thumbnailPath);
        $this->assertSame(80, $item->width);
        $this->assertSame(20, $item->height);
        $this->assertSame('SVG', $item->typeLabel());

        $stored = (string) file_get_contents($item->absolutePath());
        $this->assertStringContainsString('<rect', $stored);
        $this->assertStringNotContainsString('Inkscape', $stored, 'the stored file is the sanitized one');
        $this->assertStringNotContainsString('metadata', $stored);
    }

    /**
     * An SVG with anything active in it is refused with the reason, and
     * nothing is left behind: not the file, not a row.
     */
    public function testADangerousSvgIsRefusedAndLeavesNothingBehind(): void
    {
        $filesBefore = $this->libraryFiles();
        $cases = [
            'script.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
            'onload.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
            'extern.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>',
            'xxe.svg' => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
        ];

        foreach ($cases as $name => $svg) {
            try {
                $this->service->upload($this->uploadedFile($svg, $name));
                $this->fail($name . ' must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Deze SVG is niet toegevoegd', $e->getMessage(), $name);
            }
        }

        $this->assertSame($filesBefore, $this->libraryFiles(), 'nothing may be stored for a refused SVG');
    }

    /** A file called .svg that is not one is refused as such. */
    public function testAFileThatOnlyClaimsToBeAnSvgIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('geen SVG');

        (new TestMediaUploader())->store($this->uploadedFile($this->pngFixture(), 'logo.svg'));
    }

    /** MP4 and WebM are stored as sent, and are a video to every reader. */
    public function testAnMp4AndAWebmAreStoredAsVideo(): void
    {
        $mp4 = $this->upload($this->mp4Fixture(), 'clip.mp4', '');
        $webm = $this->upload($this->webmFixture(), 'clip.webm', '');

        $this->assertSame('video/mp4', $mp4->mimeType);
        $this->assertSame('video/webm', $webm->mimeType);
        $this->assertTrue($mp4->isVideo());
        $this->assertFalse($mp4->isPicture());
        $this->assertMatchesRegularExpression('#^assets/media/[0-9a-f]{32}\.mp4$#', $mp4->path);
        $this->assertMatchesRegularExpression('#^assets/media/[0-9a-f]{32}\.webm$#', $webm->path);
        $this->assertNull($mp4->thumbnailPath);
        $this->assertNull($mp4->width, 'a video has no dimensions the library can read');
        $this->assertSame($this->mp4Fixture(), file_get_contents($mp4->absolutePath()), 'a video is stored byte for byte');
        $this->assertSame('MP4', $mp4->typeLabel());
        $this->assertSame('clip.mp4', $mp4->displayName());
    }

    /**
     * The extension alone is never enough: a text file, a QuickTime movie and
     * a HEIC photo called .mp4 are all refused, and nothing is stored.
     */
    public function testAFileThatOnlyClaimsToBeAVideoIsRefused(): void
    {
        $filesBefore = $this->libraryFiles();
        $cases = [
            'nep.mp4' => "<?php system(\$_GET['c']);",
            'film.mp4' => "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00" . str_repeat("\x00", 64),
            'foto.webm' => "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00" . str_repeat("\x00", 64),
        ];

        foreach ($cases as $name => $bytes) {
            try {
                (new TestMediaUploader())->store($this->uploadedFile($bytes, $name));
                $this->fail($name . ' must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('geen MP4- of WEBM-video', $e->getMessage(), $name);
            }
        }

        $this->assertSame($filesBefore, $this->libraryFiles());
    }

    /** A video renamed to .jpg is judged as an image, and it is not one. */
    public function testAVideoUnderAnImageNameIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('geen afbeelding');

        (new TestMediaUploader())->store($this->uploadedFile($this->mp4Fixture(), 'clip.jpg'));
    }

    /** A video has its own cap: the Hero's 30 MB, or less where PHP takes less. */
    public function testAVideoHasItsOwnSizeCap(): void
    {
        $this->assertLessThanOrEqual(MediaUploader::VIDEO_MAX_BYTES, MediaUploader::maxBytes(MediaType::VIDEO));
        $this->assertGreaterThanOrEqual(MediaUploader::maxBytes(MediaType::IMAGE), MediaUploader::maxBytes(MediaType::VIDEO));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('te groot');

        (new TestMediaUploader())->store([
            'name' => 'lang.mp4',
            'tmp_name' => $this->tempFile($this->mp4Fixture(), 'lang.mp4'),
            'error' => UPLOAD_ERR_OK,
            'size' => MediaUploader::maxBytes(MediaType::VIDEO) + 1,
        ]);
    }

    /**
     * An upload for an image field refuses a video, and one for a video field
     * refuses an image — also when that exact file is already in the library
     * and would otherwise simply be handed back.
     */
    public function testAnUploadForOneKindRefusesTheOther(): void
    {
        $video = $this->upload($this->mp4Fixture(), 'bestaand.mp4', '');

        foreach ([[$this->mp4Fixture(), 'bestaand.mp4', MediaType::IMAGE], [$this->pngFixture(), 'foto.png', MediaType::VIDEO]] as [$bytes, $name, $kind]) {
            try {
                $this->service->upload($this->uploadedFile($bytes, $name), '', '', $kind);
                $this->fail($name . ' must be refused for a ' . $kind . ' field');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Hier kan alleen', $e->getMessage());
            }
        }

        $again = $this->service->upload($this->uploadedFile($this->mp4Fixture(), 'bestaand.mp4'), '', '', MediaType::VIDEO);
        $this->assertTrue($again['reused']);
        $this->assertSame($video->id, $again['item']->id);
    }

    /** What an image picker lists holds no video, and a video picker no image. */
    public function testBrowsingOneKindListsOnlyThatKind(): void
    {
        $image = $this->upload($this->pngFixture(12, 12), 'zzz-soortfilter.png', '');
        $svg = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><rect width="4" height="4"/></svg>', 'zzz-soortfilter.svg', '');
        $video = $this->upload($this->webmFixture(), 'zzz-soortfilter.webm', '');

        $ids = fn (string $type): array => array_map(static fn (MediaItem $item): int => $item->id, $this->service->browse('zzz-soortfilter', type: $type)['items']);

        $this->assertEqualsCanonicalizing([$image->id, $svg->id], $ids(MediaType::IMAGE));
        $this->assertSame([$video->id], $ids(MediaType::VIDEO));
        $this->assertCount(3, $ids(''));
    }

    /** An endpoint behind an image field never takes a video id, whatever the request says. */
    public function testAnImageFieldNeverStoresAVideo(): void
    {
        $video = $this->upload($this->mp4Fixture(), 'geen-logo.mp4', '');
        $image = $this->upload($this->pngFixture(), 'wel-logo.png', '');

        $this->assertNull(MediaService::findImage($video->id));
        $this->assertSame($image->id, MediaService::findImage($image->id)?->id);
        $this->assertNull(MediaService::findVideo($image->id));
        $this->assertSame($video->id, MediaService::findVideo($video->id)?->id);
        $this->assertSame(['media_id' => null, 'image_path' => ''], BlockImage::fromRequest((string) $video->id));
        $this->assertSame($image->id, BlockImage::fromRequest((string) $image->id)['media_id']);
    }

    /* ------------------------------------------------------------------ */
    /* Alt text: inherited from the library, or this place's own           */
    /* ------------------------------------------------------------------ */

    /**
     * The editor SHOWS the library's alt text in the field. Sent back as it
     * was, it stays inherited (stored empty); anything else is this place's
     * own. In a translation the same text can be a real choice.
     */
    public function testAnAltTextThatOnlyRepeatsTheLibraryStaysInherited(): void
    {
        $item = $this->upload($this->pngFixture(), 'alt-erfenis.png', 'Een blauw vlak');

        $this->assertSame('', BlockImage::ownAlt('Een blauw vlak', $item->id));
        $this->assertSame('', BlockImage::ownAlt('  Een blauw vlak ', (string) $item->id));
        $this->assertSame('Blauw vlak op de homepage', BlockImage::ownAlt('Blauw vlak op de homepage', $item->id));
        $this->assertSame('', BlockImage::ownAlt('', $item->id));
        $this->assertSame('Een blauw vlak', BlockImage::ownAlt('Een blauw vlak', $item->id, false), 'a translation keeps what it was given');
        $this->assertSame('Een blauw vlak', BlockImage::ownAlt('Een blauw vlak', null), 'without an image there is nothing to inherit from');
    }

    /** The same rule over a posted row list: new rows always, stored rows in the default language. */
    public function testRowAltTextsFollowTheSameRule(): void
    {
        $item = $this->upload($this->pngFixture(), 'alt-rijen.png', 'Bibliotheektekst');

        $rows = [
            '12' => ['media_id' => (string) $item->id, 'alt' => 'Bibliotheektekst'],
            'new1' => ['media_id' => (string) $item->id, 'alt' => 'Bibliotheektekst'],
            '13' => ['media_id' => (string) $item->id, 'alt' => 'Eigen tekst'],
        ];

        $inDefault = BlockImage::ownAltInRows($rows, true);
        $this->assertSame('', $inDefault['12']['alt']);
        $this->assertSame('', $inDefault['new1']['alt']);
        $this->assertSame('Eigen tekst', $inDefault['13']['alt']);

        $inTranslation = BlockImage::ownAltInRows($rows, false);
        $this->assertSame('Bibliotheektekst', $inTranslation['12']['alt'], 'a stored row in a translation keeps its text');
        $this->assertSame('', $inTranslation['new1']['alt'], 'a new row is always written in the default language');

        $this->assertNull(BlockImage::ownAltInRows(null, true));
    }

    /**
     * What a visitor gets: the library's alt text follows a change in the
     * library where the block has none of its own, and a block that wrote its
     * own keeps it.
     */
    public function testALaterLibraryChangeReachesOnlyInheritedUses(): void
    {
        $item = $this->upload($this->pngFixture(), 'alt-later.png', 'Oude tekst');
        $row = ['media_id' => $item->id, 'image_path' => $item->path];

        $this->service->updateAltText($item->id, 'Nieuwe tekst');
        MediaService::clearCache();

        $this->assertSame('Nieuwe tekst', BlockImage::fromOwner($row, '')['alt'], 'inherited: follows the library');
        $this->assertSame('Eigen tekst', BlockImage::fromOwner($row, 'Eigen tekst')['alt'], 'own: stays');
    }

    /**
     * A name that promises an image while the bytes are something else — a
     * program, a PDF, a script. The header decides, whatever the name says.
     */
    public function testAFileThatIsNotReallyAnImageIsRefusedWhateverItIsNamed(): void
    {
        $contents = [
            'programma.jpg' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00",
            'document.png' => "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n",
            'script.gif' => "<?php system(\$_GET['c']);",
        ];

        foreach ($contents as $name => $bytes) {
            try {
                (new TestMediaUploader())->store($this->uploadedFile($bytes, $name));
                $this->fail($name . ' must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('geen afbeelding', $e->getMessage(), $name);
            }
        }
    }

    /** The screen promises what PHP will really accept, never more. */
    public function testTheSizeLimitIsTheStricterOfTheLibraryAndPhp(): void
    {
        $max = MediaUploader::maxBytes();

        $this->assertGreaterThan(0, $max);
        $this->assertLessThanOrEqual(MediaUploader::MAX_BYTES, $max);
        $this->assertMatchesRegularExpression('/^\d+(,\d)? MB$/', MediaUploader::maxSizeLabel());
        $this->assertStringEndsNotWith(',0 MB', MediaUploader::maxSizeLabel(), 'a whole number of megabytes is said without a decimal');
    }

    /* ------------------------------------------------------------------ */
    /* Several files at once                                               */
    /* ------------------------------------------------------------------ */

    public function testSeveralFilesAreAddedAsSeveralItems(): void
    {
        $results = $this->service->uploadMany([
            $this->uploadedFile($this->pngFixture(31, 31), 'zzz-eerste.png'),
            $this->uploadedFile($this->gifFixture(), 'zzz-tweede.gif'),
            $this->uploadedFile($this->jpegFixture(33, 33), 'zzz-derde.jpg'),
        ]);

        $this->remember($results);

        $this->assertCount(3, $results);

        foreach ($results as $result) {
            $this->assertNull($result['error'], (string) $result['error']);
            $this->assertNotNull($result['item']);
        }

        $this->assertSame(
            ['zzz-eerste.png', 'zzz-tweede.gif', 'zzz-derde.jpg'],
            array_map(static fn (array $result): string => $result['item']->displayName, $results)
        );
    }

    /**
     * One refused file does not stop the rest, and it leaves nothing behind:
     * every item is complete on its own, so a batch has no reason to be
     * all-or-nothing.
     */
    public function testAMixedBatchAddsWhatItCanAndSaysWhatItCouldNot(): void
    {
        $filesBefore = $this->libraryFiles();

        $results = $this->service->uploadMany([
            $this->uploadedFile($this->pngFixture(34, 34), 'zzz-goed.png'),
            $this->uploadedFile("MZ\x90\x00\x03\x00", 'virus.exe'),
            $this->uploadedFile('geen afbeelding', 'zzz-nep.png'),
            $this->uploadedFile($this->pngFixture(35, 35), 'zzz-ook-goed.png'),
        ]);

        $this->remember($results);

        $this->assertSame(['zzz-goed.png', 'virus.exe', 'zzz-nep.png', 'zzz-ook-goed.png'], array_column($results, 'name'));

        $this->assertNotNull($results[0]['item']);
        $this->assertNull($results[1]['item']);
        $this->assertStringContainsString('Dit type bestand kan niet', (string) $results[1]['error']);
        $this->assertNull($results[2]['item']);
        $this->assertNotSame('', (string) $results[2]['error']);
        $this->assertNotNull($results[3]['item']);

        $this->assertSame($filesBefore + 2, $this->libraryFiles(), 'two images stored, nothing for the two refusals');
    }

    public function testANameTypedForAnUploadNamesTheItem(): void
    {
        $result = $this->service->upload($this->uploadedFile($this->pngFixture(36, 36), 'IMG_0001.png'), '', 'zzz Vakantie aan zee');
        $this->created[] = $result['item']->id;

        $this->assertSame('zzz Vakantie aan zee.png', $result['item']->displayName);
        $this->assertSame('IMG_0001.png', $result['item']->originalFilename, 'where the file came from stays on record');
    }

    /** Typing the extension anyway does not double it. */
    public function testATypedExtensionIsNotDoubled(): void
    {
        $result = $this->service->upload($this->uploadedFile($this->pngFixture(37, 37), 'scan.png'), '', 'zzz-scan-kopie.png');
        $this->created[] = $result['item']->id;

        $this->assertSame('zzz-scan-kopie.png', $result['item']->displayName);
    }

    public function testATypedNameThatCannotBeAFilenameIsRefusedBeforeAnythingIsStored(): void
    {
        $file = $this->uploadedFile($this->pngFixture(38, 38), 'goed.png');
        $rowsBefore = $this->repository->countAll();
        $filesBefore = $this->libraryFiles();

        try {
            $this->service->upload($file, '', '../../geheim');
            $this->fail('a name with a path in it must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('/', $e->getMessage(), 'the reason names the character to take out');
        }

        $this->assertSame($rowsBefore, $this->repository->countAll());
        $this->assertSame($filesBefore, $this->libraryFiles());
        $this->assertFileExists($file['tmp_name'], 'the upload itself was not even moved');
    }

    /**
     * One file or several, from `name="image"` or `name="files[]"`: the same
     * list, and a slot the browser sent empty is not a file.
     */
    public function testUploadedFilesAreReadTheSameFromOneFieldOrSeveral(): void
    {
        $this->assertSame(
            [['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 10]],
            MediaUploader::filesFrom(['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 10])
        );

        $several = MediaUploader::filesFrom([
            'name' => ['a.png', '', 'b.gif'],
            'type' => ['image/png', '', 'image/gif'],
            'tmp_name' => ['/tmp/a', '', '/tmp/b'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
            'size' => [10, 0, 20],
        ]);

        $this->assertSame(['a.png', 'b.gif'], array_column($several, 'name'));
        $this->assertSame([], MediaUploader::filesFrom(null));
        $this->assertSame([], MediaUploader::filesFrom('nonsense'));
        $this->assertSame([], MediaUploader::filesFrom(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]));
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
    /* Names                                                               */
    /* ------------------------------------------------------------------ */

    public function testAnUploadIsNamedAfterTheFileTheEditorChose(): void
    {
        $item = $this->upload($this->pngFixture(21, 21), 'zzz-zomer-aan-zee.png', '');

        $this->assertSame('zzz-zomer-aan-zee.png', $item->displayName);
        $this->assertSame('zzz-zomer-aan-zee.png', $item->originalFilename);
    }

    /**
     * A PNG saved as .jpg is named after what it really is, so the name never
     * contradicts the type beside it. What the editor uploaded stays on record.
     */
    public function testTheNameTakesTheExtensionOfWhatTheFileReallyIs(): void
    {
        $item = $this->upload($this->pngFixture(22, 22), 'zzz-eigenlijk-een-png.jpg', '');

        $this->assertSame('zzz-eigenlijk-een-png.png', $item->displayName);
        $this->assertSame('zzz-eigenlijk-een-png.jpg', $item->originalFilename);
        $this->assertStringEndsWith('.png', $item->path);
    }

    /** A right extension is kept the way the editor spelt it, in lower case. */
    public function testARightExtensionKeepsItsOwnSpelling(): void
    {
        $item = $this->upload($this->jpegFixture(), 'zzz-portret.JPEG', '');

        $this->assertSame('zzz-portret.jpeg', $item->displayName);
        $this->assertStringEndsWith('.jpg', $item->path, 'stored under the one extension its type has');
    }

    /**
     * Two different files with one name are two items with two names: an
     * upload never fails over a name, and nobody may have chosen it (two
     * cameras both write IMG_0001.jpg). Case does not make a name different.
     */
    public function testASecondFileWithATakenNameGetsANumber(): void
    {
        $first = $this->upload($this->pngFixture(23, 23), 'zzz-dubbele-naam.png', '');
        $second = $this->upload($this->pngFixture(24, 24), 'ZZZ-Dubbele-Naam.png', '');

        $this->assertSame('zzz-dubbele-naam.png', $first->displayName);
        $this->assertSame('ZZZ-Dubbele-Naam-2.png', $second->displayName);
        $this->assertSame('ZZZ-Dubbele-Naam.png', $second->originalFilename);
    }

    public function testAFileNameWithNothingUsableLeftGetsANeutralName(): void
    {
        $item = $this->upload($this->pngFixture(25, 25), '. .png', '');

        $this->assertMatchesRegularExpression('/^afbeelding(-\d+)?\.png$/', $item->displayName);
    }

    public function testSearchMatchesTheNameAnItemWasGiven(): void
    {
        $this->upload($this->pngFixture(26, 26), 'zzz-gezocht.png', '');
        $second = $this->upload($this->pngFixture(27, 27), 'zzz-gezocht.png', '');

        $found = $this->service->browse('zzz-gezocht-2');

        $this->assertSame(1, $found['total'], 'only the second item has this name; no original filename contains it');
        $this->assertSame($second->id, $found['items'][0]->id);
    }

    /**
     * A name is a label and never reaches the filesystem, but it is still a
     * FILENAME to the editor who reads it: no separators, no characters a
     * desktop refuses, no leading or trailing dot, nothing invisible.
     */
    public function testANameThatCannotBeAFilenameIsRefusedWithAReason(): void
    {
        $refused = [
            '',
            '   ',
            '../geheim',
            'map/foto',
            'map\\foto',
            'foto:1',
            'wat?',
            '.verborgen',
            'eindigt.',
            "tab\tje",
            str_repeat('a', MediaFilename::MAX_BASE_LENGTH + 1),
        ];

        foreach ($refused as $name) {
            $this->assertNotNull(MediaFilename::problemWith($name), var_export($name, true) . ' must be refused');
        }

        foreach (['Zomer aan zee', 'logo (donker)', 'versie.2', 'Crème brûlée', str_repeat('a', MediaFilename::MAX_BASE_LENGTH)] as $name) {
            $this->assertNull(MediaFilename::problemWith($name), $name . ' is a good name');
        }
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
    /* Filtering by kind                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * A row of no known kind — an adopted file with an extension the adoption
     * did not recognise has an empty MIME type — is still in the library, so
     * it shows under everything and under no kind.
     */
    public function testTheTypeFilterKeepsOnlyThatKindOfFile(): void
    {
        $image = $this->upload($this->pngFixture(41, 41), 'zzz-filter-beeld.png', '');
        $this->created[] = $this->repository->create([
            'path' => 'assets/images/__zzz_filter_onbekend__.bmp',
            'original_filename' => 'zzz-filter-onbekend.bmp',
            'mime_type' => '',
        ]);
        MediaService::clearCache();

        $images = $this->service->browse('zzz-filter-', type: MediaType::IMAGE);
        $everything = $this->service->browse('zzz-filter-');

        $this->assertSame([$image->id], array_map(static fn (MediaItem $item): int => $item->id, $images['items']));
        $this->assertSame(1, $images['total']);
        $this->assertSame(2, $everything['total'], 'a file of no known kind still shows under everything');
    }

    public function testSearchAndTheTypeFilterNarrowTogether(): void
    {
        $wanted = $this->upload($this->pngFixture(42, 42), 'zzz-combi-zee.png', '');
        $this->upload($this->pngFixture(43, 43), 'zzz-combi-bos.png', '');
        $this->created[] = $this->repository->create([
            'path' => 'assets/images/__zzz_combi_zee__.bmp',
            'original_filename' => 'zzz-combi-zee.bmp',
            'mime_type' => '',
        ]);
        MediaService::clearCache();

        $found = $this->service->browse('zzz-combi-zee', type: MediaType::IMAGE);

        $this->assertSame(1, $found['total'], 'the name matches two rows, the kind only one of them');
        $this->assertSame($wanted->id, $found['items'][0]->id);
    }

    /** A kind the list does not have filters nothing, rather than emptying the library. */
    public function testAnUnknownTypeFiltersNothing(): void
    {
        $item = $this->upload($this->pngFixture(44, 44), 'zzz-onbekende-soort.png', '');

        $found = $this->service->browse('zzz-onbekende-soort', type: 'geen-soort');

        $this->assertSame(1, $found['total']);
        $this->assertSame($item->id, $found['items'][0]->id);
    }

    /**
     * The filter offers what the library accepts and nothing it does not: no
     * video, audio or documents until the uploader takes them (MEDIA.md).
     */
    public function testTheKindsOnOfferAreTheOnesTheUploaderAccepts(): void
    {
        $this->assertSame([MediaType::IMAGE, MediaType::VIDEO], MediaType::all());

        foreach ([...MediaUploader::MIME_FOR_TYPE, MediaUploader::SVG_MIME] as $mimeType) {
            $this->assertSame(MediaType::IMAGE, MediaType::ofMime($mimeType), $mimeType);
        }

        foreach (VideoFormat::MIME as $mimeType) {
            $this->assertSame(MediaType::VIDEO, MediaType::ofMime($mimeType), $mimeType);
        }

        foreach (MediaUploader::extensions() as $extension) {
            $this->assertNotNull(MediaUploader::kindOfName('x.' . $extension), $extension);
            $this->assertStringContainsString('.' . $extension, MediaUploader::acceptAttribute(), $extension);
        }

        $this->assertStringNotContainsString('.mp4', MediaUploader::acceptAttribute(MediaType::IMAGE));
        $this->assertStringNotContainsString('.png', MediaUploader::acceptAttribute(MediaType::VIDEO));
        $this->assertNull(MediaUploader::kindOfName('setup.exe'));

        foreach (['audio', 'document'] as $fictitious) {
            $this->assertFalse(MediaType::isKnown($fictitious), $fictitious . ' must not be offered before it can be uploaded');
        }
    }

    /** A card names the kind of file from what it is, never from its (editable) name. */
    public function testACardNamesTheKindOfFileFromItsType(): void
    {
        $label = static fn (string $mimeType, string $path = 'assets/media/x.bin', string $name = ''): string => MediaItem::fromRow([
            'id' => 1,
            'path' => $path,
            'mime_type' => $mimeType,
            'display_name' => $name,
        ])->typeLabel();

        $this->assertSame('JPG', $label('image/jpeg', name: 'hernoemd.png'));
        $this->assertSame('PNG', $label('image/png'));
        $this->assertSame('WEBP', $label('image/webp'));
        $this->assertSame('SVG', $label('image/svg+xml'));
        $this->assertSame('ICO', $label('image/x-icon'));
        $this->assertSame('BMP', $label('', 'assets/images/oud.bmp'), 'an unknown type falls back on the stored file');
    }

    /* ------------------------------------------------------------------ */
    /* Renaming                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A new name is a new label and nothing else: the stored file, its path,
     * its checksum and the original filename all stay as they were.
     */
    public function testRenamingChangesTheNameAndNothingAboutTheFile(): void
    {
        $item = $this->upload($this->pngFixture(51, 51), 'zzz-voor-de-naam.png', '');

        $result = $this->service->rename($item->id, 'zzz Na de naam');

        $this->assertTrue($result['renamed']);
        $this->assertSame('ok', $result['reason']);

        MediaService::clearCache();
        $renamed = MediaService::find($item->id);

        $this->assertNotNull($renamed);
        $this->assertSame('zzz Na de naam.png', $renamed->displayName);
        $this->assertSame($item->path, $renamed->path, 'no file is renamed on disk');
        $this->assertSame($item->checksum, $renamed->checksum);
        $this->assertSame('zzz-voor-de-naam.png', $renamed->originalFilename);
        $this->assertTrue($renamed->fileExists());

        $this->assertSame(
            [$item->id],
            array_map(static fn (MediaItem $found): int => $found->id, $this->service->browse('zzz Na de naam')['items'])
        );
    }

    /** The extension is the item's own: it can be neither changed nor doubled. */
    public function testRenamingKeepsTheExtension(): void
    {
        $item = $this->upload($this->pngFixture(52, 52), 'zzz-extensie.png', '');

        $this->service->rename($item->id, 'zzz-poging.exe');
        MediaService::clearCache();
        $this->assertSame('zzz-poging.exe.png', MediaService::find($item->id)?->displayName, 'a typed extension stays part of the name');

        $this->service->rename($item->id, 'zzz-netjes.PNG');
        MediaService::clearCache();
        $this->assertSame('zzz-netjes.png', MediaService::find($item->id)?->displayName, 'its own extension typed anyway is not doubled');
    }

    /**
     * A name another item carries is refused rather than numbered: the editor
     * typed it. The item's own name in another case is still its own.
     */
    public function testANameAnotherItemHasIsRefused(): void
    {
        $first = $this->upload($this->pngFixture(53, 53), 'zzz-bezet.png', '');
        $second = $this->upload($this->pngFixture(54, 54), 'zzz-vrij.png', '');

        $result = $this->service->rename($second->id, 'ZZZ-BEZET');

        $this->assertFalse($result['renamed']);
        $this->assertSame('taken', $result['reason']);
        $this->assertStringContainsString('ZZZ-BEZET.png', (string) $result['message']);

        MediaService::clearCache();
        $this->assertSame('zzz-vrij.png', MediaService::find($second->id)?->displayName, 'nothing was stored');

        $own = $this->service->rename($first->id, 'ZZZ-Bezet');
        $this->assertTrue($own['renamed'], 'a change of case to its own name is allowed');
    }

    /**
     * Nothing that could climb out of a folder, hide a file or break a line
     * gets through — even though a name never reaches the disk.
     */
    public function testANameThatCannotBeAFilenameIsRefusedOnRename(): void
    {
        $item = $this->upload($this->pngFixture(55, 55), 'zzz-blijft.png', '');

        foreach (['../../etc/passwd', 'map/bestand', 'map\\bestand', '', '   ', '.htaccess', "regel\neinde"] as $name) {
            $result = $this->service->rename($item->id, $name);

            $this->assertFalse($result['renamed'], var_export($name, true));
            $this->assertSame('invalid', $result['reason'], var_export($name, true));
            $this->assertNotSame('', (string) $result['message']);
        }

        MediaService::clearCache();
        $unchanged = MediaService::find($item->id);

        $this->assertSame('zzz-blijft.png', $unchanged?->displayName);
        $this->assertTrue((bool) $unchanged?->fileExists());
    }

    public function testRenamingSomethingThatIsNotThereIsReported(): void
    {
        $result = $this->service->rename(9_999_999, 'wat dan ook');

        $this->assertFalse($result['renamed']);
        $this->assertSame('not_found', $result['reason']);
    }

    /* ------------------------------------------------------------------ */
    /* Deleting a selection                                                */
    /* ------------------------------------------------------------------ */

    public function testDeletingASelectionRemovesEveryUnusedItemWithItsFiles(): void
    {
        $first = $this->upload($this->pngFixture(61, 61), 'zzz-weg-een.png', '');
        $second = $this->upload($this->pngFixture(62, 62), 'zzz-weg-twee.png', '');
        $files = [
            dirname(__DIR__, 2) . '/' . $first->path,
            dirname(__DIR__, 2) . '/' . $second->path,
            dirname(__DIR__, 2) . '/' . $first->thumbnailPath,
        ];

        $result = $this->service->deleteMany([$first->id, $second->id, $first->id]);

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_map(static fn (MediaItem $item): int => $item->id, $result['deleted']),
            'each item once, however often it was sent'
        );
        $this->assertSame([], $result['in_use']);
        $this->assertSame([], $result['not_found']);

        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }

        MediaService::clearCache();
        $this->assertNull(MediaService::find($first->id));
        $this->assertNull(MediaService::find($second->id));
    }

    public function testASelectionThatNamesNothingDeletesNothing(): void
    {
        $before = $this->repository->countAll();

        $result = $this->service->deleteMany([9_999_998, 9_999_999, 0, -4]);

        $this->assertSame([], $result['deleted']);
        $this->assertSame([9_999_998, 9_999_999], $result['not_found']);
        $this->assertSame($before, $this->repository->countAll());
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
     * One entry of $_FILES for a file on disk (see tempFile()).
     *
     * @return array{name: string, tmp_name: string, error: int, size: int}
     */
    private function uploadedFile(string $bytes, string $name): array
    {
        return [
            'name' => $name,
            'tmp_name' => $this->tempFile($bytes, $name),
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bytes),
        ];
    }

    /**
     * Registers the items a batch created, so tearDown() removes them.
     *
     * @param list<array{item: MediaItem|null}> $results
     */
    private function remember(array $results): void
    {
        foreach ($results as $result) {
            if ($result['item'] !== null) {
                $this->created[] = $result['item']->id;
            }
        }
    }

    /** How many full-size files the library's own folder holds right now. */
    private function libraryFiles(): int
    {
        return count(array_filter(
            (array) glob(dirname(__DIR__, 2) . '/' . MediaUploader::PUBLIC_PREFIX . '*'),
            'is_file'
        ));
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

    /** The first bytes of an MP4 (an ISO-BMFF ftyp box, brand isom) and a little more. */
    private function mp4Fixture(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", 128);
    }

    /** The EBML magic of a WebM, and a little more. */
    private function webmFixture(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01webm" . str_repeat("\x01", 128);
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

    private function jpegFixture(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 96, 160, 32));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
