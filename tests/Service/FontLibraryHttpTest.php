<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\Theme\FontLibrary;
use App\Service\Theme\FontStorage;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FontFileFixture;
use Tests\Support\FontLibraryFixture;
use Tests\Support\PageFixture;
use Tests\Support\PageThemeFixture;

/**
 * The Font Library over PHP's built-in server (Tests\Support\BuiltInServer)
 * with the site's own routing, Paginathema's on and off:
 *
 *   - upload a family through the real editor endpoint (multipart, several
 *     files, a variant each); the files land under generated names; a
 *     refused file stores nothing and the editor says why;
 *   - security: every endpoint refuses without CSRF, without settings.manage,
 *     over GET and for an unknown id, and a refused upload leaves no file;
 *   - Vormgeving: the tab Lettertypen, the choice per role, the website's
 *     pages with the family's @font-face rules and nothing of unused
 *     families; the typography preview and a page theme's preview;
 *   - a page theme with its own family, module on and off;
 *   - deleting through the endpoint: refused with who uses it, then allowed
 *     once out of use, files and all.
 */
final class FontLibraryHttpTest extends TestCase
{
    private const PLAIN = 'zz-fl-gewoon';
    private const THEMED = 'zz-fl-thema';

    private static ?BuiltInServer $on = null;
    private static ?BuiltInServer $off = null;

    private AdminTestSession $accounts;

    /** @var list<array<string, mixed>> */
    private array $roles = [];

    /** @var array<string, string> */
    private array $settings = [];

