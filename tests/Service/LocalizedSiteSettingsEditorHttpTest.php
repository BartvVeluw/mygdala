<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\SiteLanguageRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminPermissions;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The place on Site-instellingen and the footer's description and closing
 * line on the Footer screen, one website language at a time (Multilingual
 * 2.0 phase 4 wave B), over real HTTP against PHP's built-in server.
 *
 * The same contract as every per-language editor: the language chosen in the
 * CMS shell, as stored; a save writes that language only; a third language
 * needs nothing but a row; a refused save keeps what was typed and starts out
 * unsaved; and the public footer prints the pair from the new storage, a
 * value that looks like markup staying text.
 *
 * The localized settings and the two footer switches are put back in
 * tearDown(); the German language row is removed.
 */
final class LocalizedSiteSettingsEditorHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var array<string, array<string, string>> */
    private array $originalWords = [];

    /** @var array<string, string> */
    private array $originalSettings = [];

    private bool $addedGerman = false;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start();
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

        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');

        $this->accounts = new AdminTestSession();
        LocalizedSiteSettings::clearCache();
        foreach (array_keys(LocalizedSiteSettings::KEYS) as $key) {
            $this->originalWords[$key] = LocalizedSiteSettings::words($key);
        }
        $stored = (new SiteSettingRepository())->findAll();
        foreach (['footer_slogan_enabled', 'footer_copyright_template'] as $key) {
            $this->originalSettings[$key] = (string) ($stored[$key] ?? '');
        }

        Database::connection()->exec('DELETE FROM site_setting_translations');
        LocalizedSiteSettings::clearCache();
    }

    protected function tearDown(): void
    {
        Database::connection()->exec('DELETE FROM site_setting_translations');
        LocalizedSiteSettings::clearCache();
        foreach ($this->originalWords as $key => $words) {
            foreach ($words as $language => $value) {
                LocalizedSiteSettings::save($language, [$key => $value]);
            }
        }
        (new SiteSettingRepository())->upsertMany($this->originalSettings);

        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
        }

        $this->accounts->forget();
        LocalizedSiteSettings::clearCache();
        SiteLanguages::clearCache();
    }

    // -------------------------------------------------------------- the place

    public function testThePlaceIsEditedInOneLanguageAndASaveLeavesTheOthersAlone(): void
    {
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::CITY => 'Nijmegen, Nederland']);
        $session = $this->signIn([AdminPermissions::SETTINGS_MANAGE], 'en');

        $xpath = $this->xpath($this->get($session, '/admin/settings.php'));
        $input = $xpath->query('//input[@name="city"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);
        self::assertSame('', $input->getAttribute('value'), 'the untranslated place is shown empty, never as the Dutch words');
        self::assertNotSame('', $input->getAttribute('placeholder'));
        self::assertSame('en', $xpath->query('//form[.//input[@name="city"]]//input[@name="language_code"]')->item(0)?->getAttribute('value'));
        self::assertSame(0, $xpath->query('//input[@name="city_nl" or @name="city_en"]')->length);

        $response = self::$server->request('POST', '/api/admin/update-site-settings.php', $session, [
            'csrf_token' => $this->csrf($session),
            'site_name' => (string) ((new SiteSettingRepository())->findAll()['site_name'] ?? 'Website'),
            'language_code' => 'en',
            'city' => 'Nijmegen, the Netherlands',
        ]);

        self::assertSame('/admin/settings.php?saved=1', $response['location']);
        LocalizedSiteSettings::clearCache();
        self::assertSame(
            ['nl' => 'Nijmegen, Nederland', 'en' => 'Nijmegen, the Netherlands'],
            LocalizedSiteSettings::words(LocalizedSiteSettings::CITY)
        );
    }

    public function testARefusedPlaceIsKeptInItsLanguageAndWritesNothing(): void
    {
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::CITY => 'Utrecht']);
        $session = $this->signIn([AdminPermissions::SETTINGS_MANAGE], null);
        $typed = str_repeat('x', 151);

        $response = self::$server->request('POST', '/api/admin/update-site-settings.php', $session, [
            'csrf_token' => $this->csrf($session),
            'site_name' => (string) ((new SiteSettingRepository())->findAll()['site_name'] ?? 'Website'),
            'language_code' => 'nl',
            'city' => $typed,
        ]);

        self::assertSame('/admin/settings.php', $response['location']);
        LocalizedSiteSettings::clearCache();
        self::assertSame('Utrecht', LocalizedSiteSettings::raw(LocalizedSiteSettings::CITY, 'nl'));
        self::assertSame($typed, $this->xpath($this->get($session, '/admin/settings.php'))->query('//input[@name="city"]')->item(0)?->getAttribute('value'));

        $unknown = self::$server->request('POST', '/api/admin/update-site-settings.php', $session, [
            'csrf_token' => $this->csrf($session),
            'site_name' => (string) ((new SiteSettingRepository())->findAll()['site_name'] ?? 'Website'),
            'language_code' => 'xx',
            'city' => 'Nergens',
        ]);
        self::assertSame('/admin/settings.php', $unknown['location'], 'a language the website does not have is refused');
    }

    // ---------------------------------------------------------------- footer

    public function testTheFooterTextsSaveOneLanguageAtATimeIncludingAThirdOne(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        $session = $this->signIn([AdminPermissions::PAGES_MANAGE], 'de');
        $token = $this->csrf($session);
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_DESCRIPTION => 'Wij maken dingen.', LocalizedSiteSettings::FOOTER_SLOGAN => 'Met zorg gemaakt']);
        LocalizedSiteSettings::save('en', [LocalizedSiteSettings::FOOTER_SLOGAN => 'Made with care']);

        $screen = $this->get($session, '/admin/footer.php');
        self::assertSame(2, substr_count($screen, 'name="language_code" value="de"'), 'both text cards are in the chosen language');

        foreach ([
            ['section' => 'brand', 'footer_description' => 'Wir machen Dinge.'],
            ['section' => 'bottom', 'footer_slogan_enabled' => '1', 'footer_copyright_template' => '© {{year}} {{site_name}}', 'footer_slogan' => 'Mit Sorgfalt gemacht'],
        ] as $fields) {
            $saved = self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, $fields + ['csrf_token' => $token, 'language_code' => 'de']);
            self::assertStringContainsString('saved=1', $saved['location'], $fields['section']);
        }

        LocalizedSiteSettings::clearCache();
        self::assertSame(['nl' => 'Wij maken dingen.', 'de' => 'Wir machen Dinge.'], LocalizedSiteSettings::words(LocalizedSiteSettings::FOOTER_DESCRIPTION));
        self::assertSame(['nl' => 'Met zorg gemaakt', 'en' => 'Made with care', 'de' => 'Mit Sorgfalt gemacht'], LocalizedSiteSettings::words(LocalizedSiteSettings::FOOTER_SLOGAN));

        $footer = $this->get(null, '/index.php');
        self::assertStringContainsString('<p data-nl="Wij maken dingen." data-en="Wij maken dingen.">Wij maken dingen.</p>', $footer);
        self::assertStringContainsString('<span data-nl="Met zorg gemaakt" data-en="Made with care">Met zorg gemaakt</span>', $footer);

        LocalizedSiteSettings::save('de', [LocalizedSiteSettings::FOOTER_DESCRIPTION => '', LocalizedSiteSettings::FOOTER_SLOGAN => '']);
    }

    public function testARefusedFooterTextStartsOutUnsavedWithWhatWasTyped(): void
    {
        $session = $this->signIn([AdminPermissions::PAGES_MANAGE], null);
        $typed = str_repeat('y', 201);

        self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $this->csrf($session), 'section' => 'bottom', 'language_code' => 'nl',
            'footer_copyright_template' => '© {{year}} {{site_name}}', 'footer_slogan' => $typed,
        ]);

        $xpath = $this->xpath($this->get($session, '/admin/footer.php'));
        $form = $xpath->query('//section[@id="footer-bottom"]//form')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertTrue($form->hasAttribute('data-save-bar-unsaved'));
        self::assertSame($typed, $xpath->query('//input[@name="footer_slogan"]')->item(0)?->getAttribute('value'));
        LocalizedSiteSettings::clearCache();
        self::assertSame([], LocalizedSiteSettings::words(LocalizedSiteSettings::FOOTER_SLOGAN), 'nothing was written');
    }

    public function testAClosingLineThatLooksLikeMarkupStaysText(): void
    {
        (new SiteSettingRepository())->upsertMany(['footer_slogan_enabled' => '1']);
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_SLOGAN => '<script>alert(1)</script> zorg']);

        $footer = $this->get(null, '/index.php');

        self::assertStringNotContainsString('<script>alert(1)</script>', $footer);
        self::assertStringContainsString('data-nl="&lt;script&gt;alert(1)&lt;/script&gt; zorg"', $footer);
    }

    // --------------------------------------------------------------- helpers

    /** @param list<string> $permissions */
    private function signIn(array $permissions, ?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn($permissions);

        if ($editingLanguage !== null) {
            (new AdminUserRepository())->updateContentEditingLanguage(
                (int) $this->accounts->read($session, 'admin_user_id'),
                $editingLanguage
            );
        }

        return $session;
    }

    private function csrf(string $session): string
    {
        return (string) $this->accounts->read($session, 'csrf_token');
    }

    private function get(?string $session, string $path): string
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
