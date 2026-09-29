<?php

declare(strict_types=1);

namespace Tests\Service\Search;

use App\Service\Search\SearchDocument;
use App\Service\Search\SearchProvider;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchService;
use App\Service\Search\SearchText;
use PHPUnit\Framework\TestCase;

/**
 * The site search's own rules, without a database (SEARCH.md): how a query
 * is normalised, how a match is scored and cut into an excerpt, and how
 * SearchService merges, orders, pages and guards the providers' answers.
 * The providers themselves: SearchProvidersTest.
 */
final class SearchCoreTest extends TestCase
{
    /**
     * A provider answering from a fixed list, counting its calls.
     *
     * @param list<SearchDocument> $documents
     */
    private static function provider(string $label, array $documents, ?\Throwable $failure = null): SearchProvider
    {
        return new class ($label, $documents, $failure) implements SearchProvider {
            public int $calls = 0;

            /** @param list<SearchDocument> $documents */
            public function __construct(private string $label, private array $documents, private ?\Throwable $failure)
            {
            }

            public function label(string $language): string
            {
                return $this->label . ':' . $language;
            }

            public function documents(SearchQuery $query, string $language, int $limit): array
            {
                $this->calls++;
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return array_slice($this->documents, 0, $limit);
            }
        };
    }

    private static function doc(string $title, string $text = '', string $url = '/x'): SearchDocument
    {
        return new SearchDocument($title, $text, $url);
    }

    // ------------------------------------------------------------ the query

    public function testAnEmptyOrMissingQuerySearchesNothing(): void
    {
        foreach ([null, '', '   ', ['laser'], 42, "\t\n"] as $raw) {
            $query = SearchQuery::fromInput($raw);
            self::assertTrue($query->isEmpty(), var_export($raw, true));
            self::assertFalse($query->isSearchable());
        }
    }

    public function testATooShortQueryIsSaidToBeSo(): void
    {
        $query = SearchQuery::fromInput(' a ');
        self::assertTrue($query->isTooShort());
        self::assertFalse($query->isSearchable());
        self::assertTrue(SearchQuery::fromInput('ab')->isSearchable());
        self::assertTrue(SearchQuery::fromInput('é€')->isSearchable(), 'two characters, not two bytes');
    }

    public function testTheQueryIsTrimmedCollapsedAndCleaned(): void
    {
        self::assertSame('laser hout', SearchQuery::fromInput("  laser \t\n  hout ")->text);
        self::assertSame('a b', SearchQuery::fromInput("a\u{0000}b")->text, 'a control character is a space');
        self::assertSame('a b c', SearchQuery::fromInput("a\u{200B}b\u{202E}c")->text, 'invisible format characters are spaces');
        self::assertStringNotContainsString("\u{202E}", SearchQuery::fromInput("abc\u{202E}def")->text, 'no bidi override survives');
    }

    public function testALongQueryIsCutAtTheLimitInCharacters(): void
    {
        $query = SearchQuery::fromInput(str_repeat('é', 500));
        self::assertSame(SearchQuery::MAX_LENGTH, mb_strlen($query->text));
        self::assertTrue(mb_check_encoding($query->text, 'UTF-8'), 'never cut inside a character');
    }

    public function testInvalidUtf8IsNoQuery(): void
    {
        self::assertTrue(SearchQuery::fromInput("la\xC3\x28ser")->isEmpty());
        self::assertTrue(SearchQuery::fromInput("\xFF\xFE")->isEmpty());
    }

    public function testWildcardsAreOrdinaryCharacters(): void
    {
        self::assertSame('50%_off', SearchQuery::fromInput('50%_off')->text);
        self::assertSame(0, SearchText::score(SearchQuery::fromInput('%')->folded() . '_', 'Anything at all', 'any text'));
        self::assertSame(SearchText::SCORE_TITLE, SearchText::score(SearchQuery::fromInput('0%')->folded(), 'Nu 50% korting', ''));
    }

    // ------------------------------------------------------------ scoring

    public function testTheFourRankingLevelsAndAWordStart(): void
    {
        $q = SearchText::fold('Laser');

        self::assertSame(SearchText::SCORE_EXACT, SearchText::score($q, 'laser', ''));
        self::assertSame(SearchText::SCORE_PREFIX, SearchText::score($q, 'Lasersnijden', ''));
        self::assertSame(SearchText::SCORE_WORD, SearchText::score($q, 'Houten laserbord', ''));
        self::assertSame(SearchText::SCORE_TITLE, SearchText::score($q, 'Diodelaser', ''));
        self::assertSame(SearchText::SCORE_TEXT, SearchText::score($q, 'Houten bord', 'Gesneden met een LASER.'));
        self::assertSame(0, SearchText::score($q, 'Houten bord', 'Met de hand gezaagd.'));
        self::assertGreaterThan(SearchText::SCORE_PREFIX, SearchText::SCORE_EXACT);
        self::assertGreaterThan(SearchText::SCORE_WORD, SearchText::SCORE_PREFIX);
        self::assertGreaterThan(SearchText::SCORE_TITLE, SearchText::SCORE_WORD);
        self::assertGreaterThan(SearchText::SCORE_TEXT, SearchText::SCORE_TITLE);
    }

