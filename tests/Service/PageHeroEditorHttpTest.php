<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageHeroRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaService;
use App\Service\PageHeroContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Paginakop editor and its endpoint, used the way an editor uses them:
 * the form offers exactly the closed lists with the stored choices selected,
 * a save stores every choice, the image and an empty eyebrow and comes back
 * into the same form, and whatever the form cannot send is refused without
 * writing anything.
 *
 * Over real HTTP against PHP's built-in server (Tests\Support\BuiltInServer),
 * because an endpoint's answer is its redirect and its session flash, and the
 * editor's answer is its markup. The page, its header, the media rows and the
 * accounts are this test's own and are removed again in tearDown(). When the
 * server cannot be started the test skips itself, like the HTTP tier does
 * (TESTING.md).
 */
final class PageHeroEditorHttpTest extends TestCase
{
    /** A page no editor has, so its header can never be a real one. */
    private const TEST_PAGE = '__test_page_hero_editor__';

    private const ENDPOINT = '/api/admin/update-page-hero.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

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

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->removeTestPage();

        $pageId = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'draft',
        ], 'Paginakop-testpagina');

        // Attached exactly as the block picker attaches one.
        [$sectionId, $sectionKey] = SectionRegistry::create('page_hero', self::TEST_PAGE);
        (new PageSectionRepository())->create($pageId, self::TEST_PAGE, 'page_hero', $sectionKey, $sectionId);
    }

    protected function tearDown(): void
    {
        // The header goes with its page first: the media rows cannot, while
        // a header still points at one (ON DELETE RESTRICT).
        $this->removeTestPage();

        $media = new MediaRepository();
        foreach ($this->mediaIds as $id) {
            $media->delete($id);
        }

        $this->accounts->forget();
        $this->mediaIds = [];

        PageHeroContent::clearCache();
        MediaService::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The form                                                            */
    /* ------------------------------------------------------------------ */

    public function testTheFormOffersTheClosedListsWithTheStoredChoicesSelected(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();

        $this->storeHeader([
            'media_id' => $mediaId,
            'content_position' => PageHeroContent::POSITION_RIGHT,
            'title_size' => PageHeroContent::SIZE_LARGE,
            'text_size' => PageHeroContent::SIZE_SMALL,
        ]);

        $form = $this->editorForm($session);

        $this->assertSame(PageHeroContent::POSITIONS, $form['options']['content_position'] ?? null);
        $this->assertSame(PageHeroContent::SIZES, $form['options']['title_size'] ?? null);
        $this->assertSame(PageHeroContent::SIZES, $form['options']['text_size'] ?? null);

        $this->assertSame(
            ['image_mode' => 'none', 'slide_transition' => 'fade', 'slide_duration' => '5', 'content_position' => 'right', 'title_size' => 'large', 'text_size' => 'small'],
            $form['selected']
        );
        $this->assertSame((string) $mediaId, $form['values']['media_id'] ?? null, 'the picker carries the chosen item');

        $this->assertNotContains('eyebrow', $form['required'], 'the eyebrow is optional');
        $this->assertContains('title', $form['required'], 'the title is not, in the default language');
    }

    /* ------------------------------------------------------------------ */
    /* Saving                                                              */
    /* ------------------------------------------------------------------ */

    public function testASaveStoresEveryChoiceTheImageAndAnEmptyEyebrowAndComesBackIntoTheForm(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();

        $response = $this->post($session, $csrf, [
            'eyebrow' => '',
            'title' => 'Een kop met een foto',
            'lead' => 'Een korte inleiding.',
            'media_id' => (string) $mediaId,
            'content_position' => PageHeroContent::POSITION_CENTER,
            'title_size' => PageHeroContent::SIZE_LARGE,
            'text_size' => PageHeroContent::SIZE_SMALL,
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertStringEndsWith('&saved=1', $response['location'], 'the save bar recognises a save by this marker');

        $row = $this->storedHeader();
        $this->assertSame(['title' => 'Een kop met een foto', 'lead' => 'Een korte inleiding.'], $row['words']['nl'], 'an empty eyebrow has no row');
        $this->assertSame($mediaId, (int) $row['media_id']);
        $this->assertSame(
            ['center', 'large', 'small'],
            [$row['content_position'], $row['title_size'], $row['text_size']]
        );

        $form = $this->editorForm($session);

        $this->assertSame(
            ['image_mode' => 'none', 'slide_transition' => 'fade', 'slide_duration' => '5', 'content_position' => 'center', 'title_size' => 'large', 'text_size' => 'small'],
            $form['selected'],
            'a request without a place leaves the stored one alone'
        );
        $this->assertSame((string) $mediaId, $form['values']['media_id'] ?? null);
        $this->assertSame('', $form['values']['eyebrow'] ?? null);
        $this->assertSame('nl', $form['values']['language_code'] ?? null, 'the form says which language its words are in');
    }

    /* ------------------------------------------------------------------ */
    /* Where the picture goes, its height and its focus                    */
    /* ------------------------------------------------------------------ */

    public function testTheFormOffersEveryPlaceHeightAndFocusPointWithTheStoredOnesChosen(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->storeHeader([
            'media_id' => $this->mediaItem(),
            'image_mode' => PageHeroContent::IMAGE_BACKGROUND,
            'hero_height' => PageHeroContent::HEIGHT_LARGE,
            'image_focus_x' => 100,
            'image_focus_y' => 100,
        ]);

        $screen = $this->screen($session);

        $this->assertSame(PageHeroContent::IMAGE_MODES, $this->values($screen, '//select[@name="image_mode"]/option/@value'));
        $this->assertSame(['background'], $this->values($screen, '//select[@name="image_mode"]/option[@selected]/@value'));
        $this->assertSame(PageHeroContent::HEIGHTS, $this->values($screen, '//input[@name="hero_height"]/@value'));
        $this->assertSame(['large'], $this->values($screen, '//input[@name="hero_height"][@checked]/@value'));
        // Responsive Media 2.0: the nine one-click points and the point itself.
        $this->assertSame(9, $screen->query('//*[@data-rm-focus="desktop"]//button[@data-rm-preset]')->length);
        $this->assertSame(['100'], $this->values($screen, '//input[@name="image_focus_x"]/@value'));
        $this->assertSame(['100'], $this->values($screen, '//input[@name="image_focus_y"]/@value'));
        $this->assertSame(['true'], $this->values($screen, '//*[@data-rm-focus="desktop"]//button[@data-rm-preset][@data-x="100"][@data-y="100"]/@aria-pressed'));
        $this->assertSame(
            ['object-position: 100% 100%;'],
            $this->values($screen, '//*[@data-rm-focus="desktop"]//img[@data-rm-preview]/@style'),
            'the preview crops the way the website does'
        );

        // Behind the text: the height and the focus show, the alt text and the
        // note for a picture beside the text do not; the fit is only offered
        // beside the text, a phone's own height only behind it.
        $this->assertFalse($this->hidden($screen, self::partOf('input[@name="hero_height"]')));
        $this->assertFalse($this->hidden($screen, '//fieldset[@data-rm]/ancestor::*[@data-page-hero-needs-image][1]'));
        $this->assertTrue($this->hidden($screen, '//input[@name="image_fit"]/ancestor::fieldset[@data-page-hero-part][1]'));
        $this->assertFalse($this->hidden($screen, '//input[@name="image_mobile_height"]/ancestor::fieldset[@data-page-hero-part][1]'));
        $this->assertTrue($this->hidden($screen, self::partOf('input[@name="image_alt"]')));
        $this->assertFalse($this->hidden($screen, self::partOf('*[@data-media-picker]')));
    }

    public function testBesideTheTextTheFormShowsTheAltTextFilledWithTheLibrarysAndNoHeight(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->storeHeader(['media_id' => $this->mediaItem(), 'image_mode' => PageHeroContent::IMAGE_LEFT]);

        $screen = $this->screen($session);

        $this->assertTrue($this->hidden($screen, self::partOf('input[@name="hero_height"]')));
        $this->assertFalse($this->hidden($screen, self::partOf('input[@name="image_alt"]')));
        $this->assertSame(['Werkplaats'], $this->values($screen, '//input[@name="image_alt"]/@value'), 'the alt text this picture really gets');
        $this->assertSame(['media_id'], $this->values($screen, '//input[@name="image_alt"]/@data-media-alt-for'));
    }

    public function testWithoutAPlaceOnlyThePlaceIsShown(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->storeHeader(['image_mode' => PageHeroContent::IMAGE_NONE]);

        $screen = $this->screen($session);

        $this->assertTrue($this->hidden($screen, self::partOf('*[@data-media-picker]')), 'no picker for a header without a picture');
        $this->assertSame(1, $screen->query('//script[contains(@src, "/admin/assets/page-hero.js")]')->length);
        $this->assertSame(1, $screen->query('//script[contains(@src, "/admin/assets/responsive-image.js")]')->length);
    }

    public function testASaveStoresThePlaceTheHeightAndTheFocusPoint(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();

        $response = $this->post($session, $csrf, [
            'media_id' => (string) $mediaId,
            'image_mode' => PageHeroContent::IMAGE_RIGHT,
            'hero_height' => PageHeroContent::HEIGHT_SMALL,
            'image_presentation' => '1',
            'image_focus_x' => '0',
            'image_focus_y' => '0',
            'image_fit' => 'contain',
            'image_mobile_source' => 'desktop',
            'image_mobile_height' => 'compact',
        ]);

        $this->assertStringEndsWith('&saved=1', $response['location']);
        $row = $this->storedHeader();
        $this->assertSame(
            [$mediaId, 'right', 'small', 0, 0, 'contain', 'compact', null],
            [(int) $row['media_id'], $row['image_mode'], $row['hero_height'], (int) $row['image_focus_x'], (int) $row['image_focus_y'], $row['image_fit'], $row['image_mobile_height'], $row['image_mobile_media_id']]
        );
    }

    public function testChoosingNoPictureLetsTheChosenOneGo(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();
        $this->storeHeader(['media_id' => $mediaId, 'image_mode' => PageHeroContent::IMAGE_RIGHT]);
        $this->post($session, $csrf, ['media_id' => (string) $mediaId, 'image_mode' => PageHeroContent::IMAGE_RIGHT, 'image_alt' => 'Een eigen tekst']);
        $this->assertSame('Een eigen tekst', $this->storedHeader()['words']['nl']['image_alt'] ?? null);

        // The hidden picker still posts its id, and the hidden alt field the
        // library's text.
        $response = $this->post($session, $csrf, ['media_id' => (string) $mediaId, 'image_mode' => PageHeroContent::IMAGE_NONE, 'image_alt' => 'Werkplaats']);

        $this->assertStringEndsWith('&saved=1', $response['location']);
        $row = $this->storedHeader();
        $this->assertSame('none', $row['image_mode']);
        $this->assertNull($row['media_id'], 'no invisible reference that keeps the item "in use"');
        $this->assertArrayNotHasKey('image_alt', $row['words']['nl'], 'no alt text of a picture that is gone, to stick to the next one');

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($mediaId), 'the library item stays');
    }

    public function testAFocusPointThatIsNoNumberIsRefusedAndOneOutsideTheFrameIsClamped(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->storeHeader(['image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $refused = $this->post($session, $csrf, ['image_mode' => PageHeroContent::IMAGE_BACKGROUND, 'image_focus_x' => '10% 20%', 'image_focus_y' => '50']);
        $this->assertStringNotContainsString('saved=1', $refused['location']);
        $this->assertSame([50, 50], [(int) $this->storedHeader()['image_focus_x'], (int) $this->storedHeader()['image_focus_y']]);

        $clamped = $this->post($session, $csrf, ['image_mode' => PageHeroContent::IMAGE_BACKGROUND, 'image_focus_x' => '180', 'image_focus_y' => '-3']);
        $this->assertStringEndsWith('&saved=1', $clamped['location']);
        $this->assertSame([100, 0], [(int) $this->storedHeader()['image_focus_x'], (int) $this->storedHeader()['image_focus_y']]);
    }

    public function testAFormWithoutTheNewChoicesLeavesThemAlone(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();
        $this->storeHeader([
            'media_id' => $mediaId,
            'image_mode' => PageHeroContent::IMAGE_LEFT,
            'hero_height' => PageHeroContent::HEIGHT_LARGE,
        ]);
        (new \App\Repository\ResponsiveImageRepository())->save('page_heroes', (int) $this->storedHeader()['id'], PageHeroContent::imageSlot(), new \App\Service\Media\ResponsiveImage(50, 0));

        // post() sends what the form sent before these choices existed.
        $response = $this->post($session, $csrf, ['media_id' => (string) $mediaId]);

        $this->assertStringEndsWith('&saved=1', $response['location']);
        $row = $this->storedHeader();
        $this->assertSame(['left', 'large', 50, 0], [$row['image_mode'], $row['hero_height'], (int) $row['image_focus_x'], (int) $row['image_focus_y']]);
    }

    public function testAnAltTextThatIsOnlyTheLibrarysIsStoredAsInheritedAndAnOwnOneAsItsOwn(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();

        $this->post($session, $csrf, ['media_id' => (string) $mediaId, 'image_mode' => PageHeroContent::IMAGE_RIGHT, 'image_alt' => 'Werkplaats']);
        $this->assertArrayNotHasKey('image_alt', $this->storedHeader()['words']['nl'], 'seeing the library\'s text is not choosing it');

        $this->post($session, $csrf, ['media_id' => (string) $mediaId, 'image_mode' => PageHeroContent::IMAGE_RIGHT, 'image_alt' => 'Plankjes op de werkbank']);
        $this->assertSame('Plankjes op de werkbank', $this->storedHeader()['words']['nl']['image_alt'] ?? null);

        PageHeroContent::clearCache();
        $this->assertSame('Plankjes op de werkbank', PageHeroContent::forSlug(self::TEST_PAGE)['image_alt'], 'and it is what the website prints beside the text');
    }

    public function testRemovingTheImageClearsOnlyTheReference(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();
        $this->storeHeader(['media_id' => $mediaId]);

        $response = $this->post($session, $csrf, ['media_id' => '']);

        $this->assertStringEndsWith('&saved=1', $response['location']);
        $this->assertNull($this->storedHeader()['media_id']);

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($mediaId), 'the library item is not the header\'s to delete');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function idsThatNameNoItem(): array
    {
        return [
            'an id nobody has' => ['999999999'],
            'a negative number' => ['-1'],
            'a path instead of an id' => ['assets/media/anything.webp'],
            'SQL instead of an id' => ['1 OR 1=1'],
        ];
    }

    /**
     * An id is resolved against the library; one that names nothing is no
     * image, never a stored reference (MEDIA.md, "De mediakiezer").
     *
     * @dataProvider idsThatNameNoItem
     */
    public function testAnIdThatNamesNoMediaItemIsSavedAsNoImage(string $submitted): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = $this->post($session, $csrf, ['media_id' => $submitted]);

        $this->assertStringEndsWith('&saved=1', $response['location']);
        $this->assertNull($this->storedHeader()['media_id']);
    }

    /* ------------------------------------------------------------------ */
    /* What is refused                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{string, string}>
     */
    public static function choicesOutsideTheLists(): array
    {
        return [
            'an unknown position' => ['content_position', 'diagonal'],
            'an unknown place for the picture' => ['image_mode', 'diagonal'],
            'no place at all' => ['image_mode', ''],
            'a length as a height' => ['hero_height', '900px'],
            'the old default word as a height' => ['hero_height', 'normal'],
            'no position at all' => ['content_position', ''],
            'a length as a title size' => ['title_size', '96px'],
            'CSS as a text size' => ['text_size', 'normal; color: red'],
            'a default in the wrong case' => ['text_size', 'Normal'],
        ];
    }

    /**
     * @dataProvider choicesOutsideTheLists
     */
    public function testAChoiceOutsideItsListIsRefusedAndNothingIsWritten(string $field, string $value): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $before = $this->storedHeader();

        $response = $this->post($session, $csrf, [
            'title' => 'Deze titel mag niet worden opgeslagen',
            $field => $value,
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertStringNotContainsString('saved=1', $response['location']);
        $this->assertSame($before, $this->storedHeader(), 'nothing of the request is written, the valid fields included');
        $this->assertSame(
            [AdminTranslator::trans('validation.ongeldige_keuze')],
            $this->accounts->read($session, 'admin_page_hero_errors')
        );
    }

    public function testTheTitleIsStillRequired(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $before = $this->storedHeader();

        $response = $this->post($session, $csrf, ['title' => '', 'eyebrow' => '']);

        $this->assertStringNotContainsString('saved=1', $response['location']);
        $this->assertSame($before, $this->storedHeader());
        $this->assertSame(
            [AdminTranslator::trans('validation.veld_verplicht')],
            $this->accounts->read($session, 'admin_page_hero_errors')
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Everything the form posts, valid, with the test's own values on top —
     * so each test changes only what it is about.
     *
     * @param array<string, string> $overrides
     *
     * @return array{status: int, location: string, body: string}
     */
    private function post(string $session, string $csrf, array $overrides): array
    {
        return self::$server->request('POST', self::ENDPOINT, $session, array_merge([
            'csrf_token' => $csrf,
            'slug' => self::TEST_PAGE,
            'language_code' => 'nl',
            'eyebrow' => 'Bovenschrift',
            'title' => 'Een paginakop',
            'lead' => '',
            'media_id' => '',
            'content_position' => PageHeroContent::POSITION_LEFT,
            'title_size' => PageHeroContent::SIZE_NORMAL,
            'text_size' => PageHeroContent::SIZE_NORMAL,
            'is_active' => '1',
        ], $overrides));
    }

    /**
     * What the editor's form offers and carries, read from the real screen.
     *
     * @return array{options: array<string, list<string>>, selected: array<string, string>, values: array<string, string>, required: list<string>}
     */
    private function editorForm(string $session): array
    {
        $response = self::$server->request('GET', '/admin/page-hero.php?slug=' . rawurlencode(self::TEST_PAGE), $session);
        $this->assertSame(200, $response['status'], 'the editor opens');

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $response['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $formPath = '//form[@action="' . self::ENDPOINT . '"]';
        $this->assertSame(1, $xpath->query($formPath)->length, 'one form saves the whole header');

        $form = ['options' => [], 'selected' => [], 'values' => [], 'required' => []];

        foreach ($xpath->query($formPath . '//select') as $select) {
            $name = $select->getAttribute('name');
            foreach ($xpath->query('.//option', $select) as $option) {
                $form['options'][$name][] = $option->getAttribute('value');
                if ($option->hasAttribute('selected')) {
                    $form['selected'][$name] = $option->getAttribute('value');
                }
            }
        }

        foreach ($xpath->query($formPath . '//input[@name]') as $input) {
            $form['values'][$input->getAttribute('name')] = $input->getAttribute('value');
            if ($input->hasAttribute('required')) {
                $form['required'][] = $input->getAttribute('name');
            }
        }

        return $form;
    }

    /** The editor as a document, read from the real screen. */
    private function screen(string $session): \DOMXPath
    {
        $response = self::$server->request('GET', '/admin/page-hero.php?slug=' . rawurlencode(self::TEST_PAGE), $session);
        $this->assertSame(200, $response['status'], 'the editor opens');

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $response['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /** @return list<string> */
    private function values(\DOMXPath $screen, string $query): array
    {
        $values = [];
        foreach ($screen->query($query) as $node) {
            $values[] = (string) $node->nodeValue;
        }

        return $values;
    }

    /** The nearest conditional part (data-page-hero-part) around an element. */
    private static function partOf(string $element): string
    {
        return '//' . $element . '/ancestor::*[@data-page-hero-part][1]';
    }

    /** Whether the one element the query names is printed hidden. */
    private function hidden(\DOMXPath $screen, string $query): bool
    {
        $nodes = $screen->query($query);
        $this->assertSame(1, $nodes->length, $query);
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node->hasAttribute('hidden');
    }

    /**
     * A stored header. `image_focus_x` and `image_focus_y` in $overrides are
     * its focus point, stored where Responsive Media 2.0 stores it.
     *
     * @param array<string, mixed> $overrides
     */
    private function storeHeader(array $overrides): void
    {
        $point = [(int) ($overrides['image_focus_x'] ?? 50), (int) ($overrides['image_focus_y'] ?? 50)];
        unset($overrides['image_focus_x'], $overrides['image_focus_y']);

        $repository = new PageHeroRepository();
        $repository->upsert(self::TEST_PAGE, array_merge(
            PageHeroContent::startingValues(),
            ['is_active' => true],
            $overrides
        ));
        $heroId = (int) $repository->findBySlug(self::TEST_PAGE)['id'];
        (new \App\Repository\ResponsiveImageRepository())->save('page_heroes', $heroId, PageHeroContent::imageSlot(), new \App\Service\Media\ResponsiveImage($point[0], $point[1]));
        BlockLocalization::save('page_heroes', $heroId, 'nl', ['title' => 'Een opgeslagen kop']);

        PageHeroContent::clearCache();
    }

    /** @return array<string, mixed> the header's row, with its stored words per language under `words` */
    private function storedHeader(): array
    {
        $row = (new PageHeroRepository())->findBySlug(self::TEST_PAGE);
        $this->assertNotNull($row, 'the test page has its header');

        // The endpoint wrote in another process: read the words afresh.
        BlockLocalization::clearCache();

        return $row + ['words' => BlockLocalization::translations('page_heroes', (int) $row['id'])];
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function mediaItem(): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__page_hero_editor_' . bin2hex(random_bytes(4)) . '__.webp',
            'original_filename' => 'werkplaats.webp',
            'mime_type' => 'image/webp',
            'width' => 1600,
            'height' => 900,
            'file_size' => 100,
            'alt_text' => 'Werkplaats',
            'checksum' => null,
        ]);

        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function removeTestPage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::TEST_PAGE);

        if ($page !== null) {
            $sections = new PageSectionRepository();
            foreach ($sections->findForPage((int) $page['id']) as $row) {
                SectionRegistry::delete($row, $sections);
            }

            Database::connection()->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $page['id']]);
        }

        // A header an interrupted run left behind has no page to go with; its
        // words go first, so they cannot outlive it.
        $leftover = (new PageHeroRepository())->findBySlug(self::TEST_PAGE);
        if ($leftover !== null) {
            BlockLocalization::deleteOwner('page_heroes', (int) $leftover['id']);
        }
        (new PageHeroRepository())->deleteBySlug(self::TEST_PAGE);
    }
}
