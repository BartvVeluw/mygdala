<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\ProductContentOwner;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Extra vormgeving over real HTTP, against PHP's built-in server with the
 * Shop on (CONTENT-BLOCKS.md, "Extra vormgeving"):
 *
 *   - the block list shows the panel, closed, for a block that supports it
 *     and not for one that does not, with only the supported settings;
 *   - saving one block's look changes that block on the public page and no
 *     other, loads the stylesheets, and lands back on the block;
 *   - back to Standaard is the public page as it was;
 *   - CSRF, GET, a forged id, an unknown or unsupported value, CSS in a
 *     value and a user without the list's permission write nothing;
 *   - a product's own manager styles a block of its content page.
 */
final class BlockAppearanceHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $pageIds = [];

    private ?ShopStockFixture $shop = null;

    /** @var list<int> */
    private array $productIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
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
        $this->accounts = new AdminTestSession();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->pageIds) as $pageId) {
            if ((new PageRepository())->findById($pageId) !== null) {
                ContentBlockDrafts::discardForPage($pageId);
                $sections = new PageSectionRepository();
                foreach ($sections->findForPage($pageId) as $row) {
                    SectionRegistry::delete($row, $sections);
                }
                (new PageRepository())->delete($pageId);
            }
        }

        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->shop?->cleanUp();

        $this->accounts->forget();
        PageContent::clearCache();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    public function testTheBlockListShowsThePanelOnlyWhereItFits(): void
    {
        $page = $this->page();
        $text = $this->text($page, 'Paneeltekst');
        $spacer = $this->block($page, 'spacer');
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $body = self::$server->request('GET', '/admin/page.php?id=' . $page['id'], $session)['body'];

        $this->assertSame(1, substr_count($body, 'data-block-appearance-panel='), 'the Tekstblok has one, the Witruimte none');
        $this->assertStringContainsString('data-block-appearance-panel="' . $text . '"', $body);
        $this->assertStringNotContainsString('data-block-appearance-panel="' . $spacer . '"', $body);
        $this->assertMatchesRegularExpression('~<details class="admin-collapse block-appearance-panel" data-block-appearance-panel="' . $text . '">~', $body, 'closed by default');
        $this->assertStringContainsString('Extra vormgeving', $body);
        $this->assertStringContainsString('<span class="block-appearance-panel__current">Standaard</span>', $body);
        foreach (['background', 'border', 'border_tone', 'spacing', 'decoration'] as $field) {
            $this->assertStringContainsString('id="block-appearance-' . $text . '-' . $field . '" name="' . $field . '"', $body, $field);
        }
        $this->assertStringContainsString('<option value="sparks">Vallende bolletjes</option>', $body);
        $this->assertMatchesRegularExpression(
            '~<form method="post" action="/api/admin/update-block-appearance\.php" class="block-appearance-panel__form" data-save-name="Extra vormgeving">~',
            $body,
            'watched by the page\'s save bar, so an unsaved look warns before leaving'
        );
        $this->assertDoesNotMatchRegularExpression('~update-block-appearance\.php"[^>]*data-no-dirty-track~', $body);
    }

    public function testSavingOneBlocksLookChangesOnlyThatBlockAndBackToStandaardRestoresThePage(): void
    {
        $page = $this->page();
        $first = $this->text($page, 'Eerste blok');
        $second = $this->text($page, 'Tweede blok');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $url = '/pagina.php?slug=' . urlencode((string) $page['content_key']);
        $before = self::$server->request('GET', $url)['body'];
        $this->assertStringNotContainsString('block-appearance.css', $before);

        $saved = $this->save($session, $csrf, $second, ['background' => 'primary', 'border' => 'both', 'border_tone' => 'accent', 'spacing' => 'spacious', 'decoration' => 'sparks']);
        $this->assertSame(302, $saved['status']);
        $this->assertSame('/admin/page.php?id=' . $page['id'] . '&saved=' . $second . '#blok-' . $second, $saved['location']);

        $public = self::$server->request('GET', $url)['body'];
        $this->assertSame(1, substr_count($public, ' block-appearance '), 'only the second block');
        $this->assertSame(1, substr_count($public, '<section class="rich-text-section">'), 'the first is as it was');
        $this->assertMatchesRegularExpression('~<section class="rich-text-section block-appearance block-appearance--bg-primary block-appearance--border-both block-appearance--line-accent block-appearance--space-spacious block-appearance--decor-sparks"><div class="block-decor block-decor--sparks" aria-hidden="true">~', $public);
        $this->assertStringContainsString('/assets/css/block-appearance.css', $public);
        $this->assertStringContainsString('/assets/css/block-decorations.css', $public);
        $this->assertStringContainsString('Eerste blok', $public);

        $list = self::$server->request('GET', '/admin/page.php?id=' . $page['id'] . '&saved=' . $second, $session)['body'];
        $this->assertStringContainsString('Primaire themakleur · rand boven en onder (accentkleur) · ruimte ruim · Vallende bolletjes', $list);
        $this->assertStringContainsString('<option value="primary" selected>', $list);

        // 9./10. Back to Standaard.
        $this->save($session, $csrf, $second, BlockAppearance::defaults());
        $this->assertSame($this->withoutTokens($before), $this->withoutTokens(self::$server->request('GET', $url)['body']), 'the public page as it was');
        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow((array) (new PageSectionRepository())->findById($first)));
    }

    public function testForgedAndUnauthorisedRequestsWriteNothing(): void
    {
        $page = $this->page();
        $id = $this->text($page, 'Beveiligd');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $valid = ['background' => 'page'];

        // 35. CSRF, and the method.
        $this->assertSame(403, $this->save($session, 'wrong', $id, $valid)['status']);
        $this->assertSame(405, self::$server->request('GET', '/api/admin/update-block-appearance.php', $session)['status']);

        // 36. A forged section id.
        $this->assertSame(404, $this->save($session, $csrf, 999999999, $valid)['status']);
        $this->assertSame(404, $this->save($session, $csrf, 0, $valid)['status']);

        // 37./38. An unknown effect, CSS in a value, an unsupported combination.
        foreach ([
            ['decoration' => 'confetti'],
            ['background' => 'red;background:url(//evil.example)'],
            ['background' => '#ff0000'],
            ['spacing' => '10px'],
            ['border_tone' => '"><script>alert(1)</script>'],
        ] as $forged) {
            $response = $this->save($session, $csrf, $id, $forged);
            $this->assertSame(302, $response['status']);
            $this->assertSame('/admin/page.php?id=' . $page['id'] . '#blok-' . $id, $response['location'], 'back to the block, not "saved"');
        }

        // 7. The Kaarten-carrousel offers no falling sparks.
        $carousel = $this->block($page, 'card_carousel');
        $this->save($session, $csrf, $carousel, ['decoration' => 'sparks']);
        $this->assertSame('none', (new PageSectionRepository())->findById($carousel)['appearance_decoration']);

        // A block that supports nothing.
        $spacer = $this->block($page, 'spacer');
        $this->assertSame(422, $this->save($session, $csrf, $spacer, $valid)['status']);

        // 39. Not this list's permission: a Shop manager on a page.
        [$shop, $shopCsrf] = $this->accounts->signIn(['products.manage']);
        $this->assertSame(403, $this->save($shop, $shopCsrf, $id, $valid)['status']);

        $repository = new PageSectionRepository();
        foreach ([$id, $carousel, $spacer] as $row) {
            $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow((array) $repository->findById($row)), "row {$row} is untouched");
        }
    }

    public function testAProductsOwnManagerStylesABlockOfItsContentPage(): void
    {
        // 32./34. Owner-aware: the product's permission, not pages.manage.
        $this->shop ??= new ShopStockFixture();
        $productId = $this->shop->product('ZZ Vormgeving HTTP');
        $this->productIds[] = $productId;
        $productPage = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        $id = $this->text($productPage, 'Productblok', false);

        [$session, $csrf] = $this->accounts->signIn(['products.manage']);
        $tab = self::$server->request('GET', '/admin/product-form.php?id=' . $productId . '&tab=inhoud', $session)['body'];
        $this->assertStringContainsString('data-block-appearance-panel="' . $id . '"', $tab, 'the same panel on the Pagina-inhoud tab');

        $saved = $this->save($session, $csrf, $id, ['background' => 'subtle', 'decoration' => 'glow']);
        $this->assertSame('/admin/product-form.php?id=' . $productId . '&tab=inhoud&saved=' . $id . '#blok-' . $id, $saved['location']);
        $this->assertSame(['subtle', 'glow'], [
            (new PageSectionRepository())->findById($id)['appearance_background'],
            (new PageSectionRepository())->findById($id)['appearance_decoration'],
        ]);

        // A page's editor without the Shop right cannot touch it.
        [$pages, $pagesCsrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->assertSame(403, $this->save($pages, $pagesCsrf, $id, BlockAppearance::defaults())['status']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $look
     *
     * @return array{status: int, body: string, location: ?string}
     */
    private function save(string $session, string $csrf, int $id, array $look): array
    {
        return self::$server->request('POST', '/api/admin/update-block-appearance.php', $session, ['csrf_token' => $csrf, 'id' => (string) $id] + $look);
    }

    /** @return array<string, mixed> a fresh published page */
    private function page(): array
    {
        $key = 'zz-appearance-http-' . bin2hex(random_bytes(4));
        $id = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Vormgeving HTTP');
        $this->pageIds[] = $id;

        return (array) (new PageRepository())->findById($id);
    }

    /** @param array<string, mixed> $page */
    private function text(array $page, string $words, bool $track = true): int
    {
        $id = $this->block($page, 'rich_text');
        $row = (new PageSectionRepository())->findById($id);
        BlockLocalization::save('rich_text_sections', (int) $row['section_id'], BlockLocalization::defaultLanguage(), [RichTextContent::BODY => '<p>' . $words . '</p>']);

        return $id;
    }

    /** @param array<string, mixed> $page */
    private function block(array $page, string $type): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key']);

        return (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $sectionKey, $sectionId);
    }

    /** A public page without what differs per request (CSRF tokens, nonces). */
    private function withoutTokens(string $html): string
    {
        return (string) preg_replace(['/name="csrf_token" value="[^"]*"/', '/nonce="[^"]*"/'], '', $html);
    }
}
