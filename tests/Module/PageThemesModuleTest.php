<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\PageThemesModule;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\PageThemes\PageThemeSettingsSection;
use PHPUnit\Framework\TestCase;

/**
 * THE PAGINATHEMA'S MODULE as a module (Page Themes 1.0, THEMING.md
 * "Paginathema's"): registered, off on a new installation, and contributing
 * everything through ModuleDefinition — so Core names nothing and a
 * switched-off module contributes nothing: no sidebar entry, no holdable
 * permission, no field on the page editor, no page appearance. Its screens
 * and endpoints guard with its own permission, and the product and project
 * editors never offer a theme.
 *
 * No database and no web server: the module state is pinned through
 * ModuleRegistry's test seam and the rest is read from source. What the
 * module stores and renders is PageThemesAdminHttpTest and
 * PageThemesRenderingHttpTest.
 */
final class PageThemesModuleTest extends TestCase
{
    private const SCREENS = ['admin/page-themes.php', 'admin/page-theme.php', 'admin/page-theme-preview.php'];

    private const ENDPOINTS = ['api/admin/save-page-theme.php', 'api/admin/duplicate-page-theme.php', 'api/admin/delete-page-theme.php'];

    /**
     * Core files the feature touches. None may name the module: they reach it
     * through ModuleDefinition's contributions only.
     */
    private const CORE_FILES = [
        'src/Service/PageAssets.php',
        'src/Service/Theme/PageThemeCss.php',
        'src/Service/Theme/PageAppearance.php',
        'src/Service/Theme/ThemeColor.php',
        'src/Service/PageSettingsSection.php',
        'src/Repository/PageRepository.php',
        'partials/page-head.php',
        'admin/page.php',
        'admin/theme.php',
        'api/admin/update-page.php',
        'api/admin/update-appearance-module.php',
        'pagina.php',
        'index.php',
    ];

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

