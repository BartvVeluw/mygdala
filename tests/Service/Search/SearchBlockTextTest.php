<?php

declare(strict_types=1);

namespace Tests\Service\Search;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\ArticleRepository;
use App\Repository\BlogPostRepository;
use App\Repository\FaqRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Repository\RichTextRepository;
use App\Service\Articles\ArticleContentOwner;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleService;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostContentOwner;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\Publishing\PublishingClock;
use App\Service\RichTextContent;
use App\Service\Routing\RequestLanguage;
use App\Service\Search\BlockSearchIndex;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchResults;
use App\Service\Search\SearchService;
use App\Service\Search\SearchText;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;

/**
 * Search 2.0 against the database (SEARCH.md "De tekst van de blokken"): a
 * word that stands only in a content block finds its OWNER — a page, a
 * product, a project, a blog post, an article — once, at the owner's own
 * address, with the words around it as the excerpt; only as long as a
 * visitor can see it, in the language asked for, and never after the block,
 * the words or the owner are gone.
 *
 * Blocks are written the way their editors write them: words, then
 * ContentBlockDrafts::place() as the save's last step (which keeps the
 * index current). Everything runs inside one transaction that tearDown()
 * rolls back; every seeded word carries a made-up token, so no row that
 * happens to be in the test database takes part.
 */
final class SearchBlockTextTest extends TestCase
{
    private const ALL_ON = ['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true, 'articles' => true];

    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        ModuleRegistry::overrideForTests(self::ALL_ON);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        $this->clearCaches();
        BlockSearchIndex::ensureCurrent();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        RequestLanguage::reset();
        PublishingClock::freezeForTests(null);
        $this->clearCaches();
    }

    /* ------------------------------------------------------------------ */
    /* What a block offers                                                 */
    /* ------------------------------------------------------------------ */

    public function testTheWordsOfEveryKindOfBlockAreFoundAndNothingElse(): void
    {
        $page = $this->page('Werkplaats zqwerk');
        $this->text($page, '<h2>Zqkop graveren</h2><p>Wij graveren zqrich op glas.</p>');
        $this->faq($page, 'Zqfaqtitel', [['Kan zqvraag op glas?', 'Ja, zqantwoord altijd.']]);
        $this->cta($page, 'Zqcta bel ons', 'Zqlead vandaag nog');
        $this->gallery($page, 'Zqgalerij werk', 'Zqknop bekijken');
        $this->spacer($page);

        foreach (['zqrich', 'zqkop', 'zqfaqtitel', 'zqvraag', 'zqantwoord', 'zqcta', 'zqlead', 'zqgalerij'] as $word) {
            $this->assertSame(['page Werkplaats zqwerk'], $this->found($word), $word . ' finds its page');
        }

        $this->assertSame([], $this->found('zqknop'), 'a button label is not searchable');
    }

    public function testRichTextIsReadAsTextWithEntitiesUnicodeAndNoMarkup(): void
    {
        $page = $this->page('Teksten zqmarkup');
        $this->text($page, '<p>R&amp;D <strong>zqvet</strong>gedrukt</p><script>alert("zqscript")</script><p>Café Ünïcode ✨ zqcafe</p>');

        $this->assertSame([], $this->found('zqscript'), 'a script never reaches the index (sanitized away)');
        $this->assertSame([], $this->found('strong'), 'markup is not text');
        $this->assertSame(['page Teksten zqmarkup'], $this->found('R&D zqvet'), 'entities decoded');
        $this->assertSame(['page Teksten zqmarkup'], $this->found('cafe unicode'), 'accents folded both ways');

        $hit = $this->search('zqcafe')->hits[0];
        $this->assertStringNotContainsString('<', $hit->excerpt);
        $this->assertStringContainsString('Café Ünïcode ✨ zqcafe', $hit->excerpt);
    }

