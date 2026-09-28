<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\Language\SiteLanguages;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PageUsage;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * "Gebruik titel van bestemming" (Pages & Destinations 3.0, HEADER-FOOTER.md
 * "Tekst van een menu-item") over real HTTP: a header item that points at a
 * CMS page can show that page's title instead of words of its own.
 *
 * THE CONTRACT
 *   - a new item starts with the switch on, and an item that follows its page
 *     stores no words in any language: nav_item_translations has no row;
 *   - the header shows the page's title in the language of the request, with
 *     the page's own fallback, and follows a rename without anyone touching
 *     the menu;
 *   - switching it off stores words of the item's own, one language at a
 *     time, and switching it back on removes every one of them;
 *   - words of its own start in the default language: a translation alone is
 *     refused;
 *   - only a page has a title to follow: any other kind needs its own words,
 *     whatever the switch said;
 *   - a page on Concept takes a following item off the website like any other
 *     link, and a page an item points at cannot be deleted;
 *   - every item that existed before reads exactly as it did: its own words
 *     win, and its editor opens with the switch off.
 *
 * The per-language editor itself is Tests\Service\
 * NavigationFooterLocalizationEditorHttpTest. Every row, page and account is
 * this test's own and removed by exact id in tearDown(); only this test's own
 * links are asserted, found by their page's address, so whatever else the
 * test database holds does not matter.
 */
final class NavigationFollowsPageTitleHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;
    private NavigationRepository $navigation;

    /** @var list<int> */
    private array $navIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    public static function setUpBeforeClass(): void
    {
        // The dispatcher answers /en/… the way .htaccess does in production,
        // so the header can be read in both website languages.
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

        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');
        self::assertTrue(SiteLanguages::isActive('en'), 'this test expects English as a second website language');
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach (array_reverse(array_unique($this->navIds)) as $id) {
            $db->prepare('DELETE FROM nav_items WHERE id = :id')->execute(['id' => $id]);
        }
        $pages = new PageRepository();
        foreach (array_reverse($this->pageIds) as $id) {
            $pages->delete($id);
        }

        $this->navIds = [];
        $this->pageIds = [];
        $this->accounts->forget();
        NavigationLocalization::clearCache();
        PageLocalization::clearCache();
    }

    // ----------------------------------------------------------- the editor

    public function testANewItemStartsWithTheSwitchOnAndNeedsNoWords(): void
    {
        $xpath = $this->xpath($this->get($this->signIn(null), '/admin/navigation-item.php'));

        $switch = $xpath->query('//input[@name="label_follows"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $switch);
        self::assertTrue($switch->hasAttribute('checked'), 'on for a new item');
        self::assertSame('switch', $switch->getAttribute('role'));
        self::assertSame('page', $xpath->query('//input[@name="label_follows"]/ancestor::*[@data-nav-link-field][1]')->item(0)?->getAttribute('data-nav-link-field'), 'offered for a page only');

        $label = $xpath->query('//input[@name="label"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $label);
        self::assertFalse($label->hasAttribute('required'), 'no words required while the item follows its page');
        self::assertTrue($label->hasAttribute('data-nav-label-required'), 'the script puts required back when the switch goes off');
        self::assertTrue($xpath->query('//*[@data-nav-label-own]')->item(0)?->hasAttribute('hidden'));
        self::assertFalse($xpath->query('//*[@data-nav-label-preview]')->item(0)?->hasAttribute('hidden'));
        self::assertSame('polite', $xpath->query('//*[@data-nav-label-preview]')->item(0)?->getAttribute('aria-live'));
    }

    // ------------------------------------------------------- following a page

    public function testAFollowingItemStoresNoWordsAndTheHeaderShowsThePageTitleInEachLanguage(): void
    {
        [$pageId, $key] = $this->page('zz Over ons', 'zz About us');
        $session = $this->signIn(null);

        $id = $this->create($session, [
            'label_follows' => '1',
            'label' => '',
            'link_type' => 'page',
            'target_page_id' => (string) $pageId,
            'is_visible' => '1',
        ]);

        self::assertSame([], NavigationLocalization::items()->words($id), 'no words of its own, in any language');
        self::assertSame(['zz Over ons'], $this->menuTexts('/', $key));
        self::assertSame(['zz About us'], $this->menuTexts('/en/', $key));

        // The CMS calls it what the menu shows.
        $overview = $this->xpath($this->get($session, '/admin/navigation.php'));
        $row = (string) $overview->query('//*[@id="nav-item-' . $id . '"]')->item(0)?->textContent;
        self::assertStringContainsString('zz Over ons', $row);
        self::assertStringContainsString('Volgt paginatitel', $row);

        $editor = $this->xpath($this->get($session, '/admin/navigation-item.php?id=' . $id));
        self::assertSame('zz Over ons', trim((string) $editor->query('//h1')->item(0)?->textContent));
        self::assertTrue($editor->query('//input[@name="label_follows"]')->item(0)?->hasAttribute('checked'));
        self::assertSame('zz Over ons', trim((string) $editor->query('//*[@data-nav-label-preview-title]')->item(0)?->textContent));
        self::assertSame('zz Over ons', $editor->query('//select[@name="target_page_id"]/option[@value="' . $pageId . '"]')->item(0)?->getAttribute('data-title'), 'the script reads the title from the chosen page');
    }

    public function testARenameOfThePageReachesTheMenuWithoutTouchingIt(): void
    {
        [$pageId, $key] = $this->page('zz Diensten', 'zz Services');
        $id = $this->create($this->signIn(null), [
            'label_follows' => '1', 'link_type' => 'page', 'target_page_id' => (string) $pageId, 'is_visible' => '1',
        ]);

        PageLocalization::save($pageId, 'nl', [PageTranslation::TITLE => 'zz Wat we doen']);
        PageLocalization::save($pageId, 'en', [PageTranslation::TITLE => 'zz What we do'], $key . '-en');

        self::assertSame(['zz Wat we doen'], $this->menuTexts('/', $key));
        self::assertSame(['zz What we do'], $this->menuTexts('/en/', $key));
        self::assertSame([], NavigationLocalization::items()->words($id), 'the item itself was never written');
    }

    public function testAVisitorInAnotherLanguageGetsThePagesOwnFallback(): void
    {
        // A page with a Dutch title only: an English visitor reads the Dutch
        // title, exactly what the page itself would say (LanguageFallback).
        [$pageId, $key] = $this->page('zz Alleen Nederlands');
        $this->create($this->signIn(null), [
            'label_follows' => '1', 'link_type' => 'page', 'target_page_id' => (string) $pageId, 'is_visible' => '1',
        ]);

        self::assertSame(['zz Alleen Nederlands'], $this->menuTexts('/en/', $key));
    }

    public function testAFollowingButtonShowsThePageTitleToo(): void
    {
        [$pageId, $key] = $this->page('zz Offerte aanvragen', 'zz Request a quote');
        $this->create($this->signIn(null), [
            'label_follows' => '1',
            'presentation' => NavigationPresentation::BUTTON,
            'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
            'link_type' => 'page',
            'target_page_id' => (string) $pageId,
            'is_visible' => '1',
        ]);

        self::assertSame(['zz Offerte aanvragen'], $this->buttonTexts('/', $key));
        self::assertSame(['zz Request a quote'], $this->buttonTexts('/en/', $key));
    }

    // ------------------------------------------------------ words of its own

    public function testSwitchingItOffStoresWordsOfItsOwnOneLanguageAtATime(): void
    {
        [$pageId, $key] = $this->page('zz Contact', 'zz Contact us');
        $session = $this->signIn(null);
        $id = $this->create($session, [
            'label_follows' => '1', 'link_type' => 'page', 'target_page_id' => (string) $pageId, 'is_visible' => '1',
        ]);

        $saved = $this->update($session, $id, 'nl', ['label' => 'zz Bel ons', 'target_page_id' => (string) $pageId]);
        self::assertStringContainsString('saved=1', $saved['location']);
        self::assertSame(['zz Bel ons', ''], $this->labels($id));
        // Its own words win in every language; English falls back to them.
        self::assertSame(['zz Bel ons'], $this->menuTexts('/', $key));
        self::assertSame(['zz Bel ons'], $this->menuTexts('/en/', $key));

        $english = $this->signIn('en');
        $saved = $this->update($english, $id, 'en', ['label' => 'zz Call us', 'target_page_id' => (string) $pageId]);
        self::assertStringContainsString('saved=1', $saved['location']);
        self::assertSame(['zz Bel ons', 'zz Call us'], $this->labels($id));
        self::assertSame(['zz Call us'], $this->menuTexts('/en/', $key));

        // The editor now opens with the switch off and the words required.
        $editor = $this->xpath($this->get($session, '/admin/navigation-item.php?id=' . $id));
        self::assertFalse($editor->query('//input[@name="label_follows"]')->item(0)?->hasAttribute('checked'));
        self::assertTrue($editor->query('//input[@name="label"]')->item(0)?->hasAttribute('required'));
        self::assertFalse($editor->query('//*[@data-nav-label-own]')->item(0)?->hasAttribute('hidden'));
    }

    public function testSwitchingItBackOnRemovesEveryWordAndTheTitleReturns(): void
    {
        [$pageId, $key] = $this->page('zz Werkwijze', 'zz How we work');
        $id = $this->item($pageId, ['nl' => 'zz Eigen tekst', 'en' => 'zz Own text']);
        self::assertSame(['zz Eigen tekst'], $this->menuTexts('/', $key));

        // Switched on from the English screen: it is about the item, not
        // about one language.
        $saved = $this->update($this->signIn('en'), $id, 'en', ['label_follows' => '1', 'label' => 'zz Own text', 'target_page_id' => (string) $pageId]);

        self::assertStringContainsString('saved=1', $saved['location']);
        self::assertSame([], NavigationLocalization::items()->words($id), 'no stray translation outlives the choice');
        self::assertSame(['zz Werkwijze'], $this->menuTexts('/', $key));
        self::assertSame(['zz How we work'], $this->menuTexts('/en/', $key));
    }

    public function testWordsOfItsOwnStartInTheDefaultLanguage(): void
    {
        [$pageId] = $this->page('zz Nieuws', 'zz News');
        $id = $this->create($this->signIn(null), [
            'label_follows' => '1', 'link_type' => 'page', 'target_page_id' => (string) $pageId, 'is_visible' => '1',
        ]);
        $english = $this->signIn('en');

        $refused = $this->update($english, $id, 'en', ['label' => 'zz Only English', 'target_page_id' => (string) $pageId]);

        self::assertStringNotContainsString('saved=1', $refused['location']);
        self::assertSame([], NavigationLocalization::items()->words($id), 'nothing was written');
        $errors = (array) $this->accounts->read($english, 'admin_nav_item_errors');
        self::assertStringContainsString('standaardtaal', implode(' ', array_map('strval', $errors)));
    }

    public function testOnlyAPageHasATitleToFollow(): void
    {
        $session = $this->signIn(null);
        $address = '/zz-follows-' . bin2hex(random_bytes(4));

        foreach ([
            'another address' => ['link_type' => 'external', 'external_url' => $address],
            'a submenu heading' => ['link_type' => 'none'],
        ] as $case => $fields) {
            $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, $fields + [
                'csrf_token' => $this->csrf($session),
                'label_follows' => '1',
                'label' => '',
                'is_visible' => '1',
            ]);

            self::assertSame(302, $response['status'], $case);
            self::assertStringNotContainsString('saved=1', $response['location'], $case . ' needs words of its own');
            self::assertContains('Label is verplicht.', (array) $this->accounts->read($session, 'admin_nav_item_errors'), $case);
        }

        foreach ($this->navigation->findAllForAdmin() as $row) {
            self::assertNotSame($address, (string) ($row['external_url'] ?? ''), 'nothing was stored');
        }
    }

    public function testAnItemWithWordsOfItsOwnReadsExactlyAsBefore(): void
    {
        [$pageId, $key] = $this->page('zz Over het atelier', 'zz About the studio');
        $id = $this->item($pageId, ['nl' => 'zz Atelier']);

        self::assertSame(['zz Atelier'], $this->menuTexts('/', $key));
        self::assertSame(['zz Atelier'], $this->menuTexts('/en/', $key), 'its own words, never the page title');

        $session = $this->signIn(null);
        $editor = $this->xpath($this->get($session, '/admin/navigation-item.php?id=' . $id));
        self::assertFalse($editor->query('//input[@name="label_follows"]')->item(0)?->hasAttribute('checked'));
        self::assertSame('zz Atelier', $editor->query('//input[@name="label"]')->item(0)?->getAttribute('value'));

        $overview = $this->xpath($this->get($session, '/admin/navigation.php'));
        $row = (string) $overview->query('//*[@id="nav-item-' . $id . '"]')->item(0)?->textContent;
        self::assertStringContainsString('zz Atelier', $row);
        self::assertStringNotContainsString('Volgt paginatitel', $row);

        // A save that does not send the switch (a form of before) keeps the
        // words exactly as they are.
        $saved = $this->update($session, $id, 'nl', ['label' => 'zz Atelier', 'target_page_id' => (string) $pageId]);
        self::assertStringContainsString('saved=1', $saved['location']);
        self::assertSame(['zz Atelier', ''], $this->labels($id));
    }

    // -------------------------------------------- a destination that is away

    public function testAPageOnConceptTakesTheItemOffTheWebsiteAndIsNotDeleted(): void
    {
        [$pageId, $key] = $this->page('zz Vacatures', 'zz Jobs');
        $session = $this->signIn(null);
        $id = $this->create($session, [
            'label_follows' => '1', 'link_type' => 'page', 'target_page_id' => (string) $pageId, 'is_visible' => '1',
        ]);

        (new PageRepository())->update($pageId, ['slug' => $key, 'status' => PageContent::STATUS_DRAFT]);

        self::assertSame([], $this->menuTexts('/', $key), 'no link, and no empty one');
        self::assertSame([], $this->menuTexts('/en/', $key));

        // Kept and said: the row is still called by the page's name.
        $row = (string) $this->xpath($this->get($session, '/admin/navigation.php'))->query('//*[@id="nav-item-' . $id . '"]')->item(0)?->textContent;
        self::assertStringContainsString('zz Vacatures', $row);
        self::assertStringContainsString('Niet op de website', $row);

        // Deleting the page is refused while an item points at it, so an item
        // can never be left following a page that is gone.
        $page = (new PageRepository())->findById($pageId);
        self::assertNotNull($page);
        try {
            PageService::delete($page);
            self::fail('a page the menu points at was deleted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('gebruikt', $e->getMessage());
        }

        // Where the page is used names the item by what the menu shows.
        self::assertSame(['zz Vacatures'], array_column(PageUsage::forPageId($pageId), 'label'));
    }

    // --------------------------------------------------------------- helpers

    /**
     * A published page of this test's own, with its Dutch title and, when
     * given, its English title and address.
     *
     * @return array{0: int, 1: string} [id, key — also its Dutch address]
     */
    private function page(string $dutchTitle, ?string $englishTitle = null): array
    {
        $key = 'zz-follow-' . bin2hex(random_bytes(4));
        $id = PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], $dutchTitle);
        $this->pageIds[] = $id;

        if ($englishTitle !== null) {
            PageLocalization::save($id, 'en', [PageTranslation::TITLE => $englishTitle], $key . '-en');
        }

        return [$id, $key];
    }

    /**
     * A page link stored the way it was before this phase: words of its own.
     *
     * @param array<string, string> $labels
     */
    private function item(int $pageId, array $labels): int
    {
        $id = $this->navigation->create([
            'link_type' => 'page',
            'target_page_id' => $pageId,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
            'presentation' => NavigationPresentation::LINK,
            'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
        ]);
        $this->navIds[] = $id;
        foreach ($labels as $language => $label) {
            NavigationLocalization::save($id, $language, $label);
        }

        return $id;
    }

    /** @param array<string, string> $fields */
    private function create(string $session, array $fields): int
    {
        $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, $fields + ['csrf_token' => $this->csrf($session)]);

        self::assertSame(302, $response['status']);
        self::assertMatchesRegularExpression('#^/admin/navigation-item\.php\?id=(\d+)&saved=1$#', $response['location'], (string) json_encode($this->accounts->read($session, 'admin_nav_item_errors')));
        preg_match('#id=(\d+)#', $response['location'], $match);
        $this->navIds[] = (int) $match[1];
        NavigationLocalization::clearCache();

        return (int) $match[1];
    }

    /**
     * Posts the editor's form for an existing page link in one language.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function update(string $session, int $id, string $language, array $fields): array
    {
        $response = self::$server->request('POST', '/api/admin/update-nav-item.php', $session, $fields + [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $id,
            'language_code' => $language,
            'link_type' => 'page',
            'is_visible' => '1',
        ]);
        NavigationLocalization::clearCache();

        return $response;
    }

    /** @return array{0: string, 1: string} */
    private function labels(int $id): array
    {
        NavigationLocalization::clearCache();

        return [NavigationLocalization::raw($id, 'nl'), NavigationLocalization::raw($id, 'en')];
    }

    /**
     * The words of this test's own menu links, found by their page's address,
     * as the header of $path prints them.
     *
     * @return list<string>
     */
    private function menuTexts(string $path, string $key): array
    {
        return $this->texts($path, '//nav[@id="main-nav"]//ul//a[contains(@href, "' . $key . '")]');
    }

    /** @return list<string> */
    private function buttonTexts(string $path, string $key): array
    {
        return $this->texts($path, '//nav[@id="main-nav"]//div[@class="header-buttons"]/a[contains(@href, "' . $key . '")]');
    }

    /** @return list<string> */
    private function texts(string $path, string $query): array
    {
        $texts = [];
        foreach ($this->xpath($this->get(null, $path))->query($query) as $anchor) {
            $texts[] = trim($anchor->textContent);
        }

        return $texts;
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

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
