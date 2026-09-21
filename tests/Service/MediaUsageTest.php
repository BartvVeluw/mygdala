<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageHeroRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\SiteSettingRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\AdminPermissions;
use App\Service\Media\BlockImage;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\Media\VisibleMediaUsages;
use App\Service\PageHeroContent;
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

        \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'draft',
        ], 'Mediagebruik-testpagina');
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
     * A block's words per website language (block_translations) are text,
     * never a media reference: an id, a path or a URL typed into a Tekstblok
     * or a CTA band makes no usage, so it can neither keep a library item
     * from being deleted nor claim it. Usage is read from media_id columns
     * only.
     */
    public function testWordsInABlockNeverCountAsAMediaUsage(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_words__.png');
        $page = (new PageRepository())->findByContentKey(self::TEST_PAGE);
        $this->assertNotNull($page);

        foreach ([
            'rich_text' => ['rich_text_sections', ['body' => '<p>/assets/media/__usage_words__.png #' . $mediaId . '</p>']],
            'cta_band' => ['cta_bands', ['eyebrow' => (string) $mediaId, 'title' => 'assets/media/__usage_words__.png', 'primary_label' => 'media_id=' . $mediaId]],
        ] as $type => [$table, $words]) {
            [$sectionId, $sectionKey] = SectionRegistry::create($type, self::TEST_PAGE);
            $this->createdSections[] = (new PageSectionRepository())->create((int) $page['id'], self::TEST_PAGE, $type, $sectionKey, $sectionId);
            \App\Service\Blocks\BlockLocalization::save($table, $sectionId, 'nl', $words);
            \App\Service\Blocks\BlockLocalization::save($table, $sectionId, 'en', $words);
        }

        MediaService::clearCache();

        $this->assertSame([], $this->service->usagesOf($mediaId));
        $this->assertSame([$mediaId => 0], MediaUsageRegistry::countsFor([$mediaId]));
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

    /**
     * The Paginakop joined the library with a reference and nothing else. Its
     * usage names the header and links to its editor, which takes the page
     * slug rather than a section key.
     */
    public function testAPageHeaderImageIsReportedWithALinkToItsEditor(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_page_hero__.png');
        $this->attachPageHeroImage($mediaId);

        $usages = $this->service->usagesOf($mediaId);

        $this->assertCount(1, $usages);
        $this->assertSame('content_blocks', $usages[0]->source);
        $this->assertStringContainsString('Paginakop', $usages[0]->label);
        $this->assertStringContainsString(self::TEST_PAGE, $usages[0]->label);
        $this->assertSame('/admin/page-hero.php?slug=' . rawurlencode(self::TEST_PAGE), $usages[0]->editUrl);
        $this->assertSame(AdminPermissions::PAGES_MANAGE, $usages[0]->permission);
    }

    public function testAPageHeaderImageStaysUntilTheHeaderLetsGoOfIt(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_page_hero_kept__.png');
        $sectionId = $this->attachPageHeroImage($mediaId);

        $refused = $this->service->delete($mediaId);
        $this->assertFalse($refused['deleted']);
        $this->assertSame('in_use', $refused['reason']);

        // Remove the block, exactly as the page builder does.
        $sections = new PageSectionRepository();
        $row = $sections->findById($sectionId);
        $this->assertNotNull($row);
        SectionRegistry::delete($row, $sections);
        $this->createdSections = array_values(array_diff($this->createdSections, [$sectionId]));

        MediaService::clearCache();

        $this->assertNotNull(MediaService::find($mediaId), 'removing the header leaves the shared item');
        $this->assertTrue($this->service->delete($mediaId)['deleted'], 'and it may go once nothing uses it');
        $this->createdMedia = array_values(array_diff($this->createdMedia, [$mediaId]));
    }

    /* ------------------------------------------------------------------ */
    /* Renaming and deleting a selection, with real usage                  */
    /* ------------------------------------------------------------------ */

    /**
     * A block points at the item's id, and its old path twin holds the stored
     * file's path. Neither follows the name, so after a rename the usage, the
     * picture the page shows and the twin are exactly what they were.
     */
    public function testRenamingAnItemInUseKeepsEveryUsageWorking(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_renamed__.png');
        $this->attachTextImageSplitImage($mediaId);

        $before = MediaService::find($mediaId);
        $this->assertNotNull($before);

        $result = $this->service->rename($mediaId, 'Nieuwe naam voor een gebruikt beeld');
        $this->assertTrue($result['renamed']);

        MediaService::clearCache();

        $this->assertSame('Nieuwe naam voor een gebruikt beeld.png', MediaService::find($mediaId)?->displayName);
        $this->assertCount(1, $this->service->usagesOf($mediaId), 'the block still uses it');
        $this->assertSame(
            '/' . $before->path,
            BlockImage::fromOwner(['media_id' => $mediaId, 'image_path' => ''], null)['image_path'],
            'the page still shows the same file'
        );

        $twin = Database::connection()->prepare('SELECT image_path FROM text_image_split_images WHERE media_id = ?');
        $twin->execute([$mediaId]);

        $this->assertSame($before->path, (string) $twin->fetchColumn(), 'the stored path twin is untouched');
    }

    /**
     * The rule for one item, asked for a selection: the unused one goes, the
     * used one stays with the places that use it, and an id that names
     * nothing is reported rather than pretended.
     */
    public function testDeletingASelectionKeepsWhatIsStillUsed(): void
    {
        $used = $this->createMediaRow('assets/media/__usage_bulk_used__.png');
        $unused = $this->createMediaRow('assets/media/__usage_bulk_unused__.png');
        $this->attachTextImageSplitImage($used);

        $result = $this->service->deleteMany([$used, $unused, 9_999_999]);

        $this->assertSame([$unused], array_map(static fn ($item): int => $item->id, $result['deleted']));
        $this->assertCount(1, $result['in_use']);
        $this->assertSame($used, $result['in_use'][0]['item']->id);
        $this->assertNotSame([], $result['in_use'][0]['usages'], 'the refusal says where it is used');
        $this->assertSame([9_999_999], $result['not_found']);

        MediaService::clearCache();

        $this->assertNotNull(MediaService::find($used), 'the used item is still in the library');
        $this->assertNull(MediaService::find($unused));

        $this->createdMedia = array_values(array_diff($this->createdMedia, [$unused]));
    }

    /* ------------------------------------------------------------------ */
    /* Who may read where an item is used                                  */
    /* ------------------------------------------------------------------ */

    /**
     * An administrator who may edit pages reads which page uses the item and
     * can follow the link there.
     */
    public function testAManagerWhoMayEditPagesIsToldWhichPageUsesAnItem(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_pages_reader__.png');
        $this->attachTextImageSplitImage($mediaId);

        $usages = $this->service->usagesOf($mediaId);
        $this->assertSame(AdminPermissions::PAGES_MANAGE, $usages[0]->permission, 'a block is read about with the right to edit pages');

        $told = VisibleMediaUsages::of($usages, self::reader([AdminPermissions::MEDIA_MANAGE, AdminPermissions::PAGES_MANAGE]));

        $this->assertCount(1, $told->shown);
        $this->assertSame(0, $told->hidden);
        $this->assertStringContainsString(self::TEST_PAGE, $told->shown[0]->label);
        $this->assertStringContainsString(self::TEST_PAGE, (string) $told->shown[0]->editUrl);
        $this->assertStringContainsString(self::TEST_PAGE, $told->keptSentence('foto.png'));
    }

    /**
     * The same item, for somebody who may manage the library but not the
     * pages: still found, still used, still not deletable — and nothing about
     * the page.
     */
    public function testAManagerWhoMayNotEditPagesIsToldOnlyThatAnItemIsUsed(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_media_reader__.png');
        $this->attachTextImageSplitImage($mediaId);

        $usages = $this->service->usagesOf($mediaId);
        $this->assertCount(1, $usages, 'finding the usage is not filtered');

        $told = VisibleMediaUsages::of($usages, self::reader([AdminPermissions::MEDIA_MANAGE]));

        $this->assertSame([], $told->shown);
        $this->assertSame(1, $told->hidden);
        $this->assertSame(1, $told->count(), 'the reader is still told it is used');

        $sentence = $told->keptSentence('foto.png');
        $this->assertStringContainsString('foto.png', $sentence);
        $this->assertStringContainsString($told->hiddenPlaces(), $sentence);
        $this->assertStringNotContainsString(self::TEST_PAGE, $sentence);
        $this->assertStringNotContainsString('Tekst + afbeelding', $sentence);

        $result = $this->service->delete($mediaId);
        $this->assertFalse($result['deleted'], 'what a reader may see never loosens the guard');
        $this->assertSame('in_use', $result['reason']);

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($mediaId));
    }

    /**
     * A second domain by the same rule: the site's logo is named to whoever
     * may change the site settings and to nobody else — the right to edit
     * pages does not open the settings.
     */
    public function testTheLogoIsNamedOnlyToWhoeverMayChangeTheSiteSettings(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_branding_reader__.png');
        $this->useAsBrandingLogo($mediaId);

        $usages = $this->service->usagesOf($mediaId);
        $this->assertSame(AdminPermissions::SETTINGS_MANAGE, $usages[0]->permission);

        foreach ([[AdminPermissions::MEDIA_MANAGE], [AdminPermissions::MEDIA_MANAGE, AdminPermissions::PAGES_MANAGE]] as $granted) {
            $told = VisibleMediaUsages::of($usages, self::reader($granted));

            $this->assertSame([], $told->shown, implode(', ', $granted));
            $this->assertSame(1, $told->hidden, implode(', ', $granted));
            $this->assertStringNotContainsString('Logo', $told->keptSentence('foto.png'), implode(', ', $granted));
        }

        $settings = VisibleMediaUsages::of($usages, self::reader([AdminPermissions::MEDIA_MANAGE, AdminPermissions::SETTINGS_MANAGE]));

        $this->assertSame(['Logo'], array_map(static fn ($usage): string => $usage->label, $settings->shown));
        $this->assertSame('/admin/settings.php', $settings->shown[0]->editUrl);
        $this->assertSame(0, $settings->hidden);
    }

    /**
     * Deleting a selection keeps every used item whoever asks, and the reason
     * it gives for each one names only the places the reader may open.
     */
    public function testDeletingASelectionNamesOnlyThePlacesTheReaderMayOpen(): void
    {
        $onPage = $this->createMediaRow('assets/media/__usage_bulk_page__.png');
        $asBranding = $this->createMediaRow('assets/media/__usage_bulk_branding__.png');
        $unused = $this->createMediaRow('assets/media/__usage_bulk_free__.png');
        $this->attachTextImageSplitImage($onPage);
        $this->useAsBrandingLogo($asBranding);

        $result = $this->service->deleteMany([$onPage, $asBranding, $unused]);
        $this->createdMedia = array_values(array_diff($this->createdMedia, [$unused]));

        $this->assertSame([$unused], array_map(static fn ($item): int => $item->id, $result['deleted']));

        $kept = [];
        foreach ($result['in_use'] as $entry) {
            $this->assertCount(1, $entry['usages'], 'the guard saw the usage, whoever will read about it');
            $kept[$entry['item']->id] = $entry;
        }
        $this->assertEqualsCanonicalizing([$onPage, $asBranding], array_keys($kept));

        $reasons = static fn (\Closure $reader): array => array_map(
            static fn (array $entry): string => VisibleMediaUsages::of($entry['usages'], $reader)->keptSentence($entry['item']->displayName()),
            $kept
        );

        $libraryOnly = $reasons(self::reader([AdminPermissions::MEDIA_MANAGE]));
        $this->assertStringNotContainsString(self::TEST_PAGE, $libraryOnly[$onPage]);
        $this->assertStringNotContainsString('Tekst + afbeelding', $libraryOnly[$onPage]);
        $this->assertStringNotContainsString('Logo', $libraryOnly[$asBranding]);

        $withSettings = $reasons(self::reader([AdminPermissions::MEDIA_MANAGE, AdminPermissions::SETTINGS_MANAGE]));
        $this->assertStringNotContainsString(self::TEST_PAGE, $withSettings[$onPage]);
        $this->assertStringContainsString('Logo', $withSettings[$asBranding]);

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($onPage));
        $this->assertNotNull(MediaService::find($asBranding));
        $this->assertNull(MediaService::find($unused));
    }

    /**
     * A fully authorised administrator keeps the whole answer: every place, by
     * name, with its link — a Super Admin, and an account holding every grant.
     */
    public function testAFullyAuthorisedAdministratorIsToldEveryPlaceWithItsLink(): void
    {
        $mediaId = $this->createMediaRow('assets/media/__usage_everything__.png');
        $this->attachTextImageSplitImage($mediaId);
        $this->useAsBrandingLogo($mediaId);

        $usages = $this->service->usagesOf($mediaId);
        $this->assertCount(2, $usages);

        foreach (['Super Admin' => self::reader([], true), 'every grant' => self::reader(AdminPermissions::enabled())] as $who => $reader) {
            $told = VisibleMediaUsages::of($usages, $reader);

            $this->assertSame($usages, $told->shown, $who);
            $this->assertSame(0, $told->hidden, $who);
            $this->assertSame('', $told->hiddenPlaces(), $who);

            $sentence = $told->keptSentence('foto.png');
            $this->assertStringContainsString(self::TEST_PAGE, $sentence, $who);
            $this->assertStringContainsString('Logo', $sentence, $who);
        }

        foreach ($usages as $usage) {
            $this->assertNotNull($usage->editUrl, $usage->label);
        }
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
     * The question AdminAuth::can() asks, for an account that is not signed
     * in: the real decision, App\Service\AdminPermissions::userHas(), over the
     * grants given, with the implied ones folded in as a stored account has
     * them.
     *
     * @param list<string> $granted
     */
    private static function reader(array $granted, bool $superAdmin = false): \Closure
    {
        $account = ['is_super_admin' => $superAdmin, 'permissions' => AdminPermissions::expand($granted)];

        return static fn (string $permission): bool => AdminPermissions::userHas($account, $permission);
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
        ]);

        return $pageSectionId;
    }

    /**
     * A Paginakop pointing at this item, made the way the page builder and
     * its editor make one: a real block instance on a real page, then the
     * header's own repository with the id the endpoint resolved. Returns the
     * page_sections id so a test can remove it again.
     */
    private function attachPageHeroImage(int $mediaId): int
    {
        $page = (new PageRepository())->findByContentKey(self::TEST_PAGE);
        $this->assertNotNull($page);

        [$sectionId, $sectionKey] = SectionRegistry::create('page_hero', self::TEST_PAGE);

        $pageSectionId = (new PageSectionRepository())->create(
            (int) $page['id'],
            self::TEST_PAGE,
            'page_hero',
            $sectionKey,
            $sectionId
        );
        $this->createdSections[] = $pageSectionId;

        (new PageHeroRepository())->upsert(self::TEST_PAGE, array_merge(
            PageHeroContent::startingValues(),
            ['media_id' => $mediaId, 'is_active' => true]
        ));

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