    public function testAHiddenBlockAHiddenItemAndASwitchedOffBlockAreNotFound(): void
    {
        $page = $this->page('Verborgen zqhide');
        [$row] = $this->text($page, '<p>Zqlijst verborgen</p>');
        [, $faqId] = $this->faq($page, 'Zqfaq zichtbaar', [['Zqitemuit vraag', 'antwoord']]);
        [, $textId] = $this->text($page, '<p>Zqeditoruit tekst</p>');
        $page = (new PageRepository())->findById((int) $page['id']);

        SectionRegistry::setActive($row, false);
        $this->assertSame([], $this->found('zqlijst'), 'hidden in the block list');
        SectionRegistry::setActive($row, true);
        $this->assertCount(1, $this->found('zqlijst'), 'shown again');

        $item = (new FaqRepository())->findItemsBySectionId($faqId)[0];
        (new FaqRepository())->updateItem((int) $item['id'], ['is_active' => false]);
        ContentBlockDrafts::place('faq', $faqId);
        $this->assertSame([], $this->found('zqitemuit'), 'an item switched off');
        $this->assertCount(1, $this->found('zqfaq'), 'the rest of that block still counts');

        $text = (new PageSectionRepository())->findBySectionTypeAndId('rich_text', $textId);
        (new RichTextRepository())->upsertSection((string) $text['page_slug'], (string) $text['section_key'], ['is_active' => false]);
        ContentBlockDrafts::place('rich_text', $textId);
        $this->assertSame([], $this->found('zqeditoruit'), 'switched off in its own editor');
    }

    /* ------------------------------------------------------------------ */
    /* Every owner                                                         */
    /* ------------------------------------------------------------------ */

    public function testAWordOnlyInABlockFindsEachKindOfOwnerAtItsAddress(): void
    {
        $page = $this->page('Gewone pagina zqp');
        $this->text($page, '<p>Alleen hier zqpageword.</p>');

        $product = $this->product('Product zqp');
        $this->text(ContentPages::ensure(ProductContentOwner::KIND, $product), '<p>Alleen hier zqproductword.</p>');

        $project = $this->project('Project zqp');
        $this->text(ContentPages::ensure(PortfolioContentOwner::KIND, $project), '<p>Alleen hier zqprojectword.</p>');

        $post = $this->post('Bericht zqp', blocks: true);
        $this->text(ContentPages::ensure(BlogPostContentOwner::KIND, $post), '<p>Alleen hier zqpostword.</p>');

        $article = $this->article('zqp');
        $this->text(ContentPages::ensure(ArticleContentOwner::KIND, $article), '<p>Alleen hier zqarticleword.</p>');

        $expect = [
            'zqpageword' => ['page', 'Gewone pagina zqp', 'Pagina'],
            'zqproductword' => ['product', 'Product zqp', 'Product'],
            'zqprojectword' => ['project', 'Project zqp', 'Project'],
            'zqpostword' => ['post', 'Bericht zqp', 'Blogbericht'],
            'zqarticleword' => ['article', 'ZZ Artikel zqp', 'Artikel'],
        ];
        foreach ($expect as $word => [$type, $title, $label]) {
            $hits = $this->search($word)->hits;
            $this->assertCount(1, $hits, $word);
            $this->assertSame([$type, $title, $label], [$hits[0]->type, $hits[0]->title, $hits[0]->typeLabel], $word);
            $this->assertStringContainsString($word, $hits[0]->excerpt, 'the excerpt shows where the word is');
            $this->assertTrue(SearchService::isSafeUrl($hits[0]->url));
        }

        $this->assertSame('/artikelen/' . ArticleLocalization::slug($article, 'nl'), $this->search('zqarticleword')->hits[0]->url);
        $this->assertStringNotContainsString('_', $this->search('zqproductword')->hits[0]->url, 'never the content page key');
    }

    public function testABlogPostIsFoundByWhatItsPageShows(): void
    {
        $legacy = $this->post('Klassiek zqb', body: '<p>Oude tekst zqlegacybody.</p>');
        // A classic post with blocks left over from a conversion back.
        $this->text(ContentPages::ensure(BlogPostContentOwner::KIND, $legacy), '<p>Niet getoond zqlegacyblock.</p>');

        $blocks = $this->post('Blokken zqb', blocks: true, body: '<p>Bewaarde tekst zqoldbody.</p>');
        $this->text(ContentPages::ensure(BlogPostContentOwner::KIND, $blocks), '<p>Getoond zqblocksword.</p>');

        $this->assertSame(['post Klassiek zqb'], $this->found('zqlegacybody'), 'a classic post by its body');
        $this->assertSame([], $this->found('zqlegacyblock'), 'not by blocks its page does not show');
        $this->assertSame(['post Blokken zqb'], $this->found('zqblocksword'), 'a block post by its blocks');
        $this->assertSame([], $this->found('zqoldbody'), 'not by the body it keeps from before');
    }

    /* ------------------------------------------------------------------ */
    /* Ranking and excerpt                                                 */
    /* ------------------------------------------------------------------ */