    public function testCaseAndCommonAccentsDoNotMatter(): void
    {
        self::assertSame(SearchText::SCORE_EXACT, SearchText::score(SearchText::fold('CAFE'), 'Café', ''));
        self::assertSame(SearchText::SCORE_EXACT, SearchText::score(SearchText::fold('café'), 'CAFE', ''));
        self::assertSame(SearchText::SCORE_PREFIX, SearchText::score(SearchText::fold('uber'), 'Über ons', ''));
        self::assertSame(SearchText::SCORE_EXACT, SearchText::score(SearchText::fold('ÉÉN'), 'één', ''));
        self::assertSame(mb_strlen('Crème brûlée'), mb_strlen(SearchText::fold('Crème brûlée')), 'one letter for one letter');
    }

    public function testRichTextBecomesPlainText(): void
    {
        self::assertSame('Kop Eerste regel & tweede', SearchText::plain('<h2>Kop</h2><p>Eerste <strong>regel</strong> &amp; tweede</p>'));
        self::assertSame('a b', SearchText::plain('<p>a</p><p>b</p>'));
        self::assertSame('alert(1) tekst', SearchText::plain('<script>alert(1)</script> tekst'), 'tags are gone; the text is plain and escaped later');
    }

    public function testTheExcerptIsCutAroundTheMatch(): void
    {
        $text = str_repeat('Voorwoord zonder het woord. ', 12) . 'Hier staat de LASER in een zin. ' . str_repeat('Nawoord. ', 20);
        $excerpt = SearchText::excerpt($text, SearchText::fold('laser'));

        self::assertStringContainsString('LASER', $excerpt);
        self::assertStringStartsWith('…', $excerpt);
        self::assertStringEndsWith('…', $excerpt);
        self::assertLessThanOrEqual(SearchText::EXCERPT_LENGTH + 2, mb_strlen($excerpt));
        self::assertSame('Kort.', SearchText::excerpt('Kort.', 'x'));
        self::assertSame('', SearchText::excerpt('   ', 'x'));
        self::assertStringStartsWith('Voorwoord', SearchText::excerpt($text, SearchText::fold('nergens')), 'no match: the beginning');
    }

    // ------------------------------------------------------------ more words

    /** @return int the score of one document for a query, as SearchService computes it */
    private static function scoreOf(string $raw, string $title, string $text = ''): int
    {
        $query = SearchQuery::fromInput($raw);

        return SearchText::score($query->folded(), $title, $text, $query->foldedTerms());
    }

    public function testAQueryOfMoreWordsIsSplitIntoTerms(): void
    {
        self::assertSame(['laser', 'hout'], SearchQuery::fromInput('laser hout')->terms());
        self::assertSame(['laser', 'hout'], SearchQuery::fromInput("  laser \t   hout  ")->terms(), 'several spaces are one separator');
        self::assertSame(['Laser', 'hout'], SearchQuery::fromInput('Laser hout LASER')->terms(), 'each folded term once');
        self::assertSame(['laser'], SearchQuery::fromInput('laser a')->terms(), 'a one-letter word is no term of its own');
        self::assertSame([], SearchQuery::fromInput('a b')->terms());
        self::assertSame(['laser'], SearchQuery::fromInput('laser')->terms());
    }

    public function testAtMostEightTermsAreUsed(): void
    {
        $query = SearchQuery::fromInput('een twee drie vier vijf zes zeven acht negen tien');

        self::assertSame(SearchQuery::MAX_TERMS, count($query->terms()));
        self::assertSame(8, SearchQuery::MAX_TERMS);
        self::assertNotContains('negen', $query->terms());
        self::assertSame(100, SearchQuery::MAX_LENGTH, 'the 2–100 character limit is unchanged');
        self::assertSame(2, SearchQuery::MIN_LENGTH);
        self::assertGreaterThan(0, self::scoreOf('een twee drie vier vijf zes zeven acht negen', 'een twee drie vier', 'vijf zes zeven acht'), 'a ninth term is not required');
    }

    public function testTermsMayStandApartInTheTitle(): void
    {
        self::assertGreaterThan(0, self::scoreOf('laser hout', 'Laseren en graveren op hout'));
        self::assertGreaterThan(0, self::scoreOf('houten laser', 'Lasersnijden in houten platen'), 'in any order');
    }

