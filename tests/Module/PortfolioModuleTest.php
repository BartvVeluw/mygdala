<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\ModuleSettings;
use App\Module\PortfolioModule;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\ItemGalleryBlock;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Service\PageAssets;
use App\Service\PageContent;
use App\Service\ReservedRoutes;
use App\Service\RouteRegistry;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What the CMS looks like from the inside with the Portfolio on and with it
 * off, and that Core never learns what a portfolio item is.
 *
 * Everything here is a registry or a source question, so it needs no database
 * and no web server (tier `fast`). The same behaviour over real HTTP — the
 * screens, a write endpoint, the public routes, the sitemap and the data
 * surviving a switch-off — is Tests\Module\PortfolioModuleHttpTest. Same shape
 * as Tests\Blog\BlogModuleTest.
 */
final class PortfolioModuleTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnvironment = [];

    /**
     * The configuration test is about steps 2 and 3 of the precedence chain,
     * so step 1 has to be out of the way: the suite runs in a container that
     * asks for MODULE_PORTFOLIO_ENABLED=true, and the environment rightly wins
     * over everything. It is put back in tearDown(), the same seam
     * Tests\Module\ModuleConfigurationTest uses.
     */
    protected function setUp(): void
    {
        foreach (ModuleRegistry::keys() as $key) {
            $variable = ModuleConfig::variableName($key);
            $this->originalEnvironment[$variable] = $_ENV[$variable] ?? null;
            unset($_ENV[$variable]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $variable => $value) {
            if ($value === null) {
                unset($_ENV[$variable]);
            } else {
                $_ENV[$variable] = $value;
            }
        }

        ModuleRegistry::overrideForTests(null);
        ModuleSettings::overrideForTests(null);
    }

    private function withPortfolio(bool $enabled, bool $shop = true): void
    {
        ModuleRegistry::overrideForTests([
            'shop' => $shop,
            'personalization' => $shop,
            'blog' => true,
            'portfolio' => $enabled,
            'multilingual' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Registration and the default                                        */
    /* ------------------------------------------------------------------ */

    public function testThePortfolioIsARegisteredFirstPartyModule(): void
    {
        $this->assertTrue(ModuleRegistry::has('portfolio'));
        $this->assertInstanceOf(PortfolioModule::class, ModuleRegistry::definition('portfolio'));
        $this->assertSame('Portfolio', ModuleRegistry::label('portfolio'));
        $this->assertSame('MODULE_PORTFOLIO_ENABLED', ModuleConfig::variableName('portfolio'));
        $this->assertSame(
            'module_portfolio_enabled',
            ModuleSettings::settingKey('portfolio'),
            'the key the pin migration writes by hand'
        );
    }

    /**
     * A new installation only gets the Portfolio when it asks, the Blog's
     * rule. An existing installation keeps it through the preference the pin
     * migration stores (Tests\Install\PortfolioModulePinTest), and a stored
     * preference is read before this default.
     */
    public function testANewInstallationOnlyGetsThePortfolioWhenItAsks(): void
    {
        $this->assertFalse((new PortfolioModule())->enabledByDefault());

        ModuleSettings::overrideForTests([]);
        $this->assertFalse(ModuleConfig::wants('portfolio'), 'nothing configured means off');

        ModuleSettings::overrideForTests(['portfolio' => true]);
        $this->assertTrue(ModuleConfig::wants('portfolio'), 'a stored preference switches it on');

        $_ENV['MODULE_PORTFOLIO_ENABLED'] = 'false';
        $this->assertFalse(ModuleConfig::wants('portfolio'), 'and the environment still has the last word');
    }

    /* ------------------------------------------------------------------ */
    /* The admin: sidebar, permission, guards                              */
    /* ------------------------------------------------------------------ */

    public function testWithThePortfolioOnItKeepsItsPlaceInTheSidebar(): void
    {
        $this->withPortfolio(true);

        $items = AdminNavigation::items();
        $positions = array_flip(array_column($items, 'key'));

        $this->assertArrayHasKey('portfolio', $positions);
        $this->assertGreaterThan($positions['shop_settings'], $positions['portfolio']);
        $this->assertLessThan($positions['blog_posts'], $positions['portfolio']);

        $entry = $items[$positions['portfolio']];
        $this->assertSame('/admin/portfolio.php', $entry['url']);
        $this->assertSame(PortfolioModule::PORTFOLIO_MANAGE, $entry['permission']);
        $this->assertSame('portfolio', AdminNavigation::activeKeyForScript('portfolio-item.php'));
    }

    public function testNoPortfolioEntryIsLeftInTheSidebarWhileItIsOff(): void
    {
        $this->withPortfolio(false);

        $items = AdminNavigation::items();

        $this->assertNotContains('portfolio', array_column($items, 'key'));
        $this->assertNotContains('/admin/portfolio.php', array_column($items, 'url'));
        $this->assertNull(AdminNavigation::activeKeyForScript('portfolio.php'));
    }

    public function testManagingThePortfolioIsOnePermissionThatIncludesPickingAnImage(): void
    {
        $this->withPortfolio(true);

        $this->assertSame('portfolio.manage', PortfolioModule::PORTFOLIO_MANAGE, 'a stored grant must keep matching');
        $this->assertContains(PortfolioModule::PORTFOLIO_MANAGE, AdminPermissions::enabled());
        $this->assertContains(AdminPermissions::MEDIA_VIEW, AdminPermissions::expand([PortfolioModule::PORTFOLIO_MANAGE]));
        $this->assertNotContains(AdminPermissions::MEDIA_MANAGE, AdminPermissions::expand([PortfolioModule::PORTFOLIO_MANAGE]));
    }

    public function testNobodyHoldsThePortfolioPermissionWhileItIsOff(): void
    {
        $this->withPortfolio(false);

        $superAdmin = ['is_super_admin' => true, 'permissions' => []];
        $editor = ['is_super_admin' => false, 'permissions' => [PortfolioModule::PORTFOLIO_MANAGE]];

        $this->assertNotContains(PortfolioModule::PORTFOLIO_MANAGE, AdminPermissions::enabled());
        $this->assertFalse(AdminPermissions::userHas($superAdmin, PortfolioModule::PORTFOLIO_MANAGE));
        $this->assertFalse(AdminPermissions::userHas($editor, PortfolioModule::PORTFOLIO_MANAGE));

        // The NAME stays valid, so a colleague's stored grant survives untouched...
        $this->assertTrue(AdminPermissions::isValid(PortfolioModule::PORTFOLIO_MANAGE));
        $this->assertSame(
            [PortfolioModule::PORTFOLIO_MANAGE],
            AdminPermissions::sanitize([PortfolioModule::PORTFOLIO_MANAGE])
        );

        // ...and holds again the moment the module is back.
        $this->withPortfolio(true);
        $this->assertTrue(AdminPermissions::userHas($editor, PortfolioModule::PORTFOLIO_MANAGE));
    }

    /**
     * Both screens ask for the module's permission. That is what closes them
     * while the Portfolio is off, without a ModuleGuard call in each file: a
     * disabled module's permission is held by nobody (MODULES.md).
     */
    public function testEveryPortfolioAdminScreenRequiresThePortfolioPermission(): void
    {
        foreach (['admin/portfolio.php', 'admin/portfolio-item.php'] as $file) {
            $source = self::sourceOf($file);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, $file);
            $this->assertStringContainsString(
                "requirePermission('portfolio.manage')",
                $source,
                $file . ' must refuse through the module permission'
            );
        }
    }

    /** Every write endpoint: login, permission, POST-only, CSRF — in that order. */
    public function testEveryPortfolioEndpointGuardsItselfInTheUsualOrder(): void
    {
        $endpoints = self::endpoints();
        // Named rather than counted: a glob that silently finds nothing must
        // fail here, and these exist with or without a project page, so a
        // later phase that drops the project-image endpoints keeps this green.
        foreach ([
            'api/admin/create-portfolio-item.php',
            'api/admin/update-portfolio-item.php',
            'api/admin/delete-portfolio-item.php',
            'api/admin/create-portfolio-category.php',
            'api/admin/update-portfolio-category.php',
            'api/admin/delete-portfolio-category.php',
            'api/admin/move-featured-gallery-item.php',
        ] as $expected) {
            $this->assertContains($expected, $endpoints);
        }

        foreach ($endpoints as $file) {
            $source = self::withoutComments(self::sourceOf($file));

            $positions = [
                strpos($source, 'AdminAuth::requireLoginForApi()'),
                strpos($source, "requirePermissionForApi('portfolio.manage')"),
                strpos($source, "REQUEST_METHOD'] !== 'POST'"),
                strpos($source, 'Csrf::validate('),
            ];

            $this->assertNotContains(false, $positions, $file . ' is missing one of the four guards');

            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions, $file . ' must check login, permission, POST and CSRF in that order');
        }
    }

    /* ------------------------------------------------------------------ */
    /* The public side                                                     */
    /* ------------------------------------------------------------------ */

    /** Both templates refuse before they read a session, a page or an item — an old project address's redirect included. */
    public function testThePublicTemplatesRefuseBeforeTheyReadAnything(): void
    {
        foreach (['portfolio.php' => 'PublicFormSession::prime', 'portfolio-detail.php' => 'legacyProjectRedirectUrl('] as $file => $firstRead) {
            $source = self::withoutComments(self::sourceOf($file));
            $guard = strpos($source, "ModuleGuard::requirePublicRoute('portfolio')");

            $this->assertNotFalse($guard, $file . ' must refuse while the Portfolio is off');
            $this->assertLessThan((int) strpos($source, $firstRead), $guard, $file . ' must refuse before it reads anything');
        }
    }

    /**
     * An old project address with a published page linked is answered with a
     * TEMPORARY redirect before anything of the old page is read or rendered:
     * the address is a compatibility route and the link behind it may still be
     * changed or removed, so nothing may remember it as permanent. How the
     * target is found is Tests\Service\PortfolioProjectPageTest; that it really
     * answers 302 is Tests\Module\PortfolioModuleHttpTest.
     */
    public function testAnOldProjectAddressRedirectsTemporarilyBeforeTheOldPageIsRead(): void
    {
        $source = self::withoutComments(self::sourceOf('portfolio-detail.php'));
        $redirect = strpos($source, 'legacyProjectRedirectUrl($slug)');
        $oldPage = strpos($source, 'itemForDetailPage($slug)');

        $this->assertNotFalse($redirect);
        $this->assertNotFalse($oldPage);
        $this->assertLessThan($oldPage, $redirect);

        $this->assertSame(
            1,
            preg_match('#if \(\$projectPageUrl !== null\) \{\s*(header\([^;]*\);)\s*exit;\s*\}#', $source, $match),
            'a redirect that stops the template right there'
        );
        $this->assertSame("header('Location: ' . \$projectPageUrl, true, \\App\\Service\\Redirects\\Redirect::STATUS_TEMPORARY);", $match[1]);
        $this->assertSame(302, \App\Service\Redirects\Redirect::STATUS_TEMPORARY, "the CMS's own temporary code");
    }

    /**
     * /portfolio serves the CMS page with content key "portfolio" where one
     * exists, and while the module is off that URL, the old /portfolio.php
     * and every project address answer 404. The sitemap
     * (PageSeo::isIndexable()), a menu link (LinkResolver) and a redirect
     * (RedirectTarget) all ask this one question.
     *
     * A link-picker route it becomes only while NO page is the overview: then
     * the module's own overview is what the module guarantees, like /blog.
     * Where the page exists it is linked as a page, never as a route
     * (App\Service\RouteRegistry), so the picker never offers /portfolio twice.
     */
    public function testThePortfolioPageBelongsToTheModuleAndTheRootIsARouteOnlyWithoutOne(): void
    {
        $page = ['id' => 0, 'content_key' => 'portfolio', 'route_path' => '/portfolio'];
        $hasPage = \App\Service\PortfolioUrls::overviewPage() !== null;

        $this->withPortfolio(false);
        foreach (['/portfolio', '/portfolio.php', '/portfolio-detail.php'] as $path) {
            $this->assertSame('portfolio', ModuleRegistry::disabledModuleForRoutePath($path), $path);
        }
        $this->assertFalse(PageContent::isServedByAnEnabledModule($page));
        $this->assertFalse(RouteRegistry::exists('portfolio'), 'a switched-off module offers no route');

        $this->withPortfolio(true);
        $this->assertNull(ModuleRegistry::disabledModuleForRoutePath('/portfolio'));
        $this->assertNull(ModuleRegistry::disabledModuleForRoutePath('/portfolio.php'));
        $this->assertTrue(PageContent::isServedByAnEnabledModule($page));
        $this->assertSame(!$hasPage, RouteRegistry::exists('portfolio'), 'a route exactly while no page is the overview');
        if (!$hasPage) {
            $this->assertSame('/portfolio', RouteRegistry::url('portfolio'));
        }
    }

    public function testThePortfolioSitemapCollectorFollowsTheModule(): void
    {
        $this->withPortfolio(false);
        $this->assertArrayNotHasKey('portfolio', ModuleRegistry::collectMap('sitemapCollectors'));

        $this->withPortfolio(true);
        $this->assertArrayHasKey('portfolio', ModuleRegistry::collectMap('sitemapCollectors'));
    }

    /**
     * The one thing a disabled module keeps: its reserved slugs.
     * portfolio.php is still on disk, so a CMS page named "portfolio" would be
     * permanently unreachable behind it.
     */
    public function testThePortfolioSlugsStayReservedWhileTheModuleIsOff(): void
    {
        $this->withPortfolio(false);

        foreach (['portfolio', 'portfolio-detail'] as $slug) {
            $this->assertTrue(ReservedRoutes::isReserved($slug), $slug . ' must stay reserved');
        }
    }

    public function testThePortfolioAddsNothingToTheSiteShell(): void
    {
        $module = new PortfolioModule();
        $this->assertSame([], $module->shellStyles());
        $this->assertSame([], $module->shellScripts());
        $this->assertSame([], $module->headerPartials());

        foreach ([true, false] as $enabled) {
            $this->withPortfolio($enabled);
            PageAssets::reset();

            foreach (PageAssets::collected() as $group) {
                foreach ((array) $group as $path) {
                    $this->assertStringNotContainsString('portfolio', (string) $path);
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* The gallery block                                                   */
    /* ------------------------------------------------------------------ */

    public function testPortfolioItemsAreAGallerySourceOnlyWhileTheModuleRuns(): void
    {
        $this->withPortfolio(true);
        $this->assertTrue(ItemGallerySources::isAvailable(PortfolioModule::GALLERY_SOURCE));
        $this->assertSame(
            PortfolioModule::GALLERY_SOURCE,
            ItemGallerySources::defaultSource(),
            'a new gallery block still starts as the portfolio grid'
        );
        $this->assertTrue(ItemGallerySources::needsScope(PortfolioModule::GALLERY_SOURCE));

        $this->withPortfolio(false);
        $this->assertFalse(ItemGallerySources::isAvailable(PortfolioModule::GALLERY_SOURCE));
        $this->assertTrue(
            ItemGallerySources::isKnown(PortfolioModule::GALLERY_SOURCE),
            'a block set to it is recognised and kept, not rewritten'
        );
        $this->assertSame('portfolio', ItemGallerySources::moduleOwnerOf(PortfolioModule::GALLERY_SOURCE));
        $this->assertSame([], ItemGallerySources::items(PortfolioModule::GALLERY_SOURCE, ['portfolio_scope' => 'all']));
        $this->assertSame([], ItemGallerySources::filterCategories(PortfolioModule::GALLERY_SOURCE));
        $this->assertNotSame(PortfolioModule::GALLERY_SOURCE, ItemGallerySources::defaultSource());
    }

    /**
     * With neither the Portfolio nor the Shop there is nothing a gallery block
     * could show, so the picker does not offer one. The block type itself is
     * Core and stays registered, so every existing instance keeps its settings
     * for the day a module comes back.
     */
    public function testTheGalleryBlockIsNotOfferedWhenNoModuleHasASource(): void
    {
        $this->withPortfolio(false, shop: false);

        $this->assertSame([], ItemGallerySources::available());
        $this->assertSame('', ItemGallerySources::defaultSource());
        $this->assertTrue(SectionRegistry::exists('item_gallery'));
        $this->assertFalse(SectionRegistry::isManuallyAddable('item_gallery'));

        $this->withPortfolio(true, shop: false);
        $this->assertTrue(SectionRegistry::isManuallyAddable('item_gallery'));
    }

    /* ------------------------------------------------------------------ */
    /* The Projecten block                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The Portfolio's own block for a page follows the module the way its
     * gallery source does: offered while the module runs, simply absent while
     * it is off, and then recognised as a switched-off part's block rather
     * than as unknown data, so the rows it placed are kept and named for what
     * they are (CONTENT-BLOCKS.md). What that does to a stored block, over the
     * database, is Tests\Service\ProjectCardsBlockTest.
     */
    public function testTheProjectsBlockIsOfferedOnlyWhileThePortfolioRuns(): void
    {
        $this->withPortfolio(true);

        $this->assertTrue(BlockDefinitions::has('project_cards'));
        $this->assertSame('portfolio', BlockDefinitions::moduleOwnerOf('project_cards'));
        $this->assertTrue(SectionRegistry::isManuallyAddable('project_cards'));
        $this->assertTrue(SectionRegistry::allowMultiple('project_cards'));
        $this->assertSame('Projecten', (new ProjectCardsBlock())->meta()['label']);
        $this->assertSame(BlockCategories::MEDIA, (new ProjectCardsBlock())->category(), 'next to the gallery it is built on');

        $this->withPortfolio(false);

        $this->assertFalse(BlockDefinitions::has('project_cards'));
        $this->assertFalse(SectionRegistry::isManuallyAddable('project_cards'));
        $this->assertArrayNotHasKey('project_cards', SectionRegistry::types());
        $this->assertSame('portfolio', SectionRegistry::disabledModuleFor('project_cards'), 'a switched-off part, not broken data');
        $this->assertTrue(
            SectionRegistry::isManuallyAddable('item_gallery'),
            'the gallery is Core, and with the Shop on it still has a source to offer'
        );
    }

    /**
     * Whatever a save hands it, a Projecten row names the Portfolio's source
     * and none of the gallery settings this block leaves out, so no request
     * can turn it into a collection gallery or give it a zoom, a fallback link
     * or a button nobody could switch off again.
     */
    public function testAProjectsRowAlwaysNamesThePortfolioAndNoGallerySettingItLeavesOut(): void
    {
        $row = ProjectCardsBlock::rowValues([
            'portfolio_scope' => ItemGalleryContent::SCOPE_FEATURED,
            'max_items' => '6',
            'show_filter_bar' => true,
            'background' => 'soft',
            'is_active' => false,
            // What a crafted request might carry. None of it is read.
            'source_type' => 'collection',
            'collection_id' => 3,
            'enable_lightbox' => true,
            'fallback_link_url' => '/elders',
            'button_label_nl' => 'Klik',
            'button_url' => '/elders',
            'eyebrow_nl' => 'Boven',
            'footer_note_nl' => 'Onder',
            'tight_top' => true,
        ]);

        $this->assertSame(PortfolioModule::GALLERY_SOURCE, $row['source_type']);
        $this->assertNull($row['collection_id']);
        $this->assertFalse($row['enable_lightbox']);
        $this->assertFalse($row['tight_top']);
        foreach (['fallback_link_url', 'button_url'] as $leftOut) {
            $this->assertSame('', $row[$leftOut], $leftOut);
        }
        foreach (['button_label_nl', 'eyebrow_nl', 'footer_note_nl', 'title_nl', 'lead_nl'] as $words) {
            $this->assertArrayNotHasKey($words, $row, 'the words are stored per website language, not in the row');
        }

        // The words of one language: the title and the lead, and every other
        // gallery word empty, whatever a crafted request carries.
        $this->assertSame(
            ['eyebrow' => '', 'title' => 'Werk', 'lead' => 'Een greep', 'footer_note' => '', 'button_label' => ''],
            ProjectCardsBlock::rowWords([
                'title' => 'Werk',
                'lead' => 'Een greep',
                'eyebrow' => 'Boven',
                'footer_note' => 'Onder',
                'button_label' => 'Klik',
            ])
        );

        $this->assertSame(ItemGalleryContent::SCOPE_FEATURED, $row['portfolio_scope']);
        $this->assertSame(6, $row['max_items']);
        $this->assertTrue($row['show_filter_bar']);
        $this->assertSame('soft', $row['background']);
        $this->assertFalse($row['is_active']);

        $this->assertSame(
            [
                'source_type' => PortfolioModule::GALLERY_SOURCE,
                'portfolio_scope' => ItemGalleryContent::SCOPE_ALL,
                'max_items' => null,
                'show_filter_bar' => false,
            ],
            array_intersect_key(ProjectCardsBlock::rowValues([]), array_flip(['source_type', 'portfolio_scope', 'max_items', 'show_filter_bar'])),
            'a new block: every visible project, no maximum, no filter buttons'
        );
    }

    /**
     * No second gallery. The block stores, reads and draws through the gallery
     * block's own classes and files, and reaches a project only through the
     * Portfolio's gallery source, so none of its files may grow a query, a
     * card or a link of its own.
     */
    public function testTheProjectsBlockHasNoQueryCardOrLinkOfItsOwn(): void
    {
        foreach (['src/Service/Blocks/ProjectCardsBlock.php', 'admin/project-cards.php', 'api/admin/update-project-cards.php'] as $file) {
            $source = self::withoutComments(self::sourceOf($file));

            foreach (
                [
                    'PortfolioGalleryRepository', 'PortfolioCategoryRepository', 'PortfolioItemImageRepository',
                    'PortfolioGalleryContent', 'portfolio_gallery_items', 'portfolio_categories',
                    'findPublishedByIds', 'publicUrl(', 'canonicalUrl(', 'SELECT ', '->prepare(',
                ] as $name
            ) {
                $this->assertStringNotContainsString(
                    $name,
                    $source,
                    $file . ' must reach projects through the gallery source, not through ' . $name
                );
            }
        }

        $block = self::withoutComments(self::sourceOf('src/Service/Blocks/ProjectCardsBlock.php'));
        $this->assertStringContainsString('ItemGalleryContent::forSection(', $block);
        $this->assertStringContainsString('render_section_item_gallery($content, $revealGroup)', $block);
        $this->assertStringNotContainsString('gallery-item', $block, "the card markup is the gallery partial's");
        $this->assertStringNotContainsString('<section', $block);

        $projects = new ProjectCardsBlock();
        $gallery = new ItemGalleryBlock();
        $this->assertSame($gallery->contentTable(), $projects->contentTable(), 'the same rows');
        $this->assertSame($gallery->styles(), $projects->styles(), 'the same stylesheet');
        $this->assertSame($gallery->scripts(), $projects->scripts(), 'the same script');
    }

    /**
     * The editor and its endpoint are page-builder screens, guarded like every
     * block editor. On top of that they refuse while the Portfolio is off, and
     * refuse a row another block placed. The gallery's editor refuses a
     * Projecten row in turn, so neither can rewrite the other's blocks.
     */
    public function testTheProjectsEditorGuardsLikeEveryBlockEditorAndEditsOnlyItsOwnRows(): void
    {
        $editor = self::withoutComments(self::sourceOf('admin/project-cards.php'));
        $positions = [
            strpos($editor, 'AdminAuth::requireLogin()'),
            strpos($editor, "AdminAuth::requirePermission('pages.manage')"),
            strpos($editor, "SectionRegistry::exists('project_cards')"),
            strpos($editor, 'Repository('),
            strpos($editor, "findBySectionTypeAndId('project_cards'"),
            strpos($editor, '<!doctype html>'),
        ];
        $this->assertNotContains(false, $positions, 'admin/project-cards.php is missing a guard');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'admin/project-cards.php: login, permission, the module, then its own row, before anything renders');

        $endpoint = self::withoutComments(self::sourceOf('api/admin/update-project-cards.php'));
        $positions = [
            strpos($endpoint, 'AdminAuth::requireLoginForApi()'),
            strpos($endpoint, "requirePermissionForApi('pages.manage')"),
            strpos($endpoint, "REQUEST_METHOD'] !== 'POST'"),
            strpos($endpoint, 'Csrf::validate('),
            strpos($endpoint, "SectionRegistry::exists('project_cards')"),
            strpos($endpoint, "findBySectionTypeAndId('project_cards'"),
            strpos($endpoint, 'ItemGalleryContent::isPortfolioScope('),
            strpos($endpoint, 'ProjectCardsBlock::rowValues('),
        ];
        $this->assertNotContains(false, $positions, 'api/admin/update-project-cards.php is missing a guard');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'api/admin/update-project-cards.php: the four guards, the module, its own row, validation, then the save');
        $this->assertStringNotContainsString('source_type', $endpoint, 'the source is never read from the request');

        foreach (['admin/item-gallery.php', 'api/admin/update-item-gallery.php'] as $file) {
            $this->assertStringContainsString(
                "findBySectionTypeAndId('item_gallery'",
                self::withoutComments(self::sourceOf($file)),
                $file . ' must edit only the rows a gallery block placed'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* The boundary, read from the source                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Core reaches the Portfolio through a module contribution or not at all.
     * Same shape as the Shop's and the Blog's equivalents, for the same
     * reason: the coupling this removes is exactly the coupling that grows back.
     */
    public function testCoreIntegrationClassesNameNoPortfolioImplementation(): void
    {
        $forbidden = [
            'PortfolioGalleryRepository', 'PortfolioCategoryRepository', 'PortfolioItemImageRepository',
            'PortfolioGalleryContent', 'PortfolioImageProcessor', 'portfolio_gallery_items', 'portfolio_categories',
        ];

        $files = [
            'src/Service/Sitemap.php',
            'src/Service/SeoMetadata.php',
            'src/Service/SeoDefaults.php',
            'src/Service/PageSeo.php',
            'src/Service/Robots.php',
            'partials/seo-head.php',
            'src/Service/AdminNavigation.php',
            'src/Service/AdminPermissions.php',
            'src/Service/RouteRegistry.php',
            'src/Service/ReservedRoutes.php',
            'src/Service/PageAssets.php',
            'src/Service/Blocks/BlockDefinitions.php',
            'src/Service/SectionRegistry.php',
            'src/Service/ItemGallerySources.php',
            'src/Service/ItemGalleryContent.php',
            'src/Service/Blocks/ItemGalleryBlock.php',
            'admin/item-gallery.php',
            'api/admin/update-item-gallery.php',
            'src/Module/ModuleConfig.php',
            'src/Module/ModuleRegistry.php',
            'admin/index.php',
            'partials/header.php',
        ];

        foreach ($files as $file) {
            $source = self::withoutComments(self::sourceOf($file));

            foreach ($forbidden as $name) {
                $this->assertStringNotContainsString(
                    $name,
                    $source,
                    $file . ' must reach the Portfolio through a module contribution, not by naming ' . $name
                );
            }
        }
    }

    /** Nor do Core's registries carry the Portfolio's keys: those are the module's words. */
    public function testCoreRegistriesNameNoPortfolioKey(): void
    {
        foreach (
            [
                'src/Service/Sitemap.php',
                'src/Service/AdminNavigation.php',
                'src/Service/AdminPermissions.php',
                'src/Service/ReservedRoutes.php',
                'src/Service/ItemGallerySources.php',
                'src/Service/ItemGalleryContent.php',
                'src/Service/Blocks/ItemGalleryBlock.php',
            ] as $file
        ) {
            $source = self::withoutComments(self::sourceOf($file));

            $this->assertStringNotContainsString("'portfolio'", $source, $file);
            $this->assertStringNotContainsString("'portfolio.manage'", $source, $file);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every Portfolio write endpoint: the files named after it, plus the one
     * that reorders the homepage selection and was named after the gallery.
     *
     * @return list<string>
     */
    private static function endpoints(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/api/admin/*portfolio*.php') ?: [];

        $endpoints = [];
        foreach ($files as $path) {
            if (!str_starts_with(basename($path), '_')) {
                $endpoints[] = 'api/admin/' . basename($path);
            }
        }

        $endpoints[] = 'api/admin/move-featured-gallery-item.php';

        return $endpoints;
    }

    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
