<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Service\Media\MediaFolderService;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use PHPUnit\Framework\TestCase;

/**
 * The virtual folders of the Media Library (MEDIA.md, "Mappen"), against the
 * test database: making, renaming and deleting a folder, filing items under
 * one, and browsing by folder — with and without a search.
 *
 * What must hold: a folder is a label and nothing else. Moving an item
 * changes media.folder_id only (never its path, file or name); deleting a
 * folder never deletes an item; a folder that does not exist is refused, never
 * created and never read as "Geen map"; and a folder name is checked, not
 * trusted.
 *
 * The rows are this test's own (paths under assets/media/__folder_test_…,
 * folder names starting "ZZ Map"), created without files, and tearDown()
 * removes them by exact id.
 */
final class MediaFolderTest extends TestCase
{
    private MediaFolderService $folders;
    private MediaService $media;

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $folderIds = [];

    protected function setUp(): void
    {
        $this->folders = new MediaFolderService();
        $this->media = new MediaService();
        MediaService::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $repository = new MediaRepository();

        foreach ($this->mediaIds as $id) {
            $repository->delete($id);
        }

        foreach ($this->folderIds as $id) {
            $db->prepare('DELETE FROM media_folders WHERE id = :id')->execute(['id' => $id]);
        }

        MediaService::clearCache();
    }

    public function testAFolderIsMadeRenamedAndListedWithItsCount(): void
    {
        $folder = $this->folder('ZZ Map Kerst');
        $this->moveItems([$this->item('kerst-1'), $this->item('kerst-2')], (string) $folder);

        $renamed = $this->folders->rename($folder, '  ZZ Map   Kerst 2026 ');
        $this->assertTrue($renamed['ok']);
        $this->assertSame('ZZ Map Kerst 2026', $this->folders->find($folder)['name'] ?? null, 'white space is tidied, the name is kept');

        $listed = array_values(array_filter(
            $this->folders->overview()['folders'],
            static fn (array $row): bool => $row['id'] === $folder
        ));
        $this->assertCount(1, $listed);
        $this->assertSame(2, $listed[0]['item_count']);
    }

    public function testAFolderNameIsCheckedNotTrusted(): void
    {
        $this->folder('ZZ Map Bezet');

        foreach (['', '   ', "ZZ Map\x00nul", str_repeat('a', MediaFolderService::NAME_MAX_LENGTH + 1), 'zz map bezet'] as $name) {
            $result = $this->folders->create($name);
            $this->assertFalse($result['ok'], var_export($name, true));
            $this->assertNotSame('', (string) $result['error']);
        }

        // A folder name is text, never a path: these are ordinary names.
        foreach (['ZZ Map ../../etc', 'ZZ Map <script>alert(1)</script>', 'ZZ Map a/b\\c'] as $name) {
            $result = $this->folders->create($name);
            $this->assertTrue($result['ok'], $name);
            $this->folderIds[] = (int) $result['id'];
            $this->assertSame($name, $this->folders->find((int) $result['id'])['name'] ?? null, 'stored as typed; escaping is the printer\'s job');
        }
    }

    public function testMovingChangesTheFolderAndNothingAboutTheFile(): void
    {
        $folder = $this->folder('ZZ Map Verplaats');
        $id = $this->item('verplaats');
        $before = MediaService::find($id);

        $result = $this->folders->move([(string) $id], (string) $folder);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['moved']);

        MediaService::clearCache();
        $after = MediaService::find($id);
        $this->assertSame($folder, $after?->folderId);
        $this->assertSame($before?->path, $after?->path);
        $this->assertSame($before?->displayName, $after?->displayName);
        $this->assertSame($before?->altText, $after?->altText);

