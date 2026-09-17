<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\PageRepository;
use App\Repository\PageTranslationRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AdminPermissions;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The page editor on per-language storage (Multilingual 2.0 phase 2,
 * docs/multilingual/ARCHITECTURE.md), used the way an editor uses it: the
 * screen shows the fields of one website language — the one chosen in the
 * CMS shell — the save writes that language and no other, the default
 * language is the only one whose title is required, a language the website
 * does not have is refused, and a third language needs a row in
 * site_languages and nothing else.
 *
 * Over real HTTP against PHP's built-in server, like
 * Tests\Service\PageHeroEditorHttpTest: an endpoint's answer is its redirect
 * and its session flash, and the editor's answer is its markup. The page, the
 * accounts and the German registry row are this test's own and are removed in
 * tearDown(). Without a server the test skips itself.
 */
final class PageLocalizationEditorHttpTest extends TestCase
{
    /** A slug the address rules accept as it is, so a save never asks to move the page. */
    private const KEY = 'zz-page-localization-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    /** @var list<int> pages a test created through the create endpoint */
    private array $createdPageIds = [];

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
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        self::assertSame('nl', PageLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');

        $this->removePages();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Vertaaltest pagina'
        );
        PageLocalization::save($this->pageId, 'nl', [
            PageTranslation::TITLE => 'Vertaaltest pagina',
            PageTranslation::META_TITLE => 'Vertaaltest SEO',
            PageTranslation::META_DESCRIPTION => 'Nederlandse omschrijving',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removePages();

        if ($this->addedGerman) {
            Database::connection()->prepare("DELETE FROM page_translations WHERE language_code = 'de'")->execute();
            (new SiteLanguageRepository())->delete('de');
            $this->addedGerman = false;
        }

        $this->accounts->forget();
        PageLocalization::clearCache();
        PageContent::clearCache();
        SiteLanguages::clearCache();
    }

    // ------------------------------------------------------------ the screen

    public function testTheDefaultLanguageIsOnScreenRequiredAndMarked(): void
    {
        $session = $this->signIn(null);

        $html = $this->editor($session);

        self::assertStringContainsString('name="language_code" value="nl"', $html);
        self::assertStringContainsString('data-localized-language="nl"', $html);
        self::assertMatchesRegularExpression('/<input type="text" id="page-title" name="title"[^>]* required value="Vertaaltest pagina"/', $html);
        self::assertStringContainsString('value="Vertaaltest SEO"', $html);
        self::assertStringContainsString('>Nederlandse omschrijving</textarea>', $html);
        self::assertStringContainsString('admin-badge admin-badge--info', $html, 'the default language is marked');
        self::assertStringNotContainsString('name="title_en"', $html);
        self::assertStringNotContainsString('data-lang-pane', $html, 'no hidden copy of another language is rendered');
        self::assertMatchesRegularExpression('/admin-sidebar__contentlang-option[^"]*is-default[^"]*"[^>]*aria-pressed="true"/', $html, 'the shell switch marks the default language');
    }

    public function testATranslationShowsItsOwnStoredWordsAndNeverTheFallback(): void
    {
        $session = $this->signIn('en');

        $html = $this->editor($session);

        self::assertStringContainsString('name="language_code" value="en"', $html);
        self::assertStringContainsString('data-localized-language="en"', $html);
        self::assertMatchesRegularExpression('/<input type="text" id="page-title" name="title"[^>]* value=""[^>]* placeholder="[^"]+"/', $html);
        self::assertDoesNotMatchRegularExpression('/id="page-title"[^>]* required/', $html, 'a translation is never required');
        self::assertStringNotContainsString('value="Vertaaltest pagina"', $html, 'the default language\'s words are not put in the field');
        self::assertStringNotContainsString('>Nederlandse omschrijving</textarea>', $html);
        self::assertStringContainsString('Vertaaltest pagina', $html, 'the page is still named by its default-language title');
    }

    // ------------------------------------------------------------ saving

    public function testASaveWritesTheLanguageOnScreenAndLeavesTheOthersAlone(): void
    {
        $session = $this->signIn('en');

        $response = $this->save($session, 'en', 'About the translation test', 'English SEO', 'English description');

        self::assertStringContainsString('updated=1', $response['location']);
        self::assertSame(
            ['About the translation test', 'English SEO', 'English description'],
            $this->row('en')
        );
        self::assertSame(
            ['Vertaaltest pagina', 'Vertaaltest SEO', 'Nederlandse omschrijving'],
            $this->row('nl'),
            'the Dutch text is not touched by an English save'
        );
    }

    public function testAnEmptyTranslationIsAllowedAndStoresNoRow(): void
    {
        $session = $this->signIn('en');
        $this->save($session, 'en', 'About', null, null);

        $response = $this->save($session, 'en', '', '', '');

        self::assertStringContainsString('updated=1', $response['location']);
        self::assertNull($this->row('en'), 'a language with no words has no row: it falls back');
        self::assertSame('Vertaaltest pagina', PageLocalization::title($this->pageId, 'en'));
    }

    public function testTheDefaultLanguagesTitleIsRequired(): void
    {
        $session = $this->signIn(null);

        $response = $this->save($session, 'nl', '', 'Nieuwe SEO', null);

        self::assertStringNotContainsString('updated=1', $response['location']);
        self::assertNotEmpty($this->accounts->read($session, 'admin_page_errors'));
        self::assertSame(['Vertaaltest pagina', 'Vertaaltest SEO', 'Nederlandse omschrijving'], $this->row('nl'), 'nothing was written');
    }

    public function testALanguageTheWebsiteDoesNotHaveIsRefused(): void
    {
        $session = $this->signIn(null);

        foreach (['fr', 'x1', ''] as $code) {
            $response = $this->save($session, $code, 'Titre', null, null);

            self::assertStringNotContainsString('updated=1', $response['location'], $code);
        }

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM page_translations WHERE page_id = ?');
        $count->execute([$this->pageId]);
        self::assertSame(1, (int) $count->fetchColumn(), 'only the Dutch row exists');
    }

    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        $session = $this->signIn('de');

        $html = $this->editor($session);
        self::assertStringContainsString('name="language_code" value="de"', $html);
        self::assertStringContainsString('data-localized-language="de"', $html);
        self::assertStringContainsString('name="content_editing_language" value="de"', $html, 'the shell switch offers it');

        $response = $this->save($session, 'de', 'Über den Übersetzungstest', null, 'Deutsche Beschreibung');

        self::assertStringContainsString('updated=1', $response['location']);
        self::assertSame(['Über den Übersetzungstest', null, 'Deutsche Beschreibung'], $this->row('de'));
        PageLocalization::clearCache();
        self::assertSame('Vertaaltest SEO', PageLocalization::value($this->pageId, PageTranslation::META_TITLE, 'de'), 'an empty German field falls back to Dutch');
    }

