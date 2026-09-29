<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\BlockTranslationRepository;
use App\Repository\CardCarouselRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\CardCarouselContent;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * A carousel card's label mode (App\Service\Blocks\LabelMode, v0.1.13), over
 * real HTTP through admin/carousel-card.php and its endpoint:
 *
 *   - none prints nothing and keeps no room; 01 and 1 are the card's place
 *     among the cards shown, never stored, so a reorder renumbers;
 *   - an icon is an SVG of the Media Library, printed as decoration (empty
 *     alt, aria-hidden), and counts as the item's use, so the library keeps
 *     it; another mode lets it go;
 *   - own words are per language;
 *   - an unknown mode, "Icoon" without an SVG and a PNG are refused, nothing
 *     is stored, and the choice comes back on the screen;
 *   - four cards with four different modes in one carousel.
 */
final class CardCarouselLabelModeHttpTest extends TestCase
{
    private const KEY = 'zz-card-label-mode-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private CardCarouselRepository $repository;

    private int $carouselId = 0;

    private string $section = '';

    /** @var list<int> */
    private array $mediaIds = [];

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
        $pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Labeltest');
        [$id, $key] = SectionRegistry::create('card_carousel', self::KEY);
        (new PageSectionRepository())->create($pageId, self::KEY, 'card_carousel', $key, $id);
        $this->carouselId = (int) $id;
        $this->section = self::KEY . ':' . $key;
    }

    protected function tearDown(): void
    {
        $this->removePage();
        foreach ($this->mediaIds as $id) {
            Database::connection()->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
        }
        MediaService::clearCache();
        RequestLanguage::reset();
        $this->accounts->forget();
    }

    public function testNumbersAreThePlaceAmongTheCardsShownAndFollowAReorder(): void
    {
        $a = $this->card('Hout');
        $b = $this->card('Acryl');
        $c = $this->card('Glas');
        $d = $this->card('Metaal');
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $a, ['label_mode' => 'none']));
        $this->assertSaved($this->saveCard($session, $b, ['label_mode' => 'padded']));
        $this->assertSaved($this->saveCard($session, $c, ['label_mode' => 'plain']));
        $this->assertSaved($this->saveCard($session, $d, ['label_mode' => 'padded']));
        self::assertSame(['Hout' => '', 'Acryl' => '02', 'Glas' => '3', 'Metaal' => '04'], $this->labels());

        // Nothing stored but the choice.
        foreach ([$a, $b, $c, $d] as $card) {
            self::assertArrayNotHasKey('number_label', $this->stored($card, 'nl'));
        }

        // Reordered: the numbers follow.
        $this->assertSaved($this->saveCarousel($session, [$d, $c, $b, $a]));
        self::assertSame(['Metaal' => '01', 'Glas' => '2', 'Acryl' => '03', 'Hout' => ''], $this->labels());

        // A card switched off is no place: the ones after it move up.
        $this->assertSaved($this->saveCarousel($session, [$d, $c, $b, $a], [$d]));
        self::assertSame(['Glas' => '1', 'Acryl' => '02', 'Hout' => ''], $this->labels());

        // Ten cards: two digits stay two digits.
        $this->assertSaved($this->saveCarousel($session, [$d, $c, $b, $a]));
        $last = 0;
        foreach (range(5, 10) as $n) {
            $last = $this->card('Kaart ' . $n);
        }
        $this->assertSaved($this->saveCard($session, $last, ['label_mode' => 'padded'], 'Kaart 10'));
        self::assertSame('10', $this->labels()['Kaart 10']);
    }

    public function testNoLabelKeepsNoRoomAndMixedModesRenderSideBySide(): void
    {
        $a = $this->card('Hout');
        $b = $this->card('Acryl');
        $c = $this->card('Glas');
        $d = $this->card('Metaal');
        $svg = $this->libraryItem('image/svg+xml', 'svg');
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $a, ['label_mode' => 'none', 'number_label' => 'Blijft bewaard']));
        $this->assertSaved($this->saveCard($session, $b, ['label_mode' => 'padded']));
        $this->assertSaved($this->saveCard($session, $c, ['label_mode' => 'icon', 'label_icon_media_id' => (string) $svg]));
        $this->assertSaved($this->saveCard($session, $d, ['label_mode' => 'custom', 'number_label' => 'Nieuw']));

        $html = $this->rendered();
        $icon = htmlspecialchars((string) MediaService::find($svg)?->publicPath(), ENT_QUOTES, 'UTF-8');

        self::assertSame(2, substr_count($html, 'class="service-row__index"'), 'one number, one own word: no empty label for none or icon');
        self::assertStringContainsString('<span class="service-row__index">02</span>', $html);
        self::assertStringContainsString('<span class="service-row__index">Nieuw</span>', $html);
        self::assertStringNotContainsString('Blijft bewaard', $html, 'own words kept, not shown under none');
        self::assertSame('Blijft bewaard', $this->stored($a, 'nl')['number_label']);
        self::assertStringContainsString('<span class="orbit-card__label-icon" aria-hidden="true"><img src="' . $icon . '" alt=""', $html, 'decoration next to the title, never read twice');
    }

    public function testAnIconIsAnSvgOfTheLibraryThatTheLibraryKeepsWhileItIsUsed(): void
    {
        $card = $this->card('Hout');
        $svg = $this->libraryItem('image/svg+xml', 'svg');
        $png = $this->libraryItem('image/png', 'png');
        $session = $this->signIn();

        foreach (['' => 'no icon chosen', (string) $png => 'a PNG', '999999999' => 'no such item'] as $mediaId => $what) {
            $this->assertRefused($this->saveCard($session, $card, ['label_mode' => 'icon', 'label_icon_media_id' => (string) $mediaId]), $what);
            self::assertSame('none', $this->row($card)['label_mode'], $what . ': nothing stored');
        }
        $screen = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body'];
        self::assertStringContainsString('Kies een icoon (SVG) uit de mediabibliotheek.', $screen);
        self::assertMatchesRegularExpression('/<option value="icon" selected>Icoon<\/option>/', $screen, 'the choice comes back');

        $this->assertSaved($this->saveCard($session, $card, ['label_mode' => 'icon', 'label_icon_media_id' => (string) $svg]));
        self::assertSame(['icon', $svg], [$this->row($card)['label_mode'], (int) $this->row($card)['label_icon_media_id']]);

        $usages = (new ContentBlockMediaUsage())->usagesFor([$svg]);
        self::assertCount(1, $usages[$svg] ?? [], 'the library knows the card uses the icon');
        self::assertSame('/admin/carousel-card.php?card_id=' . $card, $usages[$svg][0]->editUrl);
        self::assertStringContainsString('label-icoon', $usages[$svg][0]->label);

        try {
            Database::connection()->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $svg]);
            self::fail('the database refuses to delete a used icon');
        } catch (\PDOException) {
            self::assertNotNull(MediaService::find($svg));
        }

        // Another mode lets the icon go.
        $this->assertSaved($this->saveCard($session, $card, ['label_mode' => 'plain', 'label_icon_media_id' => (string) $svg]));
        self::assertNull($this->row($card)['label_icon_media_id']);
        self::assertSame([], (new ContentBlockMediaUsage())->usagesFor([$svg])[$svg] ?? []);
    }

    public function testOwnWordsArePerLanguageAndAnUnknownModeIsRefused(): void
    {
        $card = $this->card('Hout');
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $card, ['label_mode' => 'custom', 'number_label' => 'Nieuw']));
        $this->assertSaved($this->saveCard($session, $card, ['label_mode' => 'custom', 'number_label' => 'New', 'title' => 'Wood'], null, 'en'));
        self::assertSame('Nieuw', $this->stored($card, 'nl')['number_label']);
        self::assertSame('New', $this->stored($card, 'en')['number_label']);

        self::assertSame(['Hout' => 'Nieuw'], $this->labels());
        RequestLanguage::set('en', true);
        CardCarouselContent::clearCache();
        self::assertSame(['Wood' => 'New'], $this->labels());
        RequestLanguage::reset();

        $this->assertRefused($this->saveCard($session, $card, ['label_mode' => 'sideways']), 'an unknown mode');
        self::assertSame('custom', $this->row($card)['label_mode']);
    }

    // ---------------------------------------------------------- helpers

    private function card(string $title): int
    {
        $id = $this->repository->createCard($this->carouselId, true);
        BlockLocalization::save('carousel_cards', $id, 'nl', ['title' => $title]);

        return $id;
    }

    /** @return array<string, string> title => printed label, in order */
    private function labels(): array
    {
        CardCarouselContent::clearCache();

        return array_column(CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1])['cards'], 'index_label', 'title');
    }

    private function rendered(): string
    {
        require_once dirname(__DIR__, 2) . '/partials/section-card-carousel.php';
        CardCarouselContent::clearCache();

        ob_start();
        render_section_card_carousel(CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1]));

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function row(int $cardId): array
    {
        return (array) $this->repository->findCardById($cardId);
    }

    /** @return array<string, string> */
    private function stored(int $cardId, string $language): array
    {
        return (new BlockTranslationRepository())->findForOwners(['carousel_cards' => [$cardId]])['carousel_cards'][$cardId][$language] ?? [];
    }

    private function libraryItem(string $mimeType, string $extension): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__label_mode_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'icoon.' . $extension,
            'mime_type' => $mimeType,
            'alt_text' => '',
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    /**
     * @param list<int> $order
     * @param list<int> $off
     *
     * @return array{status: int, location: string}
     */
    private function saveCarousel(string $session, array $order, array $off = []): array
    {
        $cards = [];
        foreach ($order as $id) {
            $cards[$id] = ['present' => '1'] + (in_array($id, $off, true) ? [] : ['active' => '1']);
        }

        $response = self::$server->request('POST', '/api/admin/update-card-carousel.php', $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'section' => $this->section,
            'language_code' => 'nl',
            'is_active' => '1',
            'desktop_layout' => 'orbit',
            'cards_present' => '1',
            'cards' => $cards,
        ]);
        CardCarouselContent::clearCache();

        return $response;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, location: string}
     */
    private function saveCard(string $session, int $cardId, array $fields, ?string $title = null, string $language = 'nl'): array
    {
        $response = self::$server->request('POST', '/api/admin/update-carousel-card.php', $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'card_id' => (string) $cardId,
            'language_code' => $language,
            'is_active' => '1',
            'title' => $title ?? BlockLocalization::raw('carousel_cards', $cardId, 'title', 'nl'),
            'link_type' => 'none',
            'media_id' => '',
            'tags_present' => '1',
        ]);
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();
        MediaService::clearCache();

        return $response;
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
        self::assertStringStartsWith('/admin/', $response['location'], $what);
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);

        if ($page !== null) {
            PageService::delete($page);
        }

        PageContent::clearCache();
        BlockLocalization::clearCache();
    }
}
