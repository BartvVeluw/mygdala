<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\ThemeSettingRepository;
use App\Service\AdminPermissions;
use App\Service\Theme\ThemeRegistry;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Global Theme picker on Vormgeving, over PHP's built-in server
 * (Tests\Support\BuiltInServer) with the site's own routing — the whole
 * editor flow, against the test database (THEMING.md, "Theme kiezen"):
 *
 *   - the tab Thema lists every theme of ThemeRegistry::all(), marks the one
 *     the website uses, and frames a preview of it;
 *   - no stored row = Klassiek active without a warning; `minimal` stored =
 *     Minimal active; an unknown stored key = a warning that names it, the
 *     site on Klassiek, and the row left exactly as it is until the editor
 *     chooses;
 *   - activating changes the public site at once and back again, and stores
 *     `legacy` explicitly;
 *   - a switch changes `active_theme` and nothing else (palettes, fonts,
 *     button styles, page themes, blocks, pages, navigation, footer, Shop,
 *     modules);
 *   - the endpoint: POST only, signed in, settings.manage, CSRF, and only a
 *     registered key — never a lookalike, path, URL or markup;
 *   - the preview: admin-only, request-local (the stored key and the public
 *     site never move), in both directions, sandboxed without
 *     allow-same-origin, and no public address takes a theme override.
 */
final class GlobalThemePickerHttpTest extends TestCase
{
    private const ENDPOINT = '/api/admin/save-active-theme.php';
    private const MINIMAL_CSS = 'assets/css/themes/minimal.css';

