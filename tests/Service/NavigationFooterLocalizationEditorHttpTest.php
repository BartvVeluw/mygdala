<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AdminPermissions;
use App\Service\FooterLocalization;
use App\Service\Language\SiteLanguages;
use App\Service\NavigationLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The menu-item, footer-column and footer-link editors on one website
 * language at a time (Multilingual 2.0 phase 4, admin/_localized_fields.php),
 * over real HTTP against PHP's built-in server.
 *
 * THE CONTRACT, the same as the page editor's
 * (Tests\Service\PageLocalizationEditorHttpTest):
 *   - the screen shows the language chosen in the CMS shell, as stored, with
 *     no hidden pane of another language, and `required` only in the default
 *     language;
 *   - a save writes that language only; every other language stays exactly
 *     as it was;
 *   - a third language is a row in site_languages and needs nothing else;
 *   - a new item or column is written in the default language, whatever the
 *     editor was working in;
 *   - a refused save comes back with what was typed and starts out unsaved;
 *   - the public header and footer print the pair from the new storage, and
 *     a label that looks like markup stays text.
 *
 * Every row, account and the German language row are this test's own and
 * removed in tearDown().
 */
final class NavigationFooterLocalizationEditorHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;
    private NavigationRepository $navigation;
    private FooterRepository $footer;

    /** @var list<int> */
    private array $navIds = [];

    /** @var list<int> */
    private array $columnIds = [];

    private bool $addedGerman = false;

    public static function setUpBeforeClass(): void
    {
        // The dispatcher answers /en/… and /de/… the way .htaccess does in
        // production, so a page can be read in every website language.
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
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

        $this->accounts = new AdminTestSession();
        $this->navigation = new NavigationRepository();
        $this->footer = new FooterRepository();

        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ([true, false] as $childrenOnly) {
            foreach ($this->navIds as $id) {
                $db->prepare('DELETE FROM nav_items WHERE id = :id' . ($childrenOnly ? ' AND parent_id IS NOT NULL' : ''))->execute(['id' => $id]);
            }
        }
        foreach ($this->columnIds as $id) {
            $this->footer->deleteColumn($id);
        }
        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
        }

        $this->navIds = [];
        $this->columnIds = [];
        $this->accounts->forget();
        NavigationLocalization::clearCache();
        FooterLocalization::clearCache();
        SiteLanguages::clearCache();
    }

    // ------------------------------------------------------------ menu items

    public function testTheDefaultLanguageIsOnScreenRequiredAndMarked(): void
    {
        $id = $this->navItem(['nl' => 'zz Over ons', 'en' => 'zz About us']);

        $xpath = $this->xpath($this->get($this->signIn(null), '/admin/navigation-item.php?id=' . $id));

        $input = $xpath->query('//input[@name="label"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);
        self::assertSame('zz Over ons', $input->getAttribute('value'));
        self::assertTrue($input->hasAttribute('required'));
        self::assertSame('nl', $xpath->query('//input[@name="language_code"]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $xpath->query('//*[@data-localized-language="nl"]//*[contains(@class, "admin-badge")]')->length, 'the default language is marked');
        self::assertSame(0, $xpath->query('//input[@name="label_nl" or @name="label_en"]')->length, 'no V1 pane');
    }

    public function testATranslationShowsItsOwnStoredWordsAndNeverTheFallback(): void
    {
        $translated = $this->navItem(['nl' => 'zz Over ons', 'en' => 'zz About us']);
        $untranslated = $this->navItem(['nl' => 'zz Contact']);
        $session = $this->signIn('en');

        $xpath = $this->xpath($this->get($session, '/admin/navigation-item.php?id=' . $translated));
        self::assertSame('zz About us', $xpath->query('//input[@name="label"]')->item(0)?->getAttribute('value'));
        self::assertSame('en', $xpath->query('//input[@name="language_code"]')->item(0)?->getAttribute('value'));

        $xpath = $this->xpath($this->get($session, '/admin/navigation-item.php?id=' . $untranslated));
        $input = $xpath->query('//input[@name="label"]')->item(0);
        self::assertSame('', $input?->getAttribute('value'), 'an empty translation is shown empty, never as the Dutch words');
        self::assertFalse($input?->hasAttribute('required'), 'a translation is optional');
        self::assertNotSame('', (string) $input?->getAttribute('placeholder'), 'the fallback is said as a placeholder');
    }

    public function testSavingOneLanguageLeavesTheOthersAloneAndTheHeaderPrintsEach(): void
    {
        $id = $this->navItem(['nl' => 'zz Over ons', 'en' => 'zz About us'], '/zz-over-ons');
        $session = $this->signIn('en');

        $response = $this->postItem($session, $id, 'en', 'zz Who we are', '/zz-over-ons');

        self::assertStringContainsString('saved=1', $response['location']);
        self::assertSame(['zz Over ons', 'zz Who we are'], $this->labels($id));

        self::assertStringContainsString('>zz Over ons</a>', $this->get(null, '/'));
        $english = $this->get(null, '/en/');
        self::assertStringContainsString('>zz Who we are</a>', $english);
        self::assertStringNotContainsString('zz Over ons', $english, 'one language per page');
    }

    public function testTheDefaultLanguagesLabelIsRequiredAndARefusedSaveStaysUnsaved(): void
    {
        $id = $this->navItem(['nl' => 'zz Over ons', 'en' => 'zz About us'], '/zz-a');
        $session = $this->signIn(null);

        $response = $this->postItem($session, $id, 'nl', '', '/zz-typed');

        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertSame(['zz Over ons', 'zz About us'], $this->labels($id), 'nothing was written');
        self::assertSame('/zz-a', $this->navigation->findById($id)['external_url']);

        $xpath = $this->xpath($this->get($session, '/admin/navigation-item.php?id=' . $id));
        $form = $xpath->query('//form[@data-nav-item-form]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertTrue($form->hasAttribute('data-save-bar-unsaved'));
        self::assertSame('/zz-typed', $xpath->query('//input[@name="external_url"]')->item(0)?->getAttribute('value'), 'what was typed comes back');
        self::assertSame(1, $xpath->query('//*[contains(@class, "admin-alert--error")]')->length);
    }

    public function testATranslationMayBeEmptiedAndOnlyThatLanguageLosesItsRow(): void
    {
        $id = $this->navItem(['nl' => 'zz Over ons', 'en' => 'zz About us'], '/zz-b');

        $response = $this->postItem($this->signIn('en'), $id, 'en', '', '/zz-b');

        self::assertStringContainsString('saved=1', $response['location']);
        self::assertSame(['zz Over ons', ''], $this->labels($id));
    }

    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(): void
    {
        $this->addGerman();
        $id = $this->navItem(['nl' => 'zz Over ons', 'en' => 'zz About us'], '/zz-c');
        $session = $this->signIn('de');

        $html = $this->get($session, '/admin/navigation-item.php?id=' . $id);
        self::assertStringContainsString('name="language_code" value="de"', $html);

        $response = $this->postItem($session, $id, 'de', 'zz Über uns', '/zz-c');

        self::assertStringContainsString('saved=1', $response['location']);
        NavigationLocalization::clearCache();
        self::assertSame(['zz Over ons', 'zz About us', 'zz Über uns'], [...$this->labels($id), NavigationLocalization::raw($id, 'de')]);
    }

    public function testALanguageTheWebsiteDoesNotHaveIsRefused(): void
    {
        $id = $this->navItem(['nl' => 'zz Over ons'], '/zz-d');

        $response = $this->postItem($this->signIn(null), $id, 'xx', 'zz Nope', '/zz-d');

        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertSame(['zz Over ons', ''], $this->labels($id));
    }

    public function testANewItemIsWrittenInTheDefaultLanguageWhateverTheEditorIsEditing(): void
    {
        $session = $this->signIn('en');

        $form = $this->get($session, '/admin/navigation-item.php');
        self::assertStringContainsString('data-localized-language="nl"', $form, 'the new item form says which language it writes');
        self::assertStringNotContainsString('name="language_code"', $form);

        $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, [
            'csrf_token' => $this->csrf($session),
            'label' => 'zz Nieuw item',
            'language_code' => 'en',
            'link_type' => 'external',
            'external_url' => '/zz-nieuw',
            'is_visible' => '1',
        ]);

        self::assertMatchesRegularExpression('#id=(\d+)&saved=1#', $response['location']);
        preg_match('#id=(\d+)#', $response['location'], $match);
        $id = (int) $match[1];
        $this->navIds[] = $id;
        self::assertSame(['zz Nieuw item', ''], $this->labels($id));
    }

    public function testALabelThatLooksLikeMarkupStaysText(): void
    {
        $payload = '<img src=x onerror="alert(1)">zz';
        $this->navItem(['nl' => $payload, 'en' => $payload . ' en'], '/zz-xss');

        $header = $this->get(null, '/index.php');

        self::assertStringNotContainsString('<img src=x', $header);
        self::assertStringContainsString('>&lt;img src=x onerror=&quot;alert(1)&quot;&gt;zz</a>', $header);
    }

    // ---------------------------------------------------------------- footer

    public function testAFooterColumnAndLinkSaveOneLanguageAtATime(): void
    {
        [$column, $link] = $this->columnWithLink();
        $session = $this->signIn('en');
        $token = $this->csrf($session);

        $xpath = $this->xpath($this->get($session, '/admin/footer-column.php?id=' . $column));
        self::assertSame('en', $xpath->query('//input[@name="language_code"]')->item(0)?->getAttribute('value'));
        self::assertSame('', $xpath->query('//input[@name="title"]')->item(0)?->getAttribute('value'), 'no English title stored yet');

        $saved = self::$server->request('POST', '/api/admin/update-footer-column.php', $session, [
            'csrf_token' => $token, 'id' => (string) $column, 'language_code' => 'en', 'title' => 'zz Support', 'is_visible' => '1',
        ]);
        self::assertStringContainsString('saved=1', $saved['location']);

        $saved = self::$server->request('POST', '/api/admin/update-footer-link.php', $session, [
            'csrf_token' => $token, 'id' => (string) $link, 'language_code' => 'en', 'label' => 'zz Terms',
            'link_type' => 'external', 'external_url' => '/zz-voorwaarden', 'is_visible' => '1',
        ]);
        self::assertStringContainsString('saved=1', $saved['location']);

        FooterLocalization::clearCache();
        self::assertSame(
            ['zz Service', 'zz Support', 'zz Voorwaarden', 'zz Terms'],
            [
                FooterLocalization::rawColumnTitle($column, 'nl'),
                FooterLocalization::rawColumnTitle($column, 'en'),
                FooterLocalization::rawLinkLabel($link, 'nl'),
                FooterLocalization::rawLinkLabel($link, 'en'),
            ]
        );

        $footer = $this->get(null, '/');
        self::assertStringContainsString('<h4>zz Service</h4>', $footer);
        self::assertStringContainsString('>zz Voorwaarden</a>', $footer);

        $english = $this->get(null, '/en/');
        self::assertStringContainsString('<h4>zz Support</h4>', $english);
        self::assertStringContainsString('>zz Terms</a>', $english);
    }

    public function testARefusedFooterColumnSaveKeepsTheTypedWordsInTheirLanguage(): void
    {
        [$column] = $this->columnWithLink();
        $session = $this->signIn(null);

        self::$server->request('POST', '/api/admin/update-footer-column.php', $session, [
            'csrf_token' => $this->csrf($session), 'id' => (string) $column, 'language_code' => 'nl', 'title' => str_repeat('x', 101), 'is_visible' => '1',
        ]);

        $xpath = $this->xpath($this->get($session, '/admin/footer-column.php?id=' . $column));
        self::assertTrue($xpath->query('//form[@action="/api/admin/update-footer-column.php"]')->item(0)?->hasAttribute('data-save-bar-unsaved'));
        self::assertSame(str_repeat('x', 101), $xpath->query('//input[@name="title"]')->item(0)?->getAttribute('value'));
        FooterLocalization::clearCache();
        self::assertSame('zz Service', FooterLocalization::rawColumnTitle($column, 'nl'));
    }

    public function testANewFooterColumnIsWrittenInTheDefaultLanguage(): void
    {
        $session = $this->signIn('en');

        $response = self::$server->request('POST', '/api/admin/create-footer-column.php', $session, [
            'csrf_token' => $this->csrf($session), 'title' => 'zz Nieuwe kolom',
        ]);

        self::assertMatchesRegularExpression('/footer-column-(\d+)$/', $response['location']);
        preg_match('/footer-column-(\d+)$/', $response['location'], $match);
        $this->columnIds[] = (int) $match[1];
        FooterLocalization::clearCache();
        self::assertSame(['zz Nieuwe kolom', ''], [FooterLocalization::rawColumnTitle((int) $match[1], 'nl'), FooterLocalization::rawColumnTitle((int) $match[1], 'en')]);
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string, string> $labels */
    private function navItem(array $labels, string $url = '/zz-item'): int
    {
        $id = $this->navigation->create([
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => $url,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
        ]);
        $this->navIds[] = $id;
        foreach ($labels as $language => $label) {
            NavigationLocalization::save($id, $language, $label);
        }

        return $id;
    }

    /** @return array{0: int, 1: int} */
    private function columnWithLink(): array
    {
        $column = $this->footer->createColumn(['is_visible' => true]);
        $this->columnIds[] = $column;
        FooterLocalization::saveColumnTitle($column, 'nl', 'zz Service');

        $link = $this->footer->createLink([
            'column_id' => $column,
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => '/zz-voorwaarden',
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);
        FooterLocalization::saveLinkLabel($link, 'nl', 'zz Voorwaarden');

        return [$column, $link];
    }

    /** @return array<string, mixed> */
    private function postItem(string $session, int $id, string $language, string $label, string $url): array
    {
        return self::$server->request('POST', '/api/admin/update-nav-item.php', $session, [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $id,
            'language_code' => $language,
            'label' => $label,
            'link_type' => 'external',
            'external_url' => $url,
            'is_visible' => '1',
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function labels(int $id): array
    {
        NavigationLocalization::clearCache();

        return [NavigationLocalization::raw($id, 'nl'), NavigationLocalization::raw($id, 'en')];
    }

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

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

    private function addGerman(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
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
