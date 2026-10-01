<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\CardCarouselRepository;
use App\Repository\HomepageHeroRepository;
use App\Repository\HoverCardGridRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ResponsiveImageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\CardCarouselContent;
use App\Service\HomepageHeroContent;
use App\Service\HoverCardGridContent;
use App\Service\Language\AdminTranslator;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\SavedRedirect;

/**
 * The "Afbeeldingsweergave" field over real HTTP (Responsive Media 2.0,
 * admin/_responsive_image_field.php), on the carousel card as its main
 * example and on the Hover kaarten row and the homepage Hero:
 *
 *  - the screen: nine one-click points with aria-pressed, two named sliders
 *    that are the value, a crop frame hidden from a screen reader, a closed
 *    "Op een telefoon" part that says whether a phone differs, in Dutch and
 *    in English;
 *  - a save stores the point, the fit and the phone's own picture and point,
 *    and the page prints one <picture> for exactly that card;
 *  - a phone picture must be a picture of the library: an unknown id, a video
 *    or a document is refused next to the field and nothing is stored; a
 *    point is clamped, a word is refused;
 *  - a picture only a phone shows is a use of it and cannot be deleted;
 *  - the Kaarten-carrousel's "Beeldverhouding op een rij" is a closed list,
 *    becomes a class and shapes the card editor's frames.
 *
 * The pages, blocks, accounts and library items are this test's own and are
 * removed in tearDown(); the homepage Hero's image columns are put back as
 * they were. Without a server the test skips itself.
 */
