<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\FooterRepository;
use App\Repository\FooterSocialLinkRepository;
use App\Repository\PageRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminPermissions;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
use App\Service\PageContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The one Footer screen of Footer phase B over real HTTP: the screen, its
 * endpoints, and what the public footer renders from what they store
 * (HEADER-FOOTER.md).
 *
 * THE CONTRACT
 *   - Footer is one screen with four cards; the footer description is edited
 *     there and nowhere else, and the company's own details are shown, not
 *     edited;
 *   - social profiles are rows: added, edited, hidden, moved and deleted,
 *     two on one network allowed, an address its network does not own
 *     refused with what was typed kept on screen;
 *   - the footer renders the visible profiles in their order, and nothing
 *     at all without them;
 *   - footer links follow the header's link contract: a page by its id, an
 *     address as typed, ↑/↓, and a route of a switched-off module that is
 *     off the website but kept, and back when the module is;
 *   - the two settings cards each write exactly their own keys, and a switch
 *     that hides something never loses what it hides;
 *   - the old "Slotregel & social media" screen redirects here.
 *
 * Two built-in servers on this checkout (Tests\Support\BuiltInServer): one
 * with the modules this process runs with, one with the Shop off. Every row,
 * page, account and setting is this test's own and removed or restored by
 * exact key in tearDown(). The public footer is read from /index.php and only
 * this test's own rows are asserted, so whatever else the test database holds
 * does not matter.
 */
final class FooterAdminHttpTest extends TestCase
{
    /** Every setting a test here may write; restored in tearDown(). */
    private const SETTINGS = [
        'footer_show_logo',
        'footer_show_company_name',
        'footer_show_email',
        'footer_show_phone',
        'footer_show_kvk',
        'footer_copyright_template',
        'footer_slogan_enabled',
        'email',
    ];

    /** The website text per language a test here may write (LocalizedSiteSettings); restored in tearDown(). */
    private const LOCALIZED = [LocalizedSiteSettings::FOOTER_DESCRIPTION, LocalizedSiteSettings::FOOTER_SLOGAN];

    private static ?BuiltInServer $server = null;
    private static ?BuiltInServer $shopOff = null;

    private AdminTestSession $accounts;
    private FooterRepository $footer;
    private FooterSocialLinkRepository $social;
    private PageRepository $pages;

    /** @var list<int> */
    private array $columnIds = [];

    /** @var list<int> */
    private array $socialIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var array<string, string> */
    private array $originalSettings = [];

