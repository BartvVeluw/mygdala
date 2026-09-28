<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\FeatureGridRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\StepListRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\FeatureGridContent;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageHeroContent;
use App\Service\PageService;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\StepListContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-feature-grid.php';

/**
 * The heading contract of blocks of cards (App\Service\Blocks\CardHeading) on
 * a real page, with this test's own page:
 *
 *   - the page's h1, then a grid with a title (h2, its cards h3), a grid
 *     without one (its cards h2) and a step list with a title (h2, its steps
 *     h3), one after the other: one outline without a skipped level, and
 *     every card title in the same class whatever its tag;
 *   - a title an editor takes away again moves the cards up to h2, and no
 *     empty heading is left behind;
 *   - the level follows the title the request's language shows: a title only
 *     in English gives the Dutch rendering h2 cards and the English one h3
 *     cards, and a Dutch title that English falls back to gives h3 cards in
 *     both.
 *
 * The page tests go over PHP's built-in server and skip themselves without
 * one. Everything this test makes is removed in tearDown(). Every block
 * sample with and without its title: Tests\Service\CardHeadingContractTest.
 */
final class CardHeadingPageTest extends TestCase
{
    private const KEY = 'zz-card-heading-test';

    private static ?BuiltInServer $server = null;

    /** @var array<int, string> feature grid id => section key */
    private array $gridKeys = [];

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
        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Kopjes-test');
    }

    protected function tearDown(): void
    {
        $this->removePage();
        RequestLanguage::reset();
        $this->clearCaches();
    }

    public function testOnePageKeepsOneOutlineAcrossBlocksWithAndWithoutATitle(): void
    {
        $this->hero('Kopjes-test');
        $this->grid('Wat wij doen', ['Ontwerp', 'Productie']);
        $this->grid('', ['Advies', 'Levering']);
        $this->steps('Zo werken we', ['Kennismaken', 'Maken']);

        $headings = $this->mainHeadings($this->page());

        self::assertSame(
            [
                ['h1', 'Kopjes-test'],
                ['h2', 'Wat wij doen'],
                ['h3', 'Ontwerp'],
                ['h3', 'Productie'],
                ['h2', 'Advies'],
                ['h2', 'Levering'],
                ['h2', 'Zo werken we'],
                ['h3', 'Kennismaken'],
                ['h3', 'Maken'],
            ],
            array_map(static fn (array $heading): array => [$heading[0], $heading[1]], $headings),
            'the page h1; a grid with a title and h3 cards; a grid without one and h2 cards; a titled step list'
        );
        self::assertNoSkippedLevel($headings);

        $cardClasses = [];
        foreach ($headings as [$tag, $text, $class]) {
            if (in_array($text, ['Ontwerp', 'Productie', 'Advies', 'Levering'], true)) {
                $cardClasses[$tag] = $class;
            }
        }
        self::assertSame(['h3' => 'feature-card__title', 'h2' => 'feature-card__title'], $cardClasses, 'one class, so one look, for both tags');
    }

    public function testTakingTheTitleAwayMovesTheCardsUpALevelAndLeavesNoEmptyHeading(): void
    {
        $this->hero('Kopjes-test');
        $grid = $this->grid('Wat wij doen', ['Ontwerp', 'Productie']);

        self::assertSame(['h1', 'h2', 'h3', 'h3'], array_column($this->mainHeadings($this->page()), 0), 'with its title');

        BlockLocalization::save('feature_grids', $grid, BlockLocalization::defaultLanguage(), ['title' => '']);
        $this->clearCaches();

        $page = $this->page();
        $headings = $this->mainHeadings($page);
        self::assertSame(
            [['h1', 'Kopjes-test'], ['h2', 'Ontwerp'], ['h2', 'Productie']],
            array_map(static fn (array $heading): array => [$heading[0], $heading[1]], $headings),
            'the title gone, so the cards are the h2s'
        );
        self::assertNoSkippedLevel($headings);
        self::assertDoesNotMatchRegularExpression('#<(h[1-6])\b[^>]*>\s*</\1>#i', $page, 'no empty heading anywhere on the page');
    }

    public function testTheLevelFollowsTheTitleTheRequestLanguageShows(): void
    {
        if (!in_array('en', SiteLanguages::activeCodes(), true)) {
            self::markTestSkipped('English is not a website language in this database');
        }

        $grid = $this->grid('', ['Ontwerp', 'Productie']);
        BlockLocalization::save('feature_grids', $grid, 'en', ['title' => 'What we do']);

        $dutch = $this->renderGrid($grid, 'nl', false);
        self::assertStringNotContainsString('What we do', $dutch);
        self::assertSame(2, substr_count($dutch, '<h2 class="feature-card__title">'), 'no title in Dutch, so h2 cards');
        self::assertStringNotContainsString('<h3', $dutch);

        $english = $this->renderGrid($grid, 'en', true);
        self::assertStringContainsString('<h2>What we do</h2>', $english);
        self::assertSame(2, substr_count($english, '<h3 class="feature-card__title">'), 'under the English title, h3 cards');

        // A Dutch title and no English one: English falls back to it.
        BlockLocalization::save('feature_grids', $grid, 'nl', ['title' => 'Wat wij doen']);
        BlockLocalization::save('feature_grids', $grid, 'en', []);

        self::assertSame(2, substr_count($this->renderGrid($grid, 'nl', false), '<h3 class="feature-card__title">'), 'Dutch, under its own title');
        $fallback = $this->renderGrid($grid, 'en', true);
        self::assertStringContainsString('<h2>Wat wij doen</h2>', $fallback, 'the Dutch title on the English page');
        self::assertSame(2, substr_count($fallback, '<h3 class="feature-card__title">'), 'so h3 cards there too');
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: int, 1: string|null} [content row id, section key] */
    private function place(string $type): array
    {
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        self::assertNotNull($page);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, $type, $key, (int) $id);
        $this->clearCaches();

        return [(int) $id, $key];
    }

    private function hero(string $title): void
    {
        [$id] = $this->place('page_hero');
        BlockLocalization::save('page_heroes', $id, BlockLocalization::defaultLanguage(), ['title' => $title]);
    }

    /** @param list<string> $cardTitles */
    private function grid(string $title, array $cardTitles): int
    {
        [$id, $key] = $this->place('feature_grid');
        $this->gridKeys[$id] = (string) $key;
        $language = BlockLocalization::defaultLanguage();

        BlockLocalization::save('feature_grids', $id, $language, ['title' => $title]);
        $repository = new FeatureGridRepository();
        foreach ($cardTitles as $cardTitle) {
            $itemId = $repository->createItem($id, ['icon_key' => FeatureGridContent::ICON_NONE]);
            BlockLocalization::save('feature_grid_items', $itemId, $language, ['title' => $cardTitle, 'body' => 'Tekst bij ' . $cardTitle]);
        }
        $this->clearCaches();

        return $id;
    }

    /** @param list<string> $stepTitles */
    private function steps(string $title, array $stepTitles): void
    {
        [$id] = $this->place('step_list');
        $language = BlockLocalization::defaultLanguage();

        BlockLocalization::save('step_list_sections', $id, $language, ['title' => $title]);
        $repository = new StepListRepository();
        foreach ($stepTitles as $stepTitle) {
            BlockLocalization::save('step_list_items', $repository->createItem($id), $language, ['title' => $stepTitle, 'body' => 'Tekst bij ' . $stepTitle]);
        }
        $this->clearCaches();
    }

    private function renderGrid(int $grid, string $language, bool $fromUrl): string
    {
        RequestLanguage::set($language, $fromUrl);
        $this->clearCaches();

        $content = FeatureGridContent::forSection(self::KEY, $this->gridKeys[$grid]);
        self::assertSame(FeatureGridContent::STATE_ACTIVE, $content['state']);

        ob_start();
        try {
            render_section_feature_grid($content, 'feature_grid-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function page(): string
    {
        if (self::$server === null || !self::$server->answers()) {
            self::markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $response = self::$server->request('GET', '/pagina.php?slug=' . self::KEY);
        self::assertSame(200, $response['status'], $response['body']);

        return $response['body'];
    }

    /** @return list<array{0: string, 1: string, 2: string}> tag, text and class of every heading in <main>, in document order */
    private function mainHeadings(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $headings = [];
        $nodes = (new \DOMXPath($document))->query('//main//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof \DOMElement) {
                $headings[] = [strtolower($node->tagName), trim((string) preg_replace('/\s+/', ' ', $node->textContent)), $node->getAttribute('class')];
            }
        }

        return $headings;
    }

    /** @param list<array{0: string, 1: string, 2: string}> $headings */
    private static function assertNoSkippedLevel(array $headings): void
    {
        $previous = 1;
        foreach ($headings as [$tag, $text]) {
            $level = (int) substr($tag, 1);
            self::assertLessThanOrEqual($previous + 1, $level, "{$tag} \"{$text}\" skips a level after an h{$previous}");
            $previous = $level;
        }
    }

    private function clearCaches(): void
    {
        BlockLocalization::clearCache();
        FeatureGridContent::clearCache();
        StepListContent::clearCache();
        PageHeroContent::clearCache();
        PageContent::clearCache();
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageService::delete($page);
        }

        PageContent::clearCache();
    }
}
