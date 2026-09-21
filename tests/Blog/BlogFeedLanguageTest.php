<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\BlogPostRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogFeed;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogLocalizedSettings;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogUrls;
use App\Service\Language\SiteLanguages;
use App\Service\Routing\RequestLanguage;
use App\Service\SeoDefaults;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * ONE FEED PER LANGUAGE, WRITTEN IN THAT LANGUAGE (BLOG.md, "RSS";
 * docs/multilingual/ROUTING.md).
 *
 * Phase 6 gave every language its own feed address and localized the item
 * links, but the words stayed in the default language: /en/blog/feed.xml said
 * `<language>en</language>` over a Dutch channel title and Dutch items. Now
 * the channel title and description, every item's title and description,
 * `<language>` and every link follow the request — through the Blog's own
 * fallback (requested language, then the default language, then '' or the
 * code default), and nothing else.
 *
 * Most of it in-process, with the request language pinned the way
 * dispatcher.php pins it; the last test goes through the dispatcher router
 * over real HTTP, because "the URL decides the language" is only true if the
 * URL really did.
 */
final class BlogFeedLanguageTest extends TestCase
{
    private const PREFIX = 'zz-blogfeedlang-';

    private static ?BuiltInServer $server = null;

    private BlogPostRepository $posts;

    /** @var list<int> */
    private array $createdPosts = [];

    private bool $addedGerman = false;