    public function testTermsMayBeSplitOverTitleAndText(): void
    {
        self::assertGreaterThan(0, self::scoreOf('lamp eiken', 'Hanglamp', 'Gemaakt van massief eiken.'));
        self::assertSame(0, self::scoreOf('lamp eiken', 'Hanglamp', 'Gemaakt van massief beuken.'), 'one of two terms is not a match');
        self::assertSame(0, self::scoreOf('laser hout', 'Laserwerk op metaal'));
    }

    public function testThePhraseOutranksLooseTermsAndTheTitleOutranksTheText(): void
    {
        $exact = self::scoreOf('laser hout', 'Laser hout');
        $phraseInTitle = self::scoreOf('laser hout', 'Graveren met laser hout en meer');
        $phraseInText = self::scoreOf('laser hout', 'Graveren', 'Met de laser hout bewerken.');
        $termsInTitle = self::scoreOf('laser hout', 'Laseren op hout');
        $termsSplit = self::scoreOf('laser hout', 'Laseren', 'Op hout.');
        $termsInText = self::scoreOf('laser hout', 'Graveren', 'Laseren op hout.');

        self::assertSame(SearchText::SCORE_EXACT, $exact);
        self::assertGreaterThan($phraseInTitle, $exact);
        self::assertGreaterThan($phraseInText, $phraseInTitle);
        self::assertGreaterThan($termsInTitle, $phraseInText, 'the whole phrase, even only in the text, beats loose terms');
        self::assertGreaterThan($termsSplit, $termsInTitle, 'more terms in the title rank higher');
        self::assertGreaterThan($termsInText, $termsSplit);
        self::assertGreaterThan(0, $termsInText);
    }

    public function testOneWordScoresExactlyAsBefore(): void
    {
        foreach (['Laser' => SearchText::SCORE_EXACT, 'Lasersnijden' => SearchText::SCORE_PREFIX, 'Houten laserbord' => SearchText::SCORE_WORD, 'Diodelaser' => SearchText::SCORE_TITLE] as $title => $expected) {
            self::assertSame($expected, self::scoreOf('laser', $title));
        }
        self::assertSame(SearchText::SCORE_TEXT, self::scoreOf('laser', 'Bord', 'met de laser'));
        self::assertSame(0, self::scoreOf('laser', 'Bord', 'met de hand'));
    }

    public function testTermsAreUnicodeAndCaseAware(): void
    {
        self::assertGreaterThan(0, self::scoreOf('CAFÉ crème', 'Crème brûlée', 'In het cafe'));
        self::assertGreaterThan(0, self::scoreOf('überraschung köln', 'Köln', 'Eine Überraschung'));
        self::assertSame(0, self::scoreOf('café thee', 'Café', 'Alleen koffie'));
    }

    public function testWildcardsStayLiteralInTerms(): void
    {
        self::assertSame(['50%', 'a_b'], SearchQuery::fromInput('50% a_b')->terms());
        self::assertGreaterThan(0, self::scoreOf('50% korting', 'Korting', 'Nu 50% op alles'));
        self::assertSame(0, self::scoreOf('5% korting', 'Korting', 'Nu 50 op alles'), '% is not "anything"');
        self::assertSame(0, self::scoreOf('a_b test', 'Test', 'axb'), '_ is not "one character"');
    }

    public function testTheExcerptFollowsATermWhenThePhraseIsNotInTheText(): void
    {
        $text = str_repeat('Inleiding zonder de woorden. ', 10) . 'Hier gaat het over hout. ' . str_repeat('Slot. ', 20);
        $query = SearchQuery::fromInput('laser hout');

        self::assertStringContainsString('hout', SearchText::excerpt($text, $query->folded(), SearchText::EXCERPT_LENGTH, $query->foldedTerms()));
    }

    // ------------------------------------------------------------ the service

    public function testNothingIsAskedForAQueryThatCannotBeSearched(): void
    {
        $provider = self::provider('Pagina', [self::doc('Alles')]);

        foreach (['', 'a'] as $raw) {
            $results = SearchService::search(SearchQuery::fromInput($raw), 'nl', 1, 10, ['page' => $provider]);
            self::assertSame(0, $results->total);
            self::assertSame([], $results->hits);
        }
        self::assertSame(0, $provider->calls);
    }

