<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ProductImageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentOwners;
use App\Service\DetailSectionContent;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\Routing\LinkTargets;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Detailsectie 2.1 through its own editor and endpoints, over real HTTP
 * (Tests\Support\BuiltInServer):
 *
 *   - ONE "Afbeeldingsbron": the form carries the hook of the source switch
 *     (data-nav-item-form), so only the chosen source's panel shows; a
 *     product is saved with no picture of its own and without a message;
 *   - FOLDED ITEMS: every stored gallery item is a summary line
 *     "Item <n> — Product: <name>", identified by its own row id, so a move
 *     opens nothing else; a new item and a lone one start open;
 *   - THE PREVIEW: a product's main picture as the Media Library thumbnail, a
 *     product without one as {available, picture: false}, a forged kind
 *     refused, and only for who may edit that list;
 *   - THE LIFECYCLE: a new Detailsectie is a draft until its first save, with
 *     a product item; Annuleren leaves nothing on the page; a save places it
 *     once and returns to the page.
 */
final class DetailSectionTwoOneHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $pageIds = [];

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

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
        LinkTargets::reset();
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();
    }

    protected function tearDown(): void
    {
        foreach ($this->pageIds as $pageId) {
            if ((new PageRepository())->findById($pageId) === null) {
                continue;
            }
            ContentBlockDrafts::discardForPage($pageId);
            $sections = new PageSectionRepository();
            foreach ($sections->findForPage($pageId) as $row) {
                SectionRegistry::delete($row, $sections);
            }
            (new PageRepository())->delete($pageId);
        }
        $this->shop->cleanUp();
        foreach ($this->mediaIds as $id) {
            Database::connection()->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
        }
        $this->accounts->forget();
        PageContent::clearCache();
        BlockLocalization::clearCache();
        DetailSectionContent::clearCache();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
        LinkTargets::reset();
    }

    public function testOneSourceChoiceAndAProductNeedsNoPictureOfItsOwn(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$page, $key] = $this->placedSection();
        $product = $this->productWithPicture('ZZ Houten naambordje');

        $screen = $this->editor($session, $key);
        $this->assertMatchesRegularExpression('~<form method="post" action="/api/admin/update-detail-section.php"[^>]* data-nav-item-form[ >]~', $screen, 'the source switch runs on this form');
        $this->assertStringContainsString('Afbeeldingsbron', $screen);
        $this->assertStringContainsString('<option value="media" selected>Mediabibliotheek</option>', $screen);

        $response = $this->save($session, $csrf, $key, [
            'images_present' => '1',
            'images' => ['new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product, 'media_id' => '']],
        ]);

        $this->assertStringContainsString('saved=', $response['location'], '5./9. saved without a second picture');
        $this->assertNull($this->accounts->read($session, 'admin_detail_section_field_errors'));
        $rows = $this->images($key);
        $this->assertSame(['product', $product, null], [$rows[0]['source_type'], (int) $rows[0]['source_id'], $rows[0]['media_id']]);

        // The website: the product's picture, linked to the product.
        DetailSectionContent::clearCache();
        $image = DetailSectionContent::forSection((string) $page['content_key'], explode(':', $key, 2)[1])['images'][0];
        $this->assertSame(LinkTargets::href('product', $product), $image['href']);
        $this->assertStringContainsString('zz-detail-21', $image['image_path']);
    }

    public function testStoredItemsFoldToASummaryLineKeyedByTheirOwnId(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [, $key] = $this->placedSection();
        $product = $this->productWithPicture('ZZ Plank');
        $picture = $this->media('ZZ Foto 03');

        $this->save($session, $csrf, $key, [
            'images_present' => '1',
            'images' => [
                'new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product],
                'new1' => ['present' => '1', 'source' => 'media', 'media_id' => (string) $picture],
            ],
        ]);
        [$first, $second] = array_map(static fn (array $row): int => (int) $row['id'], $this->images($key));

        $screen = $this->editor($session, $key);
        $this->assertStringContainsString('data-admin-collapse-group="detail-section-images"', $screen);
        $this->assertStringContainsString('admin-collapse.js', $screen);
        $this->assertMatchesRegularExpression('~<details class="admin-collapse admin-row-card__collapse" data-admin-collapse-id="' . $first . '">~', $screen, '1. stored items start folded');
        $this->assertStringContainsString('<span data-row-list-title> — Product: ZZ Plank</span>', $screen);
        $this->assertStringContainsString('<span data-row-list-title> — Mediabibliotheek: ZZ Foto 03</span>', $screen);
        $this->assertMatchesRegularExpression('~<template data-row-list-template="detail-section-images">.*?<details class="admin-collapse admin-row-card__collapse" data-admin-collapse-id="__KEY__" open>~s', $screen, 'a new item starts open');
        $this->assertMatchesRegularExpression('~<summary class="admin-collapse__summary">~', $screen, '2. a summary is the disclosure button');

        // 3. Moved: each item keeps its own id, so a remembered open row is
        // still the same item.
        $this->save($session, $csrf, $key, [
            'images_present' => '1',
            'images' => [
                (string) $second => ['present' => '1', 'source' => 'media', 'media_id' => (string) $picture],
                (string) $first => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product],
            ],
        ]);
        $this->assertSame([$second, $first], array_map(static fn (array $row): int => (int) $row['id'], $this->images($key)));
        $screen = $this->editor($session, $key);
        $this->assertLessThan(strpos($screen, 'data-admin-collapse-id="' . $first . '"'), strpos($screen, 'data-admin-collapse-id="' . $second . '"'));
        $this->assertMatchesRegularExpression('~data-admin-collapse-id="' . $first . '">\s*<summary[^>]*>.*?Product: ZZ Plank~s', $screen);
    }

    public function testAProductWithoutAMainPictureIsExplainedNotDemanded(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [, $key] = $this->placedSection();
        $product = $this->shop->product('ZZ Zonder foto');

        $response = $this->save($session, $csrf, $key, [
            'images_present' => '1',
            'images' => ['new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product]],
        ]);
        $this->assertStringContainsString('saved=', $response['location']);

        $screen = $this->editor($session, $key);
        $this->assertMatchesRegularExpression('~<p class="admin-alert admin-alert--warning" role="status" data-linked-image-no-picture>~', $screen, '14. the stored item says it has no main picture');

        $preview = $this->preview($session, $csrf, $key, 'product', (string) $product);
        $this->assertSame(['src' => '', 'available' => true, 'picture' => false], $preview);
    }

    public function testThePreviewIsTheThumbnailAndOnlyForWhoMayEditTheList(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [, $key] = $this->placedSection();
        $product = $this->productWithPicture('ZZ Voorbeeld');

        $preview = $this->preview($session, $csrf, $key, 'product', (string) $product);
        $this->assertTrue($preview['available']);
        $this->assertTrue($preview['picture']);
        $this->assertSame(MediaService::find($this->mediaIds[0])->displayPath(), $preview['src'], 'the library thumbnail, not the original');

        $forged = self::$server->request('POST', '/api/admin/linked-image-preview.php', $session, ['csrf_token' => $csrf, 'section' => $key, 'kind' => 'App\\Service\\Page', 'id' => '1']);
        $this->assertSame(404, $forged['status'], 'a forged kind');
        $this->assertSame(['src' => '', 'available' => false, 'picture' => false], $this->preview($session, $csrf, $key, 'product', '999999999'), 'a forged id');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/linked-image-preview.php', $session, ['csrf_token' => 'wrong', 'section' => $key, 'kind' => 'product', 'id' => (string) $product])['status'], 'CSRF');

        // 21. Who may not manage pages neither previews nor saves the list.
        [$other, $otherCsrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/linked-image-preview.php', $other, ['csrf_token' => $otherCsrf, 'section' => $key, 'kind' => 'product', 'id' => (string) $product])['status']);
        $refused = self::$server->request('POST', '/api/admin/update-detail-section.php', $other, $this->form($otherCsrf, $key, [
            'images_present' => '1',
            'images' => ['new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product]],
        ]));
        $this->assertSame(403, $refused['status']);
        $this->assertSame([], $this->images($key));
    }

    public function testANewDetailSectionIsADraftUntilItsFirstSave(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $page = $this->page();
        $product = $this->productWithPicture('ZZ Concept');

        // 24. Annuleren leaves nothing on the page.
        $key = $this->add($session, $csrf, $page);
        $this->assertSame([], $this->sectionIds($page));
        $this->assertStringContainsString('data-block-draft', $this->editor($session, $key));
        $cancelled = self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, ['csrf_token' => $csrf, 'section_type' => 'detail_section', 'section' => $key]);
        $this->assertSame(302, $cancelled['status']);
        $this->assertSame([], $this->sectionIds($page));

        // 23./25. A product item, the first save: placed once, back on the page.
        $key = $this->add($session, $csrf, $page);
        $saved = $this->save($session, $csrf, $key, [
            'images_present' => '1',
            'images' => ['new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product]],
        ]);
        $ids = $this->sectionIds($page);
        $this->assertCount(1, $ids);
        $this->assertSame('/admin/page.php?id=' . $page['id'] . '&saved=' . $ids[0] . '#blok-' . $ids[0], $saved['location']);
        $this->assertSame('product', $this->images($key)[0]['source_type']);

        // Opened again it is an ordinary block, saved in place.
        $this->assertStringNotContainsString('data-block-draft', $this->editor($session, $key));
        $this->save($session, $csrf, $key, ['images_present' => '1', 'images' => [(string) $this->images($key)[0]['id'] => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product]]]);
        $this->assertSame($ids, $this->sectionIds($page));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function page(): array
    {
        $key = 'zz-detail-21-' . bin2hex(random_bytes(4));
        $id = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detail 2.1');
        $this->pageIds[] = $id;

        return (array) (new PageRepository())->findById($id);
    }

    /** @return array{0: array<string, mixed>, 1: string} a page with one placed Detailsectie and its `<page>:<key>` */
    private function placedSection(): array
    {
        $page = $this->page();
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'detail_section', $sectionKey, $sectionId);

        return [$page, $page['content_key'] . ':' . $sectionKey];
    }

    /** @param array<string, mixed> $page */
    private function add(string $session, string $csrf, array $page): string
    {
        $response = self::$server->request('POST', '/api/admin/add-page-section.php', $session, ['csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'detail_section']);
        $this->assertSame(302, $response['status'], $response['body']);
        $this->assertStringStartsWith('/admin/detail-section.php?section=', $response['location']);
        parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);

        return (string) $query['section'];
    }

    private function editor(string $session, string $key): string
    {
        $response = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode($key), $session);
        $this->assertSame(200, $response['status']);

        return $response['body'];
    }

    /** @param array<string, mixed> $fields */
    private function save(string $session, string $csrf, string $key, array $fields): array
    {
        $response = self::$server->request('POST', '/api/admin/update-detail-section.php', $session, $this->form($csrf, $key, $fields));
        $this->assertSame(302, $response['status']);
        DetailSectionContent::clearCache();

        return $response;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private function form(string $csrf, string $key, array $fields): array
    {
        return $this->flatten($fields + [
            'csrf_token' => $csrf,
            'section' => $key,
            'language_code' => PageLocalization::defaultLanguage(),
            'title' => 'ZZ Sectie',
            'is_active' => '1',
            'image_position' => 'image_right',
        ]);
    }

    /** @return array<string, mixed> */
    private function preview(string $session, string $csrf, string $key, string $kind, string $id): array
    {
        $response = self::$server->request('POST', '/api/admin/linked-image-preview.php', $session, ['csrf_token' => $csrf, 'section' => $key, 'kind' => $kind, 'id' => $id]);
        $this->assertSame(200, $response['status'], $response['body']);

        return (array) json_decode($response['body'], true);
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

    /** @return list<array<string, mixed>> */
    private function images(string $key): array
    {
        [$pageSlug, $sectionKey] = explode(':', $key, 2);
        $section = (new DetailSectionRepository())->findBySlugAndKey($pageSlug, $sectionKey);

        return $section === null ? [] : (new DetailSectionRepository())->findImagesBySectionId((int) $section['id']);
    }

    /**
     * @param array<string, mixed> $page
     * @return list<int>
     */
    private function sectionIds(array $page): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new PageSectionRepository())->findForPage((int) $page['id']));
    }

    private function productWithPicture(string $name): int
    {
        $picture = $this->media($name);
        $product = $this->shop->product($name);
        (new ProductImageRepository())->addFromMedia($product, $picture, (string) MediaService::find($picture)->path);

        return $product;
    }

    private function media(string $name): int
    {
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
             VALUES (:path, :thumbnail, 'zz.jpg', :display, 'image/jpeg', 1600, 1200, 100, '', NOW(), NOW())"
        )->execute([
            'path' => 'assets/media/zz-detail-21-' . bin2hex(random_bytes(3)) . '.jpg',
            'thumbnail' => 'assets/media/thumbs/zz-detail-21-' . bin2hex(random_bytes(3)) . '.jpg',
            'display' => $name,
        ]);
        $id = (int) $db->lastInsertId();
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }
}