    public function testOwnWordsRankAboveHeadingsAboveBlockTextAndEachOwnerOnce(): void
    {
        $this->page('Zqrank in de titel');
        $intro = $this->page('Introductie', description: 'Over zqrank in het kort.');
        $heading = $this->page('Koppen');
        $this->faq($heading, 'Vragen', [['Hoe werkt zqrank?', 'Zo.']]);
        $body = $this->page('Tekst');
        $this->text($body, '<p>Ergens staat zqrank in de tekst.</p>');

        $everywhere = $this->page('Overal zqrank', description: 'Ook zqrank hier.');
        $this->text($everywhere, '<p>Zqrank een.</p>');
        $this->text($everywhere, '<p>Zqrank twee.</p>');
        $this->faq($everywhere, 'Zqrank vragen', [['Zqrank?', 'Zqrank.']]);

        $found = $this->found('zqrank');
        $this->assertSame(['page Zqrank in de titel', 'page Overal zqrank', 'page Introductie', 'page Koppen', 'page Tekst'], $found);
        $this->assertSame([SearchText::SCORE_PREFIX, SearchText::SCORE_WORD, SearchText::SCORE_TEXT, SearchText::SCORE_CONTENT_HEADING, SearchText::SCORE_CONTENT], array_map(
            static fn (SearchHit $hit): int => $hit->score,
            $this->search('zqrank')->hits
        ));
        unset($intro);
    }

    public function testTheExcerptIsCutAroundTheMatchInTheBlock(): void
    {
        $page = $this->page('Materialen', description: 'Een korte introductie.');
        $this->text($page, '<p>' . str_repeat('Vulling over iets anders. ', 30) . 'Verschillende materialen zoals hout, glas en zqalu kunnen worden gegraveerd.</p><p>' . str_repeat('Meer. ', 30) . '</p>');

        $excerpt = $this->search('zqalu')->hits[0]->excerpt;
        $this->assertStringContainsString('glas en zqalu kunnen', $excerpt);
        $this->assertStringStartsWith('…', $excerpt);
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertLessThanOrEqual(SearchText::EXCERPT_LENGTH + 2, mb_strlen($excerpt));

        $this->assertSame('Een korte introductie.', $this->search('materialen')->hits[0]->excerpt, 'a title match keeps the own text');
    }

    /* ------------------------------------------------------------------ */
    /* Visibility                                                          */
    /* ------------------------------------------------------------------ */

