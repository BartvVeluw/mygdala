<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Database;
use App\Module\BlogModule;
use App\Repository\AdminUserRepository;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Repository\RedirectRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogUrls;
use App\Service\Language\SiteLanguages;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * THE ADDRESS OF A CATEGORY AND OF A TAG, PER LANGUAGE, as an editor gives it
 * one (Multilingual 2.0 phase 6, docs/multilingual/ROUTING.md §8).
 *
 * Phase 6 gave both archives a slug per language and taught the router to use
 * it; this is the other half — the two screens that let somebody WRITE one,
 * and the endpoints behind them. The rules are the page editor's and the post
 * editor's, and they are proven here for the two taxonomies rather than
 * assumed:
 *
 *   - the field belongs to the language being edited, and saving language A
 *     never moves language B's address;
 *   - a changed name never moves an address that already exists;
 *   - a language without one gets its first address made from its own name;
 *   - a collision is a collision inside ONE language, plus the neutral column
 *     for the default language;
 *   - the words the Blog's own routing owns are refused in every language's
 *     spelling;
 *   - a refused save comes back with what was typed, on the card it was typed
 *     on;
 *   - and the address that comes out of all that really resolves, to that
 *     entity and in that language.
 *
 * Over real HTTP against PHP's built-in server with
 * tests/Support/dispatcher-router.php in front of it, like
 * Tests\Service\DispatcherRoutingTest: an endpoint's answer is its redirect
 * and its session flash, a screen's answer is its markup, and a URL's answer
 * is a status code. Nothing here can be proven by calling a class.
 *
 * Every row, redirect and registry entry is this test's own and is removed in
 * tearDown() by exact id. Without a server the test skips itself.
 */
final class BlogTaxonomyAddressEditorTest extends TestCase
{
    private const PREFIX = 'zz-taxaddr-';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private BlogCategoryRepository $categories;
    private BlogTagRepository $tags;
    private BlogPostRepository $posts;
    private RedirectRepository $redirects;

    /** @var list<int> */
    private array $createdCategories = [];
    /** @var list<int> */
    private array $createdTags = [];
    /** @var list<int> */
    private array $createdPosts = [];
    /** @var list<string> redirect source paths a rename in this test produced */
    private array $redirectPaths = [];

    private bool $addedGerman = false;

