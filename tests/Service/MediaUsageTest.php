<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\SiteSettingRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * "Where is this image used?", and the deletion rule that hangs off the
 * answer.
 *
 * The whole design stands or falls here: usage is DERIVED from the columns
 * that hold the references, never from a second bookkeeping table that a
 * write path could forget to update. So these tests make a real reference the
 * way the CMS makes one — a real page, a real block instance, the real
 * repository — and then ask the registry.
 *
 * The page is a throwaway with an underscore-shaped content_key, so it can
 * never collide with an editor's page or be reachable on the public site, and
 * tearDown() removes it together with every block and media row the test
 * created. Nothing here touches the site's own content.
 */
final class MediaUsageTest extends TestCase
{
    private const TEST_PAGE = '__test_media_usage__';

    /** @var list<int> */
    private array $createdMedia = [];

    /** @var list<int> */
    private array $createdSections = [];

    /** @var list<string> previous logo_media_id values, restored in tearDown */
    private array $restoreLogo = [];

    private MediaRepository $media;
    private MediaService $service;

    protected function setUp(): void
    {
        $this->media = new MediaRepository();
        $this->service = new MediaService($this->media);

        MediaService::clearCache();
        SiteSettings::clearCache();

        $this->removeTestPage();

        (new PageRepository())->create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'title' => 'Mediagebruik-testpagina',
            'status' => 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $sections = new PageSectionRepository();

        foreach ($this->createdSections as $id) {
            $row = $sections->findById($id);
            if ($row !== null) {
                SectionRegistry::delete($row, $sections);
            }
        }

        foreach ($this->createdMedia as $id) {
            $this->media->delete($id);
        }

        $this->removeTestPage();

        $this->createdSections = [];
        $this->createdMedia = [];

        MediaService::clearCache();
        SiteSettings::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Usage is derived from the reference itself                          */
    /* ------------------------------------------------------------------ */

    public function testAnItemNothingPointsAtReportsNoUsage(): void
    {
        $id = $this->createMediaRow('assets/media/__usage_unused__.png');

        $this->assertSame([], $this->service->usagesOf($id));
        $this->assertSame([$id => 0], MediaUsageRegistry::countsFor([$id]));
    }

    public function testABlockThatReferencesAnItemIsReportedAsAUsage(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_block__.png');
        $this->attachTextImageSplitImage($mediaId);

        $usages = $this->service->usagesOf($mediaId);

        $this->assertCount(1, $usages);
        $this->assertSame('content_blocks', $usages[0]->source);
        $this->assertStringContainsString('Tekst + afbeelding', $usages[0]->label);
        $this->assertStringContainsString(self::TEST_PAGE, (string) $usages[0]->editUrl);
    }

    /**
     * The point of a shared library: one file, one row, several users. The
     * count is what the admin grid shows and what deletion is decided on.
     */
    public function testOneItemUsedInTwoPlacesIsCountedTwice(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_twice__.png');

        $this->attachTextImageSplitImage($mediaId);
        $this->useAsBrandingLogo($mediaId);

        $usages = $this->service->usagesOf($mediaId);
        $sources = array_map(static fn ($usage): string => $usage->source, $usages);

        $this->assertCount(2, $usages);
        $this->assertContains('content_blocks', $sources);
        $this->assertContains('branding', $sources);
        $this->assertSame([$mediaId => 2], MediaUsageRegistry::countsFor([$mediaId]));
    }

    public function testTheBrandingUsageNamesWhichAssetItIs(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_logo__.png');
        $this->useAsBrandingLogo($mediaId);

        $usages = $this->service->usagesOf($mediaId);

        $this->assertCount(1, $usages);
        $this->assertSame('Logo', $usages[0]->label);
        $this->assertSame('/admin/settings.php', $usages[0]->editUrl);
    }

    /**
     * A whole page of the grid is one bounded set of queries, not one per
     * thumbnail. This asserts the ANSWER for many ids at once, which is the
     * contract that keeps it that way.
     */
    public function testUsageIsAnsweredForAWholeBatchAtOnce(): void
    {
        $used = $this->createMediaRow('assets/media/__usage_batch_a__.png');
        $unused = $this->createMediaRow('assets/media/__usage_batch_b__.png');
        $this->attachTextImageSplitImage($used);

        $counts = MediaUsageRegistry::countsFor([$used, $unused]);

        $this->assertSame(1, $counts[$used]);
        $this->assertSame(0, $counts[$unused], 'every requested id must be present, not only the used ones');
    }

    /* ------------------------------------------------------------------ */
    /* Deletion follows from usage                                         */
    /* ------------------------------------------------------------------ */

    public function testAnItemThatIsStillUsedIsNotDeleted(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_protected__.png');
        $this->attachTextImageSplitImage($mediaId);

        $result = $this->service->delete($mediaId);

        $this->assertFalse($result['deleted']);
        $this->assertSame('in_use', $result['reason']);
        $this->assertNotSame([], $result['usages'], 'the refusal must say where it is used');

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($mediaId), 'the row survives a refused delete');
    }

