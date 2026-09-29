<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\SectionRegistry;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemePalette;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\PageThemeFixture;
use Tests\Support\ShopStockFixture;

/**
 * What a page theme does to a real page, over PHP's built-in server with the
 * site's own routing (tests/Support/dispatcher-router.php), once with the
 * Paginathema's module on and once off:
 *
 *   - a themed page: the attribute on <main>, the complete token set (the
 *     five colours, every ThemePalette tint, both font stacks) scoped to
 *     it, the theme's web font next to the site's, the page header and every
 *     block inside the themed <main>, the site header outside it;
 *   - a page under it gets nothing (no inheritance); the English address of
 *     the same page gets the same theme; its SEO head is the same with the
 *     module on or off;
 *   - a page without a theme renders byte for byte the same with the module
 *     on and off;
 *   - module off: a themed page falls back to the site theme, and gets it
 *     back when the module is on again;
 *   - a tampered row never reaches the page;
 *   - a page with many kinds of block renders under a theme without a notice;
 *   - the editor's preview of the page shows its theme;
 *   - a product's and a project's content page never gets a theme, even with
 *     one stored on it.
 *
 * The same, for one themed page, over real Apache: PageThemesApacheHttpTest.
 */
final class PageThemesRenderingHttpTest extends TestCase
{
    private const PARENT = 'zz-pt-ouder';
    private const PARENT_EN = 'zz-pt-parent';
    private const CHILD = 'zz-pt-kind';
    private const PLAIN = 'zz-pt-gewoon';
    private const BLOCKS = 'zz-pt-blokken';

    /** A block of most kinds a page is made of, the page header first. */
    private const BLOCK_TYPES = [
        'page_hero', 'rich_text', 'text_image_split', 'detail_section', 'card_carousel',
        'cta_band', 'form', 'media_banner', 'faq', 'feature_grid',
    ];

    private static ?BuiltInServer $on = null;
    private static ?BuiltInServer $off = null;

    private AdminTestSession $accounts;
    private ShopStockFixture $shop;

