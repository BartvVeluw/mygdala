<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\Media\MediaService;
use App\Service\PageLocalization;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioProjectLayout;
use App\Service\ProjectImagesPlacement;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Portfolio 3.0, over real HTTP (Tests\Support\BuiltInServer): a project page
 * is its fixed head followed by its page content, and the project's extra
 * photos are one block of that content, Projectafbeeldingen
 * (App\Service\Blocks\ProjectImagesBlock).
 *
 *   - the photos render through the block and nowhere else, in the project's
 *     own order, in one lightbox group with the main picture;
 *   - the block moves between and below other blocks through the ordinary
 *     reorder endpoint, and hides and shows through the ordinary toggle;
 *   - hiding it keeps every photo and the Media Library's usage, which points
 *     at the project, never at the block;
 *   - a photo added to or taken off the project follows on the page at once;
 *   - no photos, no empty section;
 *   - the project layout changes the head and never the photos;
 *   - one block per project: made with the project, never offered, never
 *     deleted, a second refused by the database; only on a project;
 *   - CSRF, permission and a forged section of another project are refused;
 *   - deleting the project takes its block, content page and photo rows,
 *     and leaves the library pictures.
 */
final class ProjectImagesBlockHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true']);
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

        \App\Module\ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        $this->accounts = new AdminTestSession();
    }

    protected function tearDown(): void
    {
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            if ($gallery->findItemById($id) !== null) {
                ContentPages::deleteFor(PortfolioContentOwner::KIND, $id);
                $gallery->deleteItem($id);
            }
        }

        $media = new MediaRepository();
        foreach ($this->mediaIds as $id) {
            $media->delete($id);
        }
        MediaService::clearCache();

        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
    }

    public function testThePhotosRenderOnlyThroughTheirBlockInTheProjectsOrder(): void
    {
        $id = $this->project('ZZ PI Foto\'s', 3);

        $body = $this->page($id);

        $this->assertSame(1, substr_count($body, 'class="project-gallery"'), 'the photos once');
        $this->assertSame(['zz-pi-1', 'zz-pi-2', 'zz-pi-3'], $this->photoOrder($body));
        $head = strpos($body, 'class="project-hero"');
        $photos = strpos($body, '<section class="project-images" data-lightbox-group="project-' . $id . '">');
        $this->assertIsInt($head);
        $this->assertIsInt($photos, 'the block\'s own section');
        $this->assertLessThan($photos, $head, 'directly under the head, where they always stood');
        $this->assertStringNotContainsString('class="project-gallery"', substr($body, $head, $photos - $head), 'the head prints no photos of its own');
        $this->assertSame(2, substr_count($body, 'data-lightbox-group="project-' . $id . '"'), 'the head and the photos: one named lightbox group');

        // Without its block (a row removed behind the CMS's back) there is no
        // second renderer that would print the photos anyway.
        Database::connection()->prepare("DELETE FROM page_sections WHERE section_type = 'project_images' AND section_id = :id")->execute(['id' => $id]);
        $this->assertStringNotContainsString('class="project-gallery"', $this->page($id));
        $this->assertCount(3, $this->photoRows($id), 'the photos themselves are the project\'s');
    }

    public function testTheBlockMovesBetweenAndBelowOtherBlocks(): void
    {
        $id = $this->project('ZZ PI Volgorde', 2);
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $id);
        $first = $this->block($page, '<p>ZZ PI eerste blok</p>');
        $second = $this->block($page, '<p>ZZ PI tweede blok</p>');
        $photos = $this->photosBlock($id);
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);

        // Default: photos, first, second.
        $this->assertOrder($this->page($id), ['zz-pi-1', 'ZZ PI eerste blok', 'ZZ PI tweede blok']);

        // Below everything.
        $this->reorder($session, $csrf, (int) $page['id'], [$first, $second, $photos]);
        $this->assertOrder($this->page($id), ['ZZ PI eerste blok', 'ZZ PI tweede blok', 'zz-pi-1']);

        // Between the two.
        $this->reorder($session, $csrf, (int) $page['id'], [$first, $photos, $second]);
        $body = $this->page($id);
        $this->assertOrder($body, ['project-hero__title', 'ZZ PI eerste blok', 'zz-pi-1', 'ZZ PI tweede blok']);
        $this->assertSame(['zz-pi-1', 'zz-pi-2'], $this->photoOrder($body), 'their own order goes along');
        $this->assertStringContainsString('data-reveal-group="project_images-' . $photos . '"', $body);
    }

    public function testHidingTheBlockHidesThePhotosAndKeepsThem(): void
    {
        $id = $this->project('ZZ PI Verbergen', 2, true);
        $photos = $this->photosBlock($id);
        $mediaId = $this->mediaIds[0];
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);
        $usageBefore = $this->usageLabels($mediaId);
        $this->assertNotSame([], $usageBefore, 'a photo is in use by its project');

        $this->assertSame(302, $this->toggle($session, $csrf, $photos, false)['status']);
        $body = $this->page($id);
        $this->assertStringNotContainsString('class="project-gallery"', $body);
        $this->assertStringNotContainsString('project-images', $body, 'no empty section either');
        $this->assertStringContainsString('project-hero__title', $body, 'the head stays');
        $this->assertCount(2, $this->photoRows($id), 'every photo kept');
        $this->assertSame($usageBefore, $this->usageLabels($mediaId), 'the usage is the project\'s, hidden or not');

        $this->assertSame(302, $this->toggle($session, $csrf, $photos, true)['status']);
        $this->assertSame(['zz-pi-1', 'zz-pi-2'], $this->photoOrder($this->page($id)));
    }

    public function testAProjectWithoutPhotosPrintsNoEmptyGallery(): void
    {
        $id = $this->project('ZZ PI Zonder foto\'s', 0);

        $this->assertNotNull($this->photosBlock($id), 'the block is there, waiting');
        $body = $this->page($id);
        $this->assertStringNotContainsString('project-images', $body);
        $this->assertStringNotContainsString('project-gallery', $body);
        $this->assertStringNotContainsString('Meer afbeeldingen', $body);
    }

    public function testAPhotoAddedOrTakenOffTheProjectFollowsOnThePage(): void
    {
        $id = $this->project('ZZ PI Erbij en eraf', 1);
        $this->assertSame(['zz-pi-1'], $this->photoOrder($this->page($id)));

        $this->photo($id, 2);
        $this->assertSame(['zz-pi-1', 'zz-pi-2'], $this->photoOrder($this->page($id)), 'a new photo shows at once, no copy to sync');

        Database::connection()->prepare('DELETE FROM portfolio_item_images WHERE portfolio_item_id = :id AND sort_order = 1')->execute(['id' => $id]);
        $this->assertSame(['zz-pi-2'], $this->photoOrder($this->page($id)), 'a photo taken off is gone at once');
    }

    public function testTheProjectLayoutChangesOnlyTheHead(): void
    {
        $id = $this->project('ZZ PI Layout', 2);
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $id);
        $text = $this->block($page, '<p>ZZ PI tekst</p>');
        $photos = $this->photosBlock($id);
        (new PageSectionRepository())->reorder((int) $page['id'], [$text, $photos]);

        foreach ([PortfolioProjectLayout::IMAGE_LEFT => 'project-hero', PortfolioProjectLayout::IMAGE_RIGHT => 'project-hero project-hero--image-right', PortfolioProjectLayout::IMAGE_TOP => 'project-hero project-hero--image-top'] as $layout => $class) {
            (new PortfolioGalleryRepository())->setItemProjectLayout($id, $layout);
            $body = $this->page($id);

            $this->assertStringContainsString('<section class="' . $class . '" data-lightbox-group="project-' . $id . '">', $body, $layout);
            $this->assertOrder($body, ['project-hero__title', 'ZZ PI tekst', 'zz-pi-1'], $layout);
            $this->assertStringContainsString('<section class="project-images" data-lightbox-group="project-' . $id . '">', $body, $layout . ': the photos look the same');
        }
    }

    public function testOneBlockPerProjectNeverOfferedNeverDeleted(): void
    {
        $id = $this->project('ZZ PI Een', 1);
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $id);
        $photos = $this->photosBlock($id);
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);

        $this->assertFalse(ProjectImagesPlacement::ensure($id), 'already there');
        $this->assertCount(1, $this->blocksOfType($id));

        $this->assertNotContains('project_images', array_keys(SectionRegistry::availableDefinitionsForPage($page, new PageSectionRepository())), 'never in the picker');
        $added = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'project_images',
        ]);
        $this->assertSame(400, $added['status'], 'a second one is refused');

        $deleted = self::$server->request('POST', '/api/admin/delete-page-section.php', $session, ['csrf_token' => $csrf, 'id' => (string) $photos]);
        $this->assertSame(400, $deleted['status'], 'hidden, never deleted');
        $this->assertNotNull((new PageSectionRepository())->findById($photos));

        // The database itself: one row per (type, project).
        try {
            (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'project_images', null, $id);
            $this->fail('a second row for the same project is refused');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $this->assertCount(1, $this->blocksOfType($id));
    }

    public function testCreatingAProjectPlacesItsBlockAndASaveRestoresAMissingOne(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);
        $mediaId = $this->media('hoofd');

        $made = self::$server->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf, 'media_id' => (string) $mediaId, 'alt' => '', 'title' => 'ZZ PI Nieuw', 'subtitle' => '',
        ]);
        $this->assertSame(302, $made['status']);
        $this->assertMatchesRegularExpression('#^/admin/portfolio-item\.php\?id=(\d+)&created=1$#', $made['location']);
        preg_match('#id=(\d+)#', $made['location'], $match);
        $id = (int) $match[1];
        $this->itemIds[] = $id;

        $this->assertSame(['project_images'], array_column($this->rows($id), 'section_type'), 'a new project has its photos block, alone, on top');

        // A project that lost it (an old database, a failure at creation)
        // gets it back on its next save, on top.
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $id);
        $this->block($page, '<p>ZZ PI na het opslaan</p>');
        Database::connection()->prepare("DELETE FROM page_sections WHERE section_type = 'project_images' AND section_id = :id")->execute(['id' => $id]);
        $saved = self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
            'csrf_token' => $csrf, 'item_id' => (string) $id, 'language_code' => PortfolioLocalization::defaultLanguage(),
            'title' => 'ZZ PI Nieuw', 'is_active' => '1',
        ]);
        $this->assertSame(302, $saved['status']);
        $this->assertSame(['project_images', 'rich_text'], array_column($this->rows($id), 'section_type'));
    }

    public function testCsrfPermissionAndAnotherProjectsSectionAreRefused(): void
    {
        $id = $this->project('ZZ PI Eigen', 1);
        $other = $this->project('ZZ PI Ander', 1);
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $id);
        $text = $this->block($page, '<p>ZZ PI eigen tekst</p>');
        $photos = $this->photosBlock($id);
        $otherPhotos = $this->photosBlock($other);
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);
        [$pagesOnly, $pagesCsrf] = $this->accounts->signIn(['pages.manage']);

        $this->assertSame(403, $this->toggle($session, 'wrong-token', $photos, false)['status'], 'CSRF');
        $this->assertSame(403, $this->toggle($pagesOnly, $pagesCsrf, $photos, false)['status'], 'a project\'s blocks ask portfolio.manage');
        $this->assertSame(1, (int) (new PageSectionRepository())->findById($photos)['is_active']);

        // Another project's photos block posted in this project's order: the
        // reorder only ever touches this page's own rows.
        $before = (new PageSectionRepository())->findById($otherPhotos);
        $this->reorder($session, $csrf, (int) $page['id'], [$otherPhotos, $text, $photos]);
        $this->assertSame($before['page_id'], (new PageSectionRepository())->findById($otherPhotos)['page_id']);
        $this->assertSame($before['sort_order'], (new PageSectionRepository())->findById($otherPhotos)['sort_order']);
        $this->assertSame([$text, $photos], array_map('intval', array_column($this->rows($id), 'id')));

        // A product's list cannot get one (owners), whoever asks.
        $refused = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'portfolio_project', 'content_owner_id' => (string) $id, 'section_type' => 'project_images',
        ]);
        $this->assertSame(400, $refused['status']);
    }

    public function testDeletingTheProjectTakesItsBlockAndKeepsTheLibraryPictures(): void
    {
        $id = $this->project('ZZ PI Weg', 2, true);
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $id);
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);

        $deleted = self::$server->request('POST', '/api/admin/delete-portfolio-item.php', $session, ['csrf_token' => $csrf, 'item_id' => (string) $id]);
        $this->assertSame(302, $deleted['status']);

        $this->assertNull((new PortfolioGalleryRepository())->findItemById($id));
        $this->assertNull((new PageSectionRepository())->findBySectionTypeAndId('project_images', $id));
        $this->assertSame([], (new PageSectionRepository())->findForPage((int) $page['id']));
        $this->assertNull(ContentPages::pageFor(PortfolioContentOwner::KIND, $id));
        $this->assertSame([], $this->photoRows($id));
        foreach ($this->mediaIds as $mediaId) {
            $this->assertNotNull((new MediaRepository())->findById($mediaId), 'a library picture stays in the library');
        }
    }

    // --------------------------------------------------------------- helpers

    private function project(string $title, int $photos, bool $fromLibrary = false): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-pi-main-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::ALT => $title,
        ]);
        $repository->setItemProjectPage($id, true, 'zz-pi-project-' . $id);
        for ($position = 1; $position <= $photos; $position++) {
            $this->photo($id, $position, $fromLibrary ? $this->media('foto-' . $position) : null);
        }
        ProjectImagesPlacement::ensure($id);
        PortfolioGalleryContent::clearCache();

        return $id;
    }

    private function photo(int $projectId, int $position, ?int $mediaId = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO portfolio_item_images (portfolio_item_id, media_id, image_path, thumbnail_path, sort_order, created_at, updated_at)
             VALUES (:item, :media, :path, NULL, :sort, NOW(), NOW())'
        )->execute([
            'item' => $projectId,
            'media' => $mediaId,
            'path' => 'assets/images/sections/zz-pi-' . $position . '.jpg',
            'sort' => $position,
        ]);
    }

    private function media(string $name): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__pi_http_' . $name . '_' . bin2hex(random_bytes(4)) . '__.png',
            'thumbnail_path' => null,
            'original_filename' => $name . '.png',
            'display_name' => 'zz-pi-' . $name . '-' . bin2hex(random_bytes(3)) . '.png',
            'mime_type' => 'image/png',
            'width' => 10,
            'height' => 10,
            'file_size' => 100,
            'alt_text' => '',
            'checksum' => null,
        ]);
        $this->mediaIds[] = $id;

        return $id;
    }

    /** @param array<string, mixed> $page */
    private function block(array $page, string $body): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', (string) $page['content_key']);
        $id = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $sectionKey, $sectionId);
        BlockLocalization::save('rich_text_sections', $sectionId, PageLocalization::defaultLanguage(), ['body' => $body]);

        return $id;
    }

    private function photosBlock(int $projectId): ?int
    {
        $row = (new PageSectionRepository())->findBySectionTypeAndId('project_images', $projectId);

        return $row === null ? null : (int) $row['id'];
    }

    /** @return list<array<string, mixed>> */
    private function blocksOfType(int $projectId): array
    {
        return Database::connection()->query("SELECT id FROM page_sections WHERE section_type = 'project_images' AND section_id = " . $projectId)->fetchAll();
    }

    /** @return list<array<string, mixed>> the project's blocks, in order */
    private function rows(int $projectId): array
    {
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $projectId);

        return $page === null ? [] : (new PageSectionRepository())->findForPage((int) $page['id']);
    }

    /** @return list<array<string, mixed>> */
    private function photoRows(int $projectId): array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM portfolio_item_images WHERE portfolio_item_id = :id');
        $stmt->execute(['id' => $projectId]);

        return $stmt->fetchAll();
    }

    private function page(int $projectId): string
    {
        $response = self::$server->request('GET', '/portfolio-detail.php?slug=zz-pi-project-' . $projectId);
        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('Fatal error', $response['body']);

        return $response['body'];
    }

    /** @return list<string> the photos on the page, by their file name */
    private function photoOrder(string $body): array
    {
        preg_match_all('#class="project-gallery__item".*?data-src="/assets/images/sections/(zz-pi-\d+)\.jpg"#s', $body, $matches);

        return $matches[1];
    }

    /** @param list<string> $needles */
    private function assertOrder(string $body, array $needles, string $message = ''): void
    {
        $previous = -1;
        foreach ($needles as $needle) {
            $at = strpos($body, $needle);
            $this->assertIsInt($at, $needle . ' ' . $message);
            $this->assertGreaterThan($previous, $at, $needle . ' in order ' . $message);
            $previous = $at;
        }
    }

    /** @param list<int> $ids */
    private function reorder(string $session, string $csrf, int $pageId, array $ids): void
    {
        $response = self::$server->request('POST', '/api/admin/reorder-page-sections.php', $session, [
            'csrf_token' => $csrf, 'page_id' => (string) $pageId, 'section_ids' => implode(',', $ids),
        ]);
        $this->assertSame(200, $response['status']);
    }

    /** @return array<string, mixed> */
    private function toggle(string $session, string $csrf, int $sectionId, bool $active): array
    {
        return self::$server->request('POST', '/api/admin/toggle-page-section.php', $session, [
            'csrf_token' => $csrf, 'id' => (string) $sectionId, 'is_active' => $active ? '1' : '0',
        ]);
    }

    /** @return list<string> */
    private function usageLabels(int $mediaId): array
    {
        return array_map(
            static fn ($usage): string => $usage->source . ':' . $usage->label,
            \App\Service\Media\MediaUsageRegistry::usagesFor([$mediaId])[$mediaId] ?? []
        );
    }
}