    public function testAnItemBecomesDeletableOnceTheLastReferenceIsGone(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_released__.png');
        $sectionId = $this->attachTextImageSplitImage($mediaId);

        $this->assertFalse($this->service->delete($mediaId)['deleted']);

        // Remove the block, exactly as the page builder does.
        $sections = new PageSectionRepository();
        $row = $sections->findById($sectionId);
        $this->assertNotNull($row);
        SectionRegistry::delete($row, $sections);
        $this->createdSections = array_values(array_diff($this->createdSections, [$sectionId]));

        MediaService::clearCache();

        $this->assertTrue($this->service->delete($mediaId)['deleted']);
        $this->createdMedia = array_values(array_diff($this->createdMedia, [$mediaId]));
    }

    /**
     * Deleting a block never takes the shared file with it — the same photo
     * may be on three other pages. This is the behaviour that changed when
     * the library arrived, so it is asserted rather than assumed.
     */
    public function testRemovingABlockLeavesTheMediaItemItself(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_survives__.png');
        $sectionId = $this->attachTextImageSplitImage($mediaId);

        $sections = new PageSectionRepository();
        $row = $sections->findById($sectionId);
        $this->assertNotNull($row);
        SectionRegistry::delete($row, $sections);
        $this->createdSections = array_values(array_diff($this->createdSections, [$sectionId]));

        MediaService::clearCache();

        $this->assertNotNull(MediaService::find($mediaId), 'the media item outlives the block that used it');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** A media row for a file that does not exist — usage never reads the disk. */
    private function createMediaRow(string $path): int
    {
        $id = $this->media->create([
            'path' => $path,
            'original_filename' => basename($path),
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => '',
            'checksum' => null,
        ]);

        $this->createdMedia[] = $id;
        MediaService::clearCache();

        return $id;
    }

    /**
     * Makes a real reference the way the page builder does: a real block
     * instance on a real page, then the block's own repository. Returns the
     * page_sections id so a test can remove it again.
     */
    private function attachTextImageSplitImage(int $mediaId): int
    {
        $page = (new PageRepository())->findByContentKey(self::TEST_PAGE);
        $this->assertNotNull($page);

        [$sectionId, $sectionKey] = SectionRegistry::create('text_image_split', self::TEST_PAGE);

        $pageSectionId = (new PageSectionRepository())->create(
            (int) $page['id'],
            self::TEST_PAGE,
            'text_image_split',
            $sectionKey,
            $sectionId
        );
        $this->createdSections[] = $pageSectionId;

        $repository = new TextImageSplitRepository();
        $repository->createImage($sectionId, [
            'media_id' => $mediaId,
            'image_path' => (string) MediaService::find($mediaId)?->path,
            'alt_nl' => '',
            'alt_en' => '',
        ]);

        return $pageSectionId;
    }

    /**
     * Points the site's logo setting at this item. Restored in tearDown by
     * putting the previous value back — the development site's own branding
     * is never left changed.
     */
    private function useAsBrandingLogo(int $mediaId): void
    {
        $previous = SiteSettings::get('logo_media_id');

        (new SiteSettingRepository())->upsertMany(['logo_media_id' => (string) $mediaId]);
        SiteSettings::clearCache();

        $this->restoreLogo[] = $previous;
    }

    private function removeTestPage(): void
    {
        // Whatever the site's logo setting was before this test, it is that
        // again — the development site's branding is never left changed.
        foreach ($this->restoreLogo as $previous) {
            (new SiteSettingRepository())->upsertMany(['logo_media_id' => $previous]);
        }
        $this->restoreLogo = [];
        SiteSettings::clearCache();

        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::TEST_PAGE);

        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }

        $db = Database::connection();
        $db->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $page['id']]);
    }
}
