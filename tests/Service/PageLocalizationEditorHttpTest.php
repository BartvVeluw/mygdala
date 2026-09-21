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
use App\Service\PageService;
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
 * does not have is refused, a third language needs a row in
 * site_languages and nothing else, and input the server hands back unwritten
 * starts out unsaved in the save bar (admin/_save_bar.php), whatever the
 * language.
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

    /** The English address testClearingATranslationsAddressIsNoMove() gives and takes away. */
    private const CLEARED_ADDRESS = 'zz-page-localization-cleared';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    /** @var list<int> pages a test created through the create endpoint */
    private array $createdPageIds = [];

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

        // The redirect testClearingATranslationsAddressIsNoMove() must never
        // produce, removed in case it did.
        Database::connection()->prepare('DELETE FROM redirects WHERE source_path = ?')
            ->execute(['/en/' . self::CLEARED_ADDRESS]);

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
        self::assertNull(
            PageLocalization::slug($this->pageId, 'en'),
            'and no address either, so the page has no English URL at all'
        );
    }

    /**
     * Writing a translation publishes a URL for it: the address is made from
     * the title the way a new page's is made from its own, per language. An
     * address that is already there is never regenerated.
     */
    public function testATranslationGetsItsOwnAddressFromItsTitle(): void
    {
        $session = $this->signIn('en');

        $this->save($session, 'en', 'About this company', null, null);
        self::assertSame('about-this-company', PageLocalization::slug($this->pageId, 'en'));

        // The Dutch address is untouched by an English save. This fixture's
        // page has its address only in the neutral column, which IS the
        // default language's address (App\Service\PageContent::localizedSlug()).
        self::assertSame(
            self::KEY,
            \App\Service\PageContent::localizedSlug(
                (new PageRepository())->findById($this->pageId),
                PageLocalization::defaultLanguage()
            )
        );

        // A changed title leaves the address where it is.
        $this->save($session, 'en', 'About us instead', null, null, ['slug' => 'about-this-company']);
        self::assertSame('about-this-company', PageLocalization::slug($this->pageId, 'en'));
    }

    /**
     * REGRESSION. Clearing a translation's address means that language
     * version has no public URL any more (docs/multilingual/ROUTING.md). It
     * used to be recorded as a MOVE to an empty slug, which is the language's
     * homepage: /en/<old> answered with a 301 to /en, the soft 404 this CMS
     * already refuses to create when a page is deleted or unpublished.
     */
    public function testClearingATranslationsAddressIsNoMove(): void
    {
        $session = $this->signIn('en');
        $this->save($session, 'en', 'Cleared later', null, null, ['slug' => self::CLEARED_ADDRESS]);
        self::assertSame(self::CLEARED_ADDRESS, PageLocalization::slug($this->pageId, 'en'));

        $response = $this->save($session, 'en', 'Cleared later', null, null, ['slug' => '']);

        self::assertStringContainsString('updated=1', $response['location'], 'an ordinary save, with nothing to confirm');
        PageLocalization::clearCache();
        PageContent::clearCache();

        self::assertNull(PageLocalization::slug($this->pageId, 'en'), 'the English version has no address any more');
        self::assertNull(
            (new \App\Repository\RedirectRepository())->findBySourcePath('/en/' . self::CLEARED_ADDRESS),
            'and its old URL is not sent to the English homepage'
        );

        // Every other part of the site agrees that there is no English URL:
        // the lookup, the versions the switch and hreflang are built from,
        // and a link, which goes to the default language's real address.
        $page = (new PageRepository())->findById($this->pageId);
        self::assertNull(PageContent::forSlug(self::CLEARED_ADDRESS, 'en'));
        self::assertArrayNotHasKey('en', PageContent::localizedPaths($page));
        self::assertSame('/' . self::KEY, PageContent::publicUrl($page, 'en'));
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

    // ------------------------------------------------------------ unsaved input

    public function testAFreshEditorAndASuccessfulSaveStartOutSaved(): void
    {
        $session = $this->signIn(null);

        $fresh = $this->xpath($this->editor($session));
        self::assertFalse($this->settingsForm($fresh)->hasAttribute('data-save-bar-unsaved'), 'a plain visit starts saved');
        self::assertSame(0, $fresh->query('//*[@data-save-bar-discard]')->length, 'and has no input to throw away');

        $response = $this->save($session, 'nl', 'Vertaaltest pagina', 'Vertaaltest SEO', 'Nieuwe omschrijving');
        self::assertStringContainsString('updated=1', $response['location']);

        $saved = $this->xpath(self::$server->request('GET', $response['location'], $session)['body']);
        self::assertFalse($this->settingsForm($saved)->hasAttribute('data-save-bar-unsaved'), 'what was written is saved');
    }

    /**
     * A save the server refuses comes back with what was typed still in the
     * fields, and the settings form then starts out unsaved, so leaving asks
     * first instead of dropping that input silently. The same in the default
     * language, a translation and a language that is only a registry row. The
     * next plain visit shows the stored page again, saved.
     */
    public function testARefusedSaveKeepsTheInputOnScreenAndStartsOutUnsavedInEveryLanguage(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        $tooLong = str_repeat('x', PageService::MAX_TITLE_LENGTH + 1);
        $refusals = [
            'nl without its required title' => ['nl', ''],
            'nl with a title too long' => ['nl', $tooLong],
            'en with a title too long' => ['en', $tooLong],
            'de with a title too long' => ['de', $tooLong],
        ];

        foreach ($refusals as $what => [$language, $title]) {
            $session = $this->signIn($language);

            $response = $this->save($session, $language, $title, 'SEO ' . $language, 'Omschrijving ' . $language);
            self::assertSame('/admin/page.php?id=' . $this->pageId, $response['location'], $what . ': refused, without the success marker');

            $xpath = $this->xpath($this->editor($session));
            $form = $this->settingsForm($xpath);
            self::assertTrue($form->hasAttribute('data-save-bar-unsaved'), $what . ': what came back unwritten is unsaved');
            $this->assertTheSaveBarWatches($xpath, $form, $what);
            self::assertSame(1, $xpath->query('//*[contains(@class, "admin-alert--error")]')->length, $what . ': with the reason');

            self::assertSame($language, $this->control($xpath, $form, 'input[@name="language_code"]')->getAttribute('value'), $what);
            self::assertSame($title, $this->control($xpath, $form, 'input[@name="title"]')->getAttribute('value'), $what . ': the typed title is still there');
            self::assertSame('SEO ' . $language, $this->control($xpath, $form, 'input[@name="meta_title"]')->getAttribute('value'), $what);
            self::assertSame('Omschrijving ' . $language, $this->control($xpath, $form, 'textarea[@name="meta_description"]')->textContent, $what);

            $again = $this->xpath($this->editor($session));
            self::assertFalse($this->settingsForm($again)->hasAttribute('data-save-bar-unsaved'), $what . ': the next visit shows the stored page, saved');
        }

        self::assertSame(['Vertaaltest pagina', 'Vertaaltest SEO', 'Nederlandse omschrijving'], $this->row('nl'), 'nothing was written');
        self::assertNull($this->row('en'));
        self::assertNull($this->row('de'));
    }

    /**
     * A new web address waits for confirmation with nothing written: the
     * input is on screen and unsaved, and the confirmation's "Annuleren" is
     * the one link that throws it away without the browser asking again.
     */
    public function testANewAddressWaitingForConfirmationStartsOutUnsavedAndCancelDiscardsIt(): void
    {
        $session = $this->signIn('en');
        $moved = self::KEY . '-moved';

        // Give the English version an address first: since Multilingual 2.0
        // phase 6 the address that moves is the EDITED LANGUAGE's, and a
        // language that had none is not moving anything — it is being
        // published for the first time, which asks nothing.
        $this->save($session, 'en', 'Moved translation test', null, null, ['slug' => self::KEY . '-en']);
        self::assertSame(self::KEY . '-en', PageLocalization::slug($this->pageId, 'en'));

        $response = $this->save($session, 'en', 'Moved translation test', null, null, ['slug' => $moved]);
        self::assertSame('/admin/page.php?id=' . $this->pageId, $response['location'], 'nothing is written before the move is confirmed');

        $xpath = $this->xpath($this->editor($session));
        $form = $this->settingsForm($xpath);
        self::assertTrue($form->hasAttribute('data-save-bar-unsaved'), 'input waiting for confirmation is unsaved');
        self::assertSame($moved, $this->control($xpath, $form, 'input[@name="confirmed_slug"]')->getAttribute('value'));
        self::assertSame('Moved translation test', $this->control($xpath, $form, 'input[@name="title"]')->getAttribute('value'));

        $discard = $xpath->query('//*[@data-save-bar-discard]');
        self::assertSame(1, $discard->length, 'one link throws the input away');
        self::assertSame('/admin/page.php?id=' . $this->pageId, $discard->item(0)->getAttribute('href'));
        self::assertSame(1, $xpath->query('ancestor::section[contains(@class, "admin-url-confirm")]', $discard->item(0))->length, 'and it is the confirmation\'s Annuleren');

        // The page's neutral key — the Dutch address — never moves when an
        // English address does, and the English version still sits at the
        // address it had before the refused move.
        self::assertSame(self::KEY, (new PageRepository())->findById($this->pageId)['slug']);
        self::assertSame(self::KEY . '-en', PageLocalization::slug($this->pageId, 'en'), 'the move was not written');
    }

    /**
     * The unsaved mark belongs to the settings form alone. The draft preview
     * carries none of it and does not use the refused input up; the CMS
     * shell's language switch sits outside what the bar watches, and the
     * language it opens shows its stored text, saved.
     */
    public function testTheUnsavedMarkLeavesTheDraftPreviewAndTheLanguageSwitchAlone(): void
    {
        Database::connection()->prepare("UPDATE pages SET status = 'draft' WHERE id = ?")->execute([$this->pageId]);
        $session = $this->signIn(null);

        $refused = $this->save($session, 'nl', '', 'Geweigerd', null, ['status' => PageContent::STATUS_DRAFT]);
        self::assertStringNotContainsString('updated=1', $refused['location']);

        $preview = self::$server->request('GET', '/admin/page-preview.php?id=' . $this->pageId, $session);
        self::assertSame(200, $preview['status']);
        self::assertStringNotContainsString('data-save-bar-unsaved', $preview['body']);
        self::assertStringNotContainsString('data-save-bar-discard', $preview['body']);

        $xpath = $this->xpath($this->editor($session));
        self::assertTrue($this->settingsForm($xpath)->hasAttribute('data-save-bar-unsaved'), 'the preview did not use up the refused input');

        $switch = $xpath->query('//form[@action="/api/admin/update-content-language.php"]');
        self::assertSame(1, $switch->length);
        self::assertFalse($switch->item(0)->hasAttribute('data-save-bar-unsaved'));
        self::assertSame(0, $xpath->query('ancestor::main', $switch->item(0))->length, 'the save bar never watches the language switch');

        $switched = self::$server->request('POST', '/api/admin/update-content-language.php', $session, [
            'csrf_token' => $this->csrf($session),
            'content_editing_language' => 'en',
            'return_to' => '/admin/page.php?id=' . $this->pageId,
        ]);
        self::assertSame('/admin/page.php?id=' . $this->pageId, $switched['location']);

        $english = $this->xpath($this->editor($session));
        $form = $this->settingsForm($english);
        self::assertSame('en', $this->control($english, $form, 'input[@name="language_code"]')->getAttribute('value'));
        self::assertFalse($form->hasAttribute('data-save-bar-unsaved'), 'the other language opens saved');
    }

    // ------------------------------------------------------------ the public output

    public function testThePublicPageAndTheDraftPreviewPrintTheLanguageOfTheRequestFromTheNewStorage(): void
    {
        PageLocalization::save($this->pageId, 'en', [PageTranslation::TITLE => 'Translation test page'], self::KEY . '-en');

        $dutch = self::$server->request('GET', '/pagina.php?slug=' . self::KEY);
        self::assertSame(200, $dutch['status']);
        self::assertMatchesRegularExpression('/<title>Vertaaltest SEO/', $dutch['body']);
        self::assertStringContainsString('<meta name="description" content="Nederlandse omschrijving">', $dutch['body']);
        self::assertStringContainsString('>Vertaaltest pagina</span>', $dutch['body'], 'the breadcrumb in Dutch');
        self::assertStringNotContainsString('data-nl', $dutch['body'], 'one language per page, nothing for a browser to swap');

        PageContent::clearCache();
        $page = PageContent::forContentKey(self::KEY);
        self::assertNotNull($page);
        $english = self::$server->request('GET', PageContent::publicUrl($page, 'en'));
        self::assertSame(200, $english['status']);
        self::assertMatchesRegularExpression('/<title>Vertaaltest SEO/', $english['body'], 'an English page without its own SEO title gets the Dutch one');
        self::assertStringContainsString('<meta name="description" content="Nederlandse omschrijving">', $english['body']);
        self::assertStringContainsString('>Translation test page</span>', $english['body'], 'the breadcrumb in English');

        Database::connection()->prepare("UPDATE pages SET status = 'draft' WHERE id = ?")->execute([$this->pageId]);
        $session = $this->signIn(null);
        $preview = self::$server->request('GET', '/admin/page-preview.php?id=' . $this->pageId, $session);
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('>Vertaaltest pagina</span>', $preview['body']);
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

    /**
     * @param array<string, string> $overrides other settings fields, as the form would send them
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, string $language, string $title, ?string $metaTitle, ?string $metaDescription, array $overrides = []): array
    {
        return self::$server->request('POST', '/api/admin/update-page.php', $session, array_merge([
            'csrf_token' => $this->csrf($session),
            'id' => (string) $this->pageId,
            'language_code' => $language,
            'title' => $title,
            // The address of the LANGUAGE BEING EDITED, exactly as
            // admin/page.php renders it since Multilingual 2.0 phase 6: the
            // page's own for the default language, and empty for a language
            // that has no public route yet (docs/multilingual/ROUTING.md).
            'slug' => $language === PageLocalization::defaultLanguage() ? self::KEY : '',
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => (string) $metaTitle,
            'meta_description' => (string) $metaDescription,
            'noindex' => '0',
            'show_breadcrumb' => '1',
        ], $overrides));
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /** The one form that saves the page's settings and its text. */
    private function settingsForm(\DOMXPath $xpath): \DOMElement
    {
        $forms = $xpath->query('//form[@action="/api/admin/update-page.php"]');
        self::assertSame(1, $forms->length, 'the page settings post from one form');

        return $forms->item(0);
    }

    private function control(\DOMXPath $xpath, \DOMElement $form, string $path): \DOMElement
    {
        $controls = $xpath->query('.//' . $path, $form);
        self::assertSame(1, $controls->length, $path);

        return $controls->item(0);
    }

    /**
     * The bar only protects a form it watches (admin/assets/save-bar.js): a
     * POST form inside the page's own <main>, not a one-button form, not opted
     * out, with something to type and something that sends it — and the bar
     * and its script on the screen.
     */
    private function assertTheSaveBarWatches(\DOMXPath $xpath, \DOMElement $form, string $what): void
    {
        self::assertSame('post', strtolower($form->getAttribute('method')), $what);
        self::assertSame(1, $xpath->query('ancestor::main[contains(concat(" ", @class, " "), " admin-main ")]', $form)->length, $what . ': inside the page\'s own <main>');
        self::assertStringNotContainsString('admin-inline-form', $form->getAttribute('class'), $what);
        self::assertFalse($form->hasAttribute('data-no-dirty-track'), $what);
        self::assertGreaterThan(0, $xpath->query('.//input[@type="text"]', $form)->length, $what);
        self::assertGreaterThan(0, $xpath->query('.//*[@type="submit"] | .//button[not(@type)]', $form)->length, $what);
        self::assertSame(1, $xpath->query('//*[@data-save-bar]')->length, $what . ': the bar is on the screen');
        self::assertSame(1, $xpath->query('//script[contains(@src, "/admin/assets/save-bar.js")]')->length, $what . ': with its script');
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
