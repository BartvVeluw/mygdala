<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\BlockTranslationRepository;
use App\Repository\BlogPostRepository;
use App\Repository\CardCarouselRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostStatus;
use App\Service\CardCarouselContent;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\Routing\LinkTargets;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The Kaarten-carrousel's two one-form editors, over real HTTP
 * (admin/card-carousel.php + api/admin/update-card-carousel.php,
 * admin/carousel-card.php + api/admin/update-carousel-card.php):
 *
 *  - ONE Opslaan stores the block's fields, its cards and a card's tags, link
 *    and image together, and nothing needs a save of its own;
 *  - a refused save stores nothing and hands every typed value back, with the
 *    message next to its field;
 *  - ↑, ↓ and × without JavaScript store what was typed with the move;
 *  - a save writes only the language on screen, and a new tag is written in
 *    the default language;
 *  - a new card is a draft without a stored number; an empty number follows
 *    the card's place, an own label stays; "Actief" is per card;
 *  - a button points at a page, a blog post or a product by id.
 *
 * The page, its block, the accounts, the post and the product are this test's
 * own and are removed in tearDown(). Without a server the test skips itself.
 */
final class CardCarouselEditorHttpTest extends TestCase
{
    private const KEY = 'zz-card-carousel-editor-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private CardCarouselRepository $repository;

    private int $pageId = 0;

    private int $carouselId = 0;

    private string $section = '';

    /** @var list<int> */
    private array $postIds = [];

