<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Language\SiteLanguages;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * MANAGING THE WEBSITE'S LANGUAGES (Multilingual 2.0 phase 7, wave D):
 * Settings > Talen and its endpoints, over real HTTP.
 *
 * The lifecycle rules this pins, each refused with a sentence and never with
 * a changed row:
 *
 *   - a new language starts OFF, so nothing is published untranslated;
 *   - there is exactly one default, it is active and it cannot be switched
 *     off;
 *   - switching a language off deletes nothing;
 *   - only a switched-off language without a single word can be removed —
 *     the translation tables refuse the rest (ON DELETE RESTRICT);
 *   - the order is the order of the language switch.
 *
 * And that publishing the other languages at all is the Multilingual
 * module's stored preference, which an environment variable may pin.
 */
final class WebsiteLanguageAdminHttpTest extends TestCase
{
    private const KEY = 'zz-website-language-admin';

    private static ?BuiltInServer $server = null;

    /** The same code with the module left to the CMS rather than pinned. */
    private static ?BuiltInServer $unpinned = null;

    private AdminTestSession $accounts;

    /** @var list<array<string, mixed>> */
    private array $registry = [];

    /** @var array<string, string> */
    private array $moduleSettings = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_MULTILINGUAL_ENABLED' => 'true']);
        self::$unpinned = BuiltInServer::start(['MODULE_MULTILINGUAL_ENABLED' => '']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$unpinned?->stop();
        self::$server = null;
        self::$unpinned = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers() || self::$unpinned === null || !self::$unpinned->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        SiteLanguages::clearCache();
        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');
        self::assertFalse(SiteLanguages::exists('de'), 'this test adds German itself');

        $this->accounts = new AdminTestSession();
        $this->registry = Database::connection()->query('SELECT * FROM site_languages ORDER BY id')->fetchAll();
        $this->moduleSettings = Database::connection()->query('SELECT setting_key, setting_value FROM module_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $db->exec("DELETE FROM page_translations WHERE language_code = 'de'");
        $db->prepare('DELETE FROM pages WHERE content_key = ?')->execute([self::KEY]);
        $db->exec("DELETE FROM site_languages WHERE code NOT IN ('" . implode("','", array_map(static fn (array $row): string => (string) $row['code'], $this->registry)) . "')");

        // Restore every original row exactly: clear the default first, the
        // unique index allows one at a time.
        $db->exec('UPDATE site_languages SET is_default = NULL');
        $restore = $db->prepare(
            'UPDATE site_languages SET name = :name, native_name = :native_name, is_active = :is_active, is_default = :is_default, sort_order = :sort_order WHERE id = :id'
        );
        foreach ($this->registry as $row) {
            $restore->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'native_name' => $row['native_name'],
                'is_active' => $row['is_active'],
                'is_default' => $row['is_default'],
                'sort_order' => $row['sort_order'],
            ]);
        }

        $db->exec("DELETE FROM module_settings WHERE setting_key = 'module_multilingual_enabled'");
        if (isset($this->moduleSettings['module_multilingual_enabled'])) {
            $db->prepare("INSERT INTO module_settings (setting_key, setting_value, created_at, updated_at) VALUES ('module_multilingual_enabled', ?, NOW(), NOW())")
                ->execute([$this->moduleSettings['module_multilingual_enabled']]);
        }