    private static function source(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    public function testItIsARegisteredModuleThatANewInstallationStartsWithout(): void
    {
        $module = ModuleRegistry::definition('page_themes');

        self::assertInstanceOf(PageThemesModule::class, $module);
        self::assertSame('Paginathema\'s', $module->label());
        self::assertNotSame('', $module->description());
        self::assertFalse($module->enabledByDefault());
        self::assertSame([], $module->dependencies());
        self::assertSame('MODULE_PAGE_THEMES_ENABLED', ModuleConfig::variableName('page_themes'));
        self::assertSame('page_themes.manage', PageThemesModule::PAGE_THEMES_MANAGE, 'a stored grant must keep matching');
    }

    public function testOnlyThisModuleOffersPageAppearancesAndTheSwitchOnVormgeving(): void
    {
        foreach (ModuleRegistry::all() as $key => $module) {
            self::assertSame($key === 'page_themes', $module->switchableFromAppearance(), $key);
            self::assertSame($key === 'page_themes', $module->pageSettingsSections() !== [], $key);
            self::assertNull($module->pageAppearance(['id' => 1, 'owner_type' => null, 'page_theme_id' => null]), $key . ': no theme chosen is the site theme');
        }
    }

    public function testOnItContributesItsScreensPermissionAndPageField(): void
    {
        ModuleRegistry::overrideForTests(['page_themes' => true]);

        $item = null;
        foreach (AdminNavigation::items() as $candidate) {
            if ($candidate['key'] === 'page_themes') {
                $item = $candidate;
            }
        }

        self::assertNotNull($item);
        self::assertSame('/admin/page-themes.php', $item['url']);
        self::assertSame(815, $item['order'], 'right below Vormgeving');
        self::assertSame(['page-themes.php', 'page-theme.php', 'page-theme-preview.php'], $item['scripts']);
        self::assertContains('page_themes.manage', AdminPermissions::enabled());

        $sections = ModuleRegistry::collect('pageSettingsSections');
        self::assertCount(1, $sections);
        self::assertInstanceOf(PageThemeSettingsSection::class, $sections[0]);
        self::assertSame(['page_theme_id'], $sections[0]->fields());
    }

    public function testOffItContributesNothingAndItsPermissionIsHeldByNobody(): void
    {
        ModuleRegistry::overrideForTests(['page_themes' => false]);

        foreach (AdminNavigation::items() as $item) {
            self::assertNotSame('page_themes', $item['key']);
        }

        self::assertSame([], ModuleRegistry::collect('pageSettingsSections'));
        self::assertNotContains('page_themes.manage', AdminPermissions::enabled());
        self::assertTrue(AdminPermissions::isValid('page_themes.manage'), 'the name stays valid, so a stored grant survives');
        self::assertFalse(AdminPermissions::userHas(['is_super_admin' => true, 'permissions' => []], 'page_themes.manage'), 'not even a Super Admin');
        self::assertNull(ModuleRegistry::pageAppearance(['id' => 1, 'owner_type' => null, 'page_theme_id' => 3]));
    }

    public function testEveryScreenAndEndpointGuardsWithItsOwnPermission(): void
    {
        foreach (self::SCREENS as $screen) {
            $source = self::source($screen);
            $login = strpos($source, 'AdminAuth::requireLogin();');
            $permission = strpos($source, "AdminAuth::requirePermission('page_themes.manage');");

            self::assertNotFalse($login, $screen);
            self::assertNotFalse($permission, $screen);
            self::assertLessThan($permission, $login, $screen);
        }

        foreach (self::ENDPOINTS as $endpoint) {
            $source = self::source($endpoint);
            $order = [
                strpos($source, 'AdminAuth::requireLoginForApi();'),
                strpos($source, "AdminAuth::requirePermissionForApi('page_themes.manage');"),
                strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'"),
                strpos($source, 'Csrf::validate('),
            ];

            self::assertNotContains(false, $order, $endpoint);
            $sorted = $order;
            sort($sorted);
            self::assertSame($sorted, $order, "{$endpoint}: login, permission, POST, CSRF");
        }

        // The switch belongs to the Vormgeving screen, and so does its permission.
        $switch = self::source('api/admin/update-appearance-module.php');
        self::assertStringContainsString("AdminAuth::requirePermissionForApi('settings.manage');", $switch);
        self::assertStringContainsString('switchableFromAppearance()', $switch);
        self::assertStringContainsString('ModuleConfig::isPinnedByEnvironment(', $switch);
    }

    public function testCoreNeverNamesTheModule(): void
    {
        foreach (self::CORE_FILES as $file) {
            $source = self::source($file);

            self::assertStringNotContainsString('PageThemesModule', $source, $file);
            self::assertStringNotContainsString("'page_themes'", $source, $file);
            self::assertStringNotContainsString('page_themes.manage', $source, $file);
            self::assertStringNotContainsString('App\Service\PageThemes', $source, $file);
        }
    }

    public function testTheProductAndProjectEditorsOfferNoPageTheme(): void
    {
        foreach (['admin/product-form.php', 'admin/portfolio-item.php', 'product.php', 'portfolio-detail.php'] as $file) {
            $source = self::source($file);

            self::assertStringNotContainsString('page_theme', $source, $file);
            self::assertStringNotContainsString('pageSettingsSections', $source, $file);
        }

        // The page editor sends a product's or project's content page to its
        // owner before it renders a single field, and the save refuses one.
        $editor = self::source('admin/page.php');
        self::assertLessThan(
            strpos($editor, "collect('pageSettingsSections')"),
            strpos($editor, 'ContentPages::isContentPage($page)')
        );
        $save = self::source('api/admin/update-page.php');
        self::assertLessThan(
            strpos($save, "collect('pageSettingsSections')"),
            strpos($save, 'ContentPages::isContentPage($page)')
        );
    }

    public function testTheEnvironmentPinsTheModuleWhereTheSuiteRuns(): void
    {
        $compose = self::source('docker-compose.yml');
        self::assertStringContainsString('MODULE_PAGE_THEMES_ENABLED: "true"', $compose, 'php_test runs with the module on');
        self::assertStringContainsString('MODULE_PAGE_THEMES_ENABLED: "false"', $compose, 'php_cms proves the module off');

        $tier = self::source('tests/Support/http-tier.sh');
        self::assertStringContainsString('-e MODULE_PAGE_THEMES_ENABLED=true', $tier);
        self::assertStringContainsString('-e MODULE_PAGE_THEMES_ENABLED=false', $tier);

        self::assertStringContainsString('# MODULE_PAGE_THEMES_ENABLED=', self::source('.env.example'));
    }
}