    /** @var array<string, array<string, string>> */
    private array $originalLocalized = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PERSONALIZATION_ENABLED' => 'true']);
        self::$shopOff = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PERSONALIZATION_ENABLED' => 'false']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$shopOff?->stop();
        self::$server = null;
        self::$shopOff = null;
    }

    protected function setUp(): void
    {
        foreach ([self::$server, self::$shopOff] as $server) {
            if ($server === null || !$server->answers()) {
                $this->markTestSkipped("could not start PHP's built-in web server for this test");
            }
        }

        $this->accounts = new AdminTestSession();
        $this->footer = new FooterRepository();
        $this->social = new FooterSocialLinkRepository();
        $this->pages = new PageRepository();

        $stored = (new SiteSettingRepository())->findAll();
        foreach (self::SETTINGS as $key) {
            $this->originalSettings[$key] = (string) ($stored[$key] ?? '');
        }

        LocalizedSiteSettings::clearCache();
        foreach (self::LOCALIZED as $key) {
            $this->originalLocalized[$key] = LocalizedSiteSettings::words($key);
        }
    }

    protected function tearDown(): void
    {
        // Columns first: their links point at this test's pages (RESTRICT).
        foreach ($this->columnIds as $id) {
            $this->footer->deleteColumn($id);
        }
        foreach ($this->socialIds as $id) {
            $this->social->delete($id);
        }
        foreach ($this->pageIds as $id) {
            $this->pages->delete($id);
        }
        (new SiteSettingRepository())->upsertMany($this->originalSettings);
        foreach (SiteLanguages::all() as $language) {
            $words = [];
            foreach (self::LOCALIZED as $key) {
                $words[$key] = $this->originalLocalized[$key][$language->code] ?? '';
            }
            LocalizedSiteSettings::save($language->code, $words);
        }
        LocalizedSiteSettings::clearCache();

        $this->columnIds = [];
        $this->socialIds = [];
        $this->pageIds = [];
        $this->accounts->forget();
    }

    // ------------------------------------------------------------ the screen

    public function testFooterIsOneScreenWithFourCardsAndTheOnlyDescriptionEditor(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE, AdminPermissions::SETTINGS_MANAGE]);

        $response = self::$server->request('GET', '/admin/footer.php', $session);
        $this->assertSame(200, $response['status']);
        $xpath = $this->xpath($response['body']);

        foreach (['footer-brand', 'footer-columns', 'footer-social', 'footer-bottom'] as $card) {
            $this->assertSame(1, $xpath->query('//section[@id="' . $card . '"]')->length, $card);
        }
        $this->assertSame(1, $xpath->query('//textarea[@name="footer_description"]')->length, 'the description is edited here, in one language');
        $this->assertSame(0, $xpath->query('//*[@name="footer_description_nl" or @name="footer_slogan_en"]')->length, 'no V1 language pane');
        $this->assertSame(1, $xpath->query('//input[@name="footer_copyright_template"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="footer_slogan_enabled"][@role="switch"]')->length);
        foreach (['footer_show_logo', 'footer_show_company_name', 'footer_show_email', 'footer_show_phone', 'footer_show_kvk'] as $switch) {
            $this->assertSame(1, $xpath->query('//input[@name="' . $switch . '"][@role="switch"]')->length, $switch);
        }

        // The company's details are shown, never edited here.
        foreach (['site_name', 'email', 'company_phone', 'kvk_number'] as $companyField) {
            $this->assertSame(0, $xpath->query('//*[@name="' . $companyField . '"]')->length, $companyField . ' belongs to Site-instellingen');
        }
        $this->assertSame(1, $xpath->query('//section[@id="footer-brand"]//a[@href="/admin/settings.php"]')->length, 'a way to where they are edited');

        $this->assertSame(1, $xpath->query('//*[@data-save-bar]')->length, 'the save bar');
        $this->assertSame(1, $xpath->query('//dialog[@data-admin-confirm-dialog]')->length, 'the CMS dialog');
        $this->assertStringNotContainsString('onsubmit', $response['body'], 'no native confirm()');
        foreach (['sort_order', 'network key', 'link_type', 'target_route', 'is_visible', 'Applicatieroute'] as $jargon) {
            $this->assertStringNotContainsString($jargon, strip_tags($response['body']), 'no technical name reaches the editor: ' . $jargon);
        }

        $settings = self::$server->request('GET', '/admin/settings.php', $session);
        $this->assertSame(200, $settings['status']);
        $this->assertStringNotContainsString('name="footer_description', $settings['body'], 'Site-instellingen no longer edits it');
        $this->assertSame(1, $this->xpath($settings['body'])->query('//*[@data-footer-description-moved]//a[starts-with(@href, "/admin/footer.php")]')->length, 'and says where it went');

        // The sidebar has one footer entry.
        $this->assertSame(0, $xpath->query('//a[@href="/admin/header-footer.php"]')->length);
        $this->assertGreaterThanOrEqual(1, $xpath->query('//a[@href="/admin/footer.php"]')->length);
    }

    public function testTheOldScreenRedirectsToTheFooterScreen(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$other] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);

        $redirect = self::$server->request('GET', '/admin/header-footer.php', $session);
        $this->assertSame(302, $redirect['status']);
        $this->assertSame('/admin/footer.php#footer-bottom', $redirect['location']);

        $this->assertSame('/admin/login.php', self::$server->request('GET', '/admin/header-footer.php')['location'], 'signed out: the login, not the footer');
        $this->assertSame(403, self::$server->request('GET', '/admin/header-footer.php', $other)['status']);
        $this->assertSame(404, self::$server->request('POST', '/api/admin/update-header-footer-settings.php', $session)['status'], 'the old endpoint is gone');
    }

    // ------------------------------------------------------------- settings

    /**
     * Each card writes exactly its own keys, a switch that hides something
     * keeps what it hides, and the public footer follows.
     */
    public function testTheSettingsCardsRoundTripAndHidingLosesNothing(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        (new SiteSettingRepository())->upsertMany(['email' => 'footer-b@example.test']);

        $bottom = self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'bottom',
            'footer_copyright_template' => '© {{year}} Footer B test',
            'footer_slogan_enabled' => '1', 'language_code' => 'nl', 'footer_slogan' => 'Met zorg gemaakt (fase B)',
        ]);
        $this->assertSame('/admin/footer.php?saved=1#footer-bottom', $bottom['location']);

        // The same card in English writes the English line and leaves the Dutch one.
        self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'bottom',
            'footer_copyright_template' => '© {{year}} Footer B test',
            'footer_slogan_enabled' => '1', 'language_code' => 'en', 'footer_slogan' => 'Made with care (phase B)',
        ]);

        $brand = self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'brand',
            'footer_show_email' => '1',
            'language_code' => 'nl', 'footer_description' => 'Omschrijving van fase B',
        ]);
        $this->assertSame('/admin/footer.php?saved=1#footer-brand', $brand['location']);

        $stored = (new SiteSettingRepository())->findAll();
        $this->assertSame(['0', '0', '1', '0', '0'], [$stored['footer_show_logo'], $stored['footer_show_company_name'], $stored['footer_show_email'], $stored['footer_show_phone'], $stored['footer_show_kvk']], 'an unticked switch is stored as off');
        LocalizedSiteSettings::clearCache();
        $this->assertSame(
            ['nl' => 'Met zorg gemaakt (fase B)', 'en' => 'Made with care (phase B)'],
            LocalizedSiteSettings::words(LocalizedSiteSettings::FOOTER_SLOGAN),
            'each language kept its own line, and the brand card left the bottom card alone'
        );

        $footer = $this->publicFooter();
        $this->assertStringContainsString('<span>Met zorg gemaakt (fase B)</span>', $footer);
        $this->assertStringContainsString('Footer B test', $footer);
        $this->assertStringContainsString('Omschrijving van fase B', $footer);
        $this->assertStringContainsString('mailto:footer-b@example.test', $footer);

        // Switch the slogan and the e-mail address off: gone from the footer,
        // kept in the database, back when switched on.
        self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'bottom',
            'footer_copyright_template' => '© {{year}} Footer B test',
            'language_code' => 'nl', 'footer_slogan' => 'Met zorg gemaakt (fase B)',
        ]);
        self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'brand', 'language_code' => 'nl', 'footer_description' => '',
        ]);

        $footer = $this->publicFooter();
        $this->assertStringNotContainsString('Met zorg gemaakt (fase B)', $footer);
        $this->assertStringNotContainsString('mailto:footer-b@example.test', $footer);
        $this->assertStringNotContainsString('Omschrijving van fase B', $footer, 'no description, no paragraph');
        $stored = (new SiteSettingRepository())->findAll();
        LocalizedSiteSettings::clearCache();
        $this->assertSame(['0', 'Met zorg gemaakt (fase B)', 'footer-b@example.test'], [$stored['footer_slogan_enabled'], LocalizedSiteSettings::raw(LocalizedSiteSettings::FOOTER_SLOGAN, 'nl'), $stored['email']]);

        $brandColumn = $this->xpath($footer)->query('//footer//div[contains(@class, "footer-grid")]/div[1]/p');
        foreach ($brandColumn as $paragraph) {
            $this->assertNotSame('', trim($paragraph->textContent), 'no empty paragraph in the company block');
        }

        self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'bottom', 'footer_slogan_enabled' => '1',
            'footer_copyright_template' => '', 'language_code' => 'nl', 'footer_slogan' => 'Met zorg gemaakt (fase B)',
        ]);
        $this->assertStringContainsString('<span>Met zorg gemaakt (fase B)</span>', $this->publicFooter(), 'switched on again, the same text is back');
        $this->assertSame('© {{year}} {{site_name}}', (new SiteSettingRepository())->findAll()['footer_copyright_template'], 'an empty copyright text is the standard one');
    }

    public function testASettingsSaveIsRefusedWholeAndAnUnknownCardWritesNothing(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        LocalizedSiteSettings::clearCache();
        $before = LocalizedSiteSettings::words(LocalizedSiteSettings::FOOTER_DESCRIPTION);

        $tooLong = self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'brand', 'language_code' => 'nl', 'footer_description' => str_repeat('a', 501),
        ]);
        $this->assertSame('/admin/footer.php#footer-brand', $tooLong['location'], 'back to the card, without saved=1');
        LocalizedSiteSettings::clearCache();
        $this->assertSame($before, LocalizedSiteSettings::words(LocalizedSiteSettings::FOOTER_DESCRIPTION));

        $screen = self::$server->request('GET', '/admin/footer.php', $session);
        $brand = $this->xpath($screen['body'])->query('//section[@id="footer-brand"]//form')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $brand);
        $this->assertTrue($brand->hasAttribute('data-save-bar-unsaved'), 'the refused input starts out unsaved');
        $this->assertStringContainsString(str_repeat('a', 501), $screen['body'], 'what was typed is kept');

        $unknown = self::$server->request('POST', '/api/admin/update-footer-settings.php', $session, [
            'csrf_token' => $token, 'section' => 'header_cta', 'footer_show_logo' => '1',
        ]);
        $this->assertSame(400, $unknown['status']);
    }

    // --------------------------------------------------------- social media

    public function testSocialProfilesAreAddedEditedHiddenMovedAndDeleted(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $tag = 'fb' . bin2hex(random_bytes(3));

        $first = $this->createSocial($session, $token, 'instagram', 'https://www.instagram.com/' . $tag . '-winkel/');
        $second = $this->createSocial($session, $token, 'instagram', 'https://www.instagram.com/' . $tag . '-atelier/');
        $third = $this->createSocial($session, $token, 'pinterest', 'https://www.pinterest.co.uk/' . $tag . '/');

        $this->assertSame(1, (int) $this->social->findById($second)['is_visible'], 'a new profile is visible');
        $this->assertSame(
            [
                ['https://www.instagram.com/' . $tag . '-winkel/', '(1)'],
                ['https://www.instagram.com/' . $tag . '-atelier/', '(2)'],
                ['https://www.pinterest.co.uk/' . $tag . '/', ''],
            ],
            $this->renderedProfiles($tag),
            'two on one network, both rendered and told apart; a real UK address accepted'
        );

        $moved = self::$server->request('POST', '/api/admin/move-footer-social-link.php', $session, ['csrf_token' => $token, 'id' => (string) $third, 'direction' => 'up']);
        $this->assertSame('/admin/footer.php#footer-social-' . $third, $moved['location']);
        $this->assertSame(
            ['https://www.instagram.com/' . $tag . '-winkel/', 'https://www.pinterest.co.uk/' . $tag . '/', 'https://www.instagram.com/' . $tag . '-atelier/'],
            array_column($this->renderedProfiles($tag), 0),
            'the footer follows the new order'
        );

        // Edit: another network and address, and hidden (no is_visible sent).
        $updated = self::$server->request('POST', '/api/admin/update-footer-social-link.php', $session, [
            'csrf_token' => $token, 'id' => (string) $first, 'network' => 'facebook', 'url' => 'https://www.facebook.com/' . $tag,
        ]);
        $this->assertSame('/admin/footer.php?saved=1#footer-social-' . $first, $updated['location']);
        $row = $this->social->findById($first);
        $this->assertSame(['facebook', 'https://www.facebook.com/' . $tag, 0], [$row['network'], $row['url'], (int) $row['is_visible']]);
        $this->assertSame(
            ['https://www.pinterest.co.uk/' . $tag . '/', 'https://www.instagram.com/' . $tag . '-atelier/'],
            array_column($this->renderedProfiles($tag), 0),
            'a hidden profile is not rendered, and the remaining Instagram needs no number'
        );
        $this->assertSame('', $this->renderedProfiles($tag)[1][1]);

        $screen = $this->xpath(self::$server->request('GET', '/admin/footer.php', $session)['body']);
        $hiddenRow = $screen->query('//*[@id="footer-social-' . $first . '"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $hiddenRow);
        $this->assertStringContainsString('Verborgen', $hiddenRow->textContent, 'the screen says it is hidden');
        $this->assertSame('https://www.facebook.com/' . $tag, $screen->query('.//input[@name="url"]', $hiddenRow)->item(0)?->getAttribute('value'), 'and keeps its address');
        $delete = $screen->query('//form[@action="/api/admin/delete-footer-social-link.php"][.//input[@name="id"][@value="' . $second . '"]]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $delete);
        $this->assertSame('Social media verwijderen?', $delete->getAttribute('data-admin-confirm-title'), 'delete asks first');
        $this->assertStringContainsString($tag . '-atelier', $delete->getAttribute('data-admin-confirm'));

        // Shown again: the same profile is back.
        self::$server->request('POST', '/api/admin/update-footer-social-link.php', $session, [
            'csrf_token' => $token, 'id' => (string) $first, 'network' => 'facebook', 'url' => 'https://www.facebook.com/' . $tag, 'is_visible' => '1',
        ]);
        $this->assertContains('https://www.facebook.com/' . $tag, array_column($this->renderedProfiles($tag), 0));

        $deleted = self::$server->request('POST', '/api/admin/delete-footer-social-link.php', $session, ['csrf_token' => $token, 'id' => (string) $second]);
        $this->assertSame('/admin/footer.php?deleted=social#footer-social', $deleted['location']);
        $this->assertNull($this->social->findById($second));
        $this->assertNotContains('https://www.instagram.com/' . $tag . '-atelier/', array_column($this->renderedProfiles($tag), 0));
    }

    public function testAnAddressItsNetworkDoesNotOwnIsRefusedAndKeptOnScreen(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $tag = 'fb' . bin2hex(random_bytes(3));
        $countBefore = count($this->social->findAll());

        foreach ([['pinterest', 'https://pin.nl/' . $tag], ['instagram', 'javascript:alert(1)'], ['instagram', 'http://instagram.com/' . $tag], ['myspace', 'https://myspace.com/' . $tag], ['instagram', '']] as [$network, $url]) {
            $refused = self::$server->request('POST', '/api/admin/create-footer-social-link.php', $session, ['csrf_token' => $token, 'network' => $network, 'url' => $url]);
            $this->assertSame('/admin/footer.php#footer-social-new', $refused['location'], $network . ' ' . $url);
        }
        $this->assertCount($countBefore, $this->social->findAll(), 'nothing refused was stored');

        self::$server->request('POST', '/api/admin/create-footer-social-link.php', $session, ['csrf_token' => $token, 'network' => 'linkedin', 'url' => 'https://linked.com/' . $tag]);
        $screen = self::$server->request('GET', '/admin/footer.php', $session);
        $xpath = $this->xpath($screen['body']);
        $add = $xpath->query('//form[@id="footer-social-new"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $add);
        $this->assertStringContainsString('hoort niet bij LinkedIn', $add->textContent, 'the message names the network');
        $this->assertSame('https://linked.com/' . $tag, $xpath->query('.//input[@name="url"]', $add)->item(0)?->getAttribute('value'), 'what was typed is kept');
        $this->assertSame('true', $xpath->query('.//input[@name="url"]', $add)->item(0)?->getAttribute('aria-invalid'));
        $this->assertSame('linkedin', $xpath->query('.//select[@name="network"]/option[@selected]', $add)->item(0)?->getAttribute('value'));

        // An existing row keeps its stored address when an edit is refused.
        $id = $this->createSocial($session, $token, 'etsy', 'https://www.etsy.com/shop/' . $tag);
        $refused = self::$server->request('POST', '/api/admin/update-footer-social-link.php', $session, [
            'csrf_token' => $token, 'id' => (string) $id, 'network' => 'etsy', 'url' => 'https://etsy.evil.example/' . $tag, 'is_visible' => '1',
        ]);
        $this->assertSame('/admin/footer.php#footer-social-' . $id, $refused['location'], 'no saved=1: the save bar sees a refusal');
        $this->assertSame('https://www.etsy.com/shop/' . $tag, $this->social->findById($id)['url']);

        $row = $this->xpath(self::$server->request('GET', '/admin/footer.php', $session)['body'])->query('//*[@id="footer-social-' . $id . '"]//form[@action="/api/admin/update-footer-social-link.php"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $row);
        $this->assertTrue($row->hasAttribute('data-save-bar-unsaved'));
        $this->assertStringContainsString('etsy.evil.example', $row->ownerDocument->saveHTML($row));
    }

    public function testNoSocialRowIsRenderedWithoutVisibleProfiles(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        if ($this->social->findVisible() !== []) {
            $this->markTestSkipped('this test database already has visible social profiles of its own');
        }

        $this->assertStringNotContainsString('social-row', $this->publicFooter(), 'no profiles, no row and no heading');

        $id = $this->createSocial($session, $token, 'tiktok', 'https://www.tiktok.com/@footer-b-hidden');
        self::$server->request('POST', '/api/admin/update-footer-social-link.php', $session, [
            'csrf_token' => $token, 'id' => (string) $id, 'network' => 'tiktok', 'url' => 'https://www.tiktok.com/@footer-b-hidden',
        ]);
        $this->assertStringNotContainsString('social-row', $this->publicFooter(), 'only a hidden profile: still no row');
    }

    // ---------------------------------------------------- columns and links

    public function testColumnsAndLinksFollowTheHeadersLinkContract(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $tag = 'Fb' . bin2hex(random_bytes(3));
        $pageId = $this->page('Voorwaarden ' . $tag);

        $first = $this->createColumn($session, $token, $tag . ' Informatie');
        $second = $this->createColumn($session, $token, $tag . ' Leeg');

        $internal = $this->createLink($session, $token, $first, [
            'label' => $tag . ' Voorwaarden', 'link_type' => 'page', 'target_page_id' => (string) $pageId, 'is_visible' => '1',
            'external_url' => 'https://example.com/restje',
        ]);
        $row = $this->footer->findLinkById($internal);
        $this->assertSame(['page', $pageId, null], [$row['link_type'], (int) $row['target_page_id'], $row['external_url']], 'a page by its id, never an address');

        // As long as a label may be (100), with no space at the end that the
        // endpoint would trim.
        $longLabel = mb_substr($tag . ' ' . str_repeat('Heel lange linktekst ', 5), 0, 99) . 'x';
        $external = $this->createLink($session, $token, $first, [
            'label' => $longLabel, 'link_type' => 'external', 'external_url' => 'https://example.com/' . $tag, 'open_in_new_tab' => '1', 'is_visible' => '1',
        ]);

        $page = $this->pages->findById($pageId);
        $this->assertSame(
            [[$tag . ' Voorwaarden', PageContent::publicUrl($page)], [$longLabel, 'https://example.com/' . $tag]],
            $this->renderedColumn($tag . ' Informatie')
        );
        $this->assertNull($this->renderedColumn($tag . ' Leeg'), 'a column without links is not rendered');

        // ↑/↓ for links and columns, back to the row that moved.
        $movedLink = self::$server->request('POST', '/api/admin/move-footer-link.php', $session, ['csrf_token' => $token, 'id' => (string) $external, 'direction' => 'up']);
        $this->assertSame('/admin/footer.php#footer-link-' . $external, $movedLink['location']);
        $this->assertSame('https://example.com/' . $tag, $this->renderedColumn($tag . ' Informatie')[0][1]);

        $movedColumn = self::$server->request('POST', '/api/admin/move-footer-column.php', $session, ['csrf_token' => $token, 'id' => (string) $second, 'direction' => 'up']);
        $this->assertSame('/admin/footer.php#footer-column-' . $second, $movedColumn['location']);
        $order = array_map(static fn (array $c): int => (int) $c['id'], $this->footer->findAllColumnsForAdmin());
        $this->assertLessThan(array_search($first, $order, true), array_search($second, $order, true));

        // The screen: words for the destination, ↑/↓ on every row, a delete
        // that asks, and the empty column called out.
        $xpath = $this->xpath(self::$server->request('GET', '/admin/footer.php', $session)['body']);
        $this->assertStringContainsString('Pagina: Voorwaarden ' . $tag, (string) $xpath->query('//*[@id="footer-link-' . $internal . '"]')->item(0)?->textContent);
        $this->assertStringContainsString('Niet op de website', (string) $xpath->query('//*[@id="footer-column-' . $second . '"]')->item(0)?->textContent);
        foreach ([['move-footer-column.php', $first], ['move-footer-link.php', $internal], ['move-footer-link.php', $external]] as [$endpoint, $id]) {
            foreach (['up', 'down'] as $direction) {
                $this->assertSame(1, $xpath->query('//form[@action="/api/admin/' . $endpoint . '"][.//input[@name="id"][@value="' . $id . '"]][.//input[@name="direction"][@value="' . $direction . '"]]')->length, "{$endpoint} {$direction} {$id}");
            }
        }
        $this->assertSame('Kolom verwijderen?', $xpath->query('//form[@action="/api/admin/delete-footer-column.php"][.//input[@value="' . $first . '"]]')->item(0)?->getAttribute('data-admin-confirm-title'));
        $this->assertSame('true', $xpath->query('//*[@id="footer-link-' . $internal . '"]/span[contains(@class, "admin-drag-handle")]')->item(0)?->getAttribute('aria-hidden'));

        // Hiding the column keeps its links; showing it brings them back.
        self::$server->request('POST', '/api/admin/toggle-footer-column.php', $session, ['csrf_token' => $token, 'id' => (string) $first, 'is_visible' => '0']);
        $this->assertNull($this->renderedColumn($tag . ' Informatie'));
        $this->assertCount(2, $this->footer->findLinksForColumn($first));
        self::$server->request('POST', '/api/admin/toggle-footer-column.php', $session, ['csrf_token' => $token, 'id' => (string) $first, 'is_visible' => '1']);
        $this->assertCount(2, $this->renderedColumn($tag . ' Informatie'));

        // The link editor shows the page, switches and the save bar.
        $editor = $this->xpath(self::$server->request('GET', '/admin/footer-link.php?id=' . $internal, $session)['body']);
        $this->assertSame((string) $pageId, $editor->query('//select[@name="target_page_id"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame(1, $editor->query('//input[@name="is_visible"][@role="switch"][@checked]')->length);
        $this->assertSame(1, $editor->query('//*[@data-save-bar]')->length);
        $this->assertSame(0, $editor->query('//script[not(@src)]')->length, 'no inline script');

        $deleted = self::$server->request('POST', '/api/admin/delete-footer-link.php', $session, ['csrf_token' => $token, 'id' => (string) $external]);
        $this->assertSame('/admin/footer.php?deleted=link#footer-column-' . $first, $deleted['location']);
        $this->assertNull($this->footer->findLinkById($external));
    }

    /**
     * A footer link to a route of a switched-off module is off the website,
     * kept by the editor, and back with the module.
     */
    public function testALinkForASwitchedOffModuleIsHiddenKeptAndBack(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $tag = 'Fb' . bin2hex(random_bytes(3));
        $column = $this->createColumn($session, $token, $tag . ' Winkel');
        $link = $this->createLink($session, $token, $column, ['label' => $tag . ' Naar de winkel', 'link_type' => 'route', 'target_route' => 'shop', 'is_visible' => '1']);

        $this->assertNull($this->renderedColumn($tag . ' Winkel', self::$shopOff), 'no link into a 404, and no heading over nothing');

        $editor = self::$shopOff->request('GET', '/admin/footer-link.php?id=' . $link, $session);
        $xpath = $this->xpath($editor['body']);
        $this->assertSame('shop', $xpath->query('//select[@name="target_route"]/option[@selected]')->item(0)?->getAttribute('value'), 'the stored route stays selected');
        $this->assertSame(1, $xpath->query('//*[contains(@class, "admin-alert--warning")]')->length, 'the editor is told why it is not on the website');

        $saved = self::$shopOff->request('POST', '/api/admin/update-footer-link.php', $session, [
            'csrf_token' => $token, 'id' => (string) $link, 'language_code' => 'nl', 'label' => $tag . ' Naar de webwinkel', 'link_type' => 'route', 'target_route' => 'shop', 'is_visible' => '1',
        ]);
        $this->assertSame('/admin/footer-link.php?id=' . $link . '&saved=1', $saved['location']);
        \App\Service\FooterLocalization::clearCache();
        $this->assertSame(['shop', $tag . ' Naar de webwinkel'], [$this->footer->findLinkById($link)['target_route'], \App\Service\FooterLocalization::rawLinkLabel($link, 'nl')]);

        $this->assertSame([[$tag . ' Naar de webwinkel', '/shop.php']], $this->renderedColumn($tag . ' Winkel', self::$server));
    }

    public function testTheMoveEndpointsKeepTheirGuards(): void
    {
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$other, $otherToken] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $column = $this->createColumn($editor, $token, 'Fb bewaakt');
        $social = $this->createSocial($editor, $token, 'youtube', 'https://www.youtube.com/@bewaakt');

        foreach (['move-footer-column.php' => $column, 'move-footer-social-link.php' => $social] as $endpoint => $id) {
            $fields = ['id' => (string) $id, 'direction' => 'up'];
            $url = '/api/admin/' . $endpoint;

            $this->assertSame(401, self::$server->request('POST', $url, null, $fields + ['csrf_token' => $token])['status'], $endpoint . ' signed out');
            $this->assertSame(403, self::$server->request('POST', $url, $other, $fields + ['csrf_token' => $otherToken])['status'], $endpoint . ' without managing pages');
            $this->assertSame(405, self::$server->request('GET', $url, $editor)['status'], $endpoint);
            $this->assertSame(403, self::$server->request('POST', $url, $editor, $fields + ['csrf_token' => str_repeat('0', 64)])['status'], $endpoint . ' a wrong token');
            $this->assertSame(400, self::$server->request('POST', $url, $editor, ['id' => (string) $id, 'direction' => 'sideways', 'csrf_token' => $token])['status'], $endpoint);
            $this->assertSame(404, self::$server->request('POST', $url, $editor, ['id' => '999999999', 'direction' => 'up', 'csrf_token' => $token])['status'], $endpoint);
        }
    }

    // --------------------------------------------------------------- helpers

    private function createSocial(string $session, string $token, string $network, string $url): int
    {
        $response = self::$server->request('POST', '/api/admin/create-footer-social-link.php', $session, ['csrf_token' => $token, 'network' => $network, 'url' => $url]);

        $this->assertMatchesRegularExpression('#^/admin/footer\.php\?saved=1\#footer-social-(\d+)$#', $response['location'], (string) json_encode($this->accounts->read($session, 'admin_footer_social_error')));
        preg_match('#footer-social-(\d+)$#', $response['location'], $match);
        $this->socialIds[] = (int) $match[1];

        return (int) $match[1];
    }

    private function createColumn(string $session, string $token, string $title): int
    {
        $response = self::$server->request('POST', '/api/admin/create-footer-column.php', $session, ['csrf_token' => $token, 'title' => $title]);

        $this->assertMatchesRegularExpression('#^/admin/footer\.php\?saved=1\#footer-column-(\d+)$#', $response['location']);
        preg_match('#footer-column-(\d+)$#', $response['location'], $match);
        $this->columnIds[] = (int) $match[1];

        return (int) $match[1];
    }

    /** @param array<string, string> $fields */
    private function createLink(string $session, string $token, int $columnId, array $fields): int
    {
        $response = self::$server->request('POST', '/api/admin/create-footer-link.php', $session, $fields + ['csrf_token' => $token, 'column_id' => (string) $columnId]);

        $this->assertMatchesRegularExpression('#^/admin/footer-link\.php\?id=(\d+)&saved=1$#', $response['location'], (string) json_encode($this->accounts->read($session, 'admin_footer_link_errors')));
        preg_match('#id=(\d+)#', $response['location'], $match);

        return (int) $match[1];
    }

    private function page(string $title): int
    {
        $key = 'zz-footer-http-' . bin2hex(random_bytes(4));
        $id = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], $title);
        $this->pageIds[] = $id;

        return $id;
    }

    private function publicFooter(?BuiltInServer $server = null): string
    {
        $response = ($server ?? self::$server)->request('GET', '/index.php');
        $this->assertSame(200, $response['status'], 'the homepage renders');

        $start = strpos($response['body'], '<footer class="site-footer">');
        $this->assertNotFalse($start, 'the homepage has the footer');

        return substr($response['body'], (int) $start, (int) strpos($response['body'], '</footer>', (int) $start) - (int) $start + 9);
    }

    /**
     * This test's own social links as the footer renders them: the href and
     * the number in the accessible name, in document order.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function renderedProfiles(string $tag): array
    {
        $profiles = [];
        foreach ($this->xpath($this->publicFooter())->query('//ul[@class="social-row"]/li/a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if (!str_contains($href, $tag)) {
                continue;
            }

            $this->assertSame('_blank', $anchor->getAttribute('target'));
            $this->assertSame('noopener noreferrer me', $anchor->getAttribute('rel'));
            $this->assertSame('true', $anchor->getElementsByTagName('svg')->item(0)?->getAttribute('aria-hidden'));

            preg_match('/\((\d+)\)$/', $anchor->getAttribute('aria-label'), $number);
            $profiles[] = [$href, isset($number[1]) ? '(' . $number[1] . ')' : ''];
        }

        return $profiles;
    }

    /**
     * One column of the public footer by its title: its links' labels and
     * hrefs, or null when the column is not rendered.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function renderedColumn(string $title, ?BuiltInServer $server = null): ?array
    {
        $xpath = $this->xpath($this->publicFooter($server));

        foreach ($xpath->query('//div[@class="footer-col"]') as $column) {
            if (trim((string) $xpath->query('./h4', $column)->item(0)?->textContent) !== $title) {
                continue;
            }

            $links = [];
            foreach ($xpath->query('./ul/li/a', $column) as $anchor) {
                $links[] = [trim($anchor->textContent), $anchor->getAttribute('href')];
            }

            return $links;
        }

        return null;
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
