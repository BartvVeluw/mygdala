<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ReviewsRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\ProductContentOwner;
use App\Service\ReviewsContent;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\Theme\ButtonStyles;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\SavedRedirect;
use Tests\Support\ShopStockFixture;

require_once dirname(__DIR__, 2) . '/partials/section-reviews.php';

/**
 * Reviews 1.0 through the real editor and endpoint, over PHP's built-in
 * server, and what ReviewsContent then gives the page (CONTENT-BLOCKS.md,
 * "Reviews"):
 *
 *   - the four guards, the `<page>:<key>` gate and the owner's permission;
 *   - the lifecycle: choosing makes a draft, the first save places it and
 *     returns to the page, Annuleren leaves no row, word or picture behind;
 *   - reviews are added, changed, moved and removed with their ids, a
 *     review's text is required and everything else optional;
 *   - stars, dates, source addresses, pictures, the featured review and the
 *     button are checked, and a forged id changes nothing;
 *   - words per language: one language never overwrites another;
 *   - what an editor typed is stored as typed and printed escaped;
 *   - the editor: folded reviews with a recognisable title, four layouts.
 *
 * The pages, blocks, accounts, products and library rows are this test's
 * own and are removed in tearDown(). Without a server the test skips itself.
 */
final class ReviewsHttpTest extends TestCase
{
    private const KEY = 'zz-reviews-test';
    private const ENDPOINT = '/api/admin/update-reviews.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $productIds = [];

