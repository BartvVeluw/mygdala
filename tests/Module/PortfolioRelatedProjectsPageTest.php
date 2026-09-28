<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleRegistry;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioRelatedProjects;
use App\Service\RandomOrder;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * The related projects of a project page on the test database
 * (App\Service\PortfolioRelatedProjects::forItemId(), portfolio-detail.php):
 *
 *   - off by default, so an existing page does not change;
 *   - automatic: projects sharing a category, never the project itself, never
 *     a hidden or deleted one, at most the maximum, topped up only when asked;
 *   - manual and hybrid, in the picked order, without repeats;
 *   - the heading in the language of the request: the project's own, else
 *     "Gerelateerde projecten" / "Related projects";
 *   - on the page itself, over PHP's built-in server: the very cards every
 *     gallery shows, under their own h2, the chosen grid preset, one lightbox
 *     overlay for the whole page; nothing at all while it is off; and with the
 *     Portfolio switched off the page is gone.
 *
 * The rules themselves, without a database, are
 * Tests\Service\PortfolioRelatedProjectsTest. The catalogue may hold other
 * projects: a category of this test's own keeps "automatic" to its own; the
 * top-up, which may take any visible project, is checked for what it must and
 * must not contain. Everything made here is marked zz- and removed again.
 */
final class PortfolioRelatedProjectsPageTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'portfolio' => true, 'multilingual' => true]);
    }

    protected function tearDown(): void
    {
        RandomOrder::useEngineForTests(null);
        RequestLanguage::reset();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }
        $categories = new PortfolioCategoryRepository();
        foreach ($this->categoryIds as $id) {
            if ($categories->findById($id) !== null) {
                $categories->delete($id);
            }
        }

        $this->itemIds = $this->categoryIds = [];
        ModuleRegistry::overrideForTests(null);
        self::clearCaches();
    }

    public function testItIsOffByDefaultSoAnExistingPageDoesNotChange(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        [$current] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        $this->project('ZZ Wolf ' . $m, [$wolves]);

        self::assertFalse(PortfolioRelatedProjects::settings((new PortfolioGalleryRepository())->findItemById($current))['related_enabled']);
        self::assertNull(PortfolioRelatedProjects::forItemId($current));
    }

    public function testAutomaticShowsOnlyVisibleProjectsThatShareACategoryButNeverItself(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $keyrings = $this->category('ZZ Sleutelhangers ' . $m);
        $deer = $this->category('ZZ Herten ' . $m);
        [$current] = $this->project('ZZ Huidig ' . $m, [$wolves, $keyrings]);
        $this->project('ZZ Wolf ' . $m, [$wolves]);
        $this->project('ZZ Wolfsleutelhanger ' . $m, [$wolves, $keyrings]);
        $this->project('ZZ Verborgen wolf ' . $m, [$wolves], false);
        $gone = $this->project('ZZ Verwijderde wolf ' . $m, [$wolves])[0];
        $this->project('ZZ Hert ' . $m, [$deer]);
        (new PortfolioGalleryRepository())->deleteItem($gone);

        $this->relate($current, ['related_max' => 4]);

        self::assertSame(
            ['ZZ Wolfsleutelhanger ' . $m, 'ZZ Wolf ' . $m],
            $this->titles($current),
            'the one sharing both categories first; not itself, not the hidden, not the deleted, not the unrelated'
        );
    }

    public function testTheMaximumAndTheTopUp(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $deer = $this->category('ZZ Herten ' . $m);
        [$current] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        foreach ([1, 2, 3] as $n) {
            $this->project('ZZ Wolf ' . $n . ' ' . $m, [$wolves]);
        }
        $this->project('ZZ Hert ' . $m, [$deer]);

        $this->relate($current, ['related_max' => 2]);
        self::assertCount(2, $this->titles($current));

        $this->relate($current, ['related_max' => 8, 'related_fallback' => 'available']);
        self::assertSame(['ZZ Wolf 3 ' . $m, 'ZZ Wolf 2 ' . $m, 'ZZ Wolf 1 ' . $m], $this->titles($current), 'only what shares a category, newest first on a tie');

        $this->relate($current, ['related_max' => 8, 'related_fallback' => 'fill']);
        $filled = $this->titles($current);
        self::assertSame(['ZZ Wolf 3 ' . $m, 'ZZ Wolf 2 ' . $m, 'ZZ Wolf 1 ' . $m], array_slice($filled, 0, 3), 'the related ones first');
        self::assertLessThanOrEqual(8, count($filled));
        self::assertNotContains('ZZ Huidig ' . $m, $filled);
        if (count(PortfolioGalleryContent::visibleRows()) <= 9) {
            self::assertContains('ZZ Hert ' . $m, $filled, 'topped up with another project');
        }
    }

    public function testManualAndHybridKeepThePickedOrderWithoutRepeats(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        [$current] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        [$a] = $this->project('ZZ A ' . $m, [$wolves]);
        [$b] = $this->project('ZZ B ' . $m, []);
        [$c] = $this->project('ZZ C ' . $m, [$wolves]);
        [$hidden] = $this->project('ZZ Verborgen ' . $m, [], false);

        $this->relate($current, ['related_mode' => 'manual', 'related_max' => 8], [$b, $hidden, $current, $a, $a]);
        self::assertSame(['ZZ B ' . $m, 'ZZ A ' . $m], $this->titles($current), 'picked order; hidden skipped; never itself; never twice');
        self::assertSame([$b, $hidden, $a], (new PortfolioGalleryRepository())->relatedItemIds($current), 'the list stores itself once and never the project itself');

        $this->relate($current, ['related_mode' => 'hybrid', 'related_max' => 3], [$b]);
        self::assertSame(['ZZ B ' . $m, 'ZZ C ' . $m, 'ZZ A ' . $m], $this->titles($current), 'the picked first, then the automatic ones');
    }

    public function testRandomDrawsAgainForEveryPageAndOnlyFromTheValidOnes(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        [$current] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        $valid = [];
        foreach ([1, 2, 3, 4] as $n) {
            $this->project($valid[] = 'ZZ Wolf ' . $n . ' ' . $m, [$wolves]);
        }
        $this->relate($current, ['related_sort' => 'random', 'related_max' => 2]);

        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(13));
        foreach ([1, 2, 3] as $page) {
            self::clearCaches();
            $titles = $this->titles($current);
            self::assertCount(2, $titles);
            self::assertSame([], array_diff($titles, $valid));
            self::assertSame(array_values(array_unique($titles)), $titles);
            self::assertSame($page, RandomOrder::calls(), 'a draw per page, not one stored');
        }
    }

    public function testTheHeadingIsTheProjectsOwnElseTheBuiltInOneInTheLanguageOfTheRequest(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        [$current] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        $this->project('ZZ Wolf ' . $m, [$wolves]);
        $this->relate($current, []);

        RequestLanguage::set('nl', false);
        self::assertSame('Gerelateerde projecten', PortfolioRelatedProjects::forItemId($current)['title']);
        self::assertSame('', PortfolioRelatedProjects::forItemId($current)['lead']);

        if (in_array('en', SiteLanguages::activeCodes(), true)) {
            self::clearCaches();
            RequestLanguage::set('en', true);
            self::assertSame('Related projects', PortfolioRelatedProjects::forItemId($current)['title']);
        }

        PortfolioLocalization::saveItem($current, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::RELATED_TITLE => 'ZZ Meer wolven',
            PortfolioLocalization::RELATED_LEAD => 'ZZ Nog een paar.',
        ]);
        self::clearCaches();
        RequestLanguage::set('nl', false);
        $content = PortfolioRelatedProjects::forItemId($current);
        self::assertSame(['ZZ Meer wolven', 'ZZ Nog een paar.'], [$content['title'], $content['lead']]);
    }

    public function testThePageShowsTheGalleryCardsUnderTheirOwnHeading(): void
    {
        $this->needServer();
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        [$current, $slug] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        [, $otherSlug] = $this->project('ZZ Wolf ' . $m, [$wolves]);

        $off = (string) self::$server->request('GET', '/portfolio/' . $slug)['body'];
        self::assertStringNotContainsString('Gerelateerde projecten', $off, 'off: nothing at all');
        self::assertStringNotContainsString('data-gallery-block', $off);

        $this->relate($current, ['related_layout' => 'compact', 'related_show_text' => false]);
        $response = self::$server->request('GET', '/portfolio/' . $slug);
        $body = (string) $response['body'];

        self::assertSame(200, $response['status']);
        self::assertMatchesRegularExpression('#<section[^>]*data-gallery-block[^>]*>\s*<div class="container">\s*<div class="section-head" data-reveal>\s*<h2>Gerelateerde projecten</h2>#', $body);
        self::assertStringContainsString('<div class="gallery-grid gallery-grid--compact">', $body);
        self::assertStringContainsString('class="gallery-item gallery-item--zoom gallery-item--has-cta"', $body, 'the gallery\'s own card: the picture zooms');
        self::assertStringContainsString('href="/portfolio/' . $otherSlug . '"', $body, '"Bekijk project" goes to the related project\'s own page');
        self::assertStringNotContainsString('ZZ korte tekst van ZZ Wolf ' . $m, $body, 'the short text switched off');
        self::assertStringContainsString('ZZ korte tekst van ZZ Huidig ' . $m, $body, 'the project\'s own short text stays');
        self::assertSame(1, substr_count($body, 'class="lightbox" data-lightbox'), 'one lightbox overlay for the whole page');
        self::assertStringContainsString('/assets/css/blocks/item-gallery.css', $body, 'the gallery\'s own stylesheet');

        if (in_array('en', SiteLanguages::activeCodes(), true)) {
            self::assertStringContainsString('<h2>Related projects</h2>', (string) self::$server->request('GET', '/en/portfolio/' . $slug)['body']);
        }
    }

    public function testWithThePortfolioOffTheProjectPageIsGone(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        [$current, $slug] = $this->project('ZZ Huidig ' . $m, [$wolves]);
        $this->project('ZZ Wolf ' . $m, [$wolves]);
        $this->relate($current, []);

        $off = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'false'], 'tests/Support/dispatcher-router.php');
        if ($off === null || !$off->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        try {
            $response = $off->request('GET', '/portfolio/' . $slug);
            self::assertSame(404, $response['status']);
            self::assertStringNotContainsString('ZZ Wolf ' . $m, (string) $response['body']);
        } finally {
            $off->stop();
        }

        self::assertTrue(PortfolioRelatedProjects::settings((new PortfolioGalleryRepository())->findItemById($current))['related_enabled'], 'the setting is kept for when the module is back');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $settings
     * @param list<int>|null       $picked
     */
    private function relate(int $itemId, array $settings, ?array $picked = null): void
    {
        $repository = new PortfolioGalleryRepository();
        $repository->updateRelatedSettings($itemId, $settings + [
            'related_enabled' => true,
            'related_mode' => 'automatic',
            'related_max' => 3,
            'related_sort' => 'relevance',
            'related_fallback' => 'available',
            'related_layout' => 'normal',
            'related_show_text' => true,
        ]);
        if ($picked !== null) {
            $repository->replaceRelatedItems($itemId, $picked);
        }
        self::clearCaches();
    }

    /** @return list<string> */
    private function titles(int $itemId): array
    {
        $content = PortfolioRelatedProjects::forItemId($itemId);

        return $content === null ? [] : array_map(static fn (array $card): string => (string) $card['title'], $content['items']);
    }

    /**
     * A visible project with its own page, added now (a second apart from the
     * one before, so "newest" is never a tie of this test's making).
     *
     * @param list<int> $categories
     *
     * @return array{0: int, 1: string} id and slug
     */
    private function project(string $title, array $categories, bool $visible = true): array
    {
        static $second = 0;
        $repository = new PortfolioGalleryRepository();
        $marker = self::marker();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-related-' . $marker . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::SUBTITLE => 'ZZ korte tekst van ' . $title,
        ]);
        $repository->setItemCategories($id, $categories);
        $slug = 'zz-related-' . $marker;
        $repository->setItemProjectPage($id, true, $slug);
        \App\Database::connection()->prepare('UPDATE portfolio_gallery_items SET created_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', strtotime('2026-02-01 10:00:00') + ++$second), $id]);
        if (!$visible) {
            $repository->updateItem($id, ['media_id' => null, 'image_path' => 'assets/images/sections/zz-related-hidden.jpg', 'thumbnail_path' => null, 'is_active' => false]);
        }

        self::clearCaches();

        return [$id, $slug];
    }

    private function category(string $name): int
    {
        $id = (new PortfolioCategoryRepository())->create('zz-' . self::marker());
        PortfolioLocalization::saveCategory($id, PortfolioLocalization::defaultLanguage(), $name);
        $this->categoryIds[] = $id;

        return $id;
    }

    private function needServer(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    private static function marker(): string
    {
        return bin2hex(random_bytes(4));
    }

    private static function clearCaches(): void
    {
        PortfolioGalleryContent::clearCache();
        ItemGalleryContent::clearCache();
        PageContent::clearCache();
    }
}
