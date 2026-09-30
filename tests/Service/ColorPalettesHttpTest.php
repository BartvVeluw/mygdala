<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\Theme\ColorPaletteService;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\ColorPaletteFixture;
use Tests\Support\PageFixture;
use Tests\Support\PageThemeFixture;

/**
 * The colour palettes over PHP's built-in server (Tests\Support\BuiltInServer),
 * with the site's own routing, once with Paginathema's on and once off:
 *
 *   - Vormgeving lists every palette with its state; create, duplicate,
 *     rename, change, activate and delete through the real endpoints; the
 *     active and the last palette are refused with a message;
 *   - an inactive palette is saved without the website changing; activating
 *     it changes the website; switching back brings the old look back;
 *   - security: CSRF, a signed-in editor without settings.manage, GET, an
 *     unknown id, an unsafe colour and a name with markup in it;
 *   - the editor and its preview: the recipe on the page, the frame's
 *     sandbox, the preview's tokens and Content-Security-Policy, a refused
 *     colour shown as the active palette's, the contrast warning;
 *   - Page Themes: an ordinary page, the header and the footer take the
 *     active palette; a page with its own theme keeps it whichever palette is
 *     active; module off, that page takes the active palette; on again, its
 *     theme is back; a page under it inherits nothing.
 */
final class ColorPalettesHttpTest extends TestCase
{
    private const THEMED = 'zz-cp-thema';
    private const CHILD = 'zz-cp-kind';
    private const PLAIN = 'zz-cp-gewoon';

    /** A light palette that differs from the shipped default and from the page theme. */
    private const LIGHT = [
        'primary_color' => '#2B6CB0',
        'on_primary_color' => '#FFFFFF',
        'background_color' => '#FAFAF7',
        'surface_color' => '#FFFFFF',
        'text_color' => '#1A202C',
    ];

    private static ?BuiltInServer $on = null;
    private static ?BuiltInServer $off = null;

    private AdminTestSession $accounts;

    /** @var list<array<string, mixed>> */
    private array $palettes = [];