    private int $themeId = 0;
    private int $parentId = 0;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $modules = ['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true', 'MODULE_MULTILINGUAL_ENABLED' => 'true'];
        self::$on = BuiltInServer::start($modules + ['MODULE_PAGE_THEMES_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
        self::$off = BuiltInServer::start($modules + ['MODULE_PAGE_THEMES_ENABLED' => 'false'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$on?->stop();
        self::$off?->stop();
        self::$on = self::$off = null;
    }

    protected function setUp(): void
    {
        if (self::$on === null || self::$off === null || !self::$on->answers() || !self::$off->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        \App\Module\ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true, 'page_themes' => true]);
        BlockDefinitions::reset();
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();

        $this->removePages();
        PageThemeFixture::removeAll();

        $this->themeId = PageThemeFixture::create('Halloween');

        $this->parentId = PageFixture::create(['content_key' => self::PARENT, 'slug' => self::PARENT, 'status' => PageContent::STATUS_PUBLISHED], 'Themaouder');
        PageLocalization::save($this->parentId, 'en', [PageTranslation::TITLE => 'Theme parent'], self::PARENT_EN);
        PageFixture::create(['content_key' => self::CHILD, 'slug' => self::CHILD, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $this->parentId], 'Themakind');
        $plainId = PageFixture::create(['content_key' => self::PLAIN, 'slug' => self::PLAIN, 'status' => PageContent::STATUS_PUBLISHED], 'Gewone pagina');

        $this->block(self::PARENT, $this->parentId, 'rich_text', ['body' => '<p>ZZ thematekst <a href="/">link</a></p>']);
        $this->block(self::PLAIN, $plainId, 'rich_text', ['body' => '<p>ZZ gewone tekst</p>']);

        PageThemeFixture::assign($this->parentId, $this->themeId);
        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->shop->cleanUp();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            ContentPages::deleteFor(PortfolioContentOwner::KIND, $id);
            $gallery->deleteItem($id);
        }

        $this->removePages();
        PageThemeFixture::removeAll();
        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
    }

    public function testAThemedPageGetsTheCompleteTokenSetScopedToItsMain(): void
    {
        $body = $this->get(self::$on, '/' . self::PARENT);

        self::assertStringContainsString('<main id="main" data-page-theme="zz-test-halloween">', $body);
        self::assertSame(1, substr_count($body, ' data-page-theme="'), 'as an attribute on <main> only');

        $style = $this->pageThemeStyle($body);
        self::assertStringContainsString('main[data-page-theme="zz-test-halloween"]{', $style);

        foreach (['--color-primary' => '#FF7518', '--color-on-primary' => '#111111', '--color-bg' => '#1A0F1F', '--color-surface' => '#2A1A30', '--color-text' => '#F7F1E8'] as $token => $value) {
            self::assertStringContainsString($token . ': ' . $value . ';', $style);
        }
        $derived = ThemePalette::derive(['primary' => '#FF7518', 'background' => '#1A0F1F', 'surface' => '#2A1A30', 'text' => '#F7F1E8']);
        foreach ($derived as $token => $value) {
            self::assertStringContainsString($token . ': ' . $value . ';', $style, $token);
        }
        $pairing = ThemeFonts::pairing('playfair-source-sans');
        self::assertStringContainsString('--font-display: ' . $pairing['heading'] . ';', $style);
        self::assertStringContainsString('--font-body: ' . $pairing['body'] . ';', $style);

        // The theme's font, next to the site's, and after the site theme.
        self::assertStringContainsString(htmlspecialchars((string) $pairing['url'], ENT_QUOTES, 'UTF-8'), $body);
        self::assertGreaterThan((int) strpos($body, '/assets/css/core.css'), strpos($body, '<style id="page-theme">'));

        // The site's header stays outside the themed <main>, the page's content inside it.
        self::assertLessThan(strpos($body, '<main id="main"'), strpos($body, '<header class="site-header">'));
        self::assertGreaterThan(strpos($body, '<main id="main"'), strpos($body, 'ZZ thematekst'));
        self::assertGreaterThan(strpos($body, 'ZZ thematekst'), strpos($body, '<footer'), 'the footer after it, outside');
    }

    public function testAPageUnderAThemedPageDoesNotInheritIt(): void
    {
        $body = $this->get(self::$on, '/' . self::PARENT . '/' . self::CHILD);

        self::assertStringContainsString('<main id="main">', $body);
        self::assertStringNotContainsString('page-theme', $body);
    }

    public function testEveryLanguageOfThePageSharesTheThemeAndItsSeoIsUntouched(): void
    {
        $english = $this->get(self::$on, '/en/' . self::PARENT_EN);
        self::assertStringContainsString('<main id="main" data-page-theme="zz-test-halloween">', $english);
        self::assertStringContainsString('<style id="page-theme">', $english);

        foreach (['/' . self::PARENT, '/en/' . self::PARENT_EN] as $path) {
            self::assertSame(
                $this->seoHead($this->get(self::$off, $path)),
                $this->seoHead($this->get(self::$on, $path)),
                $path . ': canonical, hreflang, robots and the description do not change'
            );
        }
    }

    public function testAPageWithoutAThemeRendersExactlyAsWithTheModuleOff(): void
    {
        $on = $this->get(self::$on, '/' . self::PLAIN);
        $off = $this->get(self::$off, '/' . self::PLAIN);

        self::assertStringNotContainsString('page-theme', $on);
        self::assertSame($off, $on);
    }

    public function testWithTheModuleOffAThemedPageFallsBackAndGetsItsThemeBackAfterwards(): void
    {
        $off = $this->get(self::$off, '/' . self::PARENT);
        self::assertStringContainsString('<main id="main">', $off);
        self::assertStringNotContainsString('page-theme', $off);
        self::assertStringNotContainsString('Playfair', $off);

        self::assertSame($this->themeId, (int) (new PageRepository())->findById($this->parentId)['page_theme_id'], 'the choice is kept');
        self::assertStringContainsString('data-page-theme="zz-test-halloween"', $this->get(self::$on, '/' . self::PARENT));
    }

    public function testATamperedRowNeverReachesThePage(): void
    {
        $db = Database::connection();

        // A colour that could close the rule (CHAR(7), so it has to fit).
        $db->prepare('UPDATE page_themes SET primary_color = ? WHERE id = ?')->execute(['r;}a{b:', $this->themeId]);
        $body = $this->get(self::$on, '/' . self::PARENT);
        self::assertStringContainsString('<main id="main">', $body, 'a broken theme is the site theme');
        self::assertStringNotContainsString('page-theme', $body);
        self::assertStringNotContainsString('r;}a{b', $body);

        // A slug that would break out of the selector or the attribute.
        $db->prepare('UPDATE page_themes SET primary_color = ?, slug = ? WHERE id = ?')->execute(['#FF7518', 'x"]{}body{display:none}', $this->themeId]);
        $body = $this->get(self::$on, '/' . self::PARENT);
        self::assertStringNotContainsString('page-theme', $body);
        self::assertStringNotContainsString('display:none', $body);

        // A font pairing outside the closed list.
        $db->prepare('UPDATE page_themes SET slug = ?, font_pairing = ? WHERE id = ?')->execute(['zz-test-halloween', "x');}", $this->themeId]);
        self::assertStringNotContainsString('page-theme', $this->get(self::$on, '/' . self::PARENT));
    }

    public function testAPageMadeOfManyKindsOfBlockRendersUnderATheme(): void
    {
        $pageId = PageFixture::create(['content_key' => self::BLOCKS, 'slug' => self::BLOCKS, 'status' => PageContent::STATUS_PUBLISHED], 'Blokkenpagina');
        foreach (self::BLOCK_TYPES as $type) {
            self::assertNotNull(BlockDefinitions::get($type), $type . ' is a block type');
            $this->block(self::BLOCKS, $pageId, $type, $type === 'rich_text' ? ['body' => '<p>ZZ blokkentekst</p>'] : []);
        }
        PageThemeFixture::assign($pageId, $this->themeId);
        $this->clearCaches();

        $body = $this->get(self::$on, '/' . self::BLOCKS);

        self::assertStringContainsString('<main id="main" data-page-theme="zz-test-halloween">', $body);
        self::assertStringContainsString('<style id="page-theme">', $body);
        self::assertStringContainsString('ZZ blokkentekst', $body);
        self::assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)\b.*?\.php/', $body);

        $main = (int) strpos($body, '<main id="main"');
        $hero = strpos($body, '<section class="page-hero');
        self::assertNotFalse($hero, 'the page header renders');
        self::assertGreaterThan($main, $hero, 'the page header is inside the themed <main>');
    }

