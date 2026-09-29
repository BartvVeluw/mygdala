<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\DetailSectionContent;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Detailsectie 2.0 through its own editor and endpoint, over real HTTP
 * (Tests\Support\BuiltInServer):
 *
 *   - the anchor is stored in its one shape; one that leaves nothing, and one
 *     another section on the page has, are refused at the field and nothing
 *     is written;
 *   - the image position is a field of the main image: hidden without one,
 *     posted and kept either way;
 *   - a gallery item's source: a product is stored as kind + id, an unknown
 *     kind or an item that is no choice is refused, and a stored choice the
 *     picker can no longer offer survives opening and saving the form.
 */
final class DetailSectionTwoHttpTest extends TestCase
{
    private const KEY = 'zz-detail-two-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    private int $pageId = 0;

    /** @var list<int> */
    private array $mediaIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true', 'MODULE_BLOG_ENABLED' => 'true']);
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
        $this->shop = new ShopStockFixture();
        $this->removePage();
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detail http');
    }

    protected function tearDown(): void
    {
        $this->removePage();
        $this->shop->cleanUp();
        foreach ($this->mediaIds as $id) {
            Database::connection()->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
        }
        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
    }

    public function testTheAnchorIsStoredInItsShapeAndABadOrTakenOneIsRefused(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);
        $first = $this->section();
        $second = $this->section();

        $this->save($session, $csrf, $first, ['anchor' => '#Hout ']);
        $this->assertSame('hout', $this->row($first)['anchor']);

        $this->save($session, $csrf, $second, ['anchor' => 'HOUT']);
        $this->assertNull($this->row($second)['anchor'], 'taken on this page: refused, nothing written');
        $this->assertStringContainsString('#hout', implode(' ', (array) $this->accounts->read($session, 'admin_detail_section_errors')));

        $this->save($session, $csrf, $second, ['anchor' => '###']);
        $this->assertNull($this->row($second)['anchor'], 'nothing left of it: refused');

        $this->save($session, $csrf, $second, ['anchor' => 'Metaal & staal']);
        $this->assertSame('metaal-staal', $this->row($second)['anchor']);
    }

    public function testTheImagePositionSitsWithTheMainImageAndIsKept(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);
        $section = $this->section();
        $param = urlencode(self::KEY . ':' . $this->key($section));

        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . $param, $session)['body'];
        $this->assertMatchesRegularExpression('/<div class="admin-field" data-detail-image-position hidden>/', $screen, 'no main image: hidden');
        $this->assertLessThan(strpos($screen, 'data-detail-image-position'), strpos($screen, 'data-detail-main-image'), 'with the main image');

        $this->save($session, $csrf, $section, ['image_position' => 'image_left']);
        $this->assertSame('image_left', $this->row($section)['image_position'], 'a hidden field is still posted and kept');

        $media = $this->media();
        $this->save($session, $csrf, $section, ['image_position' => 'image_left', 'main_media_id' => (string) $media]);
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . $param, $session)['body'];
        $this->assertStringContainsString('<div class="admin-field" data-detail-image-position>', $screen, 'with a main image: shown');
        $this->assertStringContainsString('<option value="image_left" selected>', $screen);
    }

    public function testAGalleryItemIsStoredAsAKindAndAnId(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);
        $section = $this->section();
        $product = $this->shop->product('ZZ Plank http');

        $this->save($session, $csrf, $section, [
            'images_present' => '1',
            'images' => ['new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product]],
        ]);
        $rows = (new DetailSectionRepository())->findImagesBySectionId($this->row($section)['id']);
        $this->assertCount(1, $rows);
        $this->assertSame(['product', $product], [$rows[0]['source_type'], (int) $rows[0]['source_id']]);
        $this->assertNull($rows[0]['media_id']);

        // An unknown kind, and an id that is no choice, are refused.
        foreach ([['source' => 'App\\Service\\Page', 'source_product' => '1'], ['source' => 'product', 'source_product' => '999999999']] as $bad) {
            $this->save($session, $csrf, $section, [
                'images_present' => '1',
                'images' => [(string) $rows[0]['id'] => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product], 'new0' => ['present' => '1'] + $bad],
            ]);
            $this->assertCount(1, (new DetailSectionRepository())->findImagesBySectionId($this->row($section)['id']), json_encode($bad));
        }

        // A stored choice the picker can no longer offer (the product is
        // inactive) is kept when the form is saved as it is.
        Database::connection()->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute(['id' => $product]);
        $param = urlencode(self::KEY . ':' . $this->key($section));
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . $param, $session)['body'];
        $this->assertStringContainsString('name="images[' . $rows[0]['id'] . '][source]"', $screen);
        $this->save($session, $csrf, $section, [
            'images_present' => '1',
            'images' => [(string) $rows[0]['id'] => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product]],
        ]);
        $kept = (new DetailSectionRepository())->findImagesBySectionId($this->row($section)['id']);
        $this->assertSame(['product', $product], [$kept[0]['source_type'], (int) $kept[0]['source_id']]);
    }

    public function testTheEditorSaysLessAndExplainsInItsHelp(): void
    {
        [$session] = $this->accounts->signIn(['pages.manage']);
        $section = $this->section();
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode(self::KEY . ':' . $this->key($section)), $session)['body'];

        $this->assertStringNotContainsString('Hout graveren', $screen);
        $this->assertStringNotContainsString('Sommige secties hebben er bewust geen', $screen);
        $this->assertStringNotContainsString('<code>#anker</code>', $screen, 'the anchor explanation is help now, not a paragraph');
        $this->assertStringContainsString('data-admin-help-trigger', $screen);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $fields */
    private function save(string $session, string $csrf, int $pageSectionId, array $fields): void
    {
        $response = self::$server->request('POST', '/api/admin/update-detail-section.php', $session, $this->flatten($fields + [
            'csrf_token' => $csrf,
            'section' => self::KEY . ':' . $this->key($pageSectionId),
            'language_code' => PageLocalization::defaultLanguage(),
            'title' => 'ZZ Sectie',
            'is_active' => '1',
            'image_position' => 'image_right',
        ]));
        $this->assertSame(302, $response['status']);
        DetailSectionContent::clearCache();
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private function flatten(array $fields, string $prefix = ''): array
    {
        $flat = [];
        foreach ($fields as $name => $value) {
            $key = $prefix === '' ? (string) $name : $prefix . '[' . $name . ']';
            if (is_array($value)) {
                $flat += $this->flatten($value, $key);
            } else {
                $flat[$key] = (string) $value;
            }
        }

        return $flat;
    }

    private function section(): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', self::KEY);

        return (new PageSectionRepository())->create($this->pageId, self::KEY, 'detail_section', $sectionKey, $sectionId);
    }

    private function key(int $pageSectionId): string
    {
        return (string) (new PageSectionRepository())->findById($pageSectionId)['section_key'];
    }

    /** @return array<string, mixed> */
    private function row(int $pageSectionId): array
    {
        $row = (new DetailSectionRepository())->findBySlugAndKey(self::KEY, $this->key($pageSectionId));
        $row['id'] = (int) $row['id'];

        return $row;
    }

    private function media(): int
    {
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
             VALUES (:path, '', 'zz.jpg', 'ZZ detail http', 'image/jpeg', 10, 10, 100, '', NOW(), NOW())"
        )->execute(['path' => 'assets/media/zz-detail-http-' . bin2hex(random_bytes(3)) . '.jpg']);
        $id = (int) $db->lastInsertId();
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function removePage(): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::KEY);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }
        $pages->delete((int) $page['id']);
        PageContent::clearCache();
        BlockLocalization::clearCache();
    }
}