    private int $standaard = 0;
    private int $themedId = 0;
    private int $themeId = 0;

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

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true, 'page_themes' => true]);
        $this->accounts = new AdminTestSession();
        $this->palettes = ColorPaletteFixture::snapshot();
        $this->standaard = ColorPaletteFixture::only();

        $this->removePages();
        PageThemeFixture::removeAll();
        $this->themeId = PageThemeFixture::create('Paletproef');
        $this->themedId = PageFixture::create(['content_key' => self::THEMED, 'slug' => self::THEMED, 'status' => PageContent::STATUS_PUBLISHED], 'Pagina met thema');
        PageFixture::create(['content_key' => self::CHILD, 'slug' => self::CHILD, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $this->themedId], 'Kind van thema');
        PageFixture::create(['content_key' => self::PLAIN, 'slug' => self::PLAIN, 'status' => PageContent::STATUS_PUBLISHED], 'Gewone pagina');
        PageThemeFixture::assign($this->themedId, $this->themeId);
    }

    protected function tearDown(): void
    {
        $this->removePages();
        PageThemeFixture::removeAll();
        ColorPaletteFixture::restore($this->palettes);
        $this->accounts->forget();
        ModuleRegistry::overrideForTests(null);
        ThemeSettings::clearCache();
    }

    // ------------------------------------------------------------ managing

    public function testVormgevingListsThePalettesWithTheirState(): void
    {
        $donker = ColorPaletteService::create(['name' => 'Donker'] + ColorPaletteFixture::COLORS);
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $screen = $this->get(self::$on, $session, '/admin/theme.php');

        self::assertStringContainsString('id="paletten"', $screen);
        self::assertSame(2, substr_count($screen, 'data-palette-row='));
        self::assertMatchesRegularExpression('/data-palette-row="' . $this->standaard . '" data-palette-active>.*?data-palette-status="active"/s', $screen);
        self::assertMatchesRegularExpression('/data-palette-row="' . $donker . '">.*?data-palette-status="inactive"/s', $screen);
        self::assertStringContainsString('action="/api/admin/activate-color-palette.php"', $screen);
        // The active palette offers no delete; the inactive one does.
        self::assertSame(1, substr_count($screen, 'action="/api/admin/delete-color-palette.php"'));
        self::assertStringContainsString('href="/admin/color-palette.php"', $screen);
        // Font pairing and button shape stay on this screen, the colours do not.
        self::assertStringContainsString('name="font_pairing"', $screen);
        self::assertStringNotContainsString('name="primary_color"', $screen);
    }

    public function testCreateDuplicateRenameChangeAndDeleteThroughTheEndpoints(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $before = $this->publicStyle(self::PLAIN);

        $created = $this->post($session, '/api/admin/save-color-palette.php', ['csrf_token' => $csrf, 'name' => 'Licht'] + self::LIGHT);
        $light = $this->idNamed('Licht');
        self::assertSame('/admin/color-palette.php?id=' . $light . '&done=created', $created['location']);
        self::assertFalse(ColorPaletteService::find($light)['active']);

        $copy = $this->post($session, '/api/admin/duplicate-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $light]);
        $copyId = $this->idNamed('Licht (kopie)');
        self::assertSame('/admin/color-palette.php?id=' . $copyId . '&done=duplicated', $copy['location']);

        $saved = $this->post($session, '/api/admin/save-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $copyId, 'name' => 'Halloween', 'primary_color' => '#f60'] + self::LIGHT);
        self::assertSame('/admin/color-palette.php?id=' . $copyId . '&done=saved', $saved['location']);
        self::assertSame('Halloween', ColorPaletteService::find($copyId)['name']);
        self::assertSame('#FF6600', ColorPaletteService::find($copyId)['primary_color']);
        self::assertSame('#2B6CB0', ColorPaletteService::find($light)['primary_color'], 'the original is its own palette');

        self::assertSame($before, $this->publicStyle(self::PLAIN), 'creating and saving inactive palettes changed nothing a visitor sees');

        $deleted = $this->post($session, '/api/admin/delete-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $copyId]);
        self::assertSame('/admin/theme.php?palette=deleted#paletten', $deleted['location']);
        self::assertNull(ColorPaletteService::find($copyId));
    }

    public function testActivatingChangesTheWebsiteAndSwitchingBackRestoresIt(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $light = ColorPaletteService::create(['name' => 'Licht'] + self::LIGHT);
        $original = $this->publicStyle(self::PLAIN);
        self::assertSame('', $original, 'the shipped default emits no site-theme block');

        $activated = $this->post($session, '/api/admin/activate-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $light]);
        self::assertSame('/admin/theme.php?palette=activated#paletten', $activated['location']);
        self::assertSame([$light], $this->activeIds());

        $style = $this->publicStyle(self::PLAIN);
        self::assertStringContainsString('--color-primary: #2B6CB0;', $style);
        self::assertStringContainsString('--color-bg: #FAFAF7;', $style);
        self::assertStringContainsString('--color-surface-2: ' . ThemePalette::derive(['primary' => '#2B6CB0', 'background' => '#FAFAF7', 'surface' => '#FFFFFF', 'text' => '#1A202C'])['--color-surface-2'] . ';', $style);
        self::assertStringContainsString('&quot;Licht&quot; is nu het actieve palet', $this->get(self::$on, $session, '/admin/theme.php?palette=activated'));

        // Saving the ACTIVE palette changes the website at once.
        $saved = $this->post($session, '/api/admin/save-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $light, 'name' => 'Licht', 'primary_color' => '#0F766E'] + self::LIGHT);
        self::assertSame('/admin/color-palette.php?id=' . $light . '&done=saved_active', $saved['location']);
        self::assertStringContainsString('--color-primary: #0F766E;', $this->publicStyle(self::PLAIN));

        $this->post($session, '/api/admin/activate-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $this->standaard]);
        self::assertSame($original, $this->publicStyle(self::PLAIN));
        self::assertSame('#0F766E', ColorPaletteService::find($light)['primary_color'], 'the previous palette stays saved');
    }

    public function testTheActiveAndTheLastPaletteAreRefusedWithAMessage(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $last = $this->post($session, '/api/admin/delete-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $this->standaard]);
        self::assertSame('/admin/theme.php#paletten', $last['location']);
        self::assertNotNull(ColorPaletteService::find($this->standaard));

        ColorPaletteService::create(['name' => 'Ander'] + self::LIGHT);
        $this->post($session, '/api/admin/delete-color-palette.php', ['csrf_token' => $csrf, 'id' => (string) $this->standaard]);
        self::assertNotNull(ColorPaletteService::find($this->standaard));

        $screen = $this->get(self::$on, $session, '/admin/theme.php');
        self::assertStringContainsString('data-palette-error', $screen);
        self::assertStringContainsString('Maak eerst een ander palet actief', $screen);
    }

    // ------------------------------------------------------------ security

    public function testEveryEndpointRefusesWithoutCsrfPermissionPostOrAKnownId(): void
    {
        [$editor, $editorCsrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $other = ColorPaletteService::create(['name' => 'Doelwit'] + self::LIGHT);
        $id = (string) $other;

        foreach (['save-color-palette.php', 'activate-color-palette.php', 'duplicate-color-palette.php', 'delete-color-palette.php'] as $endpoint) {
            $path = '/api/admin/' . $endpoint;
            $fields = ['id' => $id, 'name' => 'Overgenomen'] + self::LIGHT;

            self::assertSame(403, $this->post($session, $path, $fields + ['csrf_token' => 'fout'])['status'], $endpoint . ' without a valid token');
            self::assertSame(403, $this->post($editor, $path, $fields + ['csrf_token' => $editorCsrf])['status'], $endpoint . ' without settings.manage');
            self::assertSame(405, self::$on->request('GET', $path . '?id=' . $id, $session)['status'], $endpoint . ' over GET');
            self::assertSame(404, $this->post($session, $path, ['id' => '999999', 'csrf_token' => $csrf, 'name' => 'X'] + self::LIGHT)['status'], $endpoint . ' with an unknown id');
            self::assertSame(404, $this->post($session, $path, ['id' => 'abc', 'csrf_token' => $csrf, 'name' => 'X'] + self::LIGHT)['status'], $endpoint . ' with a malformed id');
        }

        self::assertSame([$this->standaard], $this->activeIds());
        self::assertSame('Doelwit', ColorPaletteService::find($other)['name']);
        self::assertCount(2, ColorPaletteService::all());

        foreach (['/admin/color-palette.php', '/admin/color-palette-preview.php', '/admin/theme.php'] as $screen) {
            self::assertNotSame(200, self::$on->request('GET', $screen, $editor)['status'], $screen . ' without settings.manage');
        }
    }

    public function testAnUnsafeColourIsRefusedAndAMarkedUpNameIsOnlyEverText(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $count = count(ColorPaletteService::all());

        foreach (['red;}body{display:none', 'url(https://x.test/a.png)', 'var(--color-bg)', '#12'] as $bad) {
            $response = $this->post($session, '/api/admin/save-color-palette.php', ['csrf_token' => $csrf, 'name' => 'Kwaad', 'primary_color' => $bad] + self::LIGHT);
            self::assertSame('/admin/color-palette.php', $response['location'], $bad);
            self::assertCount($count, ColorPaletteService::all(), 'nothing stored: ' . $bad);
        }

        $editor = $this->get(self::$on, $session, '/admin/color-palette.php');
        self::assertStringContainsString('value="#12"', $editor, 'what was typed comes back');
        self::assertStringContainsString('data-save-bar-unsaved', $editor);

        $name = '</style><script>alert(1)</script>';
        $this->post($session, '/api/admin/save-color-palette.php', ['csrf_token' => $csrf, 'name' => $name] + self::LIGHT);
        $screen = $this->get(self::$on, $session, '/admin/theme.php');
        self::assertStringNotContainsString($name, $screen);
        self::assertStringContainsString(htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $screen);

        self::assertSame(0, (int) Database::connection()->query(
            "SELECT COUNT(*) FROM color_palettes WHERE primary_color NOT REGEXP '^#[0-9A-F]{6}$' OR text_color NOT REGEXP '^#[0-9A-F]{6}$'"
        )->fetchColumn());
    }

    // --------------------------------------------------- editor + preview

    public function testTheEditorCarriesTheRecipeAndASameOriginOnlyFrame(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $light = ColorPaletteService::create(['name' => 'Licht'] + self::LIGHT);

        $new = $this->get(self::$on, $session, '/admin/color-palette.php');
        self::assertStringContainsString('data-palette-state="new"', $new);
        self::assertMatchesRegularExpression('/id="theme-primary_color" name="primary_color" value="' . preg_quote(ThemeSettings::get('primary_color'), '/') . '"/', $new, 'a new palette starts as the website');

        $editor = $this->get(self::$on, $session, '/admin/color-palette.php?id=' . $light);
        self::assertStringContainsString('data-palette-state="inactive"', $editor);
        self::assertStringContainsString('sandbox="allow-same-origin"', $editor);
        self::assertStringContainsString('src="/admin/color-palette-preview.php?primary_color=%232B6CB0', $editor);
        self::assertStringContainsString('data-save-bar', $editor);
        self::assertStringContainsString('data-save-bar-discard data-palette-cancel', $editor);

        preg_match('/data-palette-model="([^"]+)"/', $editor, $match);
        $model = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
        self::assertSame(ThemePalette::recipe(), $model['recipe']);
        self::assertSame('--color-primary', $model['direct']['primary_color']);

        self::assertStringContainsString('data-palette-state="active"', $this->get(self::$on, $session, '/admin/color-palette.php?id=' . $this->standaard));
        self::assertSame(404, self::$on->request('GET', '/admin/color-palette.php?id=999999', $session)['status']);
        self::assertSame(404, self::$on->request('GET', '/admin/color-palette.php?id=abc', $session)['status']);
    }

    public function testThePreviewDrawsTheRealTokensAndRunsNoScript(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $preview = self::$on->request('GET', '/admin/color-palette-preview.php?primary_color=%232B6CB0&background_color=red%3B%7Dbody%7B&text_color=1a202c', $session);
        self::assertSame(200, $preview['status']);
        self::assertSame("script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'", BuiltInServer::header($preview, 'Content-Security-Policy'));

        $body = $preview['body'];
        self::assertStringContainsString('<main id="main" class="palette-preview" data-page-theme="palette-preview">', $body);
        self::assertStringContainsString('--color-primary: #2B6CB0;', $body);
        self::assertStringContainsString('--color-text: #1A202C;', $body);
        self::assertStringContainsString('--color-bg: ' . ThemeSettings::get('background_color') . ';', $body, 'a refused colour shows the active palette\'s');
        self::assertStringNotContainsString('red;}', $body);
        foreach (['class="btn"', 'class="btn btn--ghost"', 'class="feature-card"', 'class="form-field"', 'class="palette-preview__band"', '<h1>', '<h2>'] as $element) {
            self::assertStringContainsString($element, $body, $element);
        }
        self::assertStringNotContainsString('<script', $body);
    }

    public function testTheContrastWarningIsRenderedForAnUnreadablePair(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $grey = ColorPaletteService::create(['name' => 'Grijs', 'text_color' => '#777777', 'background_color' => '#808080'] + self::LIGHT);

        $editor = $this->get(self::$on, $session, '/admin/color-palette.php?id=' . $grey);
        self::assertMatchesRegularExpression('/<div class="admin-alert admin-alert--warning" data-palette-contrast role="status">/', $editor);
        self::assertMatchesRegularExpression('/<li data-contrast-fg="text_color" data-contrast-bg="background_color">/', $editor);

        $fine = $this->get(self::$on, $session, '/admin/color-palette.php?id=' . $this->standaard);
        self::assertStringContainsString('data-palette-contrast hidden', $fine);
    }

    // ---------------------------------------------------------- page themes

    public function testAnOrdinaryPageAndTheSiteShellTakeTheActivePalette(): void
    {
        ColorPaletteService::activate(ColorPaletteService::create(['name' => 'Licht'] + self::LIGHT));

        $body = $this->page(self::$on, self::PLAIN);
        self::assertStringContainsString('<main id="main">', $body);
        self::assertStringNotContainsString('<style id="page-theme">', $body);
        self::assertStringContainsString('--color-primary: #2B6CB0;', $this->siteTheme($body));
        self::assertStringContainsString('<meta name="theme-color" content="#FAFAF7">', $body);
    }

    public function testAPageThemeStaysItsOwnWhicheverPaletteIsActiveAndTheShellDoesNotTakeIt(): void
    {
        $before = $this->pageTheme($this->page(self::$on, self::THEMED));
        self::assertStringContainsString('--color-primary: #FF7518;', $before);

        ColorPaletteService::activate(ColorPaletteService::create(['name' => 'Licht'] + self::LIGHT));
        $body = $this->page(self::$on, self::THEMED);

        self::assertSame($before, $this->pageTheme($body), 'activating a palette never changes a page theme');
        self::assertStringContainsString('<main id="main" data-page-theme="zz-test-paletproef">', $body);
        // The header and footer sit outside <main>, on :root: the palette.
        self::assertStringContainsString('--color-primary: #2B6CB0;', $this->siteTheme($body));
        self::assertLessThan(strpos($body, '<main id="main"'), strpos($body, '<header class="site-header">'));
        self::assertGreaterThan(strpos($body, '</main>'), strpos($body, '<footer'));
    }

    public function testWithPageThemesOffAThemedPageTakesTheActivePaletteAndGetsItsThemeBackAfter(): void
    {
        ColorPaletteService::activate(ColorPaletteService::create(['name' => 'Licht'] + self::LIGHT));

        $off = $this->page(self::$off, self::THEMED);
        self::assertStringContainsString('<main id="main">', $off);
        self::assertStringNotContainsString('page-theme', $off);
        self::assertStringContainsString('--color-primary: #2B6CB0;', $this->siteTheme($off));
        self::assertSame($this->themeId, (int) Database::connection()->query('SELECT page_theme_id FROM pages WHERE id = ' . $this->themedId)->fetchColumn(), 'the choice is kept');

        $on = $this->page(self::$on, self::THEMED);
        self::assertStringContainsString('<main id="main" data-page-theme="zz-test-paletproef">', $on);
        self::assertStringContainsString('--color-primary: #FF7518;', $this->pageTheme($on));
    }

    public function testAPageUnderAThemedPageInheritsNothingAndTakesThePalette(): void
    {
        ColorPaletteService::activate(ColorPaletteService::create(['name' => 'Licht'] + self::LIGHT));

        $child = $this->page(self::$on, self::THEMED . '/' . self::CHILD);
        self::assertStringContainsString('<main id="main">', $child);
        self::assertStringNotContainsString('page-theme', $child);
        self::assertStringContainsString('--color-primary: #2B6CB0;', $this->siteTheme($child));
    }

    // -------------------------------------------------------------- helpers

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
    private function post(string $session, string $path, array $fields): array
    {
        return self::$on->request('POST', $path, $session, $fields);
    }

    private function page(BuiltInServer $server, string $path): string
    {
        $response = $server->request('GET', '/' . $path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /** The public page's <style id="site-theme"> block, or ''. */
    private function publicStyle(string $path): string
    {
        return $this->siteTheme($this->page(self::$on, $path));
    }

    private function siteTheme(string $body): string
    {
        return preg_match('#<style id="site-theme">.*?</style>#s', $body, $m) === 1 ? $m[0] : '';
    }

    private function pageTheme(string $body): string
    {
        return preg_match('#<style id="page-theme">.*?</style>#s', $body, $m) === 1 ? $m[0] : '';
    }

    private function idNamed(string $name): int
    {
        foreach (ColorPaletteService::all() as $palette) {
            if ($palette['name'] === $name) {
                return (int) $palette['id'];
            }
        }

        self::fail('no palette named ' . $name);
    }

    /** @return list<int> */
    private function activeIds(): array
    {
        return array_map('intval', Database::connection()
            ->query('SELECT id FROM color_palettes WHERE is_active = 1')
            ->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function removePages(): void
    {
        foreach ([self::CHILD, self::THEMED, self::PLAIN] as $key) {
            $page = (new PageRepository())->findByContentKey($key);
            if ($page !== null) {
                PageThemeFixture::assign((int) $page['id'], null);
                PageService::delete($page);
            }
        }
    }
}
