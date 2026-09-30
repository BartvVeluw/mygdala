<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Module\ModuleSettings;
use App\Repository\PageRepository;
use App\Repository\PageThemeRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PageThemes\PageThemeService;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\PageThemeFixture;

/**
 * Page Themes 1.0 in the CMS, over PHP's built-in server
 * (Tests\Support\BuiltInServer), three times over: with the module pinned
 * on, pinned off, and left to the CMS.
 *
 *   - managing themes: a new one starts as the site theme; create, rename
 *     (a new unique slug), duplicate ("(kopie)", "(kopie 2)"), delete an
 *     unused one; a refused save stores nothing and keeps what was typed; an
 *     unsafe colour is refused; a theme in use is not deleted and the
 *     refusal lists its pages, and the database refuses it too (RESTRICT);
 *   - the page editor's "Paginathema": choose, change, back to the site
 *     theme, an unknown id refused, a save without the field keeps it;
 *   - module off: no screen, no endpoint, no field on the page editor, and
 *     nothing stored is touched;
 *   - the switch on Vormgeving: stores the preference, refuses while the
 *     environment pins the module, and deleting nothing on the way.
 */
final class PageThemesAdminHttpTest extends TestCase
{
    private const KEY = 'zz-page-themes-admin';

    private static ?BuiltInServer $on = null;
    private static ?BuiltInServer $off = null;
    private static ?BuiltInServer $unpinned = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    /** @var array<string, string> */
    private array $moduleSettings = [];