    public function testANewPageIsWrittenInTheDefaultLanguageWhateverTheEditorIsEditing(): void
    {
        $session = $this->signIn('en');
        $csrf = $this->csrf($session);
        $slug = self::KEY . '-new';

        $response = self::$server->request('POST', '/api/admin/create-page.php', $session, [
            'csrf_token' => $csrf,
            'title' => 'Nieuwe vertaaltest',
            'slug' => $slug,
            'status' => PageContent::STATUS_DRAFT,
            'meta_title' => '',
            'meta_description' => 'Omschrijving bij aanmaken',
            'template' => 'blank',
        ]);

        self::assertStringContainsString('created=1', $response['location']);
        $page = (new PageRepository())->findByContentKey($slug);
        self::assertNotNull($page);
        $this->createdPageIds[] = (int) $page['id'];

        $rows = (new PageTranslationRepository())->findForPage((int) $page['id']);
        self::assertSame(['nl'], array_keys($rows));
        self::assertSame('Nieuwe vertaaltest', $rows['nl']['title']);
        self::assertSame('Omschrijving bij aanmaken', $rows['nl']['meta_description']);
    }

    // ------------------------------------------------------------ the V1 output

    public function testThePublicPageAndTheDraftPreviewPrintBothSwitchLanguagesFromTheNewStorage(): void
    {
        PageLocalization::save($this->pageId, 'en', [PageTranslation::TITLE => 'Translation test page']);

        $public = self::$server->request('GET', '/pagina.php?slug=' . self::KEY);
        self::assertSame(200, $public['status']);
        self::assertMatchesRegularExpression('/<title data-nl="Vertaaltest SEO" data-en="Vertaaltest SEO">/', $public['body'], 'an English page without its own SEO title gets the Dutch one');
        self::assertStringContainsString('data-nl-content="Nederlandse omschrijving" data-en-content="Nederlandse omschrijving"', $public['body']);
        self::assertStringContainsString('data-nl="Vertaaltest pagina" data-en="Translation test page"', $public['body'], 'the breadcrumb carries both languages');

        Database::connection()->prepare("UPDATE pages SET status = 'draft' WHERE id = ?")->execute([$this->pageId]);
        $session = $this->signIn(null);
        $preview = self::$server->request('GET', '/admin/page-preview.php?id=' . $this->pageId, $session);
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('data-nl="Vertaaltest pagina" data-en="Translation test page"', $preview['body']);
    }

    // ------------------------------------------------------------ helpers

    /** Signs in with pages.manage and, when given, a chosen editing language. */
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

    private function editor(string $session): string
    {
        $response = self::$server->request('GET', '/admin/page.php?id=' . $this->pageId, $session);
        self::assertSame(200, $response['status']);

        return $response['body'];
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function save(string $session, string $language, string $title, ?string $metaTitle, ?string $metaDescription): array
    {
        return self::$server->request('POST', '/api/admin/update-page.php', $session, [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $this->pageId,
            'language_code' => $language,
            'title' => $title,
            'slug' => self::KEY,
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => (string) $metaTitle,
            'meta_description' => (string) $metaDescription,
            'noindex' => '0',
            'show_breadcrumb' => '1',
        ]);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string}|null the stored title, SEO title and description */
    private function row(string $language): ?array
    {
        $row = (new PageTranslationRepository())->find($this->pageId, $language);

        return $row === null ? null : [$row['title'], $row['meta_title'], $row['meta_description']];
    }

    private function removePages(): void
    {
        $db = Database::connection();
        $ids = $this->createdPageIds;

        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key IN (?, ?)');
        $stmt->execute([self::KEY, self::KEY . '-new']);
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['id'];
        }

        foreach (array_unique($ids) as $id) {
            $db->prepare('DELETE FROM page_sections WHERE page_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
        }

        $this->createdPageIds = [];
        PageContent::clearCache();
        PageLocalization::clearCache();
    }
}