    public function testTheEditorsPreviewShowsThePagesTheme(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $preview = self::$on->request('GET', '/admin/page-preview.php?id=' . $this->parentId, $session);
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('<main id="main" data-page-theme="zz-test-halloween">', $preview['body']);

        $off = self::$off->request('GET', '/admin/page-preview.php?id=' . $this->parentId, $session);
        self::assertStringContainsString('<main id="main">', $off['body']);
    }

    public function testAProductOrProjectNeverGetsATheme(): void
    {
        $productId = $this->shop->product('ZZ Thema product');
        $this->productIds[] = $productId;
        $productPage = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        PageThemeFixture::assign((int) $productPage['id'], $this->themeId);

        $product = $this->get(self::$on, '/product.php?id=' . $productId);
        self::assertStringNotContainsString('page-theme', $product);

        $repository = new PortfolioGalleryRepository();
        $itemId = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-pt-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $itemId;
        PortfolioLocalization::saveItem($itemId, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => 'ZZ Thema project',
            PortfolioLocalization::ALT => 'ZZ Thema project',
        ]);
        $repository->setItemProjectPage($itemId, true, 'zz-pt-project-' . $itemId);
        PortfolioGalleryContent::clearCache();
        $projectPage = ContentPages::ensure(PortfolioContentOwner::KIND, $itemId);
        PageThemeFixture::assign((int) $projectPage['id'], $this->themeId);

        $project = $this->get(self::$on, '/portfolio-detail.php?slug=zz-pt-project-' . $itemId);
        self::assertStringContainsString('ZZ Thema project', $project);
        self::assertStringNotContainsString('page-theme', $project);

        // Tidy: the content pages go with their owners in tearDown(), and
        // must not keep the theme from being removed.
        PageThemeFixture::assign((int) $productPage['id'], null);
        PageThemeFixture::assign((int) $projectPage['id'], null);
    }

    // ------------------------------------------------------------ helpers

    private function get(BuiltInServer $server, string $path): string
    {
        $response = $server->request('GET', $path);
        self::assertSame(200, $response['status'], $path);
        self::assertStringNotContainsString('Fatal error', $response['body']);

        return $response['body'];
    }

    private function pageThemeStyle(string $body): string
    {
        self::assertSame(1, preg_match('#<style id="page-theme">(.*?)</style>#s', $body, $match));

        return $match[1];
    }

    /** The head's SEO lines: title, description, robots, canonical and hreflang. */
    private function seoHead(string $body): string
    {
        preg_match_all('#<title>.*?</title>|<meta name="(?:description|robots)"[^>]*>|<link rel="(?:canonical|alternate)"[^>]*>#s', $body, $matches);

        return implode("\n", $matches[0]);
    }

    /** @param array<string, string> $words */
    private function block(string $contentKey, int $pageId, string $type, array $words): void
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, $contentKey);
        (new PageSectionRepository())->create($pageId, $contentKey, $type, $sectionKey, $sectionId);

        $table = BlockDefinitions::get($type)?->contentTable();
        if ($words !== [] && $table !== null) {
            BlockLocalization::save($table, $sectionId, PageLocalization::defaultLanguage(), $words);
        }
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        PageLocalization::clearCache();
        PagePath::clearCache();
    }

    private function removePages(): void
    {
        foreach ([self::CHILD, self::PARENT, self::PLAIN, self::BLOCKS] as $contentKey) {
            $page = (new PageRepository())->findByContentKey($contentKey);
            if ($page !== null) {
                PageThemeFixture::assign((int) $page['id'], null);
                PageService::delete($page);
            }
        }

        $this->clearCaches();
    }
}