final class ResponsiveImageEditorHttpTest extends TestCase
{
    private const KEY = 'zz-responsive-image-editor-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private CardCarouselRepository $carousels;

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
        $this->carousels = new CardCarouselRepository();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $this->removePage();
        $pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Weergavetest'
        );

        [$id, $key] = SectionRegistry::create('card_carousel', self::KEY);
        (new PageSectionRepository())->create($pageId, self::KEY, 'card_carousel', $key, $id);
        $this->carouselId = (int) $id;
        $this->section = self::KEY . ':' . $key;
    }

    protected function tearDown(): void
    {
        // The blocks hold the library items (ON DELETE RESTRICT): page first.
        $this->removePage();

        $media = new MediaRepository();
        foreach ($this->mediaIds as $id) {
            $media->delete($id);
        }
        $this->mediaIds = [];
        MediaService::clearCache();

        $this->accounts->forget();
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();
        HoverCardGridContent::clearCache();
        PageContent::clearCache();
    }

    // ---------------------------------------------------------------- screen

    public function testTheFieldOffersNinePointsTwoNamedSlidersAndAClosedPhonePart(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();

        $screen = $this->cardScreen($session, $card);
        $field = '//fieldset[@data-rm][@data-rm-picker="media_id"]';

        self::assertSame(['Afbeeldingsweergave'], $this->texts($screen, $field . '/legend/text()[normalize-space()]'));
        self::assertSame(['1'], $this->values($screen, $field . '//input[@type="hidden"][@name="image_presentation"]/@value'));

        // Nine one-click points, the middle pressed, in a named group.
        $desktop = $field . '//*[@data-rm-focus="desktop"]';
        self::assertSame(9, $screen->query($desktop . '//button[@data-rm-preset][@type="button"]')->length);
        self::assertSame(['true'], $this->values($screen, $desktop . '//button[@data-rm-preset][@data-x="50"][@data-y="50"]/@aria-pressed'));
        self::assertSame(8, $screen->query($desktop . '//button[@data-rm-preset][@aria-pressed="false"]')->length);
        self::assertSame(['group'], $this->values($screen, $desktop . '//*[@data-rm-presets]/@role'));
        self::assertSame(1, $screen->query($desktop . '//*[@data-rm-presets][@aria-labelledby]')->length);

        // The sliders are the value: whole percentages, each with a name.
        foreach (['image_focus_x' => 'Horizontaal', 'image_focus_y' => 'Verticaal'] as $name => $label) {
            $slider = $desktop . '//input[@type="range"][@name="' . $name . '"]';
            self::assertSame(['0', '100', '1', '50'], [
                $this->values($screen, $slider . '/@min')[0] ?? null,
                $this->values($screen, $slider . '/@max')[0] ?? null,
                $this->values($screen, $slider . '/@step')[0] ?? null,
                $this->values($screen, $slider . '/@value')[0] ?? null,
            ], $name);
            $id = $this->values($screen, $slider . '/@id')[0] ?? '';
            self::assertSame($label, trim($this->text($screen, '//label[@for="' . $id . '"]')), $name);
        }

        // The crop frame is for the eye; a screen reader has the sliders.
        self::assertSame(['true'], $this->values($screen, $desktop . '//*[@data-rm-frame]/@aria-hidden'));
        self::assertSame([''], $this->values($screen, $desktop . '//img[@data-rm-preview]/@alt'));

        // The phone part: closed, and it says a phone shows the same.
        $phone = $field . '//details[@data-rm-mobile]';
        self::assertSame(1, $screen->query($phone)->length);
        self::assertSame(0, $screen->query($phone . '[@open]')->length);
        self::assertStringContainsString('Op een telefoon', $this->text($screen, $phone . '/summary'));
        self::assertStringContainsString('zoals op een groot scherm', $this->text($screen, $phone . '/summary'));
        self::assertSame(['desktop'], $this->values($screen, $phone . '//input[@name="image_mobile_source"][@checked]/@value'));
        self::assertSame(['cover'], $this->values($screen, $field . '//input[@name="image_fit"][@checked]/@value'));

        self::assertSame(1, $screen->query('//script[contains(@src, "/admin/assets/responsive-image.js")]')->length);
    }

    public function testTheFieldSpeaksEnglishInAnEnglishCms(): void
    {
        $card = $this->card('Hout', $this->libraryItem('image/jpeg'));
        $session = $this->signIn();
        (new AdminUserRepository())->updateInterfaceLanguage((int) $this->accounts->read($session, 'admin_user_id'), 'en');

        $screen = $this->cardScreen($session, $card);
        $field = '//fieldset[@data-rm]';

        self::assertSame([AdminTranslator::trans('media.responsive.legend', [], 'en')], $this->texts($screen, $field . '/legend/text()[normalize-space()]'));
        self::assertNotSame(AdminTranslator::trans('media.responsive.legend', [], 'en'), AdminTranslator::trans('media.responsive.legend', [], 'nl'));
        self::assertStringNotContainsString('Op een telefoon', $this->text($screen, $field . '//details[@data-rm-mobile]/summary'));
        self::assertStringContainsString(AdminTranslator::trans('media.responsive.mobile', [], 'en'), $this->text($screen, $field . '//details[@data-rm-mobile]/summary'));
    }

    // ------------------------------------------------------------------ save

    public function testASaveStoresThePointTheFitAndThePhonesOwnPictureAndPoint(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $phone = $this->libraryItem('image/jpeg');
        $plain = $this->card('Acryl', $picture);
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $card, $picture, [
            'image_focus_x' => '30', 'image_focus_y' => '40', 'image_fit' => 'contain',
            'image_mobile_source' => 'own', 'image_mobile_media_id' => (string) $phone,
            'image_mobile_focus_x' => '10', 'image_mobile_focus_y' => '90', 'image_mobile_fit' => 'cover',
        ]));

        $row = $this->carousels->findCardById($card);
        self::assertSame(
            [30, 40, 'contain', $phone, 10, 90, 'cover'],
            [(int) $row['image_focus_x'], (int) $row['image_focus_y'], $row['image_fit'], (int) $row['image_mobile_media_id'], (int) $row['image_mobile_focus_x'], (int) $row['image_mobile_focus_y'], $row['image_mobile_fit']]
        );

        // The screen shows it back, the phone part open and saying so.
        $screen = $this->cardScreen($session, $card);
        self::assertSame(1, $screen->query('//details[@data-rm-mobile][@open]')->length);
        self::assertStringContainsString('eigen instellingen', $this->text($screen, '//details[@data-rm-mobile]/summary'));
        self::assertSame(['own'], $this->values($screen, '//input[@name="image_mobile_source"][@checked]/@value'));
        self::assertSame(0, $screen->query('//*[@data-rm-focus="desktop"]//button[@data-rm-preset][@aria-pressed="true"]')->length, '30/40 is none of the nine');

        // The page: one <picture> for this card, the other card as it always was.
        $this->carousels->updateSettings($this->carouselId, true, 'orbit');
        $html = $this->rendered();
        self::assertSame(1, substr_count($html, '<picture class="rm-picture">'));
        // The ring turns compact at its own flat breakpoint, not at 640px.
        self::assertMatchesRegularExpression('#<source media="\(max-width: 699px\)" srcset="/assets/media/zz-rm-editor-\d+\.jpg"#', $html);
        self::assertStringContainsString('style="object-position: 30% 40%; object-fit: contain; --rm-mobile-position: 10% 90%; --rm-mobile-fit: cover;" data-rm-mobile-position data-rm-mobile-fit>', $html);
        $plainPath = MediaService::find($picture)?->publicPath();
        self::assertStringContainsString('<img src="' . $plainPath . '" alt="Foto" width="1600" height="900" loading="lazy">', $html, 'a card without settings prints its old <img>');
        self::assertNotSame($plain, $card);
    }

    public function testAPhonePictureMustBeAPictureOfTheLibrary(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $video = $this->libraryItem('video/mp4');
        $document = $this->libraryItem('application/pdf');
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();
        $before = $this->carousels->findCardById($card);

        foreach (['999999999' => 'an id that names nothing', (string) $video => 'a video', (string) $document => 'a document', '12 OR 1=1' => 'no number'] as $posted => $what) {
            $response = $this->saveCard($session, $card, $picture, ['image_mobile_source' => 'own', 'image_mobile_media_id' => $posted]);
            self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $response['location'], $what);
            self::assertSame($before, $this->carousels->findCardById($card), $what . ': nothing is stored');
        }

        // The message stands next to the phone's picture.
        $screen = $this->cardScreen($session, $card);
        self::assertStringContainsString(AdminTranslator::trans('media.responsive.error_mobile_media', [], 'nl'), $this->text($screen, '//fieldset[@data-rm]'));
    }

    public function testAPointIsClampedAWordIsRefusedAndSoIsAFitOutsideTheList(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $card, $picture, ['image_focus_x' => '180', 'image_focus_y' => '-3']));
        $row = $this->carousels->findCardById($card);
        self::assertSame([100, 0], [(int) $row['image_focus_x'], (int) $row['image_focus_y']]);

        foreach ([['image_focus_x' => 'links'], ['image_fit' => 'stretch'], ['image_mobile_fit' => 'fill']] as $refused) {
            $response = $this->saveCard($session, $card, $picture, $refused);
            self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $response['location'], (string) json_encode($refused));
        }
        self::assertSame($row, $this->carousels->findCardById($card));
    }

    public function testBackToTheDesktopPictureDropsThePhonePictureAndAPointNeedsItsSwitch(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $phone = $this->libraryItem('image/jpeg');
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $card, $picture, ['image_mobile_source' => 'own', 'image_mobile_media_id' => (string) $phone, 'image_mobile_focus_x' => '20', 'image_mobile_focus_y' => '30']));

        // The picker still holds the phone picture, the choice says desktop.
        $this->assertSaved($this->saveCard($session, $card, $picture, ['image_mobile_source' => 'desktop', 'image_mobile_media_id' => (string) $phone, 'image_mobile_focus_x' => '20', 'image_mobile_focus_y' => '30']));
        $row = $this->carousels->findCardById($card);
        self::assertSame([null, null, null], [$row['image_mobile_media_id'], $row['image_mobile_focus_x'], $row['image_mobile_focus_y']]);

        $this->assertSaved($this->saveCard($session, $card, $picture, ['image_mobile_focus_own' => '1', 'image_mobile_focus_x' => '20', 'image_mobile_focus_y' => '30']));
        $row = $this->carousels->findCardById($card);
        self::assertSame([null, 20, 30], [$row['image_mobile_media_id'], (int) $row['image_mobile_focus_x'], (int) $row['image_mobile_focus_y']], 'the desktop picture with a point of its own on a phone');
    }

    public function testAPictureOnlyAPhoneShowsIsAUseOfItAndCannotBeDeleted(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $phone = $this->libraryItem('image/jpeg');
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();

        $this->assertSaved($this->saveCard($session, $card, $picture, ['image_mobile_source' => 'own', 'image_mobile_media_id' => (string) $phone]));

        $service = new MediaService(new MediaRepository());
        $usages = $service->usagesOf($phone);
        self::assertCount(1, $usages);
        self::assertStringContainsString('(telefoon)', $usages[0]->label);
        self::assertFalse($service->delete($phone)['deleted']);

        try {
            Database::connection()->prepare('DELETE FROM media WHERE id = ?')->execute([$phone]);
            self::fail('the foreign key let a phone picture in use go');
        } catch (\PDOException $e) {
            self::assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
    }

    // ------------------------------------------------------- carousel ratio

    public function testTheCarouselsRowShapeIsAClosedListThatBecomesAClassAndShapesTheFrames(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();

        // The default adds nothing: an existing carousel renders as it did.
        self::assertSame('auto', $this->carousels->findById($this->carouselId)['flat_image_ratio']);
        self::assertStringNotContainsString('orbit-carousel--flat', $this->rendered());
        self::assertSame(['--admin-rm-desktop-ratio: 300 / 148; --admin-rm-tablet-ratio: 240 / 120; --admin-rm-mobile-ratio: 308 / 120;'], $this->values($this->cardScreen($session, $card), '//fieldset[@data-rm]/@style'));

        $this->assertSaved($this->saveCarousel($session, ['desktop_layout' => 'orbit', 'flat_image_ratio' => '4-3']));
        self::assertSame('4-3', $this->carousels->findById($this->carouselId)['flat_image_ratio']);
        self::assertStringContainsString('class="orbit-carousel orbit-carousel--flat orbit-carousel--flat-4-3"', $this->rendered());
        self::assertSame(['--admin-rm-desktop-ratio: 300 / 148; --admin-rm-tablet-ratio: 240 / 120; --admin-rm-mobile-ratio: 4 / 3;'], $this->values($this->cardScreen($session, $card), '//fieldset[@data-rm]/@style'), 'the ring keeps its height; a phone takes the shape');

        $this->assertSaved($this->saveCarousel($session, ['desktop_layout' => 'row', 'flat_image_ratio' => '4-3']));
        self::assertSame(['--admin-rm-desktop-ratio: 4 / 3; --admin-rm-tablet-ratio: 4 / 3; --admin-rm-mobile-ratio: 4 / 3;'], $this->values($this->cardScreen($session, $card), '//fieldset[@data-rm]/@style'), 'side by side, every screen takes it');

        // Five words, one of them chosen; anything else is refused; a form without it keeps it.
        $screen = self::$server->request('GET', '/admin/card-carousel.php?section=' . urlencode($this->section), $session)['body'];
        self::assertSame(5, substr_count($screen, 'name="flat_image_ratio"'));
        self::assertMatchesRegularExpression('/name="flat_image_ratio" value="4-3" checked/', $screen);

        self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $this->saveCarousel($session, ['desktop_layout' => 'row', 'flat_image_ratio' => '2-1'])['location']);
        $this->assertSaved($this->saveCarousel($session, ['desktop_layout' => 'row']));
        self::assertSame('4-3', $this->carousels->findById($this->carouselId)['flat_image_ratio']);
    }

    /**
     * The phone picture is a card's compact picture: the ring shows it below
     * the breakpoint only, "Kaarten naast elkaar" at every width, with its own
     * point and fit; a card without one shows its desktop picture everywhere.
     */
    public function testCardsSideBySideShowTheCompactPictureOnEveryScreen(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $phone = $this->libraryItem('image/jpeg');
        $plain = $this->card('Acryl', $picture);
        $card = $this->card('Hout', $picture);
        $session = $this->signIn();
        $this->assertSaved($this->saveCard($session, $card, $picture, [
            'image_focus_x' => '30', 'image_focus_y' => '40',
            'image_mobile_source' => 'own', 'image_mobile_media_id' => (string) $phone,
            'image_mobile_focus_x' => '50', 'image_mobile_focus_y' => '0', 'image_mobile_fit' => 'contain',
        ]));
        $desktopPath = (string) MediaService::find($picture)?->publicPath();
        $phonePath = (string) MediaService::find($phone)?->publicPath();
        $plainImg = '<img src="' . $desktopPath . '" alt="Foto" width="1600" height="900" loading="lazy">';

        // The ring: the compact picture from where it turns into its flat strip.
        foreach (['auto', '4-3'] as $ratio) {
            $this->assertSaved($this->saveCarousel($session, ['desktop_layout' => 'orbit', 'flat_image_ratio' => $ratio]));
            $html = $this->rendered();
            self::assertSame(1, substr_count($html, '<source media="(max-width: 699px)" srcset="' . $phonePath . '"'), 'ring, ' . $ratio);
            self::assertStringContainsString('<img src="' . $desktopPath . '" alt="Foto" width="1600" height="900" loading="lazy" style="object-position: 30% 40%; --rm-mobile-position: 50% 0%; --rm-mobile-fit: contain;" data-rm-mobile-position data-rm-mobile-fit>', $html);
            self::assertStringContainsString($plainImg, $html, 'a card without an override keeps its desktop picture');
        }

        // Side by side: the phone picture at every width, as the one picture.
        foreach (['auto', '4-3', '3-4', '16-9'] as $ratio) {
            $this->assertSaved($this->saveCarousel($session, ['desktop_layout' => 'row', 'flat_image_ratio' => $ratio]));
            $html = $this->rendered();
            self::assertStringNotContainsString('<source', $html, 'side by side, ' . $ratio);
            self::assertStringNotContainsString('rm-picture', $html);
            self::assertStringContainsString('<img src="' . $phonePath . '" alt="Foto" width="1600" height="900" loading="lazy" style="object-position: 50% 0%; object-fit: contain;">', $html, 'the phone picture\'s point and fit, ' . $ratio);
            self::assertStringContainsString($plainImg, $html, 'a card without an override keeps its desktop picture, ' . $ratio);
            self::assertStringContainsString($ratio === 'auto' ? 'class="orbit-carousel orbit-carousel--row"' : 'orbit-carousel--flat-' . $ratio, $html, 'the row shape stays');
        }
        self::assertNotSame($plain, $card);

        // The card editor says where the picture shows.
        self::assertStringContainsString(
            htmlspecialchars(AdminTranslator::trans('block_carousel.mobile_picker_help', [], 'nl'), ENT_QUOTES, 'UTF-8'),
            self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body']
        );
    }

    // ------------------------------------------------------ the other places

    public function testAHoverCardsMainPictureTakesItsPresentationInItsOwnRow(): void
    {
        $picture = $this->libraryItem('image/jpeg');
        $phone = $this->libraryItem('image/jpeg');
        $session = $this->signIn();

        [$id, $key] = SectionRegistry::create('hover_card_grid', self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, 'hover_card_grid', $key, $id);
        $section = self::KEY . ':' . $key;

        $card = [
            'present' => '1', 'media_id' => (string) $picture, 'hover_media_id' => '', 'badge' => '', 'title' => 'Kaart', 'body' => '',
            'link_type' => 'none', 'link_target' => [], 'link_url' => '', 'link_label' => '',
            'image_presentation' => '1', 'image_focus_x' => '0', 'image_focus_y' => '50', 'image_fit' => 'cover',
            'image_mobile_source' => 'own', 'image_mobile_media_id' => (string) $phone, 'image_mobile_focus_x' => '50', 'image_mobile_focus_y' => '100', 'image_mobile_fit' => '',
        ];
        $fields = [
            'section' => $section, 'language_code' => 'nl', 'eyebrow' => '', 'title' => '', 'lead' => '',
            'layout' => 'overlay', 'shape' => 'rounded', 'columns' => '3', 'overlay' => 'medium', 'effect' => 'normal', 'header_align' => 'left',
            'cards_present' => '1', 'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
        ];

        $response = self::$server->request('POST', '/api/admin/update-hover-card-grid.php', $session, $fields + ['cards' => ['new0' => $card]]);
        $this->assertSaved($response);
        HoverCardGridContent::clearCache();

        $items = (new HoverCardGridRepository())->findItemsByGridId((int) $id);
        self::assertCount(1, $items);
        self::assertSame([0, 50, $phone, 50, 100], [(int) $items[0]['image_focus_x'], (int) $items[0]['image_focus_y'], (int) $items[0]['image_mobile_media_id'], (int) $items[0]['image_mobile_focus_x'], (int) $items[0]['image_mobile_focus_y']]);

        // A forged phone picture is refused at its card, and nothing is stored.
        $itemId = (string) $items[0]['id'];
        $response = self::$server->request('POST', '/api/admin/update-hover-card-grid.php', $session, $fields + ['cards' => [$itemId => ['image_mobile_media_id' => '999999999'] + $card]]);
        self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $response['location']);
        self::assertSame($items, (new HoverCardGridRepository())->findItemsByGridId((int) $id));
        $screen = self::$server->request('GET', '/admin/hover-card-grid.php?section=' . urlencode($section), $session)['body'];
        self::assertStringContainsString(AdminTranslator::trans('media.responsive.error_mobile_media', [], 'nl'), $screen);
    }

    public function testTheHomepageHeroOffersAPointAndAPhonePictureButNoFit(): void
    {
        $session = $this->signIn();
        $screen = $this->screen(self::$server->request('GET', '/admin/homepage-hero.php', $session)['body']);

        self::assertSame(1, $screen->query('//form[@data-homepage-hero-form]//*[@data-media-panel="image"]//fieldset[@data-rm][@data-rm-picker="media_id"]')->length, 'only an image has a presentation: behind a video it is the poster');
        self::assertSame(0, $screen->query('//fieldset[@data-rm]//input[@name="image_fit"]')->length);
        self::assertSame(1, $screen->query('//fieldset[@data-rm]//input[@name="image_mobile_media_id"]')->length);

        // The page prints the stored presentation, eagerly and at 800 x 1000 as before.
        $repository = new HomepageHeroRepository();
        $row = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
        self::assertNotNull($row);
        $picture = $this->libraryItem('image/jpeg');
        $phone = $this->libraryItem('image/jpeg');
        $was = ['media_id' => $row['media_id'], 'image_path' => $row['image_path'], 'media_type' => $row['media_type'], 'is_active' => $row['is_active']]
            + ResponsiveImage::fromRow($row, HomepageHeroContent::imageSlot())->toRow(HomepageHeroContent::imageSlot());

        try {
            Database::connection()->prepare("UPDATE homepage_hero SET media_id = ?, image_path = '', media_type = 'image', is_active = 1 WHERE id = ?")->execute([$picture, $row['id']]);
            (new ResponsiveImageRepository())->save('homepage_hero', (int) $row['id'], HomepageHeroContent::imageSlot(), new ResponsiveImage(50, 0, $phone, 0, 100));
            HomepageHeroContent::clearCache();
            MediaService::clearCache();

            require_once dirname(__DIR__, 2) . '/partials/section-homepage-hero.php';
            ob_start();
            render_section_homepage_hero(HomepageHeroContent::current());
            $html = (string) ob_get_clean();

            self::assertMatchesRegularExpression('#<picture class="rm-picture"><source media="\(max-width: 640px\)" srcset="[^"]+"[^>]*><img src="[^"]+" alt="[^"]*" width="800" height="1000" loading="eager" style="object-position: 50% 0%; --rm-mobile-position: 0% 100%;" data-rm-mobile-position></picture>#', $html);
        } finally {
            $columns = array_keys($was);
            Database::connection()->prepare(
                'UPDATE homepage_hero SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)) . ' WHERE id = ?'
            )->execute([...array_values($was), $row['id']]);
            HomepageHeroContent::clearCache();
        }
    }

    // --------------------------------------------------------------- helpers

    private function libraryItem(string $mime): int
    {
        $extension = ['image/jpeg' => 'jpg', 'video/mp4' => 'mp4', 'application/pdf' => 'pdf'][$mime];
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/zz-rm-editor-' . bin2hex(random_bytes(4)) . '.' . $extension,
            'mime_type' => $mime,
            'width' => $mime === 'image/jpeg' ? 1600 : null,
            'height' => $mime === 'image/jpeg' ? 900 : null,
            'alt_text' => 'Foto',
        ]);
        // A name the render assertions can find.
        Database::connection()->prepare('UPDATE media SET path = ? WHERE id = ?')->execute(['assets/media/zz-rm-editor-' . $id . '.' . $extension, $id]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function card(string $title, int $mediaId): int
    {
        $id = $this->carousels->createCard($this->carouselId, true);
        BlockLocalization::save('carousel_cards', $id, 'nl', ['title' => $title]);
        Database::connection()->prepare('UPDATE carousel_cards SET media_id = ? WHERE id = ?')->execute([$mediaId, $id]);
        CardCarouselContent::clearCache();

        return $id;
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    /**
     * One card as its editor posts it, the field on screen: the presentation
     * parts as the page printed them unless $presentation says otherwise.
     *
     * @param array<string, string> $presentation
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveCard(string $session, int $cardId, int $mediaId, array $presentation): array
    {
        $response = self::$server->request('POST', '/api/admin/update-carousel-card.php', $session, $presentation + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'card_id' => (string) $cardId,
            'language_code' => 'nl',
            'is_active' => '1',
            'title' => 'Hout',
            'media_id' => (string) $mediaId,
            'image_alt' => '',
            'link_type' => 'none',
            'tags_present' => '1',
            'image_presentation' => '1',
            'image_focus_x' => '50',
            'image_focus_y' => '50',
            'image_fit' => 'cover',
            'image_mobile_source' => 'desktop',
            'image_mobile_media_id' => '',
            'image_mobile_focus_x' => '50',
            'image_mobile_focus_y' => '50',
            'image_mobile_fit' => '',
        ]);
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();
        MediaService::clearCache();

        return $response;
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveCarousel(string $session, array $fields): array
    {
        $response = self::$server->request('POST', '/api/admin/update-card-carousel.php', $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'section' => $this->section,
            'language_code' => 'nl',
            'is_active' => '1',
        ]);
        CardCarouselContent::clearCache();

        return $response;
    }

    private function cardScreen(string $session, int $cardId): \DOMXPath
    {
        $response = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $cardId, $session);
        self::assertSame(200, $response['status'], $response['body']);

        return $this->screen($response['body']);
    }

    private function screen(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    /** @return list<string> */
    private function values(\DOMXPath $screen, string $query): array
    {
        $values = [];
        foreach ($screen->query($query) ?: [] as $node) {
            $values[] = (string) $node->nodeValue;
        }

        return $values;
    }

    /** @return list<string> */
    private function texts(\DOMXPath $screen, string $query): array
    {
        return array_map('trim', $this->values($screen, $query));
    }

    private function text(\DOMXPath $screen, string $query): string
    {
        $nodes = $screen->query($query);
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length, $query);

        return (string) preg_replace('/\s+/', ' ', (string) $nodes->item(0)?->textContent);
    }

    private function rendered(): string
    {
        require_once dirname(__DIR__, 2) . '/partials/section-card-carousel.php';

        ob_start();
        render_section_card_carousel(CardCarouselContent::forSection(self::KEY, explode(':', $this->section)[1]));

        return (string) ob_get_clean();
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, $response['location'], $what);
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