    public function testResultsAreRankedByScoreThenProviderThenProviderOrder(): void
    {
        $results = SearchService::search(SearchQuery::fromInput('laser'), 'en', 1, 20, [
            'page' => self::provider('Page', [
                self::doc('Over ons', 'Wij werken met een laser.', '/over'),
                self::doc('Laserwerk', '', '/laserwerk'),
                self::doc('Niets', 'Geen treffer', '/niets'),
            ]),
            'product' => self::provider('Product', [
                self::doc('Laser', '', '/product.php?id=1'),
                self::doc('Houten laserbord', '', '/product.php?id=2'),
                self::doc('Diodelaser', '', '/product.php?id=3'),
                self::doc('Laserpen', '', '/product.php?id=4'),
            ]),
        ]);

        self::assertSame(
            ['/product.php?id=1', '/laserwerk', '/product.php?id=4', '/product.php?id=2', '/product.php?id=3', '/over'],
            array_map(static fn ($hit): string => $hit->url, $results->hits),
            'exact, then prefix (page before product, then provider order), word start, title, text'
        );
        self::assertSame(6, $results->total);
        self::assertSame('Product:en', $results->hits[0]->typeLabel, 'the label in the language asked for');
        self::assertSame('product', $results->hits[0]->type);
    }

    public function testPagesAreCutAndCounted(): void
    {
        $documents = [];
        for ($i = 1; $i <= 23; $i++) {
            $documents[] = self::doc('Laser ' . $i, '', '/p' . $i);
        }
        $providers = ['page' => self::provider('Pagina', $documents)];

        $first = SearchService::search(SearchQuery::fromInput('laser'), 'nl', 1, 10, $providers);
        self::assertSame(23, $first->total);
        self::assertSame(3, $first->pages());
        self::assertCount(10, $first->hits);
        self::assertFalse($first->hasPrevious());
        self::assertTrue($first->hasNext());

        $last = SearchService::search(SearchQuery::fromInput('laser'), 'nl', 3, 10, $providers);
        self::assertCount(3, $last->hits);
        self::assertSame('/p21', $last->hits[0]->url);
        self::assertFalse($last->hasNext());

        $beyond = SearchService::search(SearchQuery::fromInput('laser'), 'nl', 99, 10, $providers);
        self::assertSame(3, $beyond->page, 'a page past the end is the last page');

        $live = SearchService::search(SearchQuery::fromInput('laser'), 'nl', 1, SearchService::LIVE_LIMIT, $providers);
        self::assertCount(SearchService::LIVE_LIMIT, $live->hits);
        self::assertSame(23, $live->total, 'the live list knows how many more "Alle resultaten bekijken" shows');
        self::assertLessThanOrEqual(10, SearchService::LIVE_LIMIT);
    }

    public function testAFailingProviderLeavesTheOthersAndIsNamed(): void
    {
        $results = SearchService::search(SearchQuery::fromInput('laser'), 'nl', 1, 10, [
            'page' => self::provider('Pagina', [self::doc('Laser')]),
            'post' => self::provider('Blog', [], new \RuntimeException('table missing')),
        ]);

        self::assertSame(1, $results->total);
        self::assertSame(['post'], $results->failedTypes);
    }

    public function testOnlyAddressesOnThisSiteAreEverLinked(): void
    {
        $results = SearchService::search(SearchQuery::fromInput('laser'), 'nl', 1, 10, [
            'page' => self::provider('Pagina', [
                self::doc('Laser 1', '', 'https://evil.example/'),
                self::doc('Laser 2', '', '//evil.example/'),
                self::doc('Laser 3', '', 'javascript:alert(1)'),
                self::doc('Laser 4', '', '/\\evil.example'),
                self::doc('Laser 5', '', '/a"onmouseover="x'),
                self::doc('Laser 6', '', ''),
                self::doc('Laser 7', '', '/en/product.php?id=7'),
                new SearchDocument('Laser 8', '', '/ok', 'https://evil.example/x.png'),
            ]),
        ]);

        self::assertSame(['/en/product.php?id=7', '/ok'], array_map(static fn ($hit): string => $hit->url, $results->hits));
        self::assertNull($results->hits[1]->thumbnail, 'a picture elsewhere is dropped, the result stays');
    }

    public function testAHitIsPlainDataForTheLiveList(): void
    {
        $results = SearchService::search(SearchQuery::fromInput('<b>'), 'nl', 1, 10, [
            'page' => self::provider('Pagina', [new SearchDocument('Tag <b> in titel', '<script>x</script>', '/p', '/assets/media/a.jpg')]),
        ]);

        self::assertSame(
            ['type' => 'page', 'type_label' => 'Pagina:nl', 'title' => 'Tag <b> in titel', 'excerpt' => '<script>x</script>', 'url' => '/p', 'thumbnail' => '/assets/media/a.jpg'],
            $results->hits[0]->toArray(),
            'raw text: every screen escapes it (zoeken.php with htmlspecialchars, search.js with textContent)'
        );
    }
}