    /** @var array<string, array<string, string>>|null language => key => the raw words before a test stored its own */
    private ?array $storedChannelWords = null;

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
        $this->posts = new BlogPostRepository();
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'multilingual' => true]);

        if (BlogLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPosts as $id) {
            $this->posts->delete($id);
        }
        $this->createdPosts = [];

        if ($this->storedChannelWords !== null) {
            foreach ($this->storedChannelWords as $code => $words) {
                BlogLocalizedSettings::save($code, $words);
            }
            $this->storedChannelWords = null;
        }

        if ($this->addedGerman) {
            Database::connection()->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            $this->addedGerman = false;
        }

        RequestLanguage::reset();
        BlogLocalizedSettings::overrideForTests(null);
        BlogLocalizedSettings::clearCache();
        BlogLocalization::clearCache();
        BlogSettings::overrideForTests(null);
        SiteLanguages::clearCache();
        ModuleRegistry::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* Each language's feed                                                */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultLanguagesFeedIsDutchThroughout(): void
    {
        $this->channelWords([
            'nl' => ['Werkplaats zz', 'Wat er gebeurt zz.'],
            'en' => ['Workshop zz', 'What happens zz.'],
        ]);
        $post = $this->bilingualPost();

        $feed = $this->feed('nl', false);

        self::assertStringStartsWith('Werkplaats zz', (string) $feed->channel->title);
        self::assertSame('Wat er gebeurt zz.', (string) $feed->channel->description);
        self::assertSame('nl', (string) $feed->channel->language);
        self::assertSame(BlogUrls::index(1, 'nl'), (string) $feed->channel->link);
        self::assertSame(BlogUrls::feed('nl'), $this->selfLink($feed));

        $item = $this->item($feed, BlogUrls::post($post['nl_slug'], 'nl'));
        self::assertSame('Werkbank zz', (string) $item->title);
        self::assertSame('Over de werkbank zz.', (string) $item->description);
        self::assertSame(BlogUrls::post($post['nl_slug'], 'nl'), (string) $item->guid);

        $this->assertNoWordsOf(['Workshop zz', 'What happens zz.', 'Workbench zz', 'About the workbench zz.'], $feed);
    }

    /**
     * REGRESSION. /en/blog/feed.xml carried English links and
     * `<language>en</language>` over the Dutch channel title, the Dutch
     * introduction and every post's Dutch title and excerpt.
     */
    public function testTheEnglishFeedIsEnglishThroughout(): void
    {
        $this->channelWords([
            'nl' => ['Werkplaats zz', 'Wat er gebeurt zz.'],
            'en' => ['Workshop zz', 'What happens zz.'],
        ]);
        $post = $this->bilingualPost();

        $feed = $this->feed('en', true);

        self::assertStringStartsWith('Workshop zz', (string) $feed->channel->title);
        self::assertSame('What happens zz.', (string) $feed->channel->description);
        self::assertSame('en', (string) $feed->channel->language);
        self::assertSame(BlogUrls::index(1, 'en'), (string) $feed->channel->link);
        self::assertStringEndsWith('/en/blog', (string) $feed->channel->link);
        self::assertSame(BlogUrls::feed('en'), $this->selfLink($feed));
        self::assertStringEndsWith('/en/blog/feed.xml', $this->selfLink($feed));

        $item = $this->item($feed, BlogUrls::post($post['en_slug'], 'en'));
        self::assertSame('Workbench zz', (string) $item->title);
        self::assertSame('About the workbench zz.', (string) $item->description);

        $this->assertNoWordsOf(['Werkplaats zz', 'Wat er gebeurt zz.', 'Werkbank zz', 'Over de werkbank zz.'], $feed);
    }

    /** A third language is a row in site_languages and nothing else. */
    public function testAThirdLanguagesFeedIsWrittenInItsOwnWords(): void
    {
        $this->addGerman();
        $this->channelWords([
            'nl' => ['Werkplaats zz', 'Wat er gebeurt zz.'],
            'de' => ['Werkstatt zz', 'Was passiert zz.'],
        ]);
        $post = $this->bilingualPost();
        $deSlug = $post['nl_slug'] . '-de';
        BlogLocalization::savePost($post['id'], 'de', [
            BlogLocalization::SLUG => $deSlug,
            BlogLocalization::TITLE => 'Hobelbank zz',
            BlogLocalization::EXCERPT => 'Über die Hobelbank zz.',
        ]);
        BlogLocalization::clearCache();

        $feed = $this->feed('de', true);

        self::assertStringStartsWith('Werkstatt zz', (string) $feed->channel->title);
        self::assertSame('Was passiert zz.', (string) $feed->channel->description);
        self::assertSame('de', (string) $feed->channel->language);
        self::assertStringEndsWith('/de/blog/feed.xml', $this->selfLink($feed));

        $item = $this->item($feed, BlogUrls::post($deSlug, 'de'));
        self::assertStringContainsString('/de/blog/' . $deSlug, (string) $item->link);
        self::assertSame('Hobelbank zz', (string) $item->title);
        self::assertSame('Über die Hobelbank zz.', (string) $item->description);

        $this->assertNoWordsOf(['Werkplaats zz', 'Werkbank zz', 'Workbench zz'], $feed);
    }

    /* ------------------------------------------------------------------ */
    /* The existing fallback, and no other                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Requested language, then the default language — per field, the way the
     * page itself resolves it. A post without English words or an English
     * address reads in Dutch and links its Dutch URL, which is what every
     * other internal link to it does; it is not dropped and not invented.
     */
    public function testMissingWordsFallBackToTheDefaultLanguage(): void
    {
        $this->channelWords(['nl' => ['Werkplaats zz', 'Wat er gebeurt zz.']]);
        $post = $this->post('Alleen Nederlands zz', 'Nog niet vertaald zz.');

        $feed = $this->feed('en', true);

        self::assertStringStartsWith('Werkplaats zz', (string) $feed->channel->title, 'no English title: the Dutch one');
        self::assertSame('Wat er gebeurt zz.', (string) $feed->channel->description, 'no English introduction: the Dutch one');
        self::assertSame('en', (string) $feed->channel->language, 'the feed is still the English one');

        $item = $this->item($feed, BlogUrls::post($post['nl_slug'], 'nl'));
        self::assertSame('Alleen Nederlands zz', (string) $item->title);
        self::assertSame('Nog niet vertaald zz.', (string) $item->description);
    }

    /**
     * Past the default language the domain's own end of the chain: the code
     * default "Blog" for the title, and for the required description the
     * site's default description or, lacking that, the title.
     */
    public function testWithNoWordsAnywhereTheCodeDefaultsAnswer(): void
    {
        $this->channelWords(['nl' => ['', ''], 'en' => ['', '']]);

        $feed = $this->feed('en', true);

        $siteName = SeoDefaults::siteName();
        $expectedTitle = ($siteName === '' || $siteName === BlogLocalizedSettings::DEFAULT_TITLE)
            ? BlogLocalizedSettings::DEFAULT_TITLE
            : BlogLocalizedSettings::DEFAULT_TITLE . ' — ' . $siteName;
        self::assertSame($expectedTitle, (string) $feed->channel->title);

        $description = SeoDefaults::description();
        self::assertSame($description !== '' ? $description : $expectedTitle, (string) $feed->channel->description);
    }

    /* ------------------------------------------------------------------ */
    /* Escaping                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * English words are administrator-typed too; a feed reader is a parser.
     * The two titles are printed as typed; the introduction and the excerpt
     * are plain text first (Seo::plainText()), so they carry no markup here.
     */
    public function testEveryLanguagesWordsAreEscaped(): void
    {
        $this->channelWords([
            'nl' => ['Werkplaats zz', ''],
            'en' => ['Tom & <Jerry> zz', 'Fish & "chips" zz'],
        ]);
        $post = $this->post('Gewoon zz', 'Gewoon zz.');
        BlogLocalization::savePost($post['id'], 'en', [
            BlogLocalization::SLUG => $post['nl_slug'] . '-en',
            BlogLocalization::TITLE => 'Salt & <pepper> "zz"',
            BlogLocalization::EXCERPT => 'Salt & vinegar \'zz\'',
        ]);
        BlogLocalization::clearCache();

        $xml = $this->feedXml('en', true);

        $feed = simplexml_load_string($xml);
        self::assertNotFalse($feed, 'the feed must parse');
        self::assertStringNotContainsString('<Jerry>', $xml);
        self::assertStringNotContainsString('<pepper>', $xml);
        self::assertStringContainsString('<title>Tom &amp; &lt;Jerry&gt; zz', $xml);
        self::assertStringContainsString('<title>Salt &amp; &lt;pepper&gt; &quot;zz&quot;</title>', $xml);
        self::assertStringContainsString('<description>Fish &amp; &quot;chips&quot; zz</description>', $xml);

        // And a reader gets the words back exactly.
        self::assertStringStartsWith('Tom & <Jerry> zz', (string) $feed->channel->title);
        self::assertSame('Fish & "chips" zz', (string) $feed->channel->description);
        $item = $this->item($feed, BlogUrls::post($post['nl_slug'] . '-en', 'en'));
        self::assertSame('Salt & <pepper> "zz"', (string) $item->title);
        self::assertSame('Salt & vinegar \'zz\'', (string) $item->description);
    }

    /* ------------------------------------------------------------------ */
    /* Over real HTTP                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The address decides: /blog/feed.xml is Dutch and /en/blog/feed.xml
     * English, and the feed a page points its readers at in its <head> is the
     * one in the page's own language, under that language's blog title.
     */
    public function testEachLanguagesFeedAddressServesThatLanguage(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        if (!BlogSettings::rssEnabled()) {
            $this->markTestSkipped('the feed is switched off in this test database');
        }

        $this->channelWords([
            'nl' => ['Werkplaats zz', 'Wat er gebeurt zz.'],
            'en' => ['Workshop zz', 'What happens zz.'],
        ]);
        $post = $this->bilingualPost();

        $dutch = self::$server->request('GET', '/blog/feed.xml');
        self::assertSame(200, $dutch['status'], 'the Blog module must be on for this server');
        $dutchFeed = simplexml_load_string($dutch['body']);
        self::assertNotFalse($dutchFeed);
        self::assertSame('nl', (string) $dutchFeed->channel->language);
        self::assertSame('Werkbank zz', (string) $this->item($dutchFeed, BlogUrls::post($post['nl_slug'], 'nl'))->title);

        $english = self::$server->request('GET', '/en/blog/feed.xml');
        self::assertSame(200, $english['status']);
        $englishFeed = simplexml_load_string($english['body']);
        self::assertNotFalse($englishFeed);
        self::assertSame('en', (string) $englishFeed->channel->language);
        self::assertStringStartsWith('Workshop zz', (string) $englishFeed->channel->title);
        self::assertSame('What happens zz.', (string) $englishFeed->channel->description);
        self::assertSame('Workbench zz', (string) $this->item($englishFeed, BlogUrls::post($post['en_slug'], 'en'))->title);
        self::assertStringNotContainsString('Werkplaats zz', $english['body']);
        self::assertStringNotContainsString('Werkbank zz', $english['body']);

        $index = self::$server->request('GET', '/en/blog');
        self::assertSame(200, $index['status']);
        self::assertMatchesRegularExpression(
            '#<link rel="alternate" type="application/rss\+xml" title="Workshop zz" href="/en/blog/feed\.xml">#',
            $index['body'],
            'the English listing points at the English feed, by its English name'
        );

        $postPage = self::$server->request('GET', BlogUrls::postPath($post['en_slug'], 'en'));
        self::assertSame(200, $postPage['status']);
        self::assertStringContainsString('type="application/rss+xml" title="Workshop zz" href="/en/blog/feed.xml"', $postPage['body']);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Store the channel words per language — [title, introduction] — in the
     * database, so a server started by this test reads them too. What was
     * there before is put back in tearDown().
     *
     * @param array<string, array{0: string, 1: string}> $words
     */
    private function channelWords(array $words): void
    {
        // A language this test says nothing about has no words of its own,
        // whatever the test database happened to hold.
        foreach (SiteLanguages::activeCodes() as $code) {
            $words[$code] ??= ['', ''];
        }

        $this->storedChannelWords ??= [];

        foreach ($words as $code => [$title, $intro]) {
            if (!isset($this->storedChannelWords[$code])) {
                $this->storedChannelWords[$code] = [
                    BlogLocalizedSettings::TITLE => BlogLocalizedSettings::raw(BlogLocalizedSettings::TITLE, $code),
                    BlogLocalizedSettings::INTRO => BlogLocalizedSettings::raw(BlogLocalizedSettings::INTRO, $code),
                ];
            }

            BlogLocalizedSettings::save($code, [
                BlogLocalizedSettings::TITLE => $title,
                BlogLocalizedSettings::INTRO => $intro,
            ]);
        }

        BlogLocalizedSettings::clearCache();
    }

    /**
     * A public post with Dutch and English words and an address in each.
     *
     * @return array{id: int, nl_slug: string, en_slug: string}
     */
    private function bilingualPost(): array
    {
        $post = $this->post('Werkbank zz', 'Over de werkbank zz.');
        $post['en_slug'] = $post['nl_slug'] . '-en';

        BlogLocalization::savePost($post['id'], 'en', [
            BlogLocalization::SLUG => $post['en_slug'],
            BlogLocalization::TITLE => 'Workbench zz',
            BlogLocalization::EXCERPT => 'About the workbench zz.',
        ]);
        BlogLocalization::clearCache();

        return $post;
    }

    /**
     * A public post with words in the default language only.
     *
     * @return array{id: int, nl_slug: string}
     */
    private function post(string $title, string $excerpt): array
    {
        $slug = BlogSlug::unique(
            self::PREFIX . BlogSlug::sanitize($title),
            $title,
            fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
        );

        $id = $this->posts->create([
            'slug' => $slug,
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => (new \DateTimeImmutable('-1 minute'))->format(BlogClock::SQL_FORMAT),
        ]);
        $this->createdPosts[] = $id;

        BlogLocalization::savePost($id, 'nl', [
            BlogLocalization::SLUG => $slug,
            BlogLocalization::TITLE => $title,
            BlogLocalization::EXCERPT => $excerpt,
        ]);
        BlogLocalization::clearCache();

        return ['id' => $id, 'nl_slug' => $slug];
    }

    private function addGerman(): void
    {
        if (SiteLanguages::exists('de')) {
            $this->markTestSkipped('the test database already registers de');
        }

        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
    }

    /** The feed document as dispatcher.php would build it for this language. */
    private function feedXml(string $language, bool $fromUrl): string
    {
        RequestLanguage::set($language, $fromUrl);

        return BlogFeed::xml();
    }

    private function feed(string $language, bool $fromUrl): \SimpleXMLElement
    {
        $feed = simplexml_load_string($this->feedXml($language, $fromUrl));
        self::assertNotFalse($feed, 'the feed must parse');

        return $feed;
    }

    private function selfLink(\SimpleXMLElement $feed): string
    {
        $links = $feed->channel->children('http://www.w3.org/2005/Atom')->link;
        self::assertCount(1, $links);

        return (string) $links[0]->attributes()->href;
    }

    /** The one item that links $url; fails when there is none. */
    private function item(\SimpleXMLElement $feed, string $url): \SimpleXMLElement
    {
        foreach ($feed->channel->item as $item) {
            if ((string) $item->link === $url) {
                return $item;
            }
        }

        self::fail('no item links ' . $url);
    }

    /** @param list<string> $words */
    private function assertNoWordsOf(array $words, \SimpleXMLElement $feed): void
    {
        $xml = (string) $feed->asXML();

        foreach ($words as $word) {
            self::assertStringNotContainsString(htmlspecialchars($word, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $xml, 'another language\'s words: ' . $word);
            self::assertStringNotContainsString($word, $xml, 'another language\'s words: ' . $word);
        }
    }
}