    private string $directory = '';

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
        $this->directory = dirname(__DIR__, 2) . '/' . FontStorage::PUBLIC_PREFIX;
        $this->roles = FontLibraryFixture::rolesSnapshot();
        $this->settings = ['font_pairing' => ThemeSettings::get('font_pairing')];
        FontLibraryFixture::removeAll($this->roles);
        PageThemeFixture::removeAll();
        $this->removePages();
        PageFixture::create(['content_key' => self::PLAIN, 'slug' => self::PLAIN, 'status' => PageContent::STATUS_PUBLISHED], 'Gewone pagina');
    }

    protected function tearDown(): void
    {
        $this->removePages();
        PageThemeFixture::removeAll();
        FontLibraryFixture::removeAll($this->roles);
        ThemeSettings::save($this->settings);
        $this->accounts->forget();
        ModuleRegistry::overrideForTests(null);
        ThemeSettings::clearCache();
    }

    public function testAFamilyIsUploadedThroughTheEditor(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $before = $this->libraryFiles();

        $response = $this->upload($session, '/api/admin/save-font-family.php', [
            'csrf_token' => $csrf,
            'name' => FontLibraryFixture::PREFIX . 'Roboto',
            'category' => 'sans',
            'source_url' => 'https://fonts.google.com/specimen/Roboto',
        ], [
            ['../../Roboto-Regular.ttf', FontFileFixture::ttf(), '400'],
            ['Roboto-Bold.woff2', FontFileFixture::woff2(), '700'],
        ]);

        $id = $this->idNamed(FontLibraryFixture::PREFIX . 'Roboto');
        self::assertSame('/admin/font-family.php?id=' . $id . '&done=created', $response['location']);

        $family = FontLibrary::family($id);
        self::assertSame(['Roboto-Regular.ttf', 'Roboto-Bold.woff2'], array_column($family['variants'], 'original_filename'));
        self::assertSame(['ttf', 'woff2'], array_column($family['variants'], 'format'));
        $new = array_values(array_diff($this->libraryFiles(), $before));
        sort($new);
        $stored = array_column($family['variants'], 'file_name');
        sort($stored);
        self::assertSame($stored, $new, 'exactly the two generated names, in the library folder');

        $editor = $this->get(self::$on, $session, '/admin/font-family.php?id=' . $id . '&done=created');
        self::assertStringContainsString('data-font-notice="created"', $editor);
        self::assertStringContainsString('data-font-licence', $editor);
        self::assertSame(2, substr_count($editor, 'data-font-variant="'));
        self::assertStringContainsString('font-family: &#039;mygdala-font-' . $id . '&#039;', $editor);
        self::assertStringContainsString('@font-face{font-family:"mygdala-font-' . $id . '"', $editor);
        self::assertStringContainsString('data-font-usage-none', $editor);

        $overview = $this->get(self::$on, $session, '/admin/theme.php?tab=lettertypen');
        self::assertStringContainsString('data-font-row="' . $id . '"', $overview);
        self::assertStringContainsString('data-font-status="unused"', $overview);
        self::assertStringContainsString('data-font-help="howto"', $overview);
        self::assertStringContainsString('https://fonts.google.com/', $overview);
        self::assertStringContainsString('name="heading_font_family_id"', $overview, 'the family can be chosen now');
    }

    public function testARefusedUploadStoresNothingAndTheEditorSaysWhy(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $before = $this->libraryFiles();

        $response = $this->upload($session, '/api/admin/save-font-family.php', [
            'csrf_token' => $csrf,
            'name' => FontLibraryFixture::PREFIX . 'Kapot',
            'category' => 'sans',
        ], [
            ['Kapot-Regular.ttf', FontFileFixture::ttf(), '400'],
            ['foto<b>.woff2', FontFileFixture::png(), '700'],
        ]);

        self::assertSame('/admin/font-family.php', $response['location']);
        self::assertSame($before, $this->libraryFiles());
        self::assertSame(0, $this->countNamed(FontLibraryFixture::PREFIX . 'Kapot'));

        $editor = $this->get(self::$on, $session, '/admin/font-family.php');
        self::assertStringContainsString('data-font-errors', $editor);
        self::assertStringContainsString('&quot;foto&lt;b&gt;.woff2&quot; is geen lettertype', $editor, 'the name is text, never markup');
        self::assertStringContainsString('value="' . FontLibraryFixture::PREFIX . 'Kapot"', $editor, 'what was typed comes back');
    }

    public function testEveryEndpointRefusesWithoutCsrfPermissionPostOrAKnownId(): void
    {
        [$editor, $editorCsrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $id = FontLibraryFixture::create('Doelwit', ['400']);
        $fileId = (int) FontLibrary::family($id)['variants'][0]['id'];
        $before = $this->libraryFiles();

        $endpoints = [
            'save-font-family.php' => ['id' => (string) $id, 'name' => 'Overgenomen'],
            'add-font-files.php' => ['id' => (string) $id],
            'replace-font-file.php' => ['file_id' => (string) $fileId],
            'delete-font-file.php' => ['file_id' => (string) $fileId],
            'delete-font-family.php' => ['id' => (string) $id],
        ];

        foreach ($endpoints as $endpoint => $fields) {
            $path = '/api/admin/' . $endpoint;
            $files = [['Aanval.woff2', FontFileFixture::woff2(), '700']];

            self::assertSame(403, $this->upload($session, $path, $fields + ['csrf_token' => 'fout'], $files)['status'], $endpoint . ' without a valid token');
            self::assertSame(403, $this->upload($session, $path, $fields, $files)['status'], $endpoint . ' without a token');
            self::assertSame(403, $this->upload($editor, $path, $fields + ['csrf_token' => $editorCsrf], $files)['status'], $endpoint . ' without settings.manage');
            self::assertSame(405, self::$on->request('GET', $path . '?id=' . $id, $session)['status'], $endpoint . ' over GET');

            $unknown = array_map(static fn (string $v): string => '999999', array_intersect_key($fields, ['id' => 1, 'file_id' => 1]));
            self::assertSame(404, $this->upload($session, $path, $unknown + ['csrf_token' => $csrf, 'name' => 'X'], $files)['status'], $endpoint . ' with an unknown id');
        }

        self::assertSame(FontLibraryFixture::PREFIX . 'Doelwit', FontLibrary::family($id)['name']);
        self::assertCount(1, FontLibrary::family($id)['variants']);
        self::assertSame($before, $this->libraryFiles(), 'no refused request left a file');

        self::assertNotSame(200, self::$on->request('GET', '/admin/font-family.php?id=' . $id, $editor)['status'], 'the editor without settings.manage');
        self::assertNotSame(200, self::$on->request('GET', '/admin/font-family.php')['status'], 'the editor without a session');
    }

    public function testTheWebsiteLoadsOnlyTheFamiliesItUses(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $body = FontLibraryFixture::create('Tekst', ['400', '700', '400-italic']);
        $heading = FontLibraryFixture::create('Kop', ['700'], 'serif');
        for ($i = 1; $i <= 10; $i++) {
            FontLibraryFixture::create('Bewaard ' . $i, ['400', '700']);
        }

        $plain = $this->page(self::$on, self::PLAIN);
        self::assertStringNotContainsString('@font-face', $plain, 'ten stored families cost a visitor nothing');

        $saved = self::$on->request('POST', '/api/admin/update-theme-settings.php', $session, [
            'csrf_token' => $csrf, 'font_pairing' => 'lora-montserrat', 'body_font_family_id' => (string) $body, 'heading_font_family_id' => '', 'button_shape' => 'pill',
        ]);
        self::assertSame('/admin/theme.php?saved=1', $saved['location']);

        $page = $this->page(self::$on, self::PLAIN);
        self::assertSame(3, substr_count($page, '@font-face'));
        self::assertStringContainsString("--font-body: 'mygdala-font-" . $body . "'", $page);
        self::assertStringContainsString('--font-display: ' . ThemeFonts::pairing('lora-montserrat')['heading'], $page);
        self::assertStringContainsString('fonts.googleapis.com/css2?family=Lora', $page, 'the headings still use the pairing');

        self::$on->request('POST', '/api/admin/update-theme-settings.php', $session, [
            'csrf_token' => $csrf, 'heading_font_family_id' => (string) $heading,
        ]);
        $page = $this->page(self::$on, self::PLAIN);
        self::assertSame(4, substr_count($page, '@font-face'));
        self::assertStringNotContainsString('fonts.googleapis.com', $page, 'both roles self-hosted: nothing from Google');
        self::assertStringNotContainsString(FontLibraryFixture::PREFIX, $page, 'a family name never reaches a page');

        $refused = self::$on->request('POST', '/api/admin/update-theme-settings.php', $session, [
            'csrf_token' => $csrf, 'body_font_family_id' => "1'; } body { x:y",
        ]);
        self::assertSame('/admin/theme.php', $refused['location']);
        ThemeSettings::clearCache();
        self::assertSame((string) $body, ThemeSettings::get('body_font_family_id'), 'a refused value changes nothing');
    }

    public function testAPageThemeHasItsOwnFamilyOnlyWhileTheModuleIsOn(): void
    {
        $site = FontLibraryFixture::create('Site', ['400']);
        $own = FontLibraryFixture::create('Thema', ['400', '700']);
        ThemeSettings::save(['body_font_family_id' => (string) $site]);
        $theme = PageThemeFixture::create('Letters', ['heading_font_family_id' => (string) $own]);
        $pageId = PageFixture::create(['content_key' => self::THEMED, 'slug' => self::THEMED, 'status' => PageContent::STATUS_PUBLISHED], 'Pagina met thema');
        PageThemeFixture::assign($pageId, $theme);

        $on = $this->page(self::$on, self::THEMED);
        self::assertSame(3, substr_count($on, '@font-face'), 'the site family and the theme family, each once');
        self::assertMatchesRegularExpression('#<style id="page-theme">.*--font-display: \'mygdala-font-' . $own . '\'#s', $on);

        $off = $this->page(self::$off, self::THEMED);
        self::assertSame(1, substr_count($off, '@font-face'), 'module off: only the site family');
        self::assertStringNotContainsString('mygdala-font-' . $own, $off);
    }

    public function testThePreviewsShowTheChosenFonts(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE, 'page_themes.manage']);
        $id = FontLibraryFixture::create('Voorbeeld', ['400']);

        $typography = $this->get(self::$on, $session, '/admin/color-palette-preview.php?font_pairing=system&heading_font_family_id=' . $id);
        self::assertStringContainsString('@font-face{font-family:"mygdala-font-' . $id . '"', $typography);
        self::assertStringContainsString("--font-display: 'mygdala-font-" . $id . "'", $typography);
        self::assertStringNotContainsString('--font-display: \'mygdala-font-999999', $this->get(self::$on, $session, '/admin/color-palette-preview.php?heading_font_family_id=999999'));

        $theme = $this->get(self::$on, $session, '/admin/page-theme-preview.php?font_pairing=system&body_font_family_id=' . $id);
        self::assertStringContainsString("--font-body: 'mygdala-font-" . $id . "'", $theme);

        $vormgeving = $this->get(self::$on, $session, '/admin/theme.php');
        self::assertStringContainsString('data-typography-preview', $vormgeving);
        self::assertStringContainsString('/admin/assets/theme-fonts-admin.js', $vormgeving);

        $editor = $this->get(self::$on, $session, '/admin/page-theme.php');
        self::assertStringContainsString('name="heading_font_family_id"', $editor);
        self::assertStringContainsString('name="body_font_family_id"', $editor);
    }

    public function testDeletingThroughTheEndpointIsRefusedWhileInUse(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $response = $this->upload($session, '/api/admin/save-font-family.php', [
            'csrf_token' => $csrf, 'name' => FontLibraryFixture::PREFIX . 'Weg', 'category' => 'serif',
        ], [['Weg-Regular.woff2', FontFileFixture::woff2(), '400']]);
        self::assertStringContainsString('done=created', $response['location']);
        $id = $this->idNamed(FontLibraryFixture::PREFIX . 'Weg');
        $file = FontLibrary::family($id)['variants'][0]['file_name'];
        ThemeSettings::save(['heading_font_family_id' => (string) $id]);

        $refused = self::$on->request('POST', '/api/admin/delete-font-family.php', $session, ['csrf_token' => $csrf, 'id' => (string) $id]);
        self::assertSame('/admin/theme.php#lettertypen', $refused['location']);
        $screen = $this->get(self::$on, $session, '/admin/theme.php');
        self::assertStringContainsString('data-fonts-error', $screen);
        self::assertStringContainsString('het website-thema (koppen)', $screen);
        self::assertFileExists($this->directory . $file);

        $fromEditor = self::$on->request('POST', '/api/admin/delete-font-family.php', $session, ['csrf_token' => $csrf, 'id' => (string) $id, 'from' => 'editor']);
        self::assertSame('/admin/font-family.php?id=' . $id, $fromEditor['location']);

        ThemeSettings::save(['heading_font_family_id' => '']);
        $deleted = self::$on->request('POST', '/api/admin/delete-font-family.php', $session, ['csrf_token' => $csrf, 'id' => (string) $id]);
        self::assertSame('/admin/theme.php?fonts=deleted#lettertypen', $deleted['location']);
        self::assertNull(FontLibrary::family($id));
        self::assertFileDoesNotExist($this->directory . $file);
    }

    // -------------------------------------------------------------- helpers

    /**
     * A multipart POST with font files: font_files[i] and variants[i].
     *
     * @param array<string, string> $fields
     * @param list<array{0: string, 1: string, 2: string}> $files name, bytes, variant
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function upload(string $session, string $path, array $fields, array $files): array
    {
        $curlFiles = [];
        $paths = [];
        foreach (array_values($files) as $index => [$name, $bytes, $variant]) {
            $paths[] = $temp = FontFileFixture::file($bytes);
            $key = str_contains($path, 'replace-font-file') ? 'font_file' : 'font_files[' . $index . ']';
            $curlFiles[$key] = new \CURLFile($temp, 'application/octet-stream', $name);
            $fields['variants[' . $index . ']'] = $variant;
        }

        try {
            return self::$on->request('POST', $path, $session, $fields, $curlFiles);
        } finally {
            foreach ($paths as $temp) {
                @unlink($temp);
            }
        }
    }

    private function get(BuiltInServer $server, string $session, string $path): string
    {
        $response = $server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    private function page(BuiltInServer $server, string $path): string
    {
        $response = $server->request('GET', '/' . $path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /** @return list<string> */
    private function libraryFiles(): array
    {
        $files = array_map('basename', glob($this->directory . '*') ?: []);
        sort($files);

        return $files;
    }

    private function idNamed(string $name): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM font_families WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, 'no family named ' . $name);

        return (int) $id;
    }

    private function countNamed(string $name): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM font_families WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function removePages(): void
    {
        foreach ([self::THEMED, self::PLAIN] as $key) {
            $page = (new PageRepository())->findByContentKey($key);
            if ($page !== null) {
                PageThemeFixture::assign((int) $page['id'], null);
                PageService::delete($page);
            }
        }
    }
}