    public static function setUpBeforeClass(): void
    {
        self::$on = BuiltInServer::start(['MODULE_PAGE_THEMES_ENABLED' => 'true']);
        self::$off = BuiltInServer::start(['MODULE_PAGE_THEMES_ENABLED' => 'false']);
        self::$unpinned = BuiltInServer::start(['MODULE_PAGE_THEMES_ENABLED' => '']);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$on, self::$off, self::$unpinned] as $server) {
            $server?->stop();
        }
        self::$on = self::$off = self::$unpinned = null;
    }

    protected function setUp(): void
    {
        foreach ([self::$on, self::$off, self::$unpinned] as $server) {
            if ($server === null || !$server->answers()) {
                $this->markTestSkipped("could not start PHP's built-in web server for this test");
            }
        }

        $this->accounts = new AdminTestSession();
        $this->moduleSettings = Database::connection()->query('SELECT setting_key, setting_value FROM module_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);

        PageThemeFixture::removeAll();
        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Paginathema beheer'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();
        PageThemeFixture::removeAll();

        $key = ModuleSettings::settingKey('page_themes');
        $db = Database::connection();
        $db->prepare('DELETE FROM module_settings WHERE setting_key = ?')->execute([$key]);
        if (isset($this->moduleSettings[$key])) {
            $db->prepare('INSERT INTO module_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')
                ->execute([$key, $this->moduleSettings[$key]]);
        }
        ModuleSettings::clearCache();

        $this->accounts->forget();
    }

    // ------------------------------------------------------------- managing

    public function testANewThemeStartsAsTheSiteThemeAndHasALivePreview(): void
    {
        ThemeSettings::clearCache();
        [$session] = $this->accounts->signIn(['page_themes.manage']);

        $editor = $this->get(self::$on, $session, '/admin/page-theme.php');

        foreach (['primary_color', 'on_primary_color', 'background_color', 'surface_color', 'text_color'] as $key) {
            self::assertMatchesRegularExpression(
                '/id="theme-' . $key . '" name="' . $key . '" value="' . preg_quote(ThemeSettings::get($key), '/') . '"/',
                $editor,
                $key . ' starts at the site theme\'s value'
            );
        }
        self::assertMatchesRegularExpression('/<option value="' . preg_quote(ThemeSettings::get('font_pairing'), '/') . '" selected>/', $editor);
        self::assertStringContainsString('src="/admin/page-theme-preview.php?primary_color=', $editor);
        self::assertStringContainsString('sandbox=""', $editor);

        $preview = self::$on->request('GET', '/admin/page-theme-preview.php?primary_color=%23FF7518&background_color=red%3B%7Dbody%7B&font_pairing=system', $session);
        self::assertSame(200, $preview['status']);
        self::assertSame("script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'", BuiltInServer::header($preview, 'Content-Security-Policy'));
        self::assertStringContainsString('<main id="main" class="page-theme-preview" data-page-theme="preview">', $preview['body']);
        self::assertStringContainsString('--color-primary: #FF7518;', $preview['body']);
        self::assertStringContainsString('--color-bg: ' . ThemeSettings::get('background_color') . ';', $preview['body'], 'a refused value shows the site theme\'s');
        self::assertStringNotContainsString('red;}', $preview['body']);
        self::assertStringContainsString('class="btn"', $preview['body']);
        self::assertStringContainsString('class="feature-card"', $preview['body']);
        self::assertStringContainsString('class="form-field"', $preview['body']);
    }

    public function testAThemeIsCreatedRenamedDuplicatedAndDeleted(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['page_themes.manage']);

        $created = $this->post(self::$on, $session, '/api/admin/save-page-theme.php', ['csrf_token' => $csrf, 'name' => PageThemeFixture::PREFIX . 'Herfst', 'font_pairing' => 'system'] + PageThemeFixture::COLORS);
        self::assertSame(302, $created['status']);
        self::assertSame('/admin/page-themes.php?done=created', $created['location']);

        $theme = $this->themeNamed('Herfst');
        self::assertSame('zz-test-herfst', $theme['slug']);
        self::assertSame('#FF7518', $theme['primary_color']);
        self::assertSame('system', $theme['font_pairing']);

        $renamed = $this->post(self::$on, $session, '/api/admin/save-page-theme.php', ['csrf_token' => $csrf, 'id' => (string) $theme['id'], 'name' => PageThemeFixture::PREFIX . 'Najaar', 'font_pairing' => 'system', 'primary_color' => '#abc'] + PageThemeFixture::COLORS);
        self::assertSame('/admin/page-themes.php?done=saved', $renamed['location']);
        $theme = $this->themeNamed('Najaar');
        self::assertSame('zz-test-najaar', $theme['slug'], 'the slug follows the name');
        self::assertSame('#AABBCC', $theme['primary_color'], 'a short colour is stored in its one canonical shape');

        $first = $this->post(self::$on, $session, '/api/admin/duplicate-page-theme.php', ['csrf_token' => $csrf, 'id' => (string) $theme['id']]);
        $copy = $this->themeNamed('Najaar (kopie)');
        self::assertSame('/admin/page-theme.php?id=' . $copy['id'] . '&duplicated=1', $first['location']);
        self::assertSame('zz-test-najaar-kopie', $copy['slug']);
        self::assertSame(array_intersect_key($theme, array_flip(PageThemeService::VALUE_FIELDS)), array_intersect_key($copy, array_flip(PageThemeService::VALUE_FIELDS)));

        $this->post(self::$on, $session, '/api/admin/duplicate-page-theme.php', ['csrf_token' => $csrf, 'id' => (string) $theme['id']]);
        self::assertSame('zz-test-najaar-kopie-2', $this->themeNamed('Najaar (kopie 2)')['slug']);

        $overview = $this->get(self::$on, $session, '/admin/page-themes.php');
        self::assertStringContainsString('ZZ Test Najaar (kopie 2)', $overview);
        // This test's own three; a theme the installation already had is not its to count.
        self::assertSame(
            3 + (int) Database::connection()->query("SELECT COUNT(*) FROM page_themes WHERE name NOT LIKE 'ZZ Test %'")->fetchColumn(),
            substr_count($overview, 'data-page-theme-row=')
        );

        $deleted = $this->post(self::$on, $session, '/api/admin/delete-page-theme.php', ['csrf_token' => $csrf, 'id' => (string) $copy['id']]);
        self::assertSame('/admin/page-themes.php?done=deleted', $deleted['location']);
        self::assertNull((new PageThemeRepository())->findById((int) $copy['id']));
    }

    public function testARefusedSaveStoresNothingAndAnUnsafeValueNeverGetsIn(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['page_themes.manage']);
        PageThemeFixture::create('Bestaand');
        $before = $this->themeCount();

        foreach (
            [
                ['name' => PageThemeFixture::PREFIX . 'Kapot', 'primary_color' => 'red;}body{display:none'],
                ['name' => PageThemeFixture::PREFIX . 'Kapot', 'text_color' => 'var(--color-bg)'],
                ['name' => PageThemeFixture::PREFIX . 'Kapot', 'font_pairing' => 'comic-sans'],
                ['name' => ''],
                ['name' => PageThemeFixture::PREFIX . 'bestaand'],
                ['name' => str_repeat('x', 81)],
            ] as $case
        ) {
            $response = $this->post(self::$on, $session, '/api/admin/save-page-theme.php', $case + ['csrf_token' => $csrf, 'font_pairing' => 'system'] + PageThemeFixture::COLORS);

            self::assertSame('/admin/page-theme.php', $response['location'], json_encode($case));
            self::assertSame($before, $this->themeCount(), 'nothing stored: ' . json_encode($case));
            self::assertNotEmpty($this->accounts->read($session, 'admin_page_theme_errors'));
        }

        // What was typed comes back, marked.
        $editor = $this->get(self::$on, $session, '/admin/page-theme.php');
        self::assertStringContainsString('value="' . str_repeat('x', 81) . '"', $editor);
        self::assertStringContainsString('aria-invalid="true"', $editor);

        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM page_themes WHERE primary_color NOT REGEXP '^#[0-9A-F]{6}$' OR text_color NOT REGEXP '^#[0-9A-F]{6}$'")->fetchColumn());
    }

    public function testAThemeInUseIsNotDeletedAndThePagesUsingItAreListed(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['page_themes.manage']);
        $themeId = PageThemeFixture::create('In gebruik');
        PageThemeFixture::assign($this->pageId, $themeId);

        $response = $this->post(self::$on, $session, '/api/admin/delete-page-theme.php', ['csrf_token' => $csrf, 'id' => (string) $themeId]);
        self::assertSame('/admin/page-themes.php', $response['location']);
        self::assertNotNull((new PageThemeRepository())->findById($themeId), 'still there');
        self::assertSame($themeId, (int) (new PageRepository())->findById($this->pageId)['page_theme_id'], 'and the page still uses it');

        $overview = $this->get(self::$on, $session, '/admin/page-themes.php');
        self::assertStringContainsString('data-page-theme-refusal', $overview);
        self::assertStringContainsString('<a href="/admin/page.php?id=' . $this->pageId . '">Paginathema beheer</a>', $overview);
        self::assertStringContainsString('Gebruikt door 1 pagina', $this->get(self::$on, $session, '/admin/page-themes.php'));

        $editor = $this->get(self::$on, $session, '/admin/page-theme.php?id=' . $themeId);
        self::assertStringContainsString('<a href="/admin/page.php?id=' . $this->pageId . '">Paginathema beheer</a>', $editor, 'the editor lists where it is used');
    }

    public function testTheDatabaseRefusesDeletingAThemeInUse(): void
    {
        $themeId = PageThemeFixture::create('Vergrendeld');
        PageThemeFixture::assign($this->pageId, $themeId);

        try {
            Database::connection()->prepare('DELETE FROM page_themes WHERE id = ?')->execute([$themeId]);
            self::fail('the foreign key must refuse');
        } catch (\PDOException $e) {
            self::assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }

        self::assertNotNull((new PageThemeRepository())->findById($themeId));
    }

    // ------------------------------------------------------------ the page

    public function testAPageChoosesChangesAndDropsItsTheme(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $autumn = PageThemeFixture::create('Herfst');
        $winter = PageThemeFixture::create('Winter');

        $editor = $this->get(self::$on, $session, '/admin/page.php?id=' . $this->pageId);
        self::assertStringContainsString('<select id="page-theme" name="page_theme_id" class="admin-select">', $editor);
        self::assertStringContainsString('<option value="0" selected>Standaard website-thema</option>', $editor);
        self::assertStringContainsString('>ZZ Test Winter</option>', $editor);

        $this->assertSaved($this->savePage(self::$on, $session, $csrf, ['page_theme_id' => (string) $autumn]));
        self::assertSame($autumn, $this->pageTheme());
        self::assertMatchesRegularExpression('/<option value="' . $autumn . '" data-swatches="[^"]+" selected>ZZ Test Herfst<\/option>/', $this->get(self::$on, $session, '/admin/page.php?id=' . $this->pageId));

        $this->assertSaved($this->savePage(self::$on, $session, $csrf, ['page_theme_id' => (string) $winter]));
        self::assertSame($winter, $this->pageTheme());

        // A save that does not carry the field keeps the choice.
        $this->assertSaved($this->savePage(self::$on, $session, $csrf, []));
        self::assertSame($winter, $this->pageTheme());

        foreach (['999999999', 'abc', '-3'] as $unknown) {
            $refused = $this->savePage(self::$on, $session, $csrf, ['page_theme_id' => $unknown, 'title' => 'Niet opgeslagen']);
            self::assertSame('/admin/page.php?id=' . $this->pageId, $refused['location'], $unknown);
            self::assertSame($winter, $this->pageTheme(), $unknown . ' is refused');
            PageLocalization::clearCache();
            self::assertSame('Paginathema beheer', PageLocalization::name($this->pageId), 'and nothing else of the save lands either');
        }

        $this->assertSaved($this->savePage(self::$on, $session, $csrf, ['page_theme_id' => '0']));
        self::assertNull($this->pageTheme(), 'back to the site theme');
    }

    public function testWithTheModuleOffNothingIsOfferedAndNothingStoredIsTouched(): void
    {
        [$pages, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$super, $superCsrf] = $this->accounts->signIn([], true);
        $themeId = PageThemeFixture::create('Bewaard');
        $other = PageThemeFixture::create('Ander');
        PageThemeFixture::assign($this->pageId, $themeId);

        self::assertStringNotContainsString('name="page_theme_id"', $this->get(self::$off, $pages, '/admin/page.php?id=' . $this->pageId));

        // A posted value is not read at all while the module is off.
        $this->assertSaved($this->savePage(self::$off, $pages, $csrf, ['page_theme_id' => (string) $other]));
        self::assertSame($themeId, $this->pageTheme());
        $this->assertSaved($this->savePage(self::$off, $pages, $csrf, ['page_theme_id' => '0']));
        self::assertSame($themeId, $this->pageTheme(), 'kept while the module is off');

        foreach (['/admin/page-themes.php', '/admin/page-theme.php?id=' . $themeId, '/admin/page-theme-preview.php'] as $screen) {
            self::assertSame(403, self::$off->request('GET', $screen, $super)['status'], $screen . ' refuses even a Super Admin');
        }
        foreach (['/api/admin/save-page-theme.php', '/api/admin/duplicate-page-theme.php', '/api/admin/delete-page-theme.php'] as $endpoint) {
            $response = $this->post(self::$off, $super, $endpoint, ['csrf_token' => $superCsrf, 'id' => (string) $other, 'name' => 'x'] + PageThemeFixture::COLORS);
            self::assertSame(403, $response['status'], $endpoint);
        }
        self::assertNotNull((new PageThemeRepository())->findById($other), 'nothing deleted');

        // And a user without the permission is refused with the module on.
        self::assertSame(403, self::$on->request('GET', '/admin/page-themes.php', $pages)['status']);
    }

    // ------------------------------------------------------------ the switch

    public function testTheSwitchOnVormgevingStoresThePreferenceAndRespectsThePin(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $themeId = PageThemeFixture::create('Blijft');
        PageThemeFixture::assign($this->pageId, $themeId);
        $key = ModuleSettings::settingKey('page_themes');

        $screen = $this->get(self::$unpinned, $session, '/admin/theme.php');
        self::assertStringContainsString('data-appearance-module="page_themes"', $screen);
        self::assertMatchesRegularExpression('/data-appearance-module="page_themes".*?name="enabled" value="1"(?: checked)?>/s', $screen, 'not disabled');

        foreach (['1' => '1', '0' => '0', 'on-again' => '1'] as $label => $posted) {
            $response = $this->post(self::$unpinned, $session, '/api/admin/update-appearance-module.php', ['csrf_token' => $csrf, 'module' => 'page_themes', 'enabled' => $posted]);
            self::assertSame('/admin/theme.php?updated=1#onderdelen', $response['location'], (string) $label);
            self::assertSame($posted, $this->storedPreference($key), (string) $label);
        }
        self::assertNotNull((new PageThemeRepository())->findById($themeId), 'switching deletes no theme');
        self::assertSame($themeId, $this->pageTheme(), 'and no page\'s choice');

        // Pinned by the environment: shown as such, and refused.
        $pinned = $this->get(self::$on, $session, '/admin/theme.php');
        self::assertStringContainsString('MODULE_PAGE_THEMES_ENABLED', $pinned);
        self::assertMatchesRegularExpression('/data-appearance-module="page_themes".*?name="enabled" value="1" checked disabled>/s', $pinned);
        $before = $this->storedPreference($key);
        $refused = $this->post(self::$on, $session, '/api/admin/update-appearance-module.php', ['csrf_token' => $csrf, 'module' => 'page_themes', 'enabled' => '0']);
        self::assertSame('/admin/theme.php#onderdelen', $refused['location']);
        self::assertSame($before, $this->storedPreference($key));

        // Only a module that offers itself here can be switched here.
        foreach (['shop', 'multilingual', 'App\Module\ShopModule', ''] as $module) {
            $response = $this->post(self::$unpinned, $session, '/api/admin/update-appearance-module.php', ['csrf_token' => $csrf, 'module' => $module, 'enabled' => '0']);
            self::assertSame(404, $response['status'], $module);
        }
    }

    // ------------------------------------------------------------ helpers

    private function get(BuiltInServer $server, string $session, string $path): string
    {
        $response = $server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(BuiltInServer $server, string $session, string $path, array $fields): array
    {
        return $server->request('POST', $path, $session, $fields);
    }

    /**
     * @param array<string, string> $overrides
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function savePage(BuiltInServer $server, string $session, string $csrf, array $overrides): array
    {
        return $this->post($server, $session, '/api/admin/update-page.php', array_merge([
            'csrf_token' => $csrf,
            'id' => (string) $this->pageId,
            'language_code' => PageLocalization::defaultLanguage(),
            'title' => 'Paginathema beheer',
            'slug' => self::KEY,
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => '',
            'meta_description' => '',
            'noindex' => '0',
            'show_breadcrumb' => '1',
        ], $overrides));
    }

    /** @param array{status: int, location: string} $response */
    private function assertSaved(array $response): void
    {
        self::assertSame(302, $response['status']);
        self::assertSame('/admin/page.php?id=' . $this->pageId . '&updated=1', $response['location']);
    }

    private function pageTheme(): ?int
    {
        $value = (new PageRepository())->findById($this->pageId)['page_theme_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @return array<string, mixed> */
    private function themeNamed(string $name): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM page_themes WHERE name = ?');
        $stmt->execute([PageThemeFixture::PREFIX . $name]);
        $row = $stmt->fetch();
        self::assertIsArray($row, $name);

        return $row;
    }

    private function themeCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM page_themes')->fetchColumn();
    }

    private function storedPreference(string $key): ?string
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM module_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageThemeFixture::assign((int) $page['id'], null);
            PageService::delete($page);
        }

        PageContent::clearCache();
        PageLocalization::clearCache();
    }
}