    public static function setUpBeforeClass(): void
    {
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
        $this->categories = new BlogCategoryRepository();
        $this->tags = new BlogTagRepository();
        $this->posts = new BlogPostRepository();
        $this->redirects = new RedirectRepository();

        self::assertSame('nl', BlogLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPosts as $id) {
            $this->posts->delete($id);
        }
        foreach ($this->createdCategories as $id) {
            $this->categories->delete($id);
        }
        foreach ($this->createdTags as $id) {
            $this->tags->delete($id);
        }

        $this->forgetRedirects();

        if ($this->addedGerman) {
            $db = Database::connection();
            $db->prepare("DELETE FROM blog_category_translations WHERE language_code = 'de'")->execute();
            $db->prepare("DELETE FROM blog_tag_translations WHERE language_code = 'de'")->execute();
            (new SiteLanguageRepository())->delete('de');
            $this->addedGerman = false;
        }

        $this->createdPosts = $this->createdCategories = $this->createdTags = [];

        $this->accounts->forget();
        BlogLocalization::clearCache();
        SiteLanguages::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The screen                                                          */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultLanguageShowsItsOwnAddressAndRequiresOne(): void
    {
        $id = $this->category('Adrescategorie', self::PREFIX . 'adres');
        $session = $this->signIn(null);

        $html = $this->screen($session, '/admin/blog-categories.php');

        self::assertStringContainsString('name="language_code" value="nl"', $html);
        self::assertSame(self::PREFIX . 'adres', $this->slugField($html, '/api/admin/update-blog-category.php', $id));
        self::assertTrue($this->slugFieldIsRequired($html, '/api/admin/update-blog-category.php', $id));
    }

    public function testATranslationShowsAnEmptyAddressThatIsNotRequiredAndSaysSo(): void
    {
        $id = $this->category('Adrescategorie', self::PREFIX . 'leeg');
        $session = $this->signIn('en');

        $html = $this->screen($session, '/admin/blog-categories.php');

        self::assertStringContainsString('name="language_code" value="en"', $html);
        self::assertSame('', $this->slugField($html, '/api/admin/update-blog-category.php', $id));
        self::assertFalse($this->slugFieldIsRequired($html, '/api/admin/update-blog-category.php', $id));
        self::assertStringContainsString('geen webadres in deze taal', $html, 'the screen says the archive has no URL here');
    }

    /* ------------------------------------------------------------------ */
    /* Saving one language                                                 */
    /* ------------------------------------------------------------------ */

    public function testSavingOneLanguagesAddressLeavesEveryOtherOneAlone(): void
    {
        $id = $this->category('Hout', self::PREFIX . 'hout');
        $session = $this->signIn('en');

        $response = $this->saveCategory($session, $id, 'en', ['name' => 'Wood', 'slug' => self::PREFIX . 'wood']);

        self::assertStringContainsString('saved=1', $response['location']);
        BlogLocalization::clearCache();

        $category = $this->categories->find($id);
        self::assertSame(self::PREFIX . 'wood', BlogLocalization::categorySlug($category, 'en'));
        self::assertSame(self::PREFIX . 'hout', BlogLocalization::categorySlug($category, 'nl'), 'the Dutch address did not move');
        self::assertSame(self::PREFIX . 'hout', (string) $category['slug'], 'and neither did the neutral key');
    }

    public function testATranslationGetsItsFirstAddressFromItsOwnNameAndKeepsItAfterARename(): void
    {
        $id = $this->category('Gereedschap', self::PREFIX . 'gereedschap');
        $session = $this->signIn('en');

        $this->saveCategory($session, $id, 'en', ['name' => 'Zz Taxaddr Tools', 'slug' => '']);
        BlogLocalization::clearCache();
        self::assertSame('zz-taxaddr-tools', BlogLocalization::categorySlug($this->categories->find($id), 'en'));

        // A changed name leaves the address exactly where it is: the field
        // carries it, and an existing address is never regenerated.
        $this->saveCategory($session, $id, 'en', ['name' => 'Zz Taxaddr Equipment', 'slug' => 'zz-taxaddr-tools']);
        BlogLocalization::clearCache();
        self::assertSame('zz-taxaddr-tools', BlogLocalization::categorySlug($this->categories->find($id), 'en'));
        self::assertSame('Zz Taxaddr Equipment', BlogLocalization::rawCategory($id, BlogLocalization::NAME, 'en'));
    }

    public function testClearingATranslationsAddressTakesAwayItsPublicUrlAndNothingElse(): void
    {
        $id = $this->category('Metaal', self::PREFIX . 'metaal');
        $session = $this->signIn('en');

        $this->saveCategory($session, $id, 'en', ['name' => 'Metal', 'slug' => self::PREFIX . 'metal']);
        $response = $this->saveCategory($session, $id, 'en', ['name' => 'Metal', 'slug' => '']);

        self::assertStringContainsString('saved=1', $response['location']);
        BlogLocalization::clearCache();

        $category = $this->categories->find($id);
        self::assertNull(BlogLocalization::categorySlug($category, 'en'), 'no address means no public URL in English');
        self::assertSame('Metal', BlogLocalization::rawCategory($id, BlogLocalization::NAME, 'en'), 'the words stay');
        self::assertSame(self::PREFIX . 'metaal', BlogLocalization::categorySlug($category, 'nl'));
    }

    public function testADefaultLanguageAddressMovesTheNeutralKeyWithIt(): void
    {
        $id = $this->category('Verhuizen', self::PREFIX . 'verhuizen');
        $session = $this->signIn(null);

        $this->saveCategory($session, $id, 'nl', ['name' => 'Verhuisd', 'slug' => self::PREFIX . 'verhuisd']);
        BlogLocalization::clearCache();

        $category = $this->categories->find($id);
        self::assertSame(self::PREFIX . 'verhuisd', (string) $category['slug']);
        self::assertSame(self::PREFIX . 'verhuisd', BlogLocalization::categorySlug($category, 'nl'));

        // And the old address keeps working, in this language's URL space.
        $this->rememberRedirect(BlogUrls::categoryRedirectPath(self::PREFIX . 'verhuizen'));
        self::assertNotNull(
            $this->redirects->findBySourcePath('/' . BlogUrls::categoryRedirectPath(self::PREFIX . 'verhuizen')),
            'a renamed archive keeps its old URL working'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Collisions and reserved words                                       */
    /* ------------------------------------------------------------------ */

    public function testAnAddressAlreadyTakenInTheSameLanguageIsRefused(): void
    {
        $taken = $this->category('Bezet', self::PREFIX . 'bezet');
        $other = $this->category('Vrij', self::PREFIX . 'vrij');
        $session = $this->signIn('en');

        $this->saveCategory($session, $taken, 'en', ['name' => 'Taken', 'slug' => self::PREFIX . 'taken']);

        $response = $this->saveCategory($session, $other, 'en', ['name' => 'Free', 'slug' => self::PREFIX . 'taken']);

        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertNotEmpty($this->accounts->read($session, 'admin_blog_taxonomy_errors'));
        BlogLocalization::clearCache();
        self::assertNull(BlogLocalization::categorySlug($this->categories->find($other), 'en'), 'nothing was written');
    }

    public function testTheSameWordInTwoLanguagesIsNotACollision(): void
    {
        $id = $this->category('Deelbaar', self::PREFIX . 'deelbaar');
        $session = $this->signIn('en');

        // /blog/categorie/<x> and /en/blog/category/<x> are different URLs.
        $response = $this->saveCategory($session, $id, 'en', ['name' => 'Shared', 'slug' => self::PREFIX . 'deelbaar']);

        self::assertStringContainsString('saved=1', $response['location']);
        BlogLocalization::clearCache();
        self::assertSame(self::PREFIX . 'deelbaar', BlogLocalization::categorySlug($this->categories->find($id), 'en'));
    }

    public function testADefaultLanguageAddressIsAlsoCheckedAgainstTheNeutralColumn(): void
    {
        $this->category('Eerste', self::PREFIX . 'eerste');
        $second = $this->category('Tweede', self::PREFIX . 'tweede');
        $session = $this->signIn(null);

        $response = $this->saveCategory($session, $second, 'nl', ['name' => 'Tweede', 'slug' => self::PREFIX . 'eerste']);

        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertSame(self::PREFIX . 'tweede', (string) $this->categories->find($second)['slug']);
    }

    /**
     * The Blog owns its own sub-paths, and since phase 6 it owns them in every
     * language's spelling: `categorie` and `category` are the same namespace.
     */
    public function testEverySpellingOfAReservedSubPathIsRefused(): void
    {
        $id = $this->category('Gereserveerd', self::PREFIX . 'gereserveerd');

        foreach (['nl' => 'categorie', 'en' => 'category'] as $language => $word) {
            $session = $this->signIn($language === 'nl' ? null : $language);

            $response = $this->saveCategory($session, $id, $language, ['name' => 'Naam ' . $language, 'slug' => $word]);

            self::assertStringNotContainsString('saved=1', $response['location'], $word);
            self::assertNotEmpty($this->accounts->read($session, 'admin_blog_taxonomy_errors'), $word);
        }

        BlogLocalization::clearCache();
        self::assertSame(self::PREFIX . 'gereserveerd', (string) $this->categories->find($id)['slug']);
    }

    /* ------------------------------------------------------------------ */
    /* A refused save                                                      */
    /* ------------------------------------------------------------------ */

    public function testARefusedSaveKeepsTheTypedAddressOnItsOwnCard(): void
    {
        $id = $this->category('Geweigerd', self::PREFIX . 'geweigerd');
        $untouched = $this->category('Onaangeroerd', self::PREFIX . 'onaangeroerd');
        $session = $this->signIn('en');

        $this->saveCategory($session, $id, 'en', ['name' => 'Refused', 'slug' => 'category']);

        $html = $this->screen($session, '/admin/blog-categories.php');

        self::assertSame('category', $this->slugField($html, '/api/admin/update-blog-category.php', $id), 'the typed address is still there');
        self::assertSame('', $this->slugField($html, '/api/admin/update-blog-category.php', $untouched), 'the other card shows what is stored');
        self::assertStringContainsString('admin-alert--error', $html, 'with the reason');

        $again = $this->screen($session, '/admin/blog-categories.php');
        self::assertSame('', $this->slugField($again, '/api/admin/update-blog-category.php', $id), 'the next visit shows the stored category');
    }

    /* ------------------------------------------------------------------ */
    /* The address really resolves                                         */
    /* ------------------------------------------------------------------ */

    public function testEachLanguagesArchiveAnswersAtItsOwnAddressAndNowhereElse(): void
    {
        $id = $this->category('Routebaar', self::PREFIX . 'routebaar');
        $this->publishedPostIn($id, 'Zz Taxaddr bericht');
        $session = $this->signIn('en');

        $this->saveCategory($session, $id, 'en', ['name' => 'Routable', 'slug' => self::PREFIX . 'routable']);

        self::assertSame(200, $this->get('/blog/categorie/' . self::PREFIX . 'routebaar')['status']);
        self::assertSame(200, $this->get('/en/blog/category/' . self::PREFIX . 'routable')['status']);

        // An address belongs to ONE language: the English one is not a second
        // Dutch URL, and the Dutch one is not an English URL.
        self::assertSame(404, $this->get('/blog/categorie/' . self::PREFIX . 'routable')['status']);
        self::assertSame(404, $this->get('/en/blog/category/' . self::PREFIX . 'routebaar')['status']);
    }

    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(): void
    {
        $this->registerGerman();

        $id = $this->category('Derde taal', self::PREFIX . 'derde');
        $this->publishedPostIn($id, 'Zz Taxaddr derde bericht');
        $session = $this->signIn('de');

        $response = $this->saveCategory($session, $id, 'de', ['name' => 'Dritte Sprache', 'slug' => self::PREFIX . 'dritte']);

        self::assertStringContainsString('saved=1', $response['location']);
        BlogLocalization::clearCache();
        self::assertSame(self::PREFIX . 'dritte', BlogLocalization::categorySlug($this->categories->find($id), 'de'));

        // German has no word of its own for the category segment, so it uses
        // the catalogue's default — a working URL, not a 404.
        self::assertSame(200, $this->get('/de/blog/categorie/' . self::PREFIX . 'dritte')['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Tags                                                                */
    /* ------------------------------------------------------------------ */

    public function testATagsAddressFollowsTheSameRules(): void
    {
        $id = $this->tag(self::PREFIX . 'tagnl', 'Tagnaam');
        $session = $this->signIn('en');

        $html = $this->screen($session, '/admin/blog-tags.php');
        self::assertSame('', $this->slugField($html, '/api/admin/update-blog-tag.php', $id, 'tag-form-' . $id));

        $response = $this->saveTag($session, $id, 'en', ['name' => 'Zz Taxaddr Tagname', 'slug' => '']);

        self::assertStringContainsString('saved=1', $response['location']);
        BlogLocalization::clearCache();

        $tag = $this->tags->find($id);
        self::assertSame('zz-taxaddr-tagname', BlogLocalization::tagSlug($tag, 'en'), 'a first address is made from the name');
        self::assertSame(self::PREFIX . 'tagnl', BlogLocalization::tagSlug($tag, 'nl'), 'the Dutch address did not move');
        self::assertSame(self::PREFIX . 'tagnl', (string) $tag['slug'], 'and neither did the neutral key');
    }

    public function testATagArchiveAnswersAtEachLanguagesOwnAddress(): void
    {
        $id = $this->tag(self::PREFIX . 'tagroute', 'Tagroute');
        $post = $this->publishedPost('Zz Taxaddr tagbericht');
        $this->posts->setTags($post, [$id]);
        $session = $this->signIn('en');

        $this->saveTag($session, $id, 'en', ['name' => 'Tag route', 'slug' => self::PREFIX . 'tagroute-en']);

        self::assertSame(200, $this->get('/blog/tag/' . self::PREFIX . 'tagroute')['status']);
        self::assertSame(200, $this->get('/en/blog/tag/' . self::PREFIX . 'tagroute-en')['status']);
        self::assertSame(404, $this->get('/en/blog/tag/' . self::PREFIX . 'tagroute')['status']);
    }

    public function testARefusedTagSaveKeepsTheTypedAddress(): void
    {
        $id = $this->tag(self::PREFIX . 'tagweiger', 'Tagweigering');
        $session = $this->signIn('en');

        $response = $this->saveTag($session, $id, 'en', ['name' => 'Refused tag', 'slug' => 'tag']);

        self::assertStringNotContainsString('saved=1', $response['location']);

        $html = $this->screen($session, '/admin/blog-tags.php');
        self::assertSame('tag', $this->slugField($html, '/api/admin/update-blog-tag.php', $id, 'tag-form-' . $id));
        self::assertStringContainsString('admin-alert--error', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The language switch on an archive                                   */
    /* ------------------------------------------------------------------ */

    /**
     * REGRESSION. An archive used to leave its language versions undeclared,
     * so the switch offered the same path under every prefix: the English
     * link on /blog/categorie/<dutch> went to /en/blog/categorie/<dutch>,
     * which redirects to /en/blog/category/<dutch> — and that 404s, because
     * the English archive lives at its own address.
     */
    public function testTheSwitchOnACategoryArchiveLinksEachLanguagesOwnAddress(): void
    {
        $id = $this->category('Schakelaar', self::PREFIX . 'switch');
        BlogLocalization::saveCategory($id, 'en', [
            BlogLocalization::SLUG => self::PREFIX . 'switch-en',
            BlogLocalization::NAME => 'Switch',
        ]);
        BlogLocalization::clearCache();
        $this->publishedPostIn($id, 'Zz Taxaddr schakelbericht');

        $dutchPath = BlogUrls::categoryPath(self::PREFIX . 'switch', 1, 'nl');
        $englishPath = BlogUrls::categoryPath(self::PREFIX . 'switch-en', 1, 'en');

        $dutch = $this->get($dutchPath);
        self::assertSame(200, $dutch['status']);
        self::assertSame($englishPath, $this->switchHref($dutch['body'], 'en'), 'EN links the English archive at its own address');

        $english = $this->get($englishPath);
        self::assertSame(200, $english['status'], 'and that address answers');
        self::assertSame($dutchPath, $this->switchHref($english['body'], 'nl'), 'and the way back is the Dutch address');
        self::assertStringContainsString(
            'hreflang="en" href="' . \App\Service\AppUrl::canonical($englishPath) . '"',
            $dutch['body'],
            'the alternates name the same two addresses the switch links'
        );
    }

    /**
     * A language this category has no address in has no archive, so the
     * switch shows it as unavailable — exactly what a page and a post do —
     * instead of linking a URL that 404s.
     */
    public function testTheSwitchOnACategoryArchiveOffersNoLanguageWithoutAnAddress(): void
    {
        $id = $this->category('Alleen Nederlands', self::PREFIX . 'switch-nl');
        $this->publishedPostIn($id, 'Zz Taxaddr alleen nl');

        $dutch = $this->get(BlogUrls::categoryPath(self::PREFIX . 'switch-nl', 1, 'nl'));

        self::assertSame(200, $dutch['status']);
        self::assertNull($this->switchHref($dutch['body'], 'en'), 'no English address, so no English link');
        self::assertTrue($this->switchIsUnavailable($dutch['body'], 'en'), 'but the unavailable state');
    }

    /** The same two rules for a tag archive. */
    public function testTheSwitchOnATagArchiveLinksOnlyAddressesThatExist(): void
    {
        $both = $this->tag(self::PREFIX . 'tagswitch', 'Tagschakelaar');
        BlogLocalization::saveTag($both, 'en', [
            BlogLocalization::SLUG => self::PREFIX . 'tagswitch-en',
            BlogLocalization::NAME => 'Tag switch',
        ]);
        $dutchOnly = $this->tag(self::PREFIX . 'tagswitch-nl', 'Alleen tag');
        BlogLocalization::clearCache();

        $post = $this->publishedPost('Zz Taxaddr tagschakelbericht');
        $this->posts->setTags($post, [$both, $dutchOnly]);

        $tagged = $this->get(BlogUrls::tagPath(self::PREFIX . 'tagswitch', 1, 'nl'));
        self::assertSame(200, $tagged['status']);
        self::assertSame(BlogUrls::tagPath(self::PREFIX . 'tagswitch-en', 1, 'en'), $this->switchHref($tagged['body'], 'en'));

        $single = $this->get(BlogUrls::tagPath(self::PREFIX . 'tagswitch-nl', 1, 'nl'));
        self::assertSame(200, $single['status']);
        self::assertNull($this->switchHref($single['body'], 'en'));
        self::assertTrue($this->switchIsUnavailable($single['body'], 'en'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);

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

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    private function screen(string $session, string $path): string
    {
        $response = self::$server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveCategory(string $session, int $id, string $language, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/update-blog-category.php', $session, $fields + [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $id,
            'language_code' => $language,
            'description' => '',
            'is_active' => '1',
            'sort_order' => '0',
        ]);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveTag(string $session, int $id, string $language, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/update-blog-tag.php', $session, $fields + [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $id,
            'language_code' => $language,
        ]);
    }

    /**
     * The value of one row's slug input. The categories screen has a form per
     * card; the tags screen has controls that name their form by id, so both
     * are found by the form they submit to plus the row's own id.
     */
    private function slugField(string $html, string $action, int $id, ?string $formId = null): string
    {
        return (string) ($this->slugInput($html, $action, $id, $formId)['value'] ?? '');
    }

    private function slugFieldIsRequired(string $html, string $action, int $id, ?string $formId = null): bool
    {
        return ($this->slugInput($html, $action, $id, $formId)['required'] ?? false) === true;
    }

    /** @return array{value: string, required: bool} */
    private function slugInput(string $html, string $action, int $id, ?string $formId): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);

        if ($formId !== null) {
            $query = '//input[@name="slug"][@form="' . $formId . '"]';
        } else {
            $query = '//form[@action="' . $action . '"][.//input[@name="id"][@value="' . $id . '"]]//input[@name="slug"]';
        }

        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, 'one slug field for row ' . $id);

        $input = $nodes->item(0);

        return ['value' => $input->getAttribute('value'), 'required' => $input->hasAttribute('required')];
    }

    /** Where the public language switch sends a visitor for one language, or null when it links nothing. */
    private function switchHref(string $html, string $language): ?string
    {
        $links = $this->switchXpath($html)->query('//div[contains(@class, "lang-switch")]/a[@hreflang="' . $language . '"]');
        self::assertNotFalse($links);

        return $links->length === 0 ? null : $links->item(0)->getAttribute('href');
    }

    /** Is this language shown as a version the page does not have? */
    private function switchIsUnavailable(string $html, string $language): bool
    {
        $spans = $this->switchXpath($html)->query(
            '//div[contains(@class, "lang-switch")]/span[contains(@class, "lang-switch__unavailable")][normalize-space() = "' . strtoupper($language) . '"]'
        );
        self::assertNotFalse($spans);

        return $spans->length === 1;
    }

    private function switchXpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function category(string $name, string $slug): int
    {
        $id = $this->categories->create([
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => $this->categories->nextPosition(),
        ]);
        $this->createdCategories[] = $id;

        BlogLocalization::saveCategory($id, 'nl', [
            BlogLocalization::SLUG => $slug,
            BlogLocalization::NAME => $name,
        ]);
        BlogLocalization::clearCache();

        return $id;
    }

    private function tag(string $slug, string $name): int
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO blog_tags (slug, created_at, updated_at) VALUES (:slug, NOW(), NOW())')
            ->execute(['slug' => $slug]);
        $id = (int) $db->lastInsertId();
        $this->createdTags[] = $id;

        BlogLocalization::saveTag($id, 'nl', [
            BlogLocalization::SLUG => $slug,
            BlogLocalization::NAME => $name,
        ]);
        BlogLocalization::clearCache();

        return $id;
    }

    private function publishedPost(string $title): int
    {
        $id = $this->posts->create([
            'slug' => self::PREFIX . 'post-' . bin2hex(random_bytes(3)),
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => (new \DateTimeImmutable('-1 hour'))->format(BlogClock::SQL_FORMAT),
        ]);
        $this->createdPosts[] = $id;

        BlogLocalization::savePost($id, 'nl', [BlogLocalization::TITLE => $title]);
        BlogLocalization::clearCache();

        return $id;
    }

    private function publishedPostIn(int $categoryId, string $title): int
    {
        $id = $this->publishedPost($title);
        $this->posts->setCategories($id, [$categoryId]);

        return $id;
    }

    private function registerGerman(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
    }

    /** Remembers a redirect this test's own rename produced, so tearDown clears it. */
    private function rememberRedirect(string $path): void
    {
        $this->redirectPaths[] = '/' . ltrim($path, '/');
    }

    private function forgetRedirects(): void
    {
        foreach ($this->redirectPaths as $path) {
            $row = $this->redirects->findBySourcePath($path);
            if ($row !== null) {
                $this->redirects->delete((int) $row['id']);
            }
        }

        $this->redirectPaths = [];
    }
}
