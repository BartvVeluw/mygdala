<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ProductRepository;
use App\Repository\ShopListingRepository;
use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockHead;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\PageLocalization;
use App\Service\PageContent;
use App\Service\PageTranslation;
use App\Service\Routing\RequestLanguage;
use App\Service\Search\BlockSearchIndex;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchService;
use App\Service\SectionRegistry;
use App\Service\ShopListingContent;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;

/**
 * The optional head of the Shop's Productgrid and Collectie-tegels (v0.1.15
 * phase 9, App\Service\Blocks\ShopListingBlock): an eyebrow, a title and a
 * text above the listing, per website language, found by the site search,
 * styled by Extra vormgeving, gone when the block is switched off.
 *
 *   - no words: exactly the markup the blocks always printed (the grid
 *     compared byte for byte with the partial of 7d8a68e);
 *   - each word alone, and all three, in the shared .section-head;
 *   - escaped, never markup;
 *   - per language, with the default language's words where one has none;
 *   - the search finds the page by the block's own words and never by a
 *     product's or a collection's;
 *   - Extra vormgeving lands on the block's own <section>.
 *
 * Everything runs inside one transaction that tearDown() rolls back; every
 * seeded word carries a made-up token.
 */
final class ShopListingBlockTest extends TestCase
{
    private const ALL_ON = ['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true, 'articles' => true];

    /** partials/section-product-grid.php at 7d8a68e, in Dutch. */
    private const LEGACY_GRID = "  <section style=\"padding-top:0;\">\n    <div class=\"container\">\n      <div class=\"shop-grid\" data-products-grid data-card-heading=\"h2\">\n        <p class=\"lead\" data-products-loading>Producten laden…</p>\n      </div>\n      <p class=\"lead\" data-products-error hidden>Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw of neem contact op via het offerteformulier.</p>\n    </div>\n  </section>\n  ";

    private const TILES = [
        ['id' => 1, 'slug' => 'zomer', 'name' => 'Zomer', 'description' => 'Lichte dingen', 'image_path' => 'assets/media/a.jpg', 'url' => '/collecties/zomer'],
    ];

    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        ModuleRegistry::overrideForTests(self::ALL_ON);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        RequestLanguage::reset();
        $this->clearCaches();
        BlockSearchIndex::ensureCurrent();

        require_once dirname(__DIR__, 2) . '/partials/section-product-grid.php';
        require_once dirname(__DIR__, 2) . '/partials/section-shop-collections.php';
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
        $this->clearCaches();
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    public function testWithoutWordsTheGridPrintsExactlyWhatItAlwaysDid(): void
    {
        RequestLanguage::set('nl', false);

        self::assertSame(self::LEGACY_GRID, self::capture(static fn () => render_section_product_grid()));
        self::assertSame(self::LEGACY_GRID, self::capture(static fn () => render_section_product_grid(BlockHead::none())));
        self::assertSame(self::LEGACY_GRID, self::capture(static fn () => render_section_product_grid(['eyebrow' => '  ', 'title' => '', 'lead' => "\n"])), 'whitespace is no word');

        $tiles = self::capture(static fn () => render_section_shop_collections(self::TILES));
        self::assertSame($tiles, self::capture(static fn () => render_section_shop_collections(self::TILES, BlockHead::none())));
        self::assertStringStartsWith("  <section style=\"padding-top:0;\">\n    <div class=\"container\">\n      <div class=\"collection-tiles\">", $tiles);
        self::assertStringNotContainsString('section-head', $tiles);

        // A placed block with a row and no words, rendered through the registry.
        $page = $this->page('Winkel zqleeg');
        [$row] = $this->listing($page, 'product_grid', []);
        self::assertSame(self::LEGACY_GRID, $this->render($row));
    }

    /** @return array<string, array{0: array<string, string>, 1: list<string>, 2: list<string>}> */
    public static function heads(): array
    {
        return [
            'only an eyebrow' => [['eyebrow' => 'Winkel'], ['<p class="eyebrow">Winkel</p>', 'data-card-heading="h2"'], ['<h2>', 'class="lead">', 'padding-top:0']],
            'only a title' => [['title' => 'Alle producten'], ['<h2>Alle producten</h2>', 'data-card-heading="h3"'], ['class="eyebrow"', '<p class="lead">Alle']],
            'only a text' => [['lead' => 'Met de hand gemaakt.'], ['<p class="lead">Met de hand gemaakt.</p>', 'data-card-heading="h2"'], ['class="eyebrow"', '<h2>']],
            'all three' => [['eyebrow' => 'Winkel', 'title' => 'Alle producten', 'lead' => 'Met de hand.'], ['<p class="eyebrow">Winkel</p>', '<h2>Alle producten</h2>', '<p class="lead">Met de hand.</p>', 'data-card-heading="h3"'], ['padding-top:0']],
        ];
    }

    /**
     * @dataProvider heads
     *
     * @param array<string, string> $head
     * @param list<string> $present
     * @param list<string> $absent
     */
    public function testEachWordOfTheHeadStandsAboveTheListing(array $head, array $present, array $absent): void
    {
        $head += BlockHead::none();

        $grid = self::capture(static fn () => render_section_product_grid($head));
        self::assertStringStartsWith("  <section>\n    <div class=\"container\">\n      <div class=\"section-head\" data-reveal>", $grid, 'the head first, as an ordinary section with its own room');
        self::assertLessThan(strpos($grid, 'shop-grid'), strpos($grid, 'section-head'));
        foreach ($present as $needle) {
            self::assertStringContainsString($needle, $grid);
        }
        foreach ($absent as $needle) {
            self::assertStringNotContainsString($needle, $grid);
        }

        $tiles = self::capture(static fn () => render_section_shop_collections(self::TILES, $head));
        self::assertLessThan(strpos($tiles, 'collection-tiles'), strpos($tiles, 'section-head'));
        self::assertSame(1, substr_count($tiles, 'section-head'));
        self::assertSame(1, preg_match('/<(h[23]) class="collection-tile__name"/', $tiles, $tag));
        self::assertSame(trim($head['title']) !== '' ? 'h3' : 'h2', $tag[1], 'a tile title under the block title is an h3');
    }

    public function testCollectionTilesWithoutCollectionsPrintNothingNotEvenTheirHead(): void
    {
        self::assertSame('', self::capture(static fn () => render_section_shop_collections([], ['eyebrow' => 'X', 'title' => 'Collecties', 'lead' => 'Y'])));
    }

    public function testTheHeadIsTextNeverMarkup(): void
    {
        $evil = '<script>alert(1)</script><b>"\'&';
        $html = self::capture(static fn () => render_section_product_grid(['eyebrow' => $evil, 'title' => $evil, 'lead' => $evil]));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame(3, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;&quot;&#039;&amp;'));
        // No colour, font or size of its own: the theme's tokens on .section-head decide.
        self::assertStringNotContainsString('style=', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Storage and languages                                               */
    /* ------------------------------------------------------------------ */

    public function testTheHeadIsStoredPerLanguageWithTheDefaultLanguageAsFallback(): void
    {
        $page = $this->page('Winkel zqtaal');
        [$row, $id] = $this->listing($page, 'shop_collections', ['eyebrow' => 'Winkel', 'title' => 'Onze collecties', 'lead' => 'Kies maar.']);
        BlockLocalization::save(ShopListingRepository::TABLE, $id, 'en', ['eyebrow' => '', 'title' => 'Our collections', 'lead' => '']);

        RequestLanguage::set('nl', false);
        $this->clearCaches();
        $nl = ShopListingContent::forSection((string) $row['page_slug'], (string) $row['section_key']);
        self::assertSame(['Winkel', 'Onze collecties', 'Kies maar.'], [$nl['eyebrow'], $nl['title'], $nl['lead']]);

        RequestLanguage::set('en', true);
        $this->clearCaches();
        $en = ShopListingContent::forSection((string) $row['page_slug'], (string) $row['section_key']);
        self::assertSame(['Winkel', 'Our collections', 'Kies maar.'], [$en['eyebrow'], $en['title'], $en['lead']], 'its own title, the default language\'s other words');

        // Stored as words of the block, never as _nl/_en columns.
        $columns = array_column($this->db->query('SHOW COLUMNS FROM shop_listing_blocks')->fetchAll(), 'Field');
        self::assertSame(['id', 'page_slug', 'section_key', 'is_active', 'created_at', 'updated_at'], $columns);
    }

    public function testASwitchedOffRowRendersNothingAndNoRowIsTheListingWithoutAHead(): void
    {
        $page = $this->page('Winkel zquit');
        [$row, $id] = $this->listing($page, 'product_grid', ['title' => 'Zichtbaar']);
        self::assertStringContainsString('<h2>Zichtbaar</h2>', $this->render($row));

        $this->db->prepare('UPDATE shop_listing_blocks SET is_active = 0 WHERE id = ?')->execute([$id]);
        $this->clearCaches();
        self::assertSame(ShopListingContent::STATE_HIDDEN, ShopListingContent::forSection((string) $row['page_slug'], (string) $row['section_key'])['state']);
        self::assertSame('', $this->render($row));

        // shop.php's storefront draws the grid without a page section.
        RequestLanguage::set('nl', false);
        self::assertSame(self::LEGACY_GRID, $this->render(['id' => 0, 'section_type' => 'product_grid']));
    }

    public function testDeletingTheBlockDeletesItsRowAndItsWords(): void
    {
        $page = $this->page('Winkel zqweg');
        [$row, $id] = $this->listing($page, 'product_grid', ['title' => 'Weg']);

        SectionRegistry::delete($row, new PageSectionRepository());

        self::assertNull((new ShopListingRepository())->findBySlugAndKey((string) $row['page_slug'], (string) $row['section_key']));
        $words = $this->db->prepare('SELECT COUNT(*) FROM block_translations WHERE owner_table = ? AND owner_id = ?');
        $words->execute([ShopListingRepository::TABLE, $id]);
        self::assertSame(0, (int) $words->fetchColumn(), 'no orphaned words');
    }

    /* ------------------------------------------------------------------ */
    /* Search and appearance                                               */
    /* ------------------------------------------------------------------ */

    public function testTheSearchFindsThePageByTheBlocksOwnWordsAndNeverByAProducts(): void
    {
        $product = (new ProductRepository())->create([
            'slug' => '__slb_' . bin2hex(random_bytes(5)), 'price' => 10.0, 'image_path' => null, 'active' => true,
            'shipping_profile' => 'letter', 'shipping_weight_grams' => 10, 'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($product, 'nl', [ShopLocalization::NAME => 'Zqproductnaam beker', ShopLocalization::DESCRIPTION => 'Zqproducttekst', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);

        $page = $this->page('Winkel zqzoek');
        [$grid] = $this->listing($page, 'product_grid', ['eyebrow' => 'Zqbovenkop', 'title' => 'Zqgridtitel', 'lead' => 'Zqgridtekst']);
        $this->listing($page, 'shop_collections', ['title' => 'Zqtegeltitel']);

        foreach (['zqbovenkop', 'zqgridtitel', 'zqgridtekst', 'zqtegeltitel'] as $word) {
            self::assertSame(['page Winkel zqzoek'], $this->found($word), $word . ' finds its page');
        }

        // The product is found as itself, never as the page its grid is on.
        self::assertNotContains('page Winkel zqzoek', $this->found('zqproductnaam'));
        self::assertNotContains('page Winkel zqzoek', $this->found('zqproducttekst'));
        $index = $this->db->prepare('SELECT COUNT(*) FROM search_block_texts WHERE page_section_id = ? AND (heading_text LIKE ? OR body_text LIKE ?)');
        $index->execute([(int) $grid['id'], '%zqproduct%', '%zqproduct%']);
        self::assertSame(0, (int) $index->fetchColumn(), 'no product word in the block\'s copy');
    }

    public function testExtraVormgevingStylesTheBlocksOwnSection(): void
    {
        foreach (['product_grid', 'shop_collections'] as $type) {
            $support = BlockDefinitions::get($type)->appearanceSupport();
            self::assertTrue($support->background && $support->borders && $support->spacing, $type);
            self::assertSame(['glow', 'pattern'], $support->decorations, $type);
        }

        $html = self::capture(static fn () => render_section_product_grid(['eyebrow' => '', 'title' => 'Alle producten', 'lead' => '']));
        $styled = BlockAppearance::apply($html, ['background' => 'secondary', 'border' => 'both', 'border_tone' => 'accent', 'spacing' => 'spacious', 'decoration' => 'glow']);
        self::assertMatchesRegularExpression('/^\s*<section class="[^"]*block-appearance--bg-secondary[^"]*block-appearance--space-spacious/', $styled);
        self::assertStringContainsString('block-decor', $styled);
        self::assertSame(1, substr_count($styled, '<section'), 'no wrapper of its own');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function page(string $title): array
    {
        $key = 'zq-slb-' . bin2hex(random_bytes(5));
        $id = (new PageRepository())->create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED]);
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => $title]);

        return (array) (new PageRepository())->findById($id);
    }

    /**
     * A listing placed the way its editor places it: words, then
     * ContentBlockDrafts::place() as the save's last step.
     *
     * @param array<string, mixed> $page
     * @param array<string, string> $words
     * @return array{0: array<string, mixed>, 1: int} the page_sections row, the listing row id
     */
    private function listing(array $page, string $type, array $words): array
    {
        $sectionId = (int) ContentBlockDrafts::open($page, $type)['section_id'];
        if ($words !== []) {
            BlockLocalization::save(ShopListingRepository::TABLE, (int) $sectionId, 'nl', $words + BlockHead::none());
        }
        $placed = ContentBlockDrafts::place($type, (int) $sectionId);
        $this->clearCaches();

        return [(array) $placed, (int) $sectionId];
    }

    /** @param array<string, mixed> $row */
    private function render(array $row): string
    {
        $this->clearCaches();

        return self::capture(static fn () => SectionRegistry::render($row));
    }

    /** @return list<string> "type title" per hit */
    private function found(string $query): array
    {
        $this->clearCaches();

        return array_map(static fn (SearchHit $hit): string => $hit->type . ' ' . $hit->title, SearchService::search(SearchQuery::fromInput($query), 'nl', 1, 50)->hits);
    }

    private static function capture(callable $render): string
    {
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        PageLocalization::clearCache();
        ShopLocalization::clearCache();
        BlockLocalization::clearCache();
        ShopListingContent::clearCache();
    }
}