    private ?ShopStockFixture $shop = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
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

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();

        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Reviews-test');
    }

    protected function tearDown(): void
    {
        $this->removePage();

        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->shop?->cleanUp();

        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];

        RequestLanguage::reset();
        $this->accounts->forget();
        $this->clearCaches();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    // -------------------------------------------------------------- guards

    public function testTheFourGuardsAndTheSectionGate(): void
    {
        [$section] = $this->place();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::assertSame(401, self::$server->request('POST', self::ENDPOINT, null, ['section' => $section])['status'], 'not signed in');

        [$other, $otherCsrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $other, ['section' => $section, 'csrf_token' => $otherCsrf])['status'], 'no block permission');

        self::assertSame(405, self::$server->request('GET', self::ENDPOINT . '?section=' . urlencode($section), $session)['status']);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => 'wrong'])['status'], 'CSRF');
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section])['status'], 'no CSRF token');

        foreach ([self::KEY . ':custom-nothere', 'no-such-page:custom-x', self::KEY, ''] as $unknown) {
            self::assertSame(404, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $unknown, 'csrf_token' => $csrf])['status'], $unknown);
        }
        self::assertSame(404, self::$server->request('GET', '/admin/reviews.php?section=' . urlencode(self::KEY . ':custom-nothere'), $session)['status']);
    }

    public function testTheBlockListsOwnerDecidesWhoMaySave(): void
    {
        [$section] = $this->place();

        // products.manage is a block permission, but not the one of a page.
        [$shopOnly, $shopCsrf] = $this->accounts->signIn(['products.manage']);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $shopOnly, ['section' => $section, 'csrf_token' => $shopCsrf] + $this->fields($section))['status']);
        self::assertSame(403, self::$server->request('GET', '/admin/reviews.php?section=' . urlencode($section), $shopOnly)['status']);
        self::assertSame([], $this->items($section), 'nothing written');

        // The same account adds and saves Reviews on its own product's content page.
        $productId = $this->product('ZZ Reviews product');
        $added = self::$server->request('POST', '/api/admin/add-page-section.php', $shopOnly, [
            'csrf_token' => $shopCsrf, 'page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $productId, 'section_type' => 'reviews',
        ]);
        $productSection = $this->sectionFrom($added);
        $saved = $this->post($shopOnly, ['section' => $productSection, 'reviews' => ['new0' => $this->review(['body' => 'Mooi product.'])]] + $this->fields($productSection));
        $page = ContentPages::pageFor(ProductContentOwner::KIND, $productId);
        self::assertNotNull($page);
        [$sectionId] = $this->sectionIds((array) $page);
        self::assertSame('/admin/product-form.php?id=' . $productId . '&tab=inhoud&saved=' . $sectionId . '#blok-' . $sectionId, $saved['location']);
    }

    // ----------------------------------------------------------- lifecycle

    public function testChoosingMakesADraftThatAnnulerenRemovesWithoutATrace(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $page = $this->page();

        $added = self::$server->request('POST', '/api/admin/add-page-section.php', $session, ['csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'reviews']);
        $section = $this->sectionFrom($added);
        self::assertSame([], $this->sectionIds($page), 'a draft is not on the page');

        $editor = $this->editor($section, $session);
        self::assertStringContainsString('data-block-draft', $editor);
        self::assertStringContainsString('action="/api/admin/discard-block-draft.php"', $editor);

        // Something the draft could leave behind: a review with words and a picture.
        $block = $this->row($section);
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $itemId = (new ReviewsRepository())->createItem((int) $block['id'], ['media_id' => $picture, 'rating' => 4]);
        BlockLocalization::save(ReviewsContent::ITEMS, $itemId, BlockLocalization::defaultLanguage(), ['body' => 'Concept', 'name' => 'Iemand']);
        BlockLocalization::save(ReviewsContent::TABLE, (int) $block['id'], BlockLocalization::defaultLanguage(), ['title' => 'Concepttitel']);

        $cancelled = self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, ['csrf_token' => $csrf, 'section_type' => 'reviews', 'section' => $section]);
        self::assertSame('/admin/page.php?id=' . $page['id'], $cancelled['location']);
        self::assertSame([], $this->sectionIds($page));

        [, $key] = explode(':', $section, 2);
        self::assertNull((new ReviewsRepository())->findBySlugAndKey((string) $page['content_key'], $key), 'no block row');
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM review_block_items WHERE id = ' . $itemId)->fetchColumn(), 'no review row');
        BlockLocalization::clearCache();
        self::assertSame([], BlockLocalization::translations(ReviewsContent::ITEMS, $itemId), 'no review words');
        self::assertSame([], BlockLocalization::translations(ReviewsContent::TABLE, (int) $block['id']), 'no block words');
        self::assertTrue((new MediaService(new MediaRepository()))->delete($picture)['deleted'], 'the picture is free again');
        $this->mediaIds = array_values(array_diff($this->mediaIds, [$picture]));
    }

    public function testTheFirstSavePlacesTheBlockOnceAndReturnsToThePage(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $page = $this->page();
        $section = $this->sectionFrom(self::$server->request('POST', '/api/admin/add-page-section.php', $session, ['csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'reviews']));

        // A refused save places nothing and stays in the editor.
        $refused = $this->post($session, ['section' => $section, 'reviews' => ['new0' => $this->review(['name' => 'Zonder tekst'])]] + $this->fields($section));
        $this->assertRefused($refused);
        self::assertSame([], $this->sectionIds($page));

        $saved = $this->post($session, ['section' => $section, 'reviews' => ['new0' => $this->review(['body' => 'Top!'])]] + $this->fields($section));
        $ids = $this->sectionIds($page);
        self::assertCount(1, $ids);
        self::assertSame('/admin/page.php?id=' . $page['id'] . '&saved=' . $ids[0] . '#blok-' . $ids[0], $saved['location']);

        $again = $this->post($session, ['section' => $section, 'reviews' => [(string) $this->items($section)[0]['id'] => $this->review(['body' => 'Top!'])]] + $this->fields($section));
        self::assertSame($saved['location'], $again['location']);
        self::assertCount(1, $this->sectionIds($page), 'once');
    }

    // -------------------------------------------------------------- content

    public function testANewBlockIsEmptyAndRendersNothing(): void
    {
        [$section] = $this->place();
        $row = $this->row($section);

        self::assertSame(['cards', 'left', null, null, null, 1], [$row['layout'], $row['header_align'], $row['featured_item_id'], $row['link_type'], $row['button_style_id'], (int) $row['is_active']]);
        self::assertSame([], $this->content($section)['reviews']);
        self::assertSame('', trim($this->render($this->content($section))));
    }

    public function testReviewsAreAddedChangedMovedAndRemovedWithTheirIds(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        // One review.
        $this->assertSaved($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'Een', 'name' => 'Marieke'])]]));
        self::assertSame(['Een'], array_column($this->content($section)['reviews'], 'text'));
        $one = (int) $this->items($section)[0]['id'];

        // More reviews, one added.
        $this->assertSaved($this->save($session, $section, ['reviews' => [
            (string) $one => $this->review(['body' => 'Een', 'name' => 'Marieke']),
            'new0' => $this->review(['body' => 'Twee', 'name' => 'Peter']),
            'new1' => $this->review(['body' => 'Drie']),
        ]]));
        [$first, $two, $three] = array_map(static fn (array $item): int => (int) $item['id'], $this->items($section));
        self::assertSame($one, $first, 'a stored review keeps its id');
        self::assertSame(['Een', 'Twee', 'Drie'], array_column($this->content($section)['reviews'], 'text'));

        // Changed, moved (↓ without JavaScript) and removed, in one save.
        $this->assertSaved($this->save($session, $section, [
            'editor_action' => 'reviews:down:' . $one,
            'reviews' => [
                (string) $one => $this->review(['body' => 'Een, gewijzigd', 'name' => 'Marieke']),
                (string) $three => $this->review(['body' => 'Drie']),
                (string) $two => $this->review(['body' => 'Twee', 'name' => 'Peter', 'remove' => '1']),
            ],
        ]));
        self::assertSame([$three, $one], array_map(static fn (array $item): int => (int) $item['id'], $this->items($section)), 'the same ids, in the new order');
        self::assertSame(['Drie', 'Een, gewijzigd'], array_column($this->content($section)['reviews'], 'text'));
        self::assertSame([], BlockLocalization::translations(ReviewsContent::ITEMS, $two), 'a removed review takes its words along');
    }

    public function testOnlyTheTextIsRequiredAndEveryOtherPartIsOptional(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg', 'Portret van Marieke');

        $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['name' => 'Alleen een naam', 'rating' => '5'])]]), 'no text');
        self::assertSame([], $this->items($section));

        $this->assertSaved($this->save($session, $section, ['reviews' => [
            'new0' => $this->review(['body' => 'Alleen tekst']),
            'new1' => $this->review([
                'body' => 'Alles', 'name' => 'Marieke', 'role' => 'Eigenaar', 'rating' => '5', 'review_date' => '2026-03-12',
                'source_label' => 'Google', 'source_url' => 'https://example.org/r/1', 'media_id' => (string) $picture,
            ]),
        ]]));

        [$bare, $full] = $this->content($section)['reviews'];
        self::assertSame(['', '', 0, '', '', null], [$bare['name'], $bare['role'], $bare['rating'], $bare['date'], $bare['source_href'], $bare['image']]);
        self::assertSame(['Marieke', 'Eigenaar', 5, '2026-03-12', 'Google', 'https://example.org/r/1'], [$full['name'], $full['role'], $full['rating'], $full['date_iso'], $full['source_label'], $full['source_href']]);
        self::assertSame('Portret van Marieke', $full['image']['alt'], 'the library\'s alt text');

        $html = $this->render($this->content($section));
        self::assertSame(1, substr_count($html, 'review__stars'), 'no star row for the review without stars');
        self::assertSame(1, substr_count($html, '<figcaption'), 'no empty caption');
    }

    public function testStarsAreNoneOneOrFiveAndAnythingElseIsRefused(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['reviews' => [
            'new0' => $this->review(['body' => 'Geen', 'rating' => '']),
            'new1' => $this->review(['body' => 'Eén', 'rating' => '1']),
            'new2' => $this->review(['body' => 'Vijf', 'rating' => '5']),
        ]]));
        self::assertSame([null, 1, 5], array_map(static fn (array $item): ?int => $item['rating'] === null ? null : (int) $item['rating'], $this->items($section)));

        $ids = array_map(static fn (array $item): string => (string) $item['id'], $this->items($section));
        foreach (['6', '0', '-1', '4.5', 'vijf'] as $wrong) {
            $response = $this->save($session, $section, ['reviews' => [$ids[0] => $this->review(['body' => 'Geen', 'rating' => $wrong])]]);
            $this->assertRefused($response, $wrong);
            self::assertNull($this->items($section)[0]['rating'], $wrong . ': nothing written');
        }
    }

    public function testAnUnsafeSourceAndADateThatDoesNotExistAreRefused(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', "https://example.org/\x01", 'data:text/html,x', 'mailto:a@example.org'] as $unsafe) {
            $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'Tekst', 'source_url' => $unsafe])]]), $unsafe);
        }
        foreach (['2026-02-30', '12-03-2026', 'gisteren'] as $wrong) {
            $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'Tekst', 'review_date' => $wrong])]]), $wrong);
        }
        self::assertSame([], $this->items($section), 'nothing written');

        $this->assertSaved($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'Tekst', 'source_url' => '/ervaringen'])]]), 'a path of this site');
    }

    public function testAForgedReviewIdIsNeverWritten(): void
    {
        [$section] = $this->place();
        [$otherSection] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $otherSection, ['reviews' => ['new0' => $this->review(['body' => 'Van een ander blok', 'rating' => '2'])]]));
        $foreign = (int) $this->items($otherSection)[0]['id'];

        $this->assertSaved($this->save($session, $section, [
            'reviews' => [(string) $foreign => $this->review(['body' => 'Overschreven?', 'rating' => '5', 'remove' => ''])],
            'featured' => (string) $foreign,
        ]));

        self::assertSame([], $this->items($section), 'this block got no review from it');
        self::assertSame('Van een ander blok', BlockLocalization::raw(ReviewsContent::ITEMS, $foreign, 'body', BlockLocalization::defaultLanguage()));
        self::assertSame(2, (int) $this->items($otherSection)[0]['rating'], 'the other block is untouched');
        self::assertNull($this->row($section)['featured_item_id'], 'a forged featured id stores no choice');

        $this->assertSaved($this->save($session, $section, ['reviews' => [(string) $foreign => $this->review(['body' => 'x', 'remove' => '1'])]]));
        self::assertCount(1, $this->items($otherSection), 'a forged removal removes nothing');
    }

    public function testTheFeaturedReviewIsChosenByItsRowAndFallsBackToTheFirst(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        // A new review can be the featured one: it gets its id in the save.
        $this->assertSaved($this->save($session, $section, [
            'layout' => 'featured',
            'featured' => 'new1',
            'reviews' => ['new0' => $this->review(['body' => 'Eerste']), 'new1' => $this->review(['body' => 'Tweede'])],
        ]));
        [$first, $second] = array_map(static fn (array $item): int => (int) $item['id'], $this->items($section));
        self::assertSame($second, (int) $this->row($section)['featured_item_id']);
        self::assertSame(1, $this->content($section)['featured']);
        self::assertStringContainsString('Tweede', $this->render($this->content($section)));
        self::assertStringNotContainsString('Eerste', $this->render($this->content($section)));

        // The featured review removed: no choice, the first one shows.
        $this->assertSaved($this->save($session, $section, [
            'layout' => 'featured',
            'featured' => (string) $second,
            'reviews' => [(string) $first => $this->review(['body' => 'Eerste']), (string) $second => $this->review(['body' => 'Tweede', 'remove' => '1'])],
        ]));
        self::assertNull($this->row($section)['featured_item_id']);
        self::assertStringContainsString('Eerste', $this->render($this->content($section)));
    }

    public function testEveryLayoutIsAWordOfItsListAndChangesThePage(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $reviews = ['new0' => $this->review(['body' => 'Een', 'rating' => '5']), 'new1' => $this->review(['body' => 'Twee'])];
        $this->assertSaved($this->save($session, $section, ['reviews' => $reviews]));
        $ids = array_map(static fn (array $item): string => (string) $item['id'], $this->items($section));
        $stored = [$ids[0] => $this->review(['body' => 'Een', 'rating' => '5']), $ids[1] => $this->review(['body' => 'Twee'])];

        foreach (ReviewsContent::LAYOUTS as $layout) {
            $this->assertSaved($this->save($session, $section, ['layout' => $layout, 'reviews' => $stored]), $layout);
            self::assertStringContainsString('reviews-section--' . $layout, $this->render($this->content($section)), $layout);
        }

        $this->assertRefused($this->save($session, $section, ['layout' => 'slider', 'reviews' => $stored]));
        $this->assertRefused($this->save($session, $section, ['header_align' => 'right', 'reviews' => $stored]));
        self::assertSame('carousel', $this->row($section)['layout'], 'a refused word changes nothing');
    }

    public function testTheButtonNeedsWordsAndAStyleThatExists(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $reviews = ['new0' => $this->review(['body' => 'Een'])];

        $this->assertRefused($this->save($session, $section, ['reviews' => $reviews, 'link_type' => 'url', 'link_url' => '/ervaringen', 'button_label' => '']), 'a button without words');
        $this->assertRefused($this->save($session, $section, ['reviews' => $reviews, 'link_type' => 'url', 'link_url' => 'javascript:alert(1)', 'button_label' => 'Meer']), 'an unsafe address');
        $this->assertRefused($this->save($session, $section, ['reviews' => $reviews, 'link_type' => 'url', 'link_url' => '/ervaringen', 'button_label' => 'Meer', 'button_style_id' => '999999']), 'a style that does not exist');
        self::assertSame([], $this->items($section));

        $style = ButtonStyles::all()[0]['id'] ?? null;
        $this->assertSaved($this->save($session, $section, ['reviews' => $reviews, 'link_type' => 'url', 'link_url' => '/ervaringen', 'button_label' => 'Bekijk alle ervaringen', 'button_style_id' => $style !== null ? (string) $style : '']));
        $button = $this->content($section)['button'];
        self::assertSame(['/ervaringen', 'Bekijk alle ervaringen'], [$button['href'], $button['label']]);
        if ($style !== null) {
            self::assertStringContainsString('btn btn-style-' . $style, $this->render($this->content($section)));
        }

        // "Geen knop" stores no address and shows no button.
        $id = (string) $this->items($section)[0]['id'];
        $this->assertSaved($this->save($session, $section, ['reviews' => [$id => $this->review(['body' => 'Een'])], 'link_type' => 'none', 'link_url' => '/ervaringen', 'button_label' => 'Bekijk alle ervaringen']));
        self::assertNull($this->row($section)['link_url']);
        self::assertNull($this->content($section)['button']);
    }

    public function testWhatAnEditorTypesIsStoredAsTypedAndPrintedEscaped(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $hostile = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';

        $this->assertSaved($this->save($session, $section, [
            'title' => $hostile,
            'reviews' => ['new0' => $this->review(['body' => $hostile, 'name' => $hostile, 'role' => $hostile, 'source_label' => $hostile])],
        ]));
        self::assertSame($hostile, BlockLocalization::raw(ReviewsContent::ITEMS, (int) $this->items($section)[0]['id'], 'body', BlockLocalization::defaultLanguage()), 'stored as typed');

        $html = $this->render($this->content($section));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertSame(5, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));

        $editor = $this->editor($section, $session);
        self::assertStringNotContainsString('<script>alert(1)', $editor);
    }

    public function testMaximumLengthsAreEnforced(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => str_repeat('a', 1501)])]]), 'text');
        $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'a', 'name' => str_repeat('n', 151)])]]), 'name');
        $this->assertRefused($this->save($session, $section, ['title' => str_repeat('t', 256), 'reviews' => ['new0' => $this->review(['body' => 'a'])]]), 'title');
        self::assertSame([], $this->items($section));

        $this->assertSaved($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => str_repeat('a', 1500)])]]), 'exactly the maximum');
    }

    // ------------------------------------------------------------ languages

    public function testWordsArePerLanguageAndOneLanguageNeverOverwritesAnother(): void
    {
        if (!SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this database has no English website language');
        }

        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, [
            'title' => 'Wat klanten zeggen', 'button_label' => 'Alle ervaringen', 'link_type' => 'url', 'link_url' => '/ervaringen',
            'reviews' => ['new0' => $this->review(['body' => 'Heel tevreden', 'name' => 'Marieke', 'role' => 'Klant', 'rating' => '4'])],
        ]));
        $id = (int) $this->items($section)[0]['id'];

        $this->assertSaved($this->save($session, $section, [
            'language_code' => 'en', 'title' => 'What customers say', 'lead' => 'Only in English', 'button_label' => 'All experiences', 'link_type' => 'url', 'link_url' => '/ervaringen',
            'reviews' => [(string) $id => $this->review(['body' => 'Very happy', 'name' => '', 'role' => 'Customer', 'rating' => '4'])],
        ]));

        RequestLanguage::set('en', true);
        $this->clearCaches();
        $english = $this->content($section);
        self::assertSame('What customers say', $english['title']);
        self::assertSame('', $english['lead'], 'a word only the translation has does not show');
        self::assertSame('All experiences', $english['button']['label']);
        self::assertSame(['Very happy', 'Marieke', 'Customer'], [$english['reviews'][0]['text'], $english['reviews'][0]['name'], $english['reviews'][0]['role']], 'an empty translation falls back to the default language');

        RequestLanguage::reset();
        $this->clearCaches();
        $dutch = $this->content($section);
        self::assertSame(['Wat klanten zeggen', 'Heel tevreden', 'Marieke', 'Klant', 'Alle ervaringen'], [$dutch['title'], $dutch['reviews'][0]['text'], $dutch['reviews'][0]['name'], $dutch['reviews'][0]['role'], $dutch['button']['label']], 'the default language is untouched');

        // A new review added while English is on screen is written in the default language.
        $this->assertSaved($this->save($session, $section, [
            'language_code' => 'en', 'title' => 'What customers say', 'button_label' => 'All experiences', 'link_type' => 'url', 'link_url' => '/ervaringen',
            'reviews' => [(string) $id => $this->review(['body' => 'Very happy', 'role' => 'Customer', 'rating' => '4']), 'new0' => $this->review(['body' => 'Nieuw'])],
        ]));
        $new = (int) $this->items($section)[1]['id'];
        self::assertSame('Nieuw', BlockLocalization::raw(ReviewsContent::ITEMS, $new, 'body', BlockLocalization::defaultLanguage()));
        self::assertSame('', BlockLocalization::raw(ReviewsContent::ITEMS, $new, 'body', 'en'));
    }

    // ---------------------------------------------------------------- media

    public function testAPictureMustBeALibraryPictureAndIsAUsage(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');

        $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'x', 'media_id' => (string) $video])]]), 'a video');
        $this->assertRefused($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'x', 'media_id' => '999999999'])]]), 'an id that names nothing');
        self::assertSame([], $this->items($section));

        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $this->assertSaved($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'x', 'media_id' => (string) $picture])]]));

        $service = new MediaService(new MediaRepository());
        $usages = $service->usagesOf($picture);
        self::assertCount(1, $usages);
        self::assertSame('Reviews op "' . self::KEY . '"', $usages[0]->label);
        self::assertSame('/admin/reviews.php?section=' . rawurlencode($section), $usages[0]->editUrl);
        self::assertFalse($service->delete($picture)['deleted'], 'a used picture stays');

        // Removing the picture from the review frees it.
        $id = (string) $this->items($section)[0]['id'];
        $this->assertSaved($this->save($session, $section, ['reviews' => [$id => $this->review(['body' => 'x', 'media_id' => ''])]]));
        self::assertNull($this->items($section)[0]['media_id']);
        self::assertSame([], $service->usagesOf($picture));
    }

    // --------------------------------------------------------------- editor

    public function testTheEditorFoldsReviewsWithARecognisableTitleAndShowsFourLayouts(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $this->assertSaved($this->save($session, $section, ['reviews' => [
            'new0' => $this->review(['body' => 'Een', 'name' => 'Marieke']),
            'new1' => $this->review(['body' => 'Twee']),
        ]]));

        $editor = $this->editor($section, $session);
        self::assertMatchesRegularExpression('#Review <span data-row-list-number>1</span><span data-row-list-title> — Marieke</span>#u', $editor);
        self::assertMatchesRegularExpression('#Review <span data-row-list-number>2</span><span data-row-list-title> — Anoniem</span>#u', $editor);
        self::assertSame(2, preg_match_all('#<details class="admin-collapse admin-row-card__collapse" data-admin-collapse-id="\d+"#', $editor), 'every stored review folds');
        self::assertStringNotContainsString('admin-row-card__collapse" data-admin-collapse-id="' . $this->items($section)[0]['id'] . '" open', $editor, 'stored reviews of a longer list start folded');

        foreach (ReviewsContent::LAYOUTS as $layout) {
            self::assertStringContainsString('<option value="' . $layout . '"', $editor);
            self::assertStringContainsString('data-reviews-sketch="' . $layout . '"', $editor);
        }
        self::assertMatchesRegularExpression('#<p class="admin-review__featured" data-reviews-needs="featured" hidden>#', $editor, 'the featured choice only for "Uitgelicht"');
        $form = (string) strstr((string) strstr($editor, '<form method="post" action="/api/admin/update-reviews.php"'), '</form>', true);
        self::assertStringNotContainsString('type="file"', $form, 'the Media Library, no upload field of its own');
        self::assertMatchesRegularExpression('#<option value="" selected>Geen sterren</option>#', $editor, 'no stars is the start');
        self::assertStringContainsString('<template data-row-list-template="reviews-items">', $editor);
    }

    public function testAHiddenBlockRendersNothing(): void
    {
        [$section, $blockId] = $this->place();
        $session = $this->signIn();
        $this->assertSaved($this->save($session, $section, ['reviews' => ['new0' => $this->review(['body' => 'Iets'])]]));

        (new ReviewsRepository())->updateSettings($blockId, ['is_active' => false]);
        $content = $this->content($section);
        self::assertSame(ReviewsContent::STATE_HIDDEN, $content['state']);
        self::assertSame([], $content['reviews']);
    }

    // -------------------------------------------------------------- helpers

    /** @return array{0: string, 1: int} the `<page>:<key>` and the block's id */
    private function place(): array
    {
        [$id, $key] = SectionRegistry::create('reviews', self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        self::assertNotNull($page);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, 'reviews', $key, $id);
        $this->clearCaches();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /** @return array<string, mixed> the test page */
    private function page(): array
    {
        return (array) (new PageRepository())->findByContentKey(self::KEY);
    }

    /** @return array<string, mixed> the form as the editor posts it: nothing typed, every choice at its default */
    private function fields(string $section): array
    {
        return [
            'section' => $section,
            'language_code' => BlockLocalization::defaultLanguage(),
            'eyebrow' => '',
            'title' => '',
            'lead' => '',
            'layout' => 'cards',
            'header_align' => 'left',
            'link_type' => 'none',
            'link_url' => '',
            'button_label' => '',
            'button_style_id' => '',
            'reviews_present' => '1',
        ];
    }

    /**
     * One review as the editor posts it.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function review(array $values): array
    {
        return $values + [
            'present' => '1',
            'body' => '',
            'name' => '',
            'role' => '',
            'rating' => '',
            'review_date' => '',
            'media_id' => '',
            'source_label' => '',
            'source_url' => '',
        ];
    }

    /** @param array<string, mixed> $changes */
    private function save(string $session, string $section, array $changes): array
    {
        return $this->post($session, $changes + $this->fields($section));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, array $fields): array
    {
        $response = self::$server->request('POST', self::ENDPOINT, $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
        ]);
        $this->clearCaches();

        return $response;
    }

    private function editor(string $section, string $session): string
    {
        $response = self::$server->request('GET', '/admin/reviews.php?section=' . urlencode($section), $session);
        self::assertSame(200, $response['status'], $response['body']);

        return $response['body'];
    }

    /** @return array<string, mixed> */
    private function row(string $section): array
    {
        [$pageSlug, $key] = explode(':', $section, 2);
        $row = (new ReviewsRepository())->findBySlugAndKey($pageSlug, $key);
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function items(string $section): array
    {
        return (new ReviewsRepository())->findItemsByBlockId((int) $this->row($section)['id']);
    }

    /** @return array<string, mixed> */
    private function content(string $section): array
    {
        [$pageSlug, $key] = explode(':', $section, 2);
        $this->clearCaches();

        return ReviewsContent::forSection($pageSlug, $key);
    }

    /** @return list<int> */
    private function sectionIds(array $page): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new PageSectionRepository())->findForPage((int) $page['id']));
    }

    /** The `<page>:<key>` a redirect into the Reviews editor names. */
    private function sectionFrom(array $response): string
    {
        self::assertSame(302, $response['status'], $response['body']);
        self::assertStringStartsWith('/admin/reviews.php?section=', $response['location']);
        parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);

        return (string) ($query['section'] ?? '');
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    private function product(string $name): int
    {
        $this->shop ??= new ShopStockFixture();
        $id = $this->shop->product($name);
        $this->productIds[] = $id;

        return $id;
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function libraryItem(string $mimeType, string $extension, string $alt = ''): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__rv_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'portret.' . $extension,
            'display_name' => '',
            'mime_type' => $mimeType,
            'alt_text' => $alt,
            'width' => 800,
            'height' => 800,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        ob_start();
        try {
            render_section_reviews($content, 'reviews-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function clearCaches(): void
    {
        ReviewsContent::clearCache();
        MediaService::clearCache();
        PageContent::clearCache();
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, $response['location'], $what . ' ' . $response['body']);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $what = ''): void
    {
        self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $response['location'], $what);
        self::assertStringStartsWith('/admin/reviews.php', $response['location'], $what . ': back to the editor');
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            ContentBlockDrafts::discardForPage((int) $page['id']);
            PageService::delete($page);
        }

        PageContent::clearCache();
    }
}