    /**
     * The tables a theme switch must leave alone, one per thing an owner has
     * designed or written: palettes, the Font Library and its roles, button
     * styles, page themes, Extra vormgeving (on page_sections) and block
     * content, pages, navigation, footer, site settings, the Shop and the
     * module switches. theme_settings itself is compared without its
     * active_theme row.
     */
    private const UNTOUCHED_TABLES = [
        'color_palettes', 'font_families', 'font_files', 'theme_font_roles',
        'button_styles', 'button_style_defaults', 'page_themes',
        'page_sections', 'block_translations', 'content_block_drafts', 'homepage_hero',
        'pages', 'page_translations', 'nav_items', 'nav_item_translations',
        'footer_columns', 'footer_column_translations', 'footer_links', 'footer_link_translations', 'footer_social_links',
        'site_settings', 'site_setting_translations',
        'products', 'product_variants', 'collections', 'module_settings', 'admin_settings',
    ];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** The stored active_theme row before the test, null for none. */
    private ?string $before = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(
            ['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true', 'MODULE_MULTILINGUAL_ENABLED' => 'true', 'MODULE_PAGE_THEMES_ENABLED' => 'true'],
            'tests/Support/dispatcher-router.php'
        );
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

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true, 'page_themes' => true]);
        $this->accounts = new AdminTestSession();
        $this->before = (new ThemeSettingRepository())->findAll()[ThemeSettings::ACTIVE_THEME_KEY] ?? null;
        $this->storeRow(null);
    }

    protected function tearDown(): void
    {
        $this->storeRow($this->before);
        $this->accounts->forget();
        ModuleRegistry::overrideForTests(null);
        ThemeSettings::clearCache();
    }

    // ------------------------------------------------------------- picker

    public function testWithoutAStoredThemeKlassiekIsActiveWithoutAWarning(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $screen = $this->get($session, '/admin/theme.php');

        // Every registered theme, in the registry's order, and nothing else.
        preg_match_all('/data-theme-row="([^"]+)"/', $screen, $rows);
        self::assertSame(array_keys(ThemeRegistry::all()), $rows[1]);
        self::assertSame(['legacy', 'minimal'], $rows[1]);
        self::assertMatchesRegularExpression('/data-theme-row="legacy".*?admin-section-row__name">\s*Klassiek\s*</s', $screen);
        self::assertMatchesRegularExpression('/data-theme-row="minimal".*?admin-section-row__name">\s*Minimal\s*</s', $screen);

        self::assertMatchesRegularExpression('/data-theme-row="legacy" data-theme-active>.*?data-theme-status="active"/s', $screen);
        self::assertSame(1, substr_count($screen, 'data-theme-status="active"'));
        self::assertStringNotContainsString('data-themes-unknown', $screen);

        // The active theme offers no Activeren; the other one does. Both preview.
        self::assertSame(['minimal'], $this->activatable($screen));
        self::assertStringContainsString('href="/admin/theme-preview.php?theme=legacy"', $screen);
        self::assertStringContainsString('href="/admin/theme-preview.php?theme=minimal"', $screen);

        // The tab comes first, and the frame shows the active theme.
        self::assertLessThan((int) strpos($screen, 'id="paletten"'), (int) strpos($screen, 'id="thema"'));
        self::assertStringContainsString('data-admin-tabs-default="thema"', $screen);
        preg_match_all('/data-admin-tab="([^"]+)"/', $screen, $tabs);
        self::assertSame(['thema', 'stijl', 'lettertypen', 'knoppen'], $tabs[1]);
        $frame = $this->previewFrame($screen);
        self::assertStringContainsString('src="/admin/theme-preview.php?theme=legacy"', $frame);
        self::assertStringContainsString('name="theme-preview"', $frame);
        self::assertStringContainsString('sandbox="allow-scripts"', $frame);
        self::assertStringNotContainsString('allow-same-origin', $frame);
        self::assertStringNotContainsString('allow-forms', $frame);

        // The theme styles the website, never this screen.
        self::assertStringNotContainsString(self::MINIMAL_CSS, $screen);
        self::assertNull($this->storedRow(), 'looking at the picker stores nothing');
    }

    public function testAStoredMinimalIsShownAsActive(): void
    {
        $this->storeRow('minimal');
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $screen = $this->get($session, '/admin/theme.php');

        self::assertMatchesRegularExpression('/data-theme-row="minimal" data-theme-active>.*?data-theme-status="active"/s', $screen);
        self::assertSame(['legacy'], $this->activatable($screen));
        self::assertStringContainsString('src="/admin/theme-preview.php?theme=minimal"', $this->previewFrame($screen));
        self::assertStringNotContainsString('data-themes-unknown', $screen);
        self::assertStringNotContainsString(self::MINIMAL_CSS, $screen, 'the admin is not dressed in the website theme');
    }

    public function testAnUnknownStoredKeyWarnsRunsOnKlassiekAndIsLeftUntilTheEditorChooses(): void
    {
        $this->storeRow('kobold');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $screen = $this->get($session, '/admin/theme.php');

        self::assertMatchesRegularExpression('/data-themes-unknown>[^<]*‘kobold’[^<]*Klassiek/u', $screen);
        self::assertMatchesRegularExpression('/data-theme-row="legacy" data-theme-active>/', $screen, 'the fallback in use is what is shown as active');
        self::assertSame(['legacy', 'minimal'], $this->activatable($screen), 'both can be chosen, Klassiek included');
        self::assertSame('kobold', $this->storedRow(), 'no automatic repair');
        self::assertStringNotContainsString(self::MINIMAL_CSS, $this->publicHome());
        self::assertSame('kobold', $this->storedRow());

        $saved = $this->post($session, ['csrf_token' => $csrf, 'theme' => 'minimal']);
        self::assertSame(302, $saved['status']);
        self::assertSame('minimal', $this->storedRow(), 'the editor\'s choice overwrites the unknown key');
        self::assertStringNotContainsString('data-themes-unknown', $this->get($session, '/admin/theme.php'));
    }

    // ------------------------------------------------------------- saving

    public function testActivatingChangesThePublicSiteAtOnceAndKlassiekIsStoredExplicitly(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        self::assertStringNotContainsString(self::MINIMAL_CSS, $this->publicHome());

        $toMinimal = $this->post($session, ['csrf_token' => $csrf, 'theme' => 'minimal']);
        self::assertSame(302, $toMinimal['status']);
        self::assertSame('/admin/theme.php?themes=activated&tab=thema#thema', $toMinimal['location']);
        self::assertSame('minimal', $this->storedRow());
        self::assertStringContainsString(self::MINIMAL_CSS, $this->publicHome(), 'no build, restart or cache clear');

        $screen = $this->get($session, '/admin/theme.php?themes=activated&tab=thema');
        self::assertMatchesRegularExpression('/data-themes-notice>Thema ‘Minimal’ is geactiveerd/u', $screen);
        self::assertMatchesRegularExpression('/data-theme-row="minimal" data-theme-active>/', $screen);

        $toLegacy = $this->post($session, ['csrf_token' => $csrf, 'theme' => 'legacy']);
        self::assertSame(302, $toLegacy['status']);
        self::assertSame('legacy', $this->storedRow(), 'Klassiek is a stored choice, not a deleted row');
        self::assertStringNotContainsString(self::MINIMAL_CSS, $this->publicHome());
    }

    public function testASwitchChangesActiveThemeAndNothingElse(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $this->storeRow('legacy');
        $before = $this->snapshot();

        $this->post($session, ['csrf_token' => $csrf, 'theme' => 'minimal']);
        self::assertSame('minimal', $this->storedRow());
        self::assertSame($before, $this->snapshot(), 'legacy -> minimal');

        $this->post($session, ['csrf_token' => $csrf, 'theme' => 'legacy']);
        self::assertSame('legacy', $this->storedRow());
        self::assertSame($before, $this->snapshot(), 'minimal -> legacy');
    }

    public function testTheEndpointRefusesEverythingButAGuardedPostOfARegisteredKey(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        [$editor, $editorCsrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $server = $this->server();

        self::assertSame(405, $server->request('GET', self::ENDPOINT . '?theme=minimal', $session)['status'], 'GET');
        self::assertSame(401, $server->request('POST', self::ENDPOINT, null, ['csrf_token' => $csrf, 'theme' => 'minimal'])['status'], 'not signed in');
        self::assertSame(403, $this->post($editor, ['csrf_token' => $editorCsrf, 'theme' => 'minimal'])['status'], 'without settings.manage');
        self::assertSame(403, $this->post($session, ['theme' => 'minimal'])['status'], 'without a CSRF token');
        self::assertSame(403, $this->post($session, ['csrf_token' => 'fout', 'theme' => 'minimal'])['status'], 'with a wrong CSRF token');
        self::assertSame(404, $this->post($session, ['csrf_token' => $csrf])['status'], 'without a key');

        foreach (['', 'kobold', 'LEGACY', 'Minimal', 'minimal.css', 'assets/css/themes/minimal.css', '../../evil', 'https://evil.test/x.css', '<style>', ' minimal', 'minimal '] as $key) {
            self::assertSame(404, $this->post($session, ['csrf_token' => $csrf, 'theme' => $key])['status'], json_encode($key));
        }
        self::assertSame(404, $server->request('POST', self::ENDPOINT, $session, ['csrf_token' => $csrf, 'theme[]' => 'minimal'])['status'], 'an array');
        self::assertNull($this->storedRow(), 'nothing refused was stored');

        self::assertSame(302, $this->post($session, ['csrf_token' => $csrf, 'theme' => 'legacy'])['status']);
        self::assertSame('legacy', $this->storedRow());
        self::assertSame(302, $this->post($session, ['csrf_token' => $csrf, 'theme' => 'minimal'])['status']);
        self::assertSame('minimal', $this->storedRow());

        self::assertNotSame(200, $server->request('GET', '/admin/theme.php', $editor)['status'], 'the picker without settings.manage');
    }

    // ------------------------------------------------------------ preview

    public function testThePreviewShowsMinimalWhileTheSiteStaysOnKlassiek(): void
    {
        $this->storeRow('legacy');
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $preview = $this->server()->request('GET', '/admin/theme-preview.php?theme=minimal', $session);
        self::assertSame(200, $preview['status']);
        self::assertSame(1, substr_count($preview['body'], self::MINIMAL_CSS), 'the real stylesheet slot, once');
        self::assertSame("form-action 'none'; frame-ancestors 'self'; base-uri 'none'", BuiltInServer::header($preview, 'Content-Security-Policy'));
        self::assertSame('noindex, nofollow', BuiltInServer::header($preview, 'X-Robots-Tag'));
        self::assertStringContainsString('private, no-store', BuiltInServer::header($preview, 'Cache-Control'));
        self::assertStringContainsString('<header class="site-header">', $preview['body'], 'the real site shell');
        self::assertStringContainsString('<footer', $preview['body']);
        self::assertStringContainsString('assets/js/theme-preview.js', $preview['body']);
        self::assertStringContainsString('class="theme-preview-bar"', $preview['body']);
        self::assertStringNotContainsString('theme-minimal', $preview['body'], 'no theme key in the markup');
        self::assertStringNotContainsString('data-theme', $preview['body']);

        self::assertSame('legacy', $this->storedRow(), 'a preview stores nothing');
        self::assertStringNotContainsString(self::MINIMAL_CSS, $this->publicHome(), 'and the public site does not move');
    }

    public function testThePreviewShowsKlassiekWhileTheSiteIsOnMinimal(): void
    {
        $this->storeRow('minimal');
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $preview = $this->server()->request('GET', '/admin/theme-preview.php?theme=legacy', $session);
        self::assertSame(200, $preview['status']);
        self::assertStringNotContainsString(self::MINIMAL_CSS, $preview['body']);
        self::assertStringContainsString('<header class="site-header">', $preview['body']);

        self::assertSame('minimal', $this->storedRow());
        self::assertStringContainsString(self::MINIMAL_CSS, $this->publicHome());
    }

    public function testThePreviewIsAdminOnlyAndTakesOnlyARegisteredKey(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        [$editor] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $server = $this->server();

        $anonymous = $server->request('GET', '/admin/theme-preview.php?theme=minimal');
        self::assertSame(302, $anonymous['status']);
        self::assertStringContainsString('login.php', $anonymous['location']);
        self::assertSame(403, $server->request('GET', '/admin/theme-preview.php?theme=minimal', $editor)['status']);

        foreach (['', 'kobold', 'LEGACY', 'minimal.css', '../../evil', 'https://evil.test/x.css', '%3Cstyle%3E'] as $key) {
            $response = $server->request('GET', '/admin/theme-preview.php?theme=' . $key, $session);
            self::assertSame(404, $response['status'], $key);
            self::assertStringNotContainsString(self::MINIMAL_CSS, $response['body']);
        }
        self::assertSame(404, $server->request('GET', '/admin/theme-preview.php', $session)['status'], 'without a key');
        self::assertSame(404, $server->request('GET', '/admin/theme-preview.php?theme[]=minimal', $session)['status'], 'an array');
        self::assertNull($this->storedRow());
    }

    public function testNoPublicAddressTakesAThemeOverride(): void
    {
        foreach (['/?theme=minimal', '/?preview_theme=minimal', '/?theme=minimal&preview=1'] as $path) {
            $response = $this->server()->request('GET', $path, null, [], [], ['Cookie: theme=minimal']);
            self::assertSame(200, $response['status'], $path);
            self::assertStringNotContainsString(self::MINIMAL_CSS, $response['body'], $path);
        }

        // Source: nothing outside /admin/ reads a preview theme, and the
        // preview itself writes no session and no setting.
        $preview = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/theme-preview.php');
        self::assertStringNotContainsString('$_SESSION', $preview);
        self::assertStringNotContainsString('setcookie', $preview);
        self::assertStringNotContainsString('saveActiveThemeKey', $preview);
        self::assertLessThan(strpos($preview, '<!doctype html>'), (int) strpos($preview, 'session_write_close();'));
        self::assertStringContainsString('PageAssets::renderStyles($theme)', $preview);
    }

    // ------------------------------------------------------------ helpers

    private function server(): BuiltInServer
    {
        self::assertNotNull(self::$server);

        return self::$server;
    }

    private function get(string $session, string $path): string
    {
        $response = $this->server()->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, array $fields): array
    {
        return $this->server()->request('POST', self::ENDPOINT, $session, $fields);
    }

    private function publicHome(): string
    {
        $response = $this->server()->request('GET', '/');
        self::assertSame(200, $response['status']);

        return $response['body'];
    }

    /** @return list<string> the keys whose card has an Activeren form */
    private function activatable(string $screen): array
    {
        preg_match_all('#action="/api/admin/save-active-theme\.php".*?name="theme" value="([^"]+)"#s', $screen, $matches);

        return $matches[1];
    }

    private function previewFrame(string $screen): string
    {
        self::assertSame(1, preg_match('#<iframe class="admin-theme-preview"[^>]*>#', $screen, $match));

        return $match[0];
    }

    private function storedRow(): ?string
    {
        return (new ThemeSettingRepository())->findAll()[ThemeSettings::ACTIVE_THEME_KEY] ?? null;
    }

    private function storeRow(?string $key): void
    {
        $repository = new ThemeSettingRepository();
        $repository->deleteKeys([ThemeSettings::ACTIVE_THEME_KEY]);
        if ($key !== null) {
            $repository->upsertMany([ThemeSettings::ACTIVE_THEME_KEY => $key]);
        }
        ThemeSettings::clearCache();
    }

    /**
     * Every row of every table a switch must leave alone, plus theme_settings
     * without active_theme.
     *
     * @return array<string, list<string>>
     */
    private function snapshot(): array
    {
        $db = Database::connection();
        $tables = [];

        foreach ([...self::UNTOUCHED_TABLES, 'theme_settings'] as $table) {
            $rows = [];
            foreach ($db->query('SELECT * FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ($table === 'theme_settings' && $row['setting_key'] === ThemeSettings::ACTIVE_THEME_KEY) {
                    continue;
                }
                $rows[] = json_encode($row, JSON_THROW_ON_ERROR);
            }
            sort($rows);
            $tables[$table] = $rows;
        }

        return $tables;
    }
}