    public function testOnlyWhatAVisitorMaySeeIsFound(): void
    {
        $draftPage = $this->page('Conceptpagina', PageContent::STATUS_DRAFT);
        $this->text($draftPage, '<p>zqvis concept pagina</p>');

        $inactive = $this->product('Uit product', false);
        $this->text(ContentPages::ensure(ProductContentOwner::KIND, $inactive), '<p>zqvis inactief product</p>');

        $hidden = $this->project('Verborgen project', false);
        $this->text(ContentPages::ensure(PortfolioContentOwner::KIND, $hidden), '<p>zqvis verborgen project</p>');

        foreach ([['draft', null], ['scheduled', '+3 days'], ['archived', '-1 week']] as [$status, $when]) {
            $post = $this->post('Bericht ' . $status, status: $status, publishedAt: $when === null ? null : (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'), blocks: true);
            $this->text(ContentPages::ensure(BlogPostContentOwner::KIND, $post), '<p>zqvis bericht ' . $status . '</p>');

            $article = $this->article('Artikel ' . $status, $status, $when);
            $this->text(ContentPages::ensure(ArticleContentOwner::KIND, $article), '<p>zqvis artikel ' . $status . '</p>');
        }

        $this->assertSame([], $this->found('zqvis'), 'no draft, inactive, hidden, future or archived owner');
    }

    public function testAnOwnerComesAndGoesWithItsStatusAndItsModule(): void
    {
        $article = $this->article('Wisselend');
        $this->text(ContentPages::ensure(ArticleContentOwner::KIND, $article), '<p>zqstatus wisselend</p>');
        $this->assertSame(['article ZZ Artikel Wisselend'], $this->found('zqstatus'));

        (new ArticleRepository())->updatePublication($article, 'draft', null);
        $this->assertSame([], $this->found('zqstatus'), 'back to draft: gone at once, the index is not asked');

        (new ArticleRepository())->updatePublication($article, 'published', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'));
        $this->assertSame(['article ZZ Artikel Wisselend'], $this->found('zqstatus'), 'published again: back');

        ModuleRegistry::overrideForTests(['articles' => false] + self::ALL_ON);
        BlockDefinitions::reset();
        $this->assertSame([], $this->found('zqstatus'), 'Articles off: nothing of it');
        ModuleRegistry::overrideForTests(self::ALL_ON);
        BlockDefinitions::reset();
        $this->assertSame(['article ZZ Artikel Wisselend'], $this->found('zqstatus'), 'on again');
    }

    public function testABlockOfAModuleThatIsOffIsNotFoundOnAPage(): void
    {
        $page = $this->page('Shopblok zqmod');
        [, $sectionId] = $this->block($page, 'featured_product', ['featured_products' => ['intro' => 'Uitgelicht zqfeatured']]);
        $this->assertNotNull($sectionId);
        $this->assertSame(['page Shopblok zqmod'], $this->found('zqfeatured'));

        ModuleRegistry::overrideForTests(['shop' => false] + self::ALL_ON);
        BlockDefinitions::reset();
        $this->assertSame([], $this->found('zqfeatured'), 'the page does not show it, so it is not found by it');
    }

    /* ------------------------------------------------------------------ */
    /* Languages                                                           */
    /* ------------------------------------------------------------------ */

    public function testEachLanguageFindsItsOwnWordsWithTheBlocksOwnFallback(): void
    {
        $page = $this->page('Talen zqtaal');
        PageLocalization::save((int) $page['id'], 'en', [PageTranslation::TITLE => 'Languages zqtaal']);
        $this->text($page, '<p>Nederlandse zqnlword</p>', '<p>English zqenword</p>');
        $this->text($page, '<p>Alleen Nederlands zqonlynl</p>');

        $this->assertSame(['page Talen zqtaal'], $this->found('zqnlword', 'nl'));
        $this->assertSame([], $this->found('zqenword', 'nl'), 'English never in a Dutch search');
        $this->assertSame(['page Languages zqtaal'], $this->found('zqenword', 'en'));
        $this->assertSame([], $this->found('zqnlword', 'en'), 'the English page shows its English words');
        $this->assertSame(['page Languages zqtaal'], $this->found('zqonlynl', 'en'), 'a block without English shows its Dutch words there, as the page does');
    }

    /* ------------------------------------------------------------------ */
    /* Keeping it current                                                  */
    /* ------------------------------------------------------------------ */

    public function testAddingChangingHidingAndDeletingAreFollowedAtOnce(): void
    {
        $page = $this->page('Levensloop zqlife');
        $this->assertSame([], $this->found('zqeerste'));

        [$row, $sectionId] = $this->text($page, '<p>zqeerste versie</p>');
        $this->assertSame(['page Levensloop zqlife'], $this->found('zqeerste'), 'added');

        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', [RichTextContent::BODY => '<p>zqtweede versie</p>']);
        ContentBlockDrafts::place('rich_text', $sectionId);
        $this->assertSame([], $this->found('zqeerste'), 'the old word is gone');
        $this->assertSame(['page Levensloop zqlife'], $this->found('zqtweede'), 'the new one is found');

        SectionRegistry::delete($row, new PageSectionRepository());
        $this->assertSame([], $this->found('zqtweede'), 'deleted');
        $this->assertSame(0, $this->indexRows((int) $row['id']));
    }

    public function testDeletingTheOwnerTakesItsWordsAlong(): void
    {
        $article = $this->article('Weg');
        $this->text(ContentPages::ensure(ArticleContentOwner::KIND, $article), '<p>zqgone woorden</p>');
        $pageId = (int) ContentPages::pageFor(ArticleContentOwner::KIND, $article)['id'];
        $this->assertCount(1, $this->found('zqgone'));

        ArticleService::delete($article);

        $this->assertSame([], $this->found('zqgone'));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM search_block_texts WHERE page_id = ?');
        $stmt->execute([$pageId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no index row outlives its page');
    }

    public function testTheIndexRebuildsItselfWhenItsRulesChangeOrItIsThrownAway(): void
    {
        $page = $this->page('Herbouw zqrebuild');
        $this->text($page, '<p>zqrebuildword</p>');

        $this->db->exec('DELETE FROM search_block_texts');
        BlockSearchIndex::invalidate();
        $this->assertFalse(BlockSearchIndex::isCurrent());

        $this->assertSame(['page Herbouw zqrebuild'], $this->found('zqrebuildword'), 'the next search rebuilt it');
        $this->assertTrue(BlockSearchIndex::isCurrent());

        $fingerprint = BlockSearchIndex::fingerprint();
        ModuleRegistry::overrideForTests(['shop' => false] + self::ALL_ON);
        BlockDefinitions::reset();
        $this->assertNotSame($fingerprint, BlockSearchIndex::fingerprint(), 'other block types, other fingerprint');
    }

    /* ------------------------------------------------------------------ */
    /* Security and cost                                                   */
    /* ------------------------------------------------------------------ */

    public function testWildcardsQuotesAndForgedInputAreJustText(): void
    {
        $page = $this->page('Tekens zqchars');
        $this->text($page, "<p>Korting 100% op zq_onder en O'Reilly zqquote</p>");
        $this->page('Ander zqchars');

        $this->assertSame(['page Tekens zqchars'], $this->found('100%'));
        $this->assertSame(['page Tekens zqchars'], $this->found('zq_onder'));
        $this->assertSame([], $this->found('zq%onder'), '% is not a wildcard');
        $this->assertSame([], $this->found('zq_nder'), '_ is not a wildcard');
        $this->assertSame(['page Tekens zqchars'], $this->found("O'Reilly zqquote"));
        $this->assertSame([], $this->found("zqquote' OR '1'='1"));
    }

    public function testTheQueryCountDoesNotGrowWithTheOwnersFound(): void
    {
        $seed = function (int $count, string $token): void {
            for ($i = 1; $i <= $count; $i++) {
                $this->text($this->page('Pagina ' . $i), '<p>' . $token . ' pagina</p>');
                $this->text(ContentPages::ensure(ProductContentOwner::KIND, $this->product('Lamp ' . $token . $i)), '<p>' . $token . ' lamp</p>');
                $this->text(ContentPages::ensure(ArticleContentOwner::KIND, $this->article('Stuk ' . $i)), '<p>' . $token . ' artikel</p>');
            }
        };
        $seed(1, 'zqcounteen');
        $seed(10, 'zqcounttien');

        $cost = function (string $query): array {
            $this->clearCaches();
            \App\Service\Media\MediaService::clearCache();
            $before = (int) $this->db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
            $results = SearchService::search(SearchQuery::fromInput($query), 'nl', 1, 50);

            return [$results->total, (int) $this->db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'] - $before];
        };

        [$fewTotal, $few] = $cost('zqcounteen');
        [$manyTotal, $many] = $cost('zqcounttien');

        $this->assertSame(3, $fewTotal);
        $this->assertSame(30, $manyTotal);
        $this->assertSame($few, $many, 'no query per owner, per block or per language');
    }

    /* ------------------------------------------------------------------ */
    /* Seeding                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> the pages row */
    private function page(string $title, string $status = PageContent::STATUS_PUBLISHED, string $description = ''): array
    {
        $key = 'zq-sbt-' . bin2hex(random_bytes(5));
        $id = (new PageRepository())->create(['content_key' => $key, 'slug' => $key, 'status' => $status]);
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => $title, PageTranslation::META_DESCRIPTION => $description]);

        return (new PageRepository())->findById($id);
    }

    private function product(string $name, bool $active = true): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__sbt_' . bin2hex(random_bytes(5)),
            'price' => 12.5,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name, ShopLocalization::DESCRIPTION => '', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);

        return $id;
    }

    private function project(string $title, bool $active = true): int
    {
        $repository = new PortfolioGalleryRepository();
        $catalogue = $repository->ensureCatalogue();
        $id = $repository->createItem((int) $catalogue['id'], ['media_id' => null, 'image_path' => 'assets/media/p.jpg', 'thumbnail_path' => 'assets/media/p-thumb.jpg']);
        $repository->setItemProjectPage($id, true, 'zq-sbt-project-' . $id);
        if (!$active) {
            $this->db->prepare('UPDATE portfolio_gallery_items SET is_active = 0 WHERE id = :id')->execute(['id' => $id]);
        }
        PortfolioLocalization::saveItem($id, 'nl', [PortfolioLocalization::TITLE => $title]);

        return $id;
    }

    private function post(string $title, string $status = 'published', ?string $publishedAt = '2026-01-10 10:00:00', bool $blocks = false, string $body = ''): int
    {
        $repository = new BlogPostRepository();
        $id = $repository->create(['slug' => 'zq-sbt-post-' . bin2hex(random_bytes(4)), 'status' => $status, 'published_at' => $publishedAt, 'noindex' => false]);
        if ($blocks) {
            $repository->update($id, ['content_mode' => 'blocks']);
        }
        BlogLocalization::savePost($id, 'nl', [BlogLocalization::TITLE => $title, BlogLocalization::EXCERPT => '', BlogLocalization::BODY => $body]);

        return $id;
    }

    private function article(string $title, string $status = 'published', ?string $relative = '-1 day'): int
    {
        $at = $relative === null ? null : (new \DateTimeImmutable($relative))->format(PublishingClock::SQL_FORMAT);
        $id = (new ArticleRepository())->create(['status' => $status, 'published_at' => $at, 'author_name' => '', 'topic_id' => null, 'featured_media_id' => null]);
        ArticleLocalization::save($id, 'nl', [ArticleLocalization::SLUG => 'zq-sbt-' . bin2hex(random_bytes(4)), ArticleLocalization::TITLE => 'ZZ Artikel ' . $title, ArticleLocalization::EXCERPT => '']);

        return $id;
    }

    /**
     * A block on a list, written the way its editor writes it.
     *
     * @param array<string, mixed> $page
     * @param array<string, array<string, string>> $words table => field => words (Dutch)
     * @return array{0: array<string, mixed>, 1: int} the page_sections row and the content row id
     */
    private function block(array $page, string $type, array $words = []): array
    {
        [$sectionId, $key] = SectionRegistry::create($type, (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $key, $sectionId);
        foreach ($words as $table => $fields) {
            BlockLocalization::save($table, (int) $sectionId, 'nl', $fields);
        }
        $placed = ContentBlockDrafts::place($type, (int) $sectionId);

        return [$placed, (int) $sectionId];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function text(array $page, string $nl, ?string $en = null): array
    {
        [$sectionId, $key] = SectionRegistry::create('rich_text', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $key, $sectionId);
        BlockLocalization::save('rich_text_sections', (int) $sectionId, 'nl', [RichTextContent::BODY => $nl]);
        if ($en !== null) {
            BlockLocalization::save('rich_text_sections', (int) $sectionId, 'en', [RichTextContent::BODY => $en]);
        }

        return [ContentBlockDrafts::place('rich_text', (int) $sectionId), (int) $sectionId];
    }

    /**
     * @param list<array{0: string, 1: string}> $items
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function faq(array $page, string $title, array $items): array
    {
        [$sectionId, $key] = SectionRegistry::create('faq', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'faq', $key, $sectionId);
        BlockLocalization::save('faq_sections', (int) $sectionId, 'nl', ['eyebrow' => '', 'title' => $title]);
        $faq = new FaqRepository();
        foreach ($faq->findItemsBySectionId((int) $sectionId) as $existing) {
            BlockLocalization::deleteOwner('faq_items', (int) $existing['id']);
            $faq->deleteItem((int) $existing['id']);
        }
        foreach ($items as [$question, $answer]) {
            $itemId = $faq->createItem((int) $sectionId);
            BlockLocalization::save('faq_items', $itemId, 'nl', ['question' => $question, 'answer' => $answer]);
        }

        return [ContentBlockDrafts::place('faq', (int) $sectionId), (int) $sectionId];
    }

    private function cta(array $page, string $title, string $lead): void
    {
        $this->block($page, 'cta_band', ['cta_bands' => ['eyebrow' => '', 'title' => $title, 'lead' => $lead, 'primary_label' => '', 'secondary_label' => '']]);
    }

    private function gallery(array $page, string $title, string $button): void
    {
        $this->block($page, 'item_gallery', ['item_galleries' => ['eyebrow' => '', 'title' => $title, 'lead' => '', 'footer_note' => '', 'button_label' => $button]]);
    }

    private function spacer(array $page): void
    {
        $this->block($page, 'spacer');
    }

    private function indexRows(int $pageSectionId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM search_block_texts WHERE page_section_id = ?');
        $stmt->execute([$pageSectionId]);

        return (int) $stmt->fetchColumn();
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

    private function clearCaches(): void
    {
        PageContent::clearCache();
        PageLocalization::clearCache();
        ShopLocalization::clearCache();
        BlogLocalization::posts()->clearCache();
        PortfolioLocalization::items()->clearCache();
        ArticleLocalization::clearCache();
        BlockLocalization::clearCache();
        RichTextContent::clearCache();
    }
}
