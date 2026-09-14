<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Module\BlogModule;
use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\ModuleSettings;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blog\BlogUrls;
use App\Service\Media\MediaUsageRegistry;
use App\Service\PageAssets;
use App\Service\ReservedRoutes;
use App\Service\RouteRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What the CMS looks like from the inside with the Blog on and with it off,
 * and that Core never learns what a blog post is.
 *
 * Everything here is a registry or a source question, so it needs no database
 * and no web server (tier `fast`). The same behaviour over real HTTP is
 * Tests\Blog\BlogRoutingTest.
 */
final class BlogModuleTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnvironment = [];

    /**
     * The configuration tests below are about steps 2 and 3 of the precedence
     * chain, so step 1 has to be out of the way: this suite runs in a
     * container that asks for MODULE_BLOG_ENABLED=true (docker-compose.yml),
     * and the environment rightly wins over everything. It is put back in
     * tearDown(), the same seam Tests\Module\ModuleConfigurationTest uses.
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

    /**
     * And the other half of that: an environment variable still overrules
     * both the stored preference and the module's own default, which is what
     * lets a deployment pin the Blog on or off whatever the CMS says.
     */
    public function testTheEnvironmentStillOverridesTheStoredPreferenceAndTheDefault(): void
    {
        ModuleSettings::overrideForTests(['blog' => false]);

        $_ENV['MODULE_BLOG_ENABLED'] = 'true';
        $this->assertTrue(ModuleConfig::wants('blog'));
        $this->assertTrue(ModuleConfig::isPinnedByEnvironment('blog'));

        $_ENV['MODULE_BLOG_ENABLED'] = 'false';
        ModuleSettings::overrideForTests(['blog' => true]);
        $this->assertFalse(ModuleConfig::wants('blog'));
    }

    private function withBlogOn(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'portfolio' => true]);
    }

    private function withBlogOff(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => false, 'portfolio' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Registration and the default                                        */
    /* ------------------------------------------------------------------ */

    public function testTheBlogIsARegisteredFirstPartyModule(): void
    {
        $this->assertTrue(ModuleRegistry::has('blog'));
        $this->assertContains('blog', ModuleRegistry::keys());
        $this->assertInstanceOf(BlogModule::class, ModuleRegistry::definition('blog'));
        $this->assertSame('Blog', ModuleRegistry::label('blog'));
    }

    /**
     * The Blog was the first module in this project to start OFF, and the
     * reason it could is that nothing else changed: the Shop and Personalisatie
     * keep the "enabled" default a missing variable has always meant. The
     * Portfolio starts off as well, and keeps existing sites on through a
     * stored preference instead (Tests\Install\PortfolioModulePinTest).
     */
    public function testTheBlogDefaultsToOffWhileTheShopKeepsItsEnabledDefault(): void
    {
        $defaults = [];
        foreach (ModuleRegistry::all() as $key => $module) {
            $defaults[$key] = $module->enabledByDefault();
        }

        $this->assertSame(
            ['shop' => true, 'personalization' => true, 'blog' => false, 'portfolio' => false],
            $defaults
        );
    }

    public function testWithNothingConfiguredTheBlogIsNotWanted(): void
    {
        ModuleSettings::overrideForTests([]);

        $this->assertFalse(ModuleConfig::wants('blog'));
        $this->assertTrue(ModuleConfig::wants('shop'));
    }

    /**
     * The stored preference the Setup Wizard writes still decides, so
     * switching the Blog on from the CMS works exactly like switching the
     * Shop off does — the default is only the last word, not the first.
     */
    public function testAStoredPreferenceOverridesTheModulesOwnDefault(): void
    {
        ModuleSettings::overrideForTests(['blog' => true]);
        $this->assertTrue(ModuleConfig::wants('blog'));

        ModuleSettings::overrideForTests(['blog' => false]);
        $this->assertFalse(ModuleConfig::wants('blog'));
    }

    /* ------------------------------------------------------------------ */
    /* What it contributes while it runs                                   */
    /* ------------------------------------------------------------------ */

    public function testWithTheBlogOnEverythingItOwnsIsThere(): void
    {
        $this->withBlogOn();

        $navKeys = array_column(AdminNavigation::items(), 'key');
        foreach (['blog_posts', 'blog_categories', 'blog_tags', 'blog_settings'] as $key) {
            $this->assertContains($key, $navKeys, $key . ' must be in the sidebar while the Blog runs');
        }

        $this->assertContains(BlogModule::BLOG_VIEW, AdminPermissions::enabled());
        $this->assertContains(BlogModule::BLOG_MANAGE, AdminPermissions::enabled());
        $this->assertTrue(RouteRegistry::exists('blog'));
        $this->assertSame('/blog', RouteRegistry::url('blog'));
    }

    /**
     * The four entries sit together, after Portfolio and before the orders
     * group, so the Blog reads as one area of the CMS rather than four
     * unrelated links.
     */
    public function testTheBlogEntriesSitTogetherInTheSidebar(): void
    {
        $this->withBlogOn();

        $keys = array_column(AdminNavigation::items(), 'key');
        $positions = array_flip($keys);

        $this->assertSame(
            ['blog_posts', 'blog_categories', 'blog_tags', 'blog_settings'],
            array_values(array_slice($keys, $positions['blog_posts'], 4))
        );
        $this->assertLessThan($positions['blog_posts'], $positions['portfolio']);
        $this->assertGreaterThan($positions['blog_settings'], $positions['orders']);
    }

    public function testManagingTheBlogImpliesReadingItAndUsingTheMediaLibrary(): void
    {
        $implied = AdminPermissions::expand([BlogModule::BLOG_MANAGE]);

        $this->assertContains(BlogModule::BLOG_VIEW, $implied);
        $this->assertContains(AdminPermissions::MEDIA_VIEW, $implied);
    }

    public function testTheBlogAnswersForItsOwnMediaUsage(): void
    {
        $this->withBlogOn();

        $keys = array_map(
            static fn ($provider): string => $provider->key(),
            MediaUsageRegistry::providers()
        );

        $this->assertContains('blog_post', $keys);
    }

    /* ------------------------------------------------------------------ */
    /* What disappears when it is switched off                             */
    /* ------------------------------------------------------------------ */

    public function testNoBlogEntryIsLeftInTheAdminSidebar(): void
    {
        $this->withBlogOff();

        foreach (array_column(AdminNavigation::items(), 'key') as $key) {
            $this->assertStringNotContainsString('blog', $key);
        }
    }

    public function testNobodyHoldsABlogPermissionWhileTheBlogIsOff(): void
    {
        $this->withBlogOff();

        $superAdmin = ['is_super_admin' => 1, 'permissions' => []];
        $editor = ['is_super_admin' => 0, 'permissions' => [BlogModule::BLOG_MANAGE]];

        foreach ([BlogModule::BLOG_VIEW, BlogModule::BLOG_MANAGE] as $permission) {
            $this->assertNotContains($permission, AdminPermissions::enabled());
            $this->assertFalse(AdminPermissions::userHas($superAdmin, $permission));
            $this->assertFalse(AdminPermissions::userHas($editor, $permission));

            // The NAME stays valid, so a stored grant survives untouched.
            $this->assertTrue(AdminPermissions::isValid($permission));
            $this->assertSame([$permission], AdminPermissions::sanitize([$permission]));
        }
    }

    public function testTheBlogRouteIsNotOfferedAsALinkDestination(): void
    {
        $this->withBlogOff();

        $this->assertFalse(RouteRegistry::exists('blog'));
        $this->assertNull(RouteRegistry::url('blog'));
    }

    public function testNoBlogSitemapCollectorRunsWhileTheBlogIsOff(): void
    {
        $this->withBlogOff();
        $this->assertArrayNotHasKey('blog', ModuleRegistry::collectMap('sitemapCollectors'));

        $this->withBlogOn();
        $this->assertArrayHasKey('blog', ModuleRegistry::collectMap('sitemapCollectors'));
    }

    public function testTheBlogReportsNoMediaUsageWhileItIsOff(): void
    {
        $this->withBlogOff();

        $keys = array_map(
            static fn ($provider): string => $provider->key(),
            MediaUsageRegistry::providers()
        );

        $this->assertNotContains('blog_post', $keys);
    }

    /**
     * The one thing a disabled module keeps: its reserved slugs. blog.php is
     * still on disk, so a CMS page named "blog" would be permanently
     * unreachable behind it.
     */
    public function testTheBlogSlugsStayReservedWhileTheModuleIsOff(): void
    {
        $this->withBlogOff();

        foreach ([BlogUrls::ROOT, 'blog-post', 'blog-feed'] as $slug) {
            $this->assertTrue(ReservedRoutes::isReserved($slug), $slug . ' must stay reserved');
        }
    }

    /** V1 contributes no content block, on or off. */
    public function testTheBlogContributesNoContentBlock(): void
    {
        $this->withBlogOn();
        $before = array_keys(BlockDefinitions::all());

        $this->withBlogOff();
        $after = array_keys(BlockDefinitions::all());

        $this->assertSame($before, $after);
        $this->assertSame([], (new BlogModule())->blockDefinitions());
    }

    /**
     * Blog CSS belongs to the two Blog routes, never to the site shell — the
     * rule that keeps a contact page from downloading it.
     */
    public function testNoBlogAssetIsInTheSiteShell(): void
    {
        foreach ([true, false] as $enabled) {
            ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => $enabled]);
            PageAssets::reset();

            foreach (PageAssets::collected() as $group) {
                foreach ((array) $group as $path) {
                    $this->assertStringNotContainsString('blog', (string) $path);
                }
            }
        }

        $module = new BlogModule();
        $this->assertSame([], $module->shellStyles());
        $this->assertSame([], $module->shellScripts());
    }

    /* ------------------------------------------------------------------ */
    /* The boundary, read from the source                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Core reaches the Blog through a module contribution or not at all. Same
     * shape as Tests\Module\ShopDisabledTest's equivalent, and it exists for
     * the same reason: the coupling this removes is exactly the coupling that
     * grows back.
     */
    public function testCoreIntegrationClassesNameNoBlogImplementation(): void
    {
        $forbidden = [
            'BlogPostRepository', 'BlogCategoryRepository', 'BlogTagRepository',
            'BlogSettingRepository', 'BlogContent', 'BlogSeo', 'BlogFeed',
            'BlogPostMediaUsage', 'BlogSettings', 'BlogUrls', 'blog_posts',
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
            'src/Service/Media/MediaUsageRegistry.php',
            'src/Service/Media/MediaService.php',
            'src/Module/ModuleConfig.php',
            'admin/index.php',
            'partials/header.php',
        ];

        foreach ($files as $file) {
            $source = self::withoutComments(self::sourceOf($file));

            foreach ($forbidden as $name) {
                $this->assertStringNotContainsString(
                    $name,
                    $source,
                    $file . ' must reach the Blog through a module contribution, not by naming ' . $name
                );
            }
        }
    }

    /** Core's site shell and header name no Blog asset either. */
    public function testCoreNamesNoBlogAssetPath(): void
    {
        foreach (['src/Service/PageAssets.php', 'partials/header.php'] as $file) {
            $this->assertStringNotContainsString('assets/css/blog/', self::withoutComments(self::sourceOf($file)), $file);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Guards on every screen and endpoint                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Every Blog admin screen asks for a Blog permission. That is also what
     * makes them refuse while the module is off, without a ModuleGuard call
     * in each file: a disabled module's permissions are held by nobody.
     */
    public function testEveryBlogAdminScreenRequiresABlogPermission(): void
    {
        foreach (self::adminScreens() as $file) {
            $source = self::sourceOf($file);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, $file);
            $this->assertMatchesRegularExpression(
                "/requirePermission\('blog\.(view|manage)'\)/",
                $source,
                $file . ' must require a Blog permission'
            );
        }
    }

    /** Every write endpoint: login, permission, POST-only, CSRF. */
    public function testEveryBlogEndpointGuardsItself(): void
    {
        $endpoints = self::endpoints();
        $this->assertNotSame([], $endpoints);

        foreach ($endpoints as $file) {
            $source = self::sourceOf($file);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $file);
            $this->assertStringContainsString(
                "requirePermissionForApi('blog.manage')",
                $source,
                $file . ' must require blog.manage'
            );
            $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $source, $file . ' must be POST-only');
            $this->assertStringContainsString('Csrf::validate(', $source, $file . ' must validate CSRF');
        }
    }

    /** The three public templates refuse when the module is off. */
    public function testEveryPublicBlogTemplateCallsTheModuleGuard(): void
    {
        foreach (['blog.php', 'blog-post.php', 'blog-feed.php'] as $file) {
            $this->assertStringContainsString(
                "requirePublicRoute('blog')",
                self::sourceOf($file),
                $file . ' must refuse while the Blog is switched off'
            );
        }
    }

    /**
     * The listing and the detail page ask the Redirect Manager before they
     * answer 404 — the only moment a renamed post's old URL can be rescued,
     * because Apache routed the request and 404.php never sees it.
     */
    public function testTheBlogRoutesConsultTheRedirectManagerBeforeAnswering404(): void
    {
        foreach (['blog.php', 'blog-post.php'] as $file) {
            $this->assertStringContainsString('RedirectGate::handleOr404()', self::sourceOf($file), $file);
        }
    }

    /** No Blog file writes its own <head>, sitemap or redirect machinery. */
    public function testTheBlogAddsNoSecondSeoRendererOrRedirectTable(): void
    {
        foreach (['blog.php', 'blog-post.php', 'src/Service/Blog/BlogSeo.php'] as $file) {
            $source = self::sourceOf($file);

            $this->assertStringNotContainsString('<meta name="robots"', $source, $file);
            $this->assertStringNotContainsString('rel="canonical"', $source, $file);
        }

        $this->assertStringContainsString('SeoMetadata::create', self::sourceOf('src/Service/Blog/BlogSeo.php'));
        $this->assertStringContainsString(
            'SlugChangeRedirects',
            self::sourceOf('src/Service/Blog/BlogPostService.php'),
            'a renamed post must use the existing Redirect Manager'
        );
    }

    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private static function adminScreens(): array
    {
        return ['admin/blog.php', 'admin/blog-post.php', 'admin/blog-categories.php', 'admin/blog-tags.php', 'admin/blog-settings.php'];
    }

    /** @return list<string> */
    private static function endpoints(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/api/admin/*blog*.php') ?: [];

        return array_map(static fn (string $path): string => 'api/admin/' . basename($path), $files);
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
