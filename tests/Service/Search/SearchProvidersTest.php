<?php

declare(strict_types=1);

namespace Tests\Service\Search;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\BlogPostRepository;
use App\Repository\PageRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Service\Blog\BlogLocalization;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\PortfolioLocalization;
use App\Service\Routing\RequestLanguage;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchResults;
use App\Service\Search\SearchService;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;

/**
 * The four providers against the database (SEARCH.md): each finds only what
 * its own public page would show, in the language asked for, and a module
 * that is off contributes nothing at all. Every row is written inside a
 * transaction that tearDown() rolls back.
 *
 * The seeded words all carry the made-up token "zqxatlas", so no row that
 * happens to be in the test database can take part.
 */
final class SearchProvidersTest extends TestCase
{
    private const TOKEN = 'zqxatlas';

    private const ALL_ON = ['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true];

    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        ModuleRegistry::overrideForTests(self::ALL_ON);
        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        ModuleRegistry::overrideForTests(null);
        RequestLanguage::reset();
        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        PageLocalization::clearCache();
        ShopLocalization::clearCache();
        BlogLocalization::posts()->clearCache();
        PortfolioLocalization::items()->clearCache();
    }

    private function search(string $query, string $language = 'nl'): SearchResults
    {
        $this->clearCaches();

        return SearchService::search(SearchQuery::fromInput($query), $language, 1, 50);
    }

    /** @return list<string> "type title" per hit */
    private function found(string $query, string $language = 'nl'): array
    {
        return array_map(static fn (SearchHit $hit): string => $hit->type . ' ' . $hit->title, $this->search($query, $language)->hits);
    }

    // ------------------------------------------------------------ seeding