    /** @var list<int> */
    private array $productIds = [];

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
        $this->accounts = new AdminTestSession();
        $this->repository = new CardCarouselRepository();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Carrouseltest'
        );

        [$id, $key] = SectionRegistry::create('card_carousel', self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'card_carousel', $key, $id);
        $this->carouselId = (int) $id;
        $this->section = self::KEY . ':' . $key;
    }

    protected function tearDown(): void
    {
        $this->removePage();

        foreach ($this->postIds as $id) {
            (new BlogPostRepository())->delete($id);
        }
        foreach ($this->productIds as $id) {
            (new ProductRepository())->delete($id);
        }
        $this->postIds = $this->productIds = [];

        $this->accounts->forget();
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();
        PageContent::clearCache();
        LinkTargets::reset();
    }

    // ------------------------------------------------------------ the carousel screen

    public function testOneSaveStoresTheHeadingTheLayoutAndEveryCardsSwitchAndPlace(): void
    {
        $a = $this->card('Hout', true);
        $b = $this->card('Acryl', true);
        $c = $this->card('Glas', false);
        $session = $this->signIn(null);

        $this->assertSaved($this->saveCarousel($session, [
            'title' => 'Materialen',
            'desktop_layout' => 'row',
            // The order on screen after two moves, and two switches changed.
            'cards' => [
                $c => ['present' => '1', 'active' => '1'],
                $a => ['present' => '1', 'active' => '1'],
                $b => ['present' => '1'],
            ],
        ]));

        self::assertSame(['title' => 'Materialen'], $this->stored('card_carousels', $this->carouselId, 'nl'));
        $row = $this->repository->findById($this->carouselId);
        self::assertSame('row', $row['desktop_layout']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame([[$c, 1], [$a, 1], [$b, 0]], $this->cards());

        // "Actief" is per card: the block itself is on, and only the two
        // switched-on cards render, numbered by their place.
        $content = CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1]);
        self::assertSame(CardCarouselContent::STATE_ACTIVE, $content['state']);
        self::assertSame(['Glas', 'Hout'], array_column($content['cards'], 'title'));
        self::assertSame(['01', '02'], array_column($content['cards'], 'index_label'));
        self::assertSame('row', $content['desktop_layout']);
    }

    public function testAMoveWithoutScriptStoresWhatWasTypedAndARemovedCardTakesItsWordsAndTags(): void
    {
        $a = $this->card('Hout', true);
        $b = $this->card('Acryl', true);
        $tag = $this->repository->createTag($b);
        BlockLocalization::save('carousel_card_tags', $tag, 'nl', ['label' => 'Transparant']);
        BlockLocalization::save('carousel_cards', $b, 'en', ['title' => 'Acrylic']);
        $session = $this->signIn(null);

        // ↓ on the first card, pressed without JavaScript, with a new title typed.
        $this->assertSaved($this->saveCarousel($session, [
            'title' => 'Getypt voor de verplaatsing',
            'editor_action' => 'cards:down:' . $a,
            'cards' => [$a => ['present' => '1', 'active' => '1'], $b => ['present' => '1', 'active' => '1']],
        ]));
        self::assertSame(['title' => 'Getypt voor de verplaatsing'], $this->stored('card_carousels', $this->carouselId, 'nl'));
        self::assertSame([[$b, 1], [$a, 1]], $this->cards());

        // Marked for removal and saved: gone, with its words and its tags' words.
        $this->assertSaved($this->saveCarousel($session, [
            'cards' => [$b => ['present' => '1', 'active' => '1', 'remove' => '1'], $a => ['present' => '1', 'active' => '1']],
        ]));
        self::assertSame([[$a, 1]], $this->cards());
        self::assertSame([], $this->stored('carousel_cards', $b, 'nl'));
        self::assertSame([], $this->stored('carousel_cards', $b, 'en'));
        self::assertSame([], $this->stored('carousel_card_tags', $tag, 'nl'));
    }

    public function testANewCardIsADraftWithoutAStoredNumberAndTheScreenGoesToIt(): void
    {
        $a = $this->card('Hout', true);
        $session = $this->signIn(null);

        $response = $this->saveCarousel($session, [
            'title' => 'Eerst opgeslagen',
            'new_card_title' => 'Metaal',
            'editor_action' => 'cards:add',
            'cards' => [$a => ['present' => '1', 'active' => '1']],
        ]);

        self::assertMatchesRegularExpression('#^/admin/carousel-card\.php\?card_id=(\d+)&created=1$#', $response['location']);
        $cards = $this->cards();
        self::assertCount(2, $cards);
        [$newId, $active] = $cards[1];
        self::assertSame(0, $active, 'a new card starts switched off');
        self::assertSame(['title' => 'Metaal'], $this->stored('carousel_cards', $newId, 'nl'), 'no number is stored: an empty one follows the card\'s place');
        self::assertSame(['title' => 'Eerst opgeslagen'], $this->stored('card_carousels', $this->carouselId, 'nl'), 'what was typed is saved first');

        $content = CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1]);
        self::assertSame(['Hout'], array_column($content['cards'], 'title'), 'a draft card is not on the website');

        // Its screen proposes the number it will have once it is switched on.
        $screen = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $newId, $session)['body'];
        self::assertMatchesRegularExpression('/id="card-number"[^>]*value=""[^>]*placeholder="Leeg = 02, de plaats van deze kaart"/', $screen);
    }

    public function testAnEmptyNumberFollowsTheCardsPlaceAndAnOwnLabelStays(): void
    {
        $a = $this->card('Hout', true);
        $b = $this->card('Acryl', true);
        $c = $this->card('Glas', true);
        $sectionKey = explode(':', $this->section)[1];
        $labels = static fn (): array => array_column(CardCarouselContent::forSection(self::KEY, $sectionKey)['cards'], 'index_label', 'title');

        self::assertSame(['Hout' => '01', 'Acryl' => '02', 'Glas' => '03'], $labels(), 'three empty numbers: their places');

        // Reordered in the one form: the numbers follow the new order.
        $session = $this->signIn(null);
        $this->assertSaved($this->saveCarousel($session, [
            'cards' => [$c => ['present' => '1', 'active' => '1'], $a => ['present' => '1', 'active' => '1'], $b => ['present' => '1', 'active' => '1']],
        ]));
        self::assertSame(['Glas' => '01', 'Hout' => '02', 'Acryl' => '03'], $labels());

        // A label an editor typed stays, wherever the card goes; the others still count.
        BlockLocalization::save('carousel_cards', $a, 'nl', ['title' => 'Hout', 'number_label' => 'A']);
        CardCarouselContent::clearCache();
        self::assertSame(['Glas' => '01', 'Hout' => 'A', 'Acryl' => '03'], $labels());
        $this->assertSaved($this->saveCarousel($session, [
            'cards' => [$a => ['present' => '1', 'active' => '1'], $b => ['present' => '1', 'active' => '1'], $c => ['present' => '1', 'active' => '1']],
        ]));
        self::assertSame(['Hout' => 'A', 'Acryl' => '02', 'Glas' => '03'], $labels());

        // Saving a card with its number left empty stores none.
        $this->assertSaved($this->saveCard($session, $b, 'nl', ['is_active' => '1', 'title' => 'Acryl', 'number_label' => '']));
        self::assertSame(['title' => 'Acryl'], $this->stored('carousel_cards', $b, 'nl'));
    }

    public function testARefusedCarouselSaveStoresNothingAndShowsWhatWasTyped(): void
    {
        $a = $this->card('Hout', true);
        $session = $this->signIn(null);

        $this->assertRefused($this->saveCarousel($session, [
            'title' => str_repeat('x', 256),
            'desktop_layout' => 'row',
            'cards' => [$a => ['present' => '1']],
        ]));

        self::assertSame(['title' => 'Nieuwe carrousel — pas deze titel aan'], $this->stored('card_carousels', $this->carouselId, 'nl'), 'nothing of it is stored: the starting title stays');
        self::assertSame('orbit', $this->repository->findById($this->carouselId)['desktop_layout']);
        self::assertSame([[$a, 1]], $this->cards());

        $screen = self::$server->request('GET', '/admin/card-carousel.php?section=' . rawurlencode($this->section), $session);
        self::assertStringContainsString('data-save-bar-unsaved', $screen['body']);
        self::assertMatchesRegularExpression('/name="desktop_layout" value="row" checked/', $screen['body']);
        self::assertDoesNotMatchRegularExpression('/name="cards\[' . $a . '\]\[active\]" value="1" checked/', $screen['body'], 'the switch as it was typed');
    }

    // ------------------------------------------------------------ the card screen

    public function testOneSaveStoresACardsWordsLinkAndTagsTogether(): void
    {
        $card = $this->card('Hout', false);
        $keep = $this->tag($card, 'Eiken');
        $gone = $this->tag($card, 'Grenen');
        $session = $this->signIn(null);
        $pageId = $this->pageId;

        $this->assertSaved($this->saveCard($session, $card, 'nl', [
            'is_active' => '1',
            'number_label' => 'Nieuw',
            'title' => 'Massief hout',
            'body' => 'Warm en tijdloos.',
            'link_label' => 'Bekijk',
            'link_type' => 'page',
            'link_target' => ['page' => (string) $pageId],
            // Renamed, a new one first, the other removed (not sent).
            'tags' => ['new0' => ['label' => 'Duurzaam'], $keep => ['label' => 'Eikenhout']],
        ]));

        self::assertSame(
            ['title' => 'Massief hout', 'body' => 'Warm en tijdloos.', 'link_label' => 'Bekijk', 'number_label' => 'Nieuw'],
            $this->stored('carousel_cards', $card, 'nl')
        );
        $row = $this->repository->findCardById($card);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame('page', $row['link_type']);
        self::assertSame($pageId, (int) $row['link_target_id']);

        $tags = $this->repository->findTagsByCardId($card);
        self::assertCount(2, $tags);
        self::assertSame(['label' => 'Duurzaam'], $this->stored('carousel_card_tags', (int) $tags[0]['id'], 'nl'));
        self::assertSame($keep, (int) $tags[1]['id'], 'a saved tag keeps its id');
        self::assertSame(['label' => 'Eikenhout'], $this->stored('carousel_card_tags', $keep, 'nl'));
        self::assertNull($this->repository->findTagById($gone));
        self::assertSame([], $this->stored('carousel_card_tags', $gone, 'nl'));

        $content = CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1]);
        self::assertSame('Nieuw', $content['cards'][0]['index_label'], 'the card\'s own label');
        self::assertSame(['Duurzaam', 'Eikenhout'], array_column($content['cards'][0]['tags'], 'label'));
        self::assertSame(PageContent::publicUrl((new PageRepository())->findById($pageId)), $content['cards'][0]['link_url'], 'the page, at its own address');
    }

    public function testARefusedCardSaveStoresNothingAndHandsEveryValueBackNextToItsField(): void
    {
        $card = $this->card('Hout', true);
        $tag = $this->tag($card, 'Eiken');
        $session = $this->signIn(null);

        $this->assertRefused($this->saveCard($session, $card, 'nl', [
            'is_active' => '1',
            'title' => '',
            'body' => 'Getypt en niet kwijt',
            'link_type' => 'url',
            'link_url' => '/contact',
            'link_label' => 'Contact',
            'tags' => [$tag => ['label' => 'Eikenhout'], 'new0' => ['label' => 'Nieuw getypt']],
        ]));

        self::assertSame(['title' => 'Hout'], $this->stored('carousel_cards', $card, 'nl'), 'nothing is stored');
        self::assertSame(['label' => 'Eiken'], $this->stored('carousel_card_tags', $tag, 'nl'));
        self::assertCount(1, $this->repository->findTagsByCardId($card), 'no new tag either');

        $screen = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body'];
        self::assertStringContainsString('data-save-bar-unsaved', $screen);
        self::assertStringContainsString('Getypt en niet kwijt', $screen);
        self::assertStringContainsString('value="Eikenhout"', $screen);
        self::assertStringContainsString('value="Nieuw getypt"', $screen);
        self::assertStringContainsString('value="/contact"', $screen);
        self::assertMatchesRegularExpression('/id="card-title"[^>]*aria-invalid="true" aria-describedby="error-title"/', $screen);
        self::assertStringContainsString('id="error-title"', $screen, 'the message is next to the field');
    }

    public function testATagMoveWithoutScriptStoresWhatWasTypedAndEmptyingATagRemovesIt(): void
    {
        $card = $this->card('Hout', true);
        $first = $this->tag($card, 'Eiken');
        $second = $this->tag($card, 'Grenen');
        $session = $this->signIn(null);

        $this->assertSaved($this->saveCard($session, $card, 'nl', [
            'is_active' => '1',
            'title' => 'Hout, getypt',
            'editor_action' => 'tags:up:' . $second,
            'tags' => [$first => ['label' => 'Eiken'], $second => ['label' => 'Grenen']],
        ]));
        self::assertSame(['title' => 'Hout, getypt'], $this->stored('carousel_cards', $card, 'nl'));
        self::assertSame([$second, $first], array_map(static fn (array $tag): int => (int) $tag['id'], $this->repository->findTagsByCardId($card)));

        // × without JavaScript.
        $this->assertSaved($this->saveCard($session, $card, 'nl', [
            'is_active' => '1',
            'title' => 'Hout, getypt',
            'editor_action' => 'tags:remove:' . $first,
            'tags' => [$second => ['label' => 'Grenen'], $first => ['label' => 'Eiken']],
        ]));
        self::assertSame([$second], array_map(static fn (array $tag): int => (int) $tag['id'], $this->repository->findTagsByCardId($card)));
    }

    public function testASaveWritesOnlyTheLanguageOnScreenAndANewTagIsWrittenInTheDefaultLanguage(): void
    {
        $card = $this->card('Hout', true);
        $tag = $this->tag($card, 'Eiken');
        $session = $this->signIn('en');

        $this->assertSaved($this->saveCard($session, $card, 'en', [
            'is_active' => '1',
            'title' => 'Wood',
            'tags' => [$tag => ['label' => 'Oak'], 'new0' => ['label' => 'Sustainable']],
        ]));

        self::assertSame(['title' => 'Hout'], $this->stored('carousel_cards', $card, 'nl'), 'Dutch is untouched');
        self::assertSame(['title' => 'Wood'], $this->stored('carousel_cards', $card, 'en'));
        self::assertSame(['label' => 'Eiken'], $this->stored('carousel_card_tags', $tag, 'nl'));
        self::assertSame(['label' => 'Oak'], $this->stored('carousel_card_tags', $tag, 'en'));

        $new = (int) $this->repository->findTagsByCardId($card)[1]['id'];
        self::assertSame(['label' => 'Sustainable'], $this->stored('carousel_card_tags', $new, 'nl'), 'a new tag is the default language\'s');
        self::assertSame([], $this->stored('carousel_card_tags', $new, 'en'));

        // In a translation an empty tag is a missing translation, not a removal.
        $this->assertSaved($this->saveCard($session, $card, 'en', [
            'is_active' => '1',
            'title' => 'Wood',
            'tags' => [$tag => ['label' => ''], $new => ['label' => '']],
        ]));
        self::assertCount(2, $this->repository->findTagsByCardId($card));
        self::assertSame([], $this->stored('carousel_card_tags', $tag, 'en'));
    }

    public function testAButtonPointsAtABlogPostOrAProductByIdAndATypedAddressStaysTyped(): void
    {
        $card = $this->card('Hout', true);
        $session = $this->signIn(null);
        $sectionKey = explode(':', $this->section)[1];

        $post = $this->blogPost();
        $this->assertSaved($this->saveCard($session, $card, 'nl', [
            'is_active' => '1', 'title' => 'Hout', 'link_label' => 'Lees',
            'link_type' => 'blog_post', 'link_target' => ['blog_post' => (string) $post],
        ]));
        CardCarouselContent::clearCache();
        self::assertStringEndsWith('/zz-carrousel-bericht', CardCarouselContent::forSection(self::KEY, $sectionKey)['cards'][0]['link_url']);

        $product = $this->product();
        $this->assertSaved($this->saveCard($session, $card, 'nl', [
            'is_active' => '1', 'title' => 'Hout', 'link_label' => 'Koop',
            'link_type' => 'product', 'link_target' => ['product' => (string) $product],
        ]));
        CardCarouselContent::clearCache();
        self::assertSame('/product.php?id=' . $product, CardCarouselContent::forSection(self::KEY, $sectionKey)['cards'][0]['link_url']);

        // An id that is no choice of that kind is refused.
        $this->assertRefused($this->saveCard($session, $card, 'nl', [
            'is_active' => '1', 'title' => 'Hout', 'link_type' => 'product', 'link_target' => ['product' => '999999999'],
        ]));
        $this->assertRefused($this->saveCard($session, $card, 'nl', [
            'is_active' => '1', 'title' => 'Hout', 'link_type' => 'url', 'link_url' => 'javascript:alert(1)',
        ]));

        $this->assertSaved($this->saveCard($session, $card, 'nl', [
            'is_active' => '1', 'title' => 'Hout', 'link_label' => 'Contact', 'link_type' => 'url', 'link_url' => '/contact#formulier',
        ]));
        CardCarouselContent::clearCache();
        self::assertSame('/contact#formulier', CardCarouselContent::forSection(self::KEY, $sectionKey)['cards'][0]['link_url']);
        self::assertSame('url', $this->repository->findCardById($card)['link_type']);
    }

    public function testACardWrittenBeforeTheLinkKindExistedKeepsItsNumberAndItsTypedButton(): void
    {
        // Exactly what an existing installation has: an address, no kind, no label.
        $first = $this->card('Hout', true);
        $second = $this->card('Acryl', true);
        Database::connection()->prepare('UPDATE carousel_cards SET link_url = ?, link_type = NULL WHERE id = ?')->execute(['/materialen', $second]);
        BlockLocalization::save('carousel_cards', $second, 'nl', ['title' => 'Acryl', 'link_label' => 'Meer']);
        CardCarouselContent::clearCache();

        $cards = CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1])['cards'];
        self::assertSame(['01', '02'], array_column($cards, 'index_label'), 'the position, as before');
        self::assertSame('/materialen', $cards[1]['link_url']);
        self::assertSame('', $cards[0]['link_url']);
        self::assertGreaterThan(0, $first);
    }

    public function testTheRotatingCarouselStaysTheDefaultAndTheRowLayoutRendersFlat(): void
    {
        $this->card('Hout', true);
        $sectionKey = explode(':', $this->section)[1];

        self::assertSame('orbit', $this->repository->findById($this->carouselId)['desktop_layout'], 'a new carousel is the rotating one');
        self::assertStringContainsString('data-orbit-layout="orbit"', $this->rendered($sectionKey));
        self::assertStringNotContainsString('orbit-carousel--row', $this->rendered($sectionKey));

        $this->repository->updateSettings($this->carouselId, true, 'row');
        CardCarouselContent::clearCache();
        $html = $this->rendered($sectionKey);
        self::assertStringContainsString('class="orbit-carousel orbit-carousel--row"', $html);
        self::assertStringContainsString('data-orbit-layout="row"', $html);

        // Anything that is not a known layout reads as the rotating one.
        Database::connection()->prepare('UPDATE card_carousels SET desktop_layout = ? WHERE id = ?')->execute(['spiral', $this->carouselId]);
        CardCarouselContent::clearCache();
        self::assertStringContainsString('data-orbit-layout="orbit"', $this->rendered($sectionKey));
    }

    // ------------------------------------------------------------ helpers

    private function rendered(string $sectionKey): string
    {
        require_once dirname(__DIR__, 2) . '/partials/section-card-carousel.php';

        ob_start();
        render_section_card_carousel(CardCarouselContent::forSection(self::KEY, $sectionKey));

        return (string) ob_get_clean();
    }

    private function card(string $title, bool $active): int
    {
        $id = $this->repository->createCard($this->carouselId, $active);
        BlockLocalization::save('carousel_cards', $id, 'nl', ['title' => $title]);

        return $id;
    }

    private function tag(int $cardId, string $label): int
    {
        $id = $this->repository->createTag($cardId);
        BlockLocalization::save('carousel_card_tags', $id, 'nl', ['label' => $label]);

        return $id;
    }

    private function blogPost(): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zz-carrousel-bericht',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => (new \DateTimeImmutable('-1 minute'))->format(BlogClock::SQL_FORMAT),
        ]);
        BlogLocalization::savePost($id, 'nl', [BlogLocalization::SLUG => 'zz-carrousel-bericht', BlogLocalization::TITLE => 'Carrouselbericht']);
        BlogLocalization::clearCache();
        $this->postIds[] = $id;

        return $id;
    }

    private function product(): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_carousel_link__',
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => 'Carrouselproduct']);
        ShopLocalization::clearCache();
        $this->productIds[] = $id;

        return $id;
    }

    /** @return list<array{int, int}> [id, is_active] of the carousel's cards, in their stored order */
    private function cards(): array
    {
        return array_map(
            static fn (array $card): array => [(int) $card['id'], (int) $card['is_active']],
            $this->repository->findCardsByCarouselId($this->carouselId)
        );
    }

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        if ($editingLanguage !== null) {
            (new AdminUserRepository())->updateContentEditingLanguage((int) $this->accounts->read($session, 'admin_user_id'), $editingLanguage);
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveCarousel(string $session, array $fields): array
    {
        $response = self::$server->request('POST', '/api/admin/update-card-carousel.php', $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'section' => $this->section,
            'language_code' => 'nl',
            'is_active' => '1',
            'desktop_layout' => 'orbit',
            'cards_present' => '1',
        ]);
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();

        return $response;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveCard(string $session, int $cardId, string $language, array $fields): array
    {
        $response = self::$server->request('POST', '/api/admin/update-carousel-card.php', $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'card_id' => (string) $cardId,
            'language_code' => $language,
            'link_type' => 'none',
            'media_id' => '',
            'tags_present' => '1',
        ]);
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();

        return $response;
    }

    /** @return array<string, string> the stored words of one row in one language, in declaration order */
    private function stored(string $table, int $id, string $language): array
    {
        $words = (new BlockTranslationRepository())->findForOwners([$table => [$id]])[$table][$id][$language] ?? [];

        $ordered = [];
        foreach (array_keys(BlockLocalization::fields($table)) as $field) {
            if (array_key_exists($field, $words)) {
                $ordered[$field] = $words[$field];
            }
        }

        return $ordered;
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $what);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $what = ''): void
    {
        self::assertStringNotContainsString('saved=1', $response['location'], $what);
        self::assertStringStartsWith('/admin/', $response['location'], $what . ': back to the editor');
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