        $this->accounts->forget();
        SiteLanguages::clearCache();
        PageLocalization::clearCache();
    }

    // ------------------------------------------------------------- the screen

    public function testTheTabListsEveryLanguageWithWhatCanBeDoneToIt(): void
    {
        $session = $this->signIn();
        $xpath = $this->xpath($this->get($session, '/admin/settings.php'));

        foreach (SiteLanguages::all() as $language) {
            self::assertSame(1, $xpath->query('//div[@id="language-' . $language->code . '"]')->length, $language->code . ' is listed');
        }

        // The default can be renamed and moved, never switched off or deleted.
        self::assertSame(0, $xpath->query('//div[@id="language-nl"]//form[contains(@action, "toggle-website-language")]')->length);
        self::assertSame(0, $xpath->query('//div[@id="language-nl"]//form[contains(@action, "delete-website-language")]')->length);
        self::assertSame(1, $xpath->query('//div[@id="language-en"]//form[contains(@action, "toggle-website-language")]')->length);
        self::assertSame(1, $xpath->query('//form[@action="/api/admin/create-website-language.php"]')->length);
        self::assertSame(1, $xpath->query('//form[@action="/api/admin/update-multilingual-publishing.php"]')->length);
    }

    // ------------------------------------------------------------- the lifecycle

    public function testANewLanguageStartsOffAndIsPublishedOnlyOnceSwitchedOn(): void
    {
        $session = $this->signIn();

        $this->post($session, 'create-website-language.php', ['code' => 'DE ', 'name' => 'German', 'native_name' => 'Deutsch']);
        $german = $this->language('de');
        self::assertNotNull($german, 'the code is stored in its normal form');
        self::assertFalse($german->isActive, 'a new language starts off');
        self::assertSame('Deutsch', $german->nativeName);
        self::assertFalse(SiteLanguages::isActive('de'));

        $this->post($session, 'toggle-website-language.php', ['code' => 'de', 'is_active' => '1']);
        self::assertTrue($this->language('de')?->isActive);
        self::assertTrue(SiteLanguages::isActive('de'), 'published: the module is on');

        $this->post($session, 'update-website-language.php', ['code' => 'de', 'name' => 'German (DE)', 'native_name' => 'Deutsch (DE)']);
        self::assertSame('Deutsch (DE)', $this->language('de')?->nativeName);
    }

    public function testRefusedInputChangesNothingAndSaysWhy(): void
    {
        $session = $this->signIn();
        $before = $this->rows();

        foreach ([
            [['code' => 'deu', 'name' => 'German', 'native_name' => 'Deutsch'], 'language.error_code'],
            [['code' => '1x', 'name' => 'German', 'native_name' => 'Deutsch'], 'language.error_code'],
            [['code' => 'en', 'name' => 'English', 'native_name' => 'English'], 'language.error_exists'],
            [['code' => 'de', 'name' => '', 'native_name' => 'Deutsch'], 'language.error_name'],
            [['code' => 'de', 'name' => 'German', 'native_name' => '<b>Deutsch</b>'], 'language.error_name'],
            [['code' => 'de', 'name' => str_repeat('x', 65), 'native_name' => 'Deutsch'], 'language.error_name'],
            // Latin-1 bytes, not UTF-8: refused here, never sent to MySQL
            // (which refuses it with an error of its own).
            [['code' => 'fr', 'name' => 'French', 'native_name' => "Fran\xE7ais"], 'language.error_name'],
        ] as [$fields, $expected]) {
            $response = $this->post($session, 'create-website-language.php', $fields, false);
            self::assertSame('/admin/settings.php#tab-talen', $response['location'], (string) json_encode($fields, JSON_INVALID_UTF8_SUBSTITUTE));
            self::assertSame([\App\Service\Language\AdminTranslator::trans($expected)], $this->accounts->read($session, 'admin_language_errors'), (string) json_encode($fields, JSON_INVALID_UTF8_SUBSTITUTE));
        }

        self::assertSame($before, $this->rows(), 'nothing was written');
    }

    public function testTheDefaultLanguageCannotBeSwitchedOffOrRemoved(): void
    {
        $session = $this->signIn();

        $this->post($session, 'toggle-website-language.php', ['code' => 'nl', 'is_active' => '0'], false);
        self::assertSame([\App\Service\Language\AdminTranslator::trans('language.error_default')], $this->accounts->read($session, 'admin_language_errors'));
        self::assertTrue($this->language('nl')?->isActive);

        $this->post($session, 'delete-website-language.php', ['code' => 'nl'], false);
        self::assertTrue(SiteLanguages::exists('nl'));
        self::assertSame('nl', SiteLanguages::defaultCode());
    }

    public function testTheDefaultMovesOnlyToAnActiveLanguageAndBack(): void
    {
        $session = $this->signIn();

        $this->post($session, 'update-language-settings.php', ['primary_content_language' => 'en']);
        SiteLanguages::clearCache();
        self::assertSame('en', SiteLanguages::defaultCode());
        self::assertSame(1, (int) Database::connection()->query('SELECT COUNT(*) FROM site_languages WHERE is_default = 1')->fetchColumn(), 'exactly one default');

        $this->post($session, 'create-website-language.php', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']);
        $this->post($session, 'update-language-settings.php', ['primary_content_language' => 'de'], false);
        SiteLanguages::clearCache();
        self::assertSame('en', SiteLanguages::defaultCode(), 'a language that is off cannot become the default');

        $this->post($session, 'update-language-settings.php', ['primary_content_language' => 'nl']);
        SiteLanguages::clearCache();
        self::assertSame('nl', SiteLanguages::defaultCode());
    }

    /**
     * Switching off keeps every word; removing is only for a language that
     * never held one. The translation tables say so themselves.
     */
    public function testALanguageWithWordsCanBeSwitchedOffButNeverRemoved(): void
    {
        $session = $this->signIn();
        $this->post($session, 'create-website-language.php', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']);
        $this->post($session, 'toggle-website-language.php', ['code' => 'de', 'is_active' => '1']);

        $pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => 'published'], 'Taaltest');
        PageLocalization::save($pageId, 'de', [PageTranslation::TITLE => 'Sprachtest']);

        $this->post($session, 'delete-website-language.php', ['code' => 'de'], false);
        self::assertSame([\App\Service\Language\AdminTranslator::trans('language.error_active')], $this->accounts->read($session, 'admin_language_errors'), 'switch it off first');

        $this->post($session, 'toggle-website-language.php', ['code' => 'de', 'is_active' => '0']);
        PageLocalization::clearCache();
        self::assertSame('Sprachtest', PageLocalization::raw($pageId, PageTranslation::TITLE, 'de'), 'switching off deleted nothing');

        $this->post($session, 'delete-website-language.php', ['code' => 'de'], false);
        self::assertSame([\App\Service\Language\AdminTranslator::trans('language.error_in_use')], $this->accounts->read($session, 'admin_language_errors'));
        self::assertTrue(SiteLanguages::exists('de'));
        PageLocalization::clearCache();
        self::assertSame('Sprachtest', PageLocalization::raw($pageId, PageTranslation::TITLE, 'de'), 'and removing it took no word with it');

        // Without its words it can go.
        Database::connection()->exec("DELETE FROM page_translations WHERE language_code = 'de'");
        $this->post($session, 'delete-website-language.php', ['code' => 'de']);
        self::assertFalse(SiteLanguages::exists('de'));
    }

    public function testTheOrderMovesOnePlaceAtATime(): void
    {
        $session = $this->signIn();
        $this->post($session, 'create-website-language.php', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']);
        self::assertSame(['nl', 'en', 'de'], $this->order());

        $this->post($session, 'move-website-language.php', ['code' => 'de', 'direction' => 'up']);
        self::assertSame(['nl', 'de', 'en'], $this->order());

        $this->post($session, 'move-website-language.php', ['code' => 'nl', 'direction' => 'up'], false);
        self::assertSame(['nl', 'de', 'en'], $this->order(), 'the first stays first');

        $this->post($session, 'move-website-language.php', ['code' => 'nl', 'direction' => 'down']);
        self::assertSame(['de', 'nl', 'en'], $this->order());
    }

    // ------------------------------------------------------------- the module

    public function testPublishingTheOtherLanguagesIsTheModulesStoredPreference(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $csrf = (string) $this->accounts->read($session, 'csrf_token');

        $off = self::$unpinned->request('POST', '/api/admin/update-multilingual-publishing.php', $session, ['csrf_token' => $csrf, 'enabled' => '0']);
        self::assertSame('/admin/settings.php?saved=1&section=talen#tab-talen', $off['location']);
        self::assertSame('0', $this->storedModuleSetting());
        self::assertTrue(SiteLanguages::exists('en'), 'switching publishing off removes no language');
        self::assertTrue($this->language('en')?->isActive, 'and changes no language\'s own flag');

        $on = self::$unpinned->request('POST', '/api/admin/update-multilingual-publishing.php', $session, ['csrf_token' => $csrf, 'enabled' => '1']);
        self::assertSame('/admin/settings.php?saved=1&section=talen#tab-talen', $on['location']);
        self::assertSame('1', $this->storedModuleSetting());
    }

    public function testAnEnvironmentThatPinsTheModuleHasTheLastWord(): void
    {
        $session = $this->signIn();
        $before = $this->storedModuleSetting();

        $response = $this->post($session, 'update-multilingual-publishing.php', ['enabled' => '0'], false);
        self::assertSame('/admin/settings.php#tab-talen', $response['location']);
        self::assertSame(
            [\App\Service\Language\AdminTranslator::trans('language.error_pinned', ['variable' => 'MODULE_MULTILINGUAL_ENABLED'])],
            $this->accounts->read($session, 'admin_language_errors')
        );
        self::assertSame($before, $this->storedModuleSetting(), 'nothing was stored');
    }

    // ------------------------------------------------------------- the guards

    public function testEveryEndpointHasTheFourGuards(): void
    {
        foreach (['create-website-language.php', 'update-website-language.php', 'toggle-website-language.php', 'move-website-language.php', 'delete-website-language.php', 'update-multilingual-publishing.php'] as $endpoint) {
            $anonymous = self::$server->request('POST', '/api/admin/' . $endpoint, null, ['code' => 'de']);
            self::assertContains($anonymous['status'], [302, 401, 403], $endpoint . ' needs a login');

            [$withoutPermission] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
            $refused = self::$server->request('POST', '/api/admin/' . $endpoint, $withoutPermission, ['code' => 'de', 'csrf_token' => (string) $this->accounts->read($withoutPermission, 'csrf_token')]);
            self::assertSame(403, $refused['status'], $endpoint . ' needs settings.manage');

            $session = $this->signIn();
            self::assertSame(405, self::$server->request('GET', '/api/admin/' . $endpoint, $session)['status'], $endpoint . ' is POST only');
            self::assertSame(403, self::$server->request('POST', '/api/admin/' . $endpoint, $session, ['code' => 'de'])['status'], $endpoint . ' needs its CSRF token');
        }

        self::assertFalse(SiteLanguages::exists('de'), 'no guard let anything through');
    }

    // ------------------------------------------------------------- helpers

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        return $session;
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, string $endpoint, array $fields, bool $expectSaved = true): array
    {
        $response = self::$server->request('POST', '/api/admin/' . $endpoint, $session, $fields + ['csrf_token' => (string) $this->accounts->read($session, 'csrf_token')]);

        if ($expectSaved) {
            self::assertStringContainsString('saved=1', $response['location'], $endpoint . ' ' . json_encode($this->accounts->read($session, 'admin_language_errors')));
        }

        SiteLanguages::clearCache();

        return $response;
    }

    private function language(string $code): ?\App\Service\Language\SiteLanguage
    {
        SiteLanguages::clearCache();

        return SiteLanguages::find($code);
    }

    /** @return list<string> */
    private function order(): array
    {
        SiteLanguages::clearCache();

        return array_map(static fn (\App\Service\Language\SiteLanguage $language): string => $language->code, SiteLanguages::all());
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return Database::connection()->query('SELECT code, name, native_name, is_active, is_default, sort_order FROM site_languages ORDER BY id')->fetchAll();
    }

    private function storedModuleSetting(): ?string
    {
        $value = Database::connection()->query("SELECT setting_value FROM module_settings WHERE setting_key = 'module_multilingual_enabled'")->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function get(string $session, string $path): string
    {
        $response = self::$server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