        $back = $this->folders->move([$id], MediaFolderService::NONE);
        $this->assertTrue($back['ok']);
        MediaService::clearCache();
        $this->assertNull(MediaService::find($id)?->folderId, 'back to Geen map');
    }

    public function testAFolderThatDoesNotExistIsRefusedAndChangesNothing(): void
    {
        $folder = $this->folder('ZZ Map Echt');
        $id = $this->item('onbekende-map');
        $this->moveItems([$id], (string) $folder);

        foreach (['999999999', '-1', '0', 'abc', '1 OR 1=1', ''] as $target) {
            $result = $this->folders->move([$id], $target);
            $this->assertFalse($result['ok'], var_export($target, true));
        }

        MediaService::clearCache();
        $this->assertSame($folder, MediaService::find($id)?->folderId, 'a refused move leaves the item where it was');

        $this->assertNull($this->folders->existing('999999999'));
        $this->assertSame('', $this->folders->filter('999999999'), 'an unknown folder in an address filters nothing');
        $this->assertSame('', $this->folders->filter('<script>'));
        $this->assertSame(MediaFolderService::NONE, $this->folders->filter('none'));
        $this->assertSame((string) $folder, $this->folders->filter((string) $folder));
    }

    public function testASelectionIsCappedAndIdsAreCheckedOneByOne(): void
    {
        $folder = $this->folder('ZZ Map Selectie');
        $id = $this->item('selectie');

        $tooMany = $this->folders->move(range(1, MediaFolderService::MAX_MOVE_AT_ONCE + 1), (string) $folder);
        $this->assertFalse($tooMany['ok']);

        $nothing = $this->folders->move(['abc', '-3', 0], (string) $folder);
        $this->assertFalse($nothing['ok']);

        $mixed = $this->folders->move([(string) $id, 'abc', '999999999'], (string) $folder);
        $this->assertTrue($mixed['ok']);
        $this->assertSame(1, $mixed['moved'], 'only a real item moves');
    }

    public function testDeletingAFolderKeepsEveryItemInIt(): void
    {
        $folder = $this->folder('ZZ Map Weg');
        $ids = [$this->item('blijft-1'), $this->item('blijft-2')];
        $this->moveItems($ids, (string) $folder);

        $result = $this->folders->delete($folder);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['released']);
        $this->assertNull($this->folders->find($folder));

        MediaService::clearCache();
        foreach ($ids as $id) {
            $item = MediaService::find($id);
            $this->assertNotNull($item, 'the item is not deleted with its folder');
            $this->assertNull($item->folderId, 'it is in Geen map now');
        }

        $this->assertFalse($this->folders->delete($folder)['ok'], 'a folder that is gone cannot be deleted again');
        $this->assertFalse($this->folders->rename($folder, 'ZZ Map Terug')['ok']);
    }

    public function testBrowsingByFolderCombinesWithASearch(): void
    {
        $folder = $this->folder('ZZ Map Zoek');
        $inFolder = $this->item('zzfoldersearch-binnen');
        $outside = $this->item('zzfoldersearch-buiten');
        $this->moveItems([$inFolder], (string) $folder);

        $ids = static fn (array $result): array => array_map(static fn (MediaItem $item): int => $item->id, $result['items']);

        $this->assertSame([$inFolder], $ids($this->media->browse('zzfoldersearch', folder: (string) $folder)));
        $this->assertSame([$outside], $ids($this->media->browse('zzfoldersearch', folder: MediaFolderService::NONE)));
        $this->assertEqualsCanonicalizing([$inFolder, $outside], $ids($this->media->browse('zzfoldersearch')));
        $this->assertEqualsCanonicalizing([$inFolder, $outside], $ids($this->media->browse('zzfoldersearch', folder: 'nonsense')), 'an unknown folder filters nothing');
        $this->assertSame(1, $this->media->browse('zzfoldersearch', folder: (string) $folder)['total']);
    }

    /* ------------------------------------------------------------------ */

    private function folder(string $name): int
    {
        $result = $this->folders->create($name);
        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->folderIds[] = (int) $result['id'];

        return (int) $result['id'];
    }

    private function item(string $name): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__folder_test_' . $name . '_' . bin2hex(random_bytes(4)) . '__.png',
            'original_filename' => $name . '.png',
            'display_name' => $name . '-' . bin2hex(random_bytes(3)) . '.png',
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => 'Tekst van ' . $name,
            'checksum' => null,
        ]);
        $this->mediaIds[] = $id;

        return $id;
    }

    /** @param list<int> $ids */
    private function moveItems(array $ids, string $target): void
    {
        $this->assertTrue($this->folders->move($ids, $target)['ok']);
        MediaService::clearCache();
    }
}