    /** @param array<string, mixed> $extra */
    private function page(string $key, string $title, string $status = PageContent::STATUS_PUBLISHED, array $extra = [], string $description = ''): int
    {
        $id = (new PageRepository())->create(['content_key' => $key, 'slug' => $key, 'status' => $status] + $extra);
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => $title, PageTranslation::META_DESCRIPTION => $description]);

        return $id;
    }

    private function product(string $name, bool $active = true, string $description = ''): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__search_' . bin2hex(random_bytes(5)),
            'price' => 12.5,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, 'nl', [
            ShopLocalization::NAME => $name,
            ShopLocalization::DESCRIPTION => $description,
            ShopLocalization::META_TITLE => '',
            ShopLocalization::META_DESCRIPTION => '',
        ]);

        return $id;
    }

    private function project(string $title, bool $active = true, bool $projectPage = true, string $subtitle = ''): int
    {
        $repository = new PortfolioGalleryRepository();
        $catalogue = $repository->ensureCatalogue();
        $id = $repository->createItem((int) $catalogue['id'], ['media_id' => null, 'image_path' => 'assets/media/p.jpg', 'thumbnail_path' => 'assets/media/p-thumb.jpg']);
        $repository->setItemProjectPage($id, $projectPage, $projectPage ? 'zq-project-' . $id : null);
        if (!$active) {
            $this->db->prepare('UPDATE portfolio_gallery_items SET is_active = 0 WHERE id = :id')->execute(['id' => $id]);
        }
        PortfolioLocalization::saveItem($id, 'nl', [PortfolioLocalization::TITLE => $title, PortfolioLocalization::SUBTITLE => $subtitle]);

        return $id;
    }

    private function post(string $title, string $status = 'published', string $publishedAt = '2026-01-10 10:00:00', bool $noindex = false, string $excerpt = ''): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zq-post-' . bin2hex(random_bytes(4)),
            'status' => $status,
            'published_at' => $publishedAt,
            'noindex' => $noindex,
        ]);
        BlogLocalization::savePost($id, 'nl', [BlogLocalization::TITLE => $title, BlogLocalization::EXCERPT => $excerpt]);

        return $id;
    }

    // ------------------------------------------------------------ pages

    public function testAPublishedPageIsFoundByTitleAndDescription(): void
    {
        $this->page('zq-page-a', 'Zqxatlas werkplaats');
        $this->page('zq-page-b', 'Openingstijden', PageContent::STATUS_PUBLISHED, [], 'Kom langs in de zqxatlas winkel.');

        $results = $this->search(self::TOKEN);
        self::assertSame(['page Zqxatlas werkplaats', 'page Openingstijden'], array_map(static fn (SearchHit $hit): string => $hit->type . ' ' . $hit->title, $results->hits));
        self::assertSame('/zq-page-a', $results->hits[0]->url);
        self::assertSame('Pagina', $results->hits[0]->typeLabel);
        self::assertStringContainsString('zqxatlas winkel', $results->hits[1]->excerpt);
    }

    public function testADraftOrNoindexPageIsNeverFound(): void
    {
        $this->page('zq-draft', 'Zqxatlas concept', PageContent::STATUS_DRAFT);
        $noindex = $this->page('zq-noindex', 'Zqxatlas bedankt');
        $this->db->prepare('UPDATE pages SET noindex = 1 WHERE id = :id')->execute(['id' => $noindex]);

        self::assertSame([], $this->found(self::TOKEN));
    }

    public function testAPageUnderASwitchedOffModuleIsNotFound(): void
    {
        $portfolioRoot = $this->db->query("SELECT id FROM pages WHERE route_path = '/portfolio' LIMIT 1")->fetchColumn();
        if ($portfolioRoot === false) {
            self::markTestSkipped('no Portfolio system page in this database');
        }
        $this->page('zq-under-portfolio', 'Zqxatlas onder portfolio', PageContent::STATUS_PUBLISHED, ['parent_id' => (int) $portfolioRoot]);

        self::assertSame(['page Zqxatlas onder portfolio'], $this->found(self::TOKEN));

        ModuleRegistry::overrideForTests(['portfolio' => false] + self::ALL_ON);
        self::assertSame([], $this->found(self::TOKEN), 'a page in a switched-off module\'s subtree answers 404, so search does not show it');
    }

    public function testAPageIsFoundInTheLanguageAskedFor(): void
    {
        $id = $this->page('zq-lang', 'Zqxatlas over ons');
        PageLocalization::save($id, 'en', [PageTranslation::TITLE => 'About zqxatlas'], 'zq-lang-en');
        $onlyDutch = $this->page('zq-only-nl', 'Zqxatlas alleen Nederlands');

        $english = $this->search(self::TOKEN, 'en');
        $titles = array_map(static fn (SearchHit $hit): string => $hit->title, $english->hits);
        self::assertContains('About zqxatlas', $titles, 'the English words on the English results');
        self::assertNotContains('Zqxatlas over ons', $titles);
        self::assertSame('/en/zq-lang-en', $english->hits[array_search('About zqxatlas', $titles, true)]->url, 'and the English address');
        self::assertSame('Page', $english->hits[0]->typeLabel);

        // A page with no English words falls back as the site itself does
        // (PageLocalization's fallback, PageContent::publicUrl): the menu
        // links such a page to its Dutch address, and so does the search.
        $fallback = $english->hits[array_search('Zqxatlas alleen Nederlands', $titles, true)];
        self::assertSame('/zq-only-nl', $fallback->url);
        self::assertGreaterThan(0, $onlyDutch);
    }

    // ------------------------------------------------------------ shop

    public function testAnActiveProductIsFoundAndAnInactiveOneNot(): void
    {
        $active = $this->product('Zqxatlas lamp', true, '<p>Een <strong>houten</strong> lamp.</p>');
        $this->product('Zqxatlas oud model', false);
        $this->product('Tafel', true, '<p>Past bij de zqxatlas lamp.</p>');

        $results = $this->search(self::TOKEN);
        self::assertSame(['product Zqxatlas lamp', 'product Tafel'], array_map(static fn (SearchHit $hit): string => $hit->type . ' ' . $hit->title, $results->hits));
        self::assertSame('/product.php?id=' . $active, $results->hits[0]->url);
        self::assertSame('Een houten lamp.', $results->hits[0]->excerpt, 'the description as plain text');
        self::assertSame('/en/product.php?id=' . $active, $this->search(self::TOKEN, 'en')->hits[0]->url);
    }

    public function testWithTheShopOffNoProductIsFound(): void
    {
        $this->product('Zqxatlas lamp');

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false] + self::ALL_ON);
        self::assertSame([], $this->found(self::TOKEN));
        self::assertArrayNotHasKey('product', SearchService::providers());
    }

    // ------------------------------------------------------------ portfolio

    public function testAPublicProjectIsFoundAndAHiddenOneNot(): void
    {
        $public = $this->project('Zqxatlas kast', true, true, 'Eikenhout');
        $this->project('Zqxatlas verborgen', false);
        $this->project('Zqxatlas zonder pagina', true, false);

        $results = $this->search(self::TOKEN);
        self::assertSame(['project Zqxatlas kast'], array_map(static fn (SearchHit $hit): string => $hit->type . ' ' . $hit->title, $results->hits));
        self::assertSame('/portfolio/zq-project-' . $public, $results->hits[0]->url);
        self::assertSame('/assets/media/p-thumb.jpg', $results->hits[0]->thumbnail);
        self::assertSame('Eikenhout', $results->hits[0]->excerpt);
        self::assertSame('/en/portfolio/zq-project-' . $public, $this->search(self::TOKEN, 'en')->hits[0]->url);
    }

    public function testWithPortfolioOffNoProjectIsFound(): void
    {
        $this->project('Zqxatlas kast');

        ModuleRegistry::overrideForTests(['portfolio' => false] + self::ALL_ON);
        self::assertSame([], $this->found(self::TOKEN));
    }

    // ------------------------------------------------------------ blog

    public function testOnlyAPublishedIndexablePostIsFoundNewestFirst(): void
    {
        $this->post('Zqxatlas oud nieuws', 'published', '2026-01-01 09:00:00');
        $this->post('Zqxatlas nieuws', 'published', '2026-02-01 09:00:00', false, 'Kort verslag.');
        $this->post('Zqxatlas concept', 'draft', '2026-01-05 09:00:00');
        $this->post('Zqxatlas later', 'scheduled', '2099-01-01 09:00:00');
        $this->post('Zqxatlas verborgen', 'published', '2026-01-03 09:00:00', true);

        self::assertSame(['post Zqxatlas nieuws', 'post Zqxatlas oud nieuws'], $this->found(self::TOKEN), 'a prefix match each; within one score the Blog\'s own order, newest first');
        $hit = $this->search(self::TOKEN)->hits[0];
        self::assertSame('Blogbericht', $hit->typeLabel);
        self::assertStringStartsWith('/blog/', $hit->url);
        self::assertSame('Kort verslag.', $hit->excerpt);
    }

    public function testWithTheBlogOffNoPostIsFound(): void
    {
        $this->post('Zqxatlas nieuws');

        ModuleRegistry::overrideForTests(['blog' => false] + self::ALL_ON);
        self::assertSame([], $this->found(self::TOKEN));
    }

    // ------------------------------------------------------------ together

    public function testEveryKindTogetherIsRankedByOneRule(): void
    {
        $this->page('zq-mix', 'Zqxatlas');
        $this->product('Zqxatlas lamp');
        $this->project('Houten zqxatlas');
        $this->post('Nieuws', 'published', '2026-01-10 10:00:00', false, 'Over de zqxatlas.');

        self::assertSame(
            ['page Zqxatlas', 'product Zqxatlas lamp', 'project Houten zqxatlas', 'post Nieuws'],
            $this->found(self::TOKEN),
            'exact title, title starts with, a word starts with, only the text'
        );
    }

    /**
     * No query per result: every provider batches (SearchProvider's
     * contract), so twelve results of each kind cost what one of each does.
     * Measured with Com_select on this connection, as LinkResolverTest does.
     */
    public function testTheQueryCountDoesNotGrowWithTheResults(): void
    {
        $seed = function (int $count, string $token): void {
            for ($i = 1; $i <= $count; $i++) {
                $this->page('zq-count-' . $token . '-' . $i, ucfirst($token) . ' pagina ' . $i);
                $this->product(ucfirst($token) . ' lamp ' . $i);
                $this->project(ucfirst($token) . ' kast ' . $i);
                $this->post(ucfirst($token) . ' nieuws ' . $i);
            }
        };
        $seed(1, 'zqxeen');
        $seed(12, 'zqxtwaalf');

        $cost = function (string $query): array {
            $this->clearCaches();
            \App\Service\Media\MediaService::clearCache();
            $before = $this->selects();
            $results = SearchService::search(SearchQuery::fromInput($query), 'nl', 1, 50);

            return [$results->total, $this->selects() - $before];
        };

        [$fewTotal, $few] = $cost('zqxeen');
        [$manyTotal, $many] = $cost('zqxtwaalf');

        self::assertSame(4, $fewTotal);
        self::assertSame(48, $manyTotal);
        self::assertSame($few, $many, 'the same number of queries for 4 and for 48 results');
    }

    private function selects(): int
    {
        return (int) $this->db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
    }

    // ------------------------------------------------------------ more words

    /**
     * Every provider finds a result whose words are all there but apart, or
     * split over title and text; none finds one that has only one of them;
     * and no visibility rule loosens for a query of more words.
     */
    public function testEveryProviderFindsTermsApartAndSplit(): void
    {
        $this->page('zq-terms-page', 'Zqxlaseren en graveren op zqxhout');
        $this->page('zq-terms-page-split', 'Zqxlaseren', PageContent::STATUS_PUBLISHED, [], 'Werk op zqxhout.');
        $this->page('zq-terms-page-one', 'Alleen zqxlaseren');
        $this->product('Zqxlaseren lamp', true, '<p>Van <strong>zqxhout</strong>.</p>');
        $this->product('Zqxlaseren alleen', true, '<p>Van metaal.</p>');
        $this->project('Kast zqxhout', true, true, 'Zqxlaseren en schuren');
        $this->post('Zqxhout nieuws', 'published', '2026-01-10 10:00:00', false, 'Over zqxlaseren.');

        self::assertSame(
            [
                'page Zqxlaseren en graveren op zqxhout',
                'page Zqxlaseren',
                'product Zqxlaseren lamp',
                'post Zqxhout nieuws',
                'project Kast zqxhout',
            ],
            $this->found('zqxlaseren zqxhout'),
            'both terms in the title first; then one of two in the title and one in the text, in provider order (pages, then the modules in registry order); never a result with only one term'
        );
        self::assertSame($this->found('zqxlaseren zqxhout'), $this->found('zqxhout   zqxlaseren'), 'order and spacing of the terms do not matter for which results');
    }

    public function testThePhraseRanksAboveLooseTermsAcrossProviders(): void
    {
        $this->product('Zqxgraveren op zqxhout', true);
        $this->page('zq-phrase', 'Over zqxhout zqxgraveren');

        self::assertSame(['page Over zqxhout zqxgraveren', 'product Zqxgraveren op zqxhout'], $this->found('zqxhout zqxgraveren'));
    }

    public function testMoreWordsNeverLoosenVisibility(): void
    {
        $this->page('zq-hidden-draft', 'Zqxlaseren zqxhout concept', PageContent::STATUS_DRAFT);
        $this->product('Zqxlaseren zqxhout oud', false);
        $this->project('Zqxlaseren zqxhout verborgen', false);
        $this->project('Zqxlaseren zqxhout zonder pagina', true, false);
        $this->post('Zqxlaseren zqxhout concept', 'draft');
        $this->post('Zqxlaseren zqxhout later', 'scheduled', '2099-01-01 09:00:00');

        self::assertSame([], $this->found('zqxhout zqxlaseren'));

        $this->product('Zqxlaseren zqxhout lamp', true);
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false] + self::ALL_ON);
        self::assertSame([], $this->found('zqxhout zqxlaseren'), 'a module that is off has no provider, whatever the query');
    }

    public function testAWildcardInTheQueryIsNotAWildcard(): void
    {
        $this->page('zq-pct', 'Zqxatlas');

        self::assertSame([], $this->found('zq%las'));
        self::assertSame([], $this->found('zqx_tlas'));
        self::assertSame(['page Zqxatlas'], $this->found('ZQXATLAS'));
    }
}
