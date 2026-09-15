<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageHeroRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
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

        $pageId = (new PageRepository())->create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'title' => 'Paginakop-testpagina',
            'status' => 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);

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
            ['content_position' => 'right', 'title_size' => 'large', 'text_size' => 'small'],
            $form['selected']
        );
        $this->assertSame((string) $mediaId, $form['values']['media_id'] ?? null, 'the picker carries the chosen item');

        $this->assertNotContains('eyebrow_nl', $form['required'], 'the eyebrow is optional');
        $this->assertContains('title_nl', $form['required'], 'the title is not');
    }

    /* ------------------------------------------------------------------ */
    /* Saving                                                              */
    /* ------------------------------------------------------------------ */

    public function testASaveStoresEveryChoiceTheImageAndAnEmptyEyebrowAndComesBackIntoTheForm(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $mediaId = $this->mediaItem();

        $response = $this->post($session, $csrf, [
            'eyebrow_nl' => '',
            'title_nl' => 'Een kop met een foto',
            'lead_nl' => 'Een korte inleiding.',
            'media_id' => (string) $mediaId,
            'content_position' => PageHeroContent::POSITION_CENTER,
            'title_size' => PageHeroContent::SIZE_LARGE,
            'text_size' => PageHeroContent::SIZE_SMALL,
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertStringEndsWith('&saved=1', $response['location'], 'the save bar recognises a save by this marker');

        $row = $this->storedHeader();
        $this->assertSame('', $row['eyebrow_nl']);
        $this->assertSame('Een kop met een foto', $row['title_nl']);
        $this->assertSame('Een korte inleiding.', $row['lead_nl']);
        $this->assertSame($mediaId, (int) $row['media_id']);
        $this->assertSame(
            ['center', 'large', 'small'],
            [$row['content_position'], $row['title_size'], $row['text_size']]
        );

        $form = $this->editorForm($session);

        $this->assertSame(
            ['content_position' => 'center', 'title_size' => 'large', 'text_size' => 'small'],
            $form['selected']
        );
        $this->assertSame((string) $mediaId, $form['values']['media_id'] ?? null);
        $this->assertSame('', $form['values']['eyebrow_nl'] ?? null);
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
            'title_nl' => 'Deze titel mag niet worden opgeslagen',
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

        $response = $this->post($session, $csrf, ['title_nl' => '', 'eyebrow_nl' => '']);

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
            'eyebrow_nl' => 'Bovenschrift',
            'eyebrow_en' => '',
            'title_nl' => 'Een paginakop',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'breadcrumb_label_nl' => 'Kruimelpad',
            'breadcrumb_label_en' => '',
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

    /** @param array<string, mixed> $overrides */
    private function storeHeader(array $overrides): void
    {
        (new PageHeroRepository())->upsert(self::TEST_PAGE, array_merge(
            PageHeroContent::startingValues('Paginakop-testpagina'),
            ['title_nl' => 'Een opgeslagen kop', 'is_active' => true],
            $overrides
        ));

        PageHeroContent::clearCache();
    }

    /** @return array<string, mixed> */
    private function storedHeader(): array
    {
        $row = (new PageHeroRepository())->findBySlug(self::TEST_PAGE);
        $this->assertNotNull($row, 'the test page has its header');

        return $row;
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

        // A header an interrupted run left behind has no page to go with.
        (new PageHeroRepository())->deleteBySlug(self::TEST_PAGE);
    }
}
