<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\BlockTranslationRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The block editors phase 3B moved onto per-language storage (Multilingual 2.0,
 * docs/multilingual/ARCHITECTURE.md), one contract for all of them, used the
 * way an editor uses them: the screen shows the stored words of the one
 * website language chosen in the CMS shell and never the default language's
 * words in an empty translation; a save writes that language and leaves every
 * other language alone; required fields exist only in the default language; a
 * language the website does not have is refused; a third language is a row in
 * site_languages and nothing else; words are stored as typed; and a refused
 * save comes back unsaved, in the language it was typed in.
 *
 * Table-driven: BLOCKS names each block's screen, endpoint, the settings a
 * valid save carries and a set of words. The contract that is the same for
 * every block runs once per block; rules of one block (a button that needs a
 * label and a URL) have a test of their own. The tekstblok, CTA band and
 * contactkaart of phase 3A are Tests\Service\BlockLocalizationEditorHttpTest's.
 *
 * Over real HTTP against PHP's built-in server. The page, its blocks, their
 * words, the accounts and the German registry row are this test's own and are
 * removed in tearDown(). Without a server the test skips itself.
 */
final class BlockWordsEditorHttpTest extends TestCase
{
    private const KEY = 'zz-block-words-editor-test';

    /**
     * block type => how an editor reaches it.
     *
     *   table     the owner table of its words
     *   screen    the editor, `{section}` replaced by the block's address
     *   endpoint  the write endpoint
     *   address   the request field that names the block: section (page:key) or slug (the page)
     *   settings  the language-neutral fields a valid save carries
     *   words     Dutch words for every field, valid; `required` the fields the default language must have
     */
    private const BLOCKS = [
        'page_hero' => [
            'table' => 'page_heroes',
            'screen' => '/admin/page-hero.php?slug={slug}',
            'endpoint' => '/api/admin/update-page-hero.php',
            'address' => 'slug',
            'settings' => ['media_id' => '', 'content_position' => 'left', 'title_size' => 'normal', 'text_size' => 'normal', 'is_active' => '1'],
            'words' => ['eyebrow' => 'Over ons', 'title' => 'Wie wij zijn', 'lead' => 'Een korte inleiding.'],
            'required' => ['title'],
        ],
        'form' => [
            'table' => 'form_blocks',
            'screen' => '/admin/form-block.php?section={section}',
            'endpoint' => '/api/admin/update-form-block.php',
            'address' => 'section',
            'settings' => ['form_id' => '', 'is_active' => '1'],
            'words' => ['title' => 'Stuur ons een bericht', 'intro' => 'Wij antwoorden snel.'],
            'required' => [],
        ],
        'contact_form' => [
            'table' => 'contact_form_sections',
            'screen' => '/admin/contact-form.php?section={section}',
            'endpoint' => '/api/admin/update-contact-form.php',
            'address' => 'section',
            'settings' => ['form_id' => '', 'allow_attachment' => '1', 'is_active' => '1'],
            'words' => ['title' => 'Vraag een offerte aan'],
            'required' => ['title'],
        ],
        'item_gallery' => [
            'table' => 'item_galleries',
            'screen' => '/admin/item-gallery.php?section={section}',
            'endpoint' => '/api/admin/update-item-gallery.php',
            'address' => 'section',
            'settings' => [
                'source_type' => 'portfolio', 'portfolio_scope' => 'all', 'collection_id' => '', 'max_items' => '',
                'show_filter_bar' => '1', 'enable_lightbox' => '1', 'fallback_link_url' => '', 'button_url' => '/werk',
                'background' => 'default', 'is_active' => '1',
            ],
            'words' => ['eyebrow' => 'Werk', 'title' => 'Onze projecten', 'lead' => 'Een greep.', 'footer_note' => 'En meer.', 'button_label' => 'Al het werk'],
            'required' => [],
        ],
        'project_cards' => [
            'table' => 'item_galleries',
            'screen' => '/admin/project-cards.php?section={section}',
            'endpoint' => '/api/admin/update-project-cards.php',
            'address' => 'section',
            'settings' => ['portfolio_scope' => 'all', 'max_items' => '', 'background' => 'default', 'is_active' => '1'],
            'words' => ['title' => 'Projecten', 'lead' => 'Wat wij maakten.'],
            'required' => [],
        ],
        // Phase 3B, wave B: the heading of a repeater; its items are
        // Tests\Service\BlockChildWordsEditorHttpTest's.
        'feature_grid' => [
            'table' => 'feature_grids',
            'screen' => '/admin/feature-grid.php?section={section}',
            'endpoint' => '/api/admin/update-feature-grid.php',
            'address' => 'section',
            'settings' => ['is_active' => '1'],
            'words' => ['eyebrow' => 'Waarom wij', 'title' => 'Wat je van ons krijgt', 'lead' => 'Kort gezegd.'],
            'required' => ['eyebrow', 'title'],
        ],
        'faq' => [
            'table' => 'faq_sections',
            'screen' => '/admin/faq.php?section={section}',
            'endpoint' => '/api/admin/update-faq-section.php',
            'address' => 'section',
            'settings' => ['is_active' => '1'],
            'words' => ['eyebrow' => 'Vragen', 'title' => 'Veelgestelde vragen'],
            'required' => ['eyebrow', 'title'],
        ],
        'step_list' => [
            'table' => 'step_list_sections',
            'screen' => '/admin/step-list.php?section={section}',
            'endpoint' => '/api/admin/update-step-list-section.php',
            'address' => 'section',
            'settings' => ['is_active' => '1'],
            'words' => ['eyebrow' => 'Werkwijze', 'title' => 'In drie stappen'],
            'required' => ['eyebrow', 'title'],
        ],
        // Phase 3B, wave C. The Detailsectie's body is rich text; its main
        // image's alt text is on the image form, a rule of its own below.
        'text_image_split' => [
            'table' => 'text_image_splits',
            'screen' => '/admin/text-image-split.php?section={section}',
            'endpoint' => '/api/admin/update-text-image-split-section.php',
            'address' => 'section',
            'settings' => ['layout' => 'image_right', 'button_url' => '/contact', 'is_active' => '1'],
            'words' => ['eyebrow' => 'Over mij', 'title' => 'Het verhaal', 'button_label' => 'Neem contact op'],
            'required' => [],
        ],
        'detail_section' => [
            'table' => 'detail_sections',
            'screen' => '/admin/detail-section.php?section={section}',
            'endpoint' => '/api/admin/update-detail-section.php',
            'address' => 'section',
            'settings' => ['anchor' => 'hout', 'image_position' => 'image_right', 'cta_url' => '/contact', 'is_active' => '1'],
            'words' => [
                'nav_label' => 'Hout', 'title' => 'Hout graveren', 'lead' => 'Warm en tijdloos.', 'body' => '<p>Elk stuk is uniek.</p>',
                'closing_note' => 'Op aanvraag.', 'cta_label' => 'Vraag een offerte',
            ],
            'required' => ['title'],
        ],
        'card_carousel' => [
            'table' => 'card_carousels',
            'screen' => '/admin/card-carousel.php?section={section}',
            'endpoint' => '/api/admin/update-card-carousel.php',
            'address' => 'section',
            'settings' => ['is_active' => '1', 'desktop_layout' => 'orbit'],
            'words' => ['eyebrow' => 'Materialen', 'title' => 'Waar wij mee werken', 'lead' => 'Een keuze.'],
            'required' => [],
        ],
    ];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    /** @var array<string, array{id: int, section: string}> block type => content row id and its ?section= value */
    private array $blocks = [];

    private bool $addedGerman = false;

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

        self::assertSame('nl', BlockLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');

        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Blokwoordentest'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();

        // After the page: a block that shows a library item keeps it from going.
        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];
        MediaService::clearCache();

        if ($this->addedGerman) {
            Database::connection()->prepare("DELETE FROM block_translations WHERE language_code = 'de'")->execute();
            (new SiteLanguageRepository())->delete('de');
            $this->addedGerman = false;
        }

        $this->accounts->forget();
        BlockLocalization::clearCache();
        PageContent::clearCache();
        SiteLanguages::clearCache();
    }

    /** @return array<string, array{string}> */
    public static function blocks(): array
    {
        $cases = [];
        foreach (array_keys(self::BLOCKS) as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    // ------------------------------------------------------------ one language on screen

    /**
     * @dataProvider blocks
     */
    public function testTheScreenShowsTheStoredWordsOfTheChosenLanguageOnly(string $type): void
    {
        $this->place($type);
        $words = self::BLOCKS[$type]['words'];
        $field = array_key_first($words);
        BlockLocalization::save(self::BLOCKS[$type]['table'], $this->blocks[$type]['id'], 'nl', $words);

        $dutch = $this->xpath($this->editor($this->signIn('nl'), $type));
        $form = $this->form($dutch, $type);
        self::assertSame('nl', $this->control($dutch, $form, 'input[@name="language_code"]')->getAttribute('value'));
        self::assertSame($words[$field], $this->valueOf($dutch, $form, $field), 'the default language shows its stored words');
        self::assertSame(0, $dutch->query('.//*[contains(@name, "_nl") or contains(@name, "_en")]', $form)->length, 'no fixed-language field is left');

        $english = $this->xpath($this->editor($this->signIn('en'), $type));
        $form = $this->form($english, $type);
        self::assertSame('en', $this->control($english, $form, 'input[@name="language_code"]')->getAttribute('value'));
        foreach (array_keys($words) as $key) {
            self::assertSame('', $this->valueOf($english, $form, $key), $key . ': an untranslated field is empty, never the Dutch words');
        }
        self::assertStringNotContainsString('value="' . htmlspecialchars($words[$field], ENT_QUOTES, 'UTF-8') . '"', $english->document->saveHTML($form), 'the Dutch words are not on the English form at all');
        foreach (array_keys($words) as $key) {
            self::assertFalse($this->control($english, $form, '*[(self::input or self::textarea) and @name="' . $key . '"]')->hasAttribute('required'), $key . ': nothing about a translation is required');
        }
    }

    // ------------------------------------------------------------ saving

    /**
     * @dataProvider blocks
     */
    public function testASaveWritesTheLanguageOnScreenAndLeavesTheOthersAlone(string $type): void
    {
        $this->place($type);
        $table = self::BLOCKS[$type]['table'];
        $words = self::BLOCKS[$type]['words'];
        $session = $this->signIn(null);

        $this->assertSaved($this->save($session, $type, 'nl', $words), $type . ' in Dutch');
        self::assertSame($words, $this->stored($type, 'nl'));

        $field = array_key_first($words);
        $this->assertSaved($this->save($session, $type, 'en', [$field => 'English ' . $field]), $type . ' in English');

        self::assertSame([$field => 'English ' . $field], $this->stored($type, 'en'));
        self::assertSame($words, $this->stored($type, 'nl'), 'saving English leaves the Dutch words exactly as they were');

        // Emptying the translation removes its rows, so it falls back again.
        $this->assertSaved($this->save($session, $type, 'en', []), $type . ' emptied');
        self::assertSame([], $this->stored($type, 'en'));
        self::assertSame($words, $this->stored($type, 'nl'));
        self::assertSame($table, self::BLOCKS[$type]['table']);
    }

    /**
     * @dataProvider blocks
     */
    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(string $type): void
    {
        $this->place($type);
        $this->addGerman();
        $words = self::BLOCKS[$type]['words'];
        $field = array_key_first($words);
        $session = $this->signIn('de');

        $this->assertSaved($this->save($session, $type, 'nl', $words));
        $this->assertSaved($this->save($session, $type, 'de', [$field => 'Deutsch ' . $field]), $type . ' in German');

        self::assertSame([$field => 'Deutsch ' . $field], $this->stored($type, 'de'));
        self::assertSame($words, $this->stored($type, 'nl'));

        $screen = $this->xpath($this->editor($session, $type));
        $form = $this->form($screen, $type);
        self::assertSame('de', $this->control($screen, $form, 'input[@name="language_code"]')->getAttribute('value'));
        self::assertSame('Deutsch ' . $field, $this->valueOf($screen, $form, $field));
    }

    /**
     * @dataProvider blocks
     */
    public function testALanguageTheWebsiteDoesNotHaveIsRefused(string $type): void
    {
        $this->place($type);
        $session = $this->signIn(null);

        foreach (['xx', '', 'nl_NL', 'title_en'] as $language) {
            $this->assertRefused($this->save($session, $type, $language, self::BLOCKS[$type]['words']), $type . ' in "' . $language . '"');
        }

        self::assertSame([], $this->stored($type, 'xx'));
    }

    /**
     * @dataProvider blocks
     */
    public function testRequiredFieldsAreOnlyRequiredInTheDefaultLanguage(string $type): void
    {
        $this->place($type);
        $session = $this->signIn(null);
        $required = self::BLOCKS[$type]['required'];
        $words = self::BLOCKS[$type]['words'];

        foreach ($required as $field) {
            $this->assertRefused($this->save($session, $type, 'nl', [$field => ''] + $words), $type . ': ' . $field . ' in the default language');
        }

        $this->assertSaved($this->save($session, $type, 'nl', $words), $type . ': the default language with its words');
        $this->assertSaved($this->save($session, $type, 'en', []), $type . ': a translation without words is fine');
    }

    /**
     * @dataProvider blocks
     */
    public function testWordsAreStoredAsTypedAndTooLongIsRefused(string $type): void
    {
        $this->place($type);
        $session = $this->signIn(null);
        $words = self::BLOCKS[$type]['words'];
        $field = array_key_first($words);
        $payload = '<script>alert("' . $type . '")</script> & "quotes"';

        $this->assertSaved($this->save($session, $type, 'nl', [$field => '  ' . $payload . ' '] + $words));
        self::assertSame($payload, $this->stored($type, 'nl')[$field], 'plain text is trimmed and otherwise stored as typed; escaping is the output\'s job');

        $max = BlockLocalization::fields(self::BLOCKS[$type]['table'])[$field]->maxLength;
        $this->assertRefused($this->save($session, $type, 'nl', [$field => str_repeat('a', $max + 1)] + $words), $type . ': longer than its field');
        self::assertSame($payload, $this->stored($type, 'nl')[$field], 'a refused save writes nothing');
    }

    /**
     * @dataProvider blocks
     */
    public function testARefusedSaveComesBackUnsavedInTheLanguageItWasTypedIn(string $type): void
    {
        $this->place($type);
        $session = $this->signIn('en');
        $words = self::BLOCKS[$type]['words'];
        $field = array_key_first($words);
        $max = BlockLocalization::fields(self::BLOCKS[$type]['table'])[$field]->maxLength;
        $typed = 'Getypt ' . str_repeat('b', $max);

        $response = $this->save($session, $type, 'en', [$field => $typed]);
        $this->assertRefused($response);

        $screen = $this->xpath($this->editor($session, $type));
        $form = $this->form($screen, $type);
        self::assertTrue($form->hasAttribute('data-save-bar-unsaved'), 'the refused input starts out unsaved');
        self::assertSame($typed, $this->valueOf($screen, $form, $field), 'the typed words come back in their own language');

        // Refused words are never shown on another language's screen, not
        // even to the editor who typed them.
        $this->assertRefused($this->save($session, $type, 'en', [$field => $typed]));
        (new AdminUserRepository())->updateContentEditingLanguage((int) $this->accounts->read($session, 'admin_user_id'), 'nl');
        $dutch = $this->xpath($this->editor($session, $type));
        self::assertStringNotContainsString($typed, $dutch->document->saveHTML($this->form($dutch, $type)));
    }

    // ------------------------------------------------------------ rules of one block

    public function testTheGalleryButtonFollowsTheDefaultLanguagesLabel(): void
    {
        $this->place('item_gallery');
        $session = $this->signIn(null);
        $words = self::BLOCKS['item_gallery']['words'];

        $this->assertRefused($this->save($session, 'item_gallery', 'nl', $words, ['button_url' => '']), 'a label without a URL');
        $this->assertRefused($this->save($session, 'item_gallery', 'nl', ['button_label' => ''] + $words), 'a URL without a label');
        $this->assertSaved($this->save($session, 'item_gallery', 'nl', ['button_label' => ''] + $words, ['button_url' => '']), 'neither');
        $this->assertSaved($this->save($session, 'item_gallery', 'nl', $words), 'both');

        $this->assertSaved($this->save($session, 'item_gallery', 'en', ['button_label' => 'All work']), 'a translated label with the URL');
        $this->assertRefused($this->save($session, 'item_gallery', 'en', ['button_label' => 'All work'], ['button_url' => '']), 'a translated label without a URL');
        $this->assertRefused($this->save($session, 'item_gallery', 'en', [], ['button_url' => '']), 'removing the URL while the default language has a label');
    }

    public function testAProjectsSaveKeepsTheGalleryWordsItsEditorDoesNotShowEmpty(): void
    {
        $type = 'project_cards';
        $this->place($type);
        $session = $this->signIn(null);

        // Words a crafted request might carry: the endpoint reads only the two
        // this block's editor has.
        $this->assertSaved($this->save($session, $type, 'nl', self::BLOCKS[$type]['words'] + ['eyebrow' => 'Boven', 'footer_note' => 'Onder', 'button_label' => 'Klik']));

        self::assertSame(self::BLOCKS[$type]['words'], $this->stored($type, 'nl'));
    }

    public function testTheDetailSectionBodyIsRichTextSanitizedOnSave(): void
    {
        $type = 'detail_section';
        self::assertTrue(BlockLocalization::fields('detail_sections')['body']->isRich());
        $this->place($type);
        $session = $this->signIn(null);

        $this->assertSaved($this->save($session, $type, 'nl', [
            'body' => '<p>Veilig <strong>vet</strong></p><script>alert(1)</script><img src="x" onerror="alert(1)">',
        ] + self::BLOCKS[$type]['words']));

        $body = $this->stored($type, 'nl')['body'];
        self::assertStringContainsString('<strong>vet</strong>', $body, 'the markup an editor may use stays markup');
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('onerror', $body);
    }

    public function testTheDetailSectionImageFormWritesOnlyItsAltTextAndARemovedImageTakesIt(): void
    {
        $type = 'detail_section';
        $this->place($type);
        $session = $this->signIn(null);
        $words = self::BLOCKS[$type]['words'];
        $media = (string) $this->mediaItem();
        $image = fn (string $language, array $fields): array => $this->post($session, '/api/admin/update-detail-section-main-image.php', [
            'section' => $this->blocks[$type]['section'],
            'language_code' => $language,
        ] + $fields);

        $this->assertSaved($this->save($session, $type, 'nl', $words));
        $this->assertSaved($this->save($session, $type, 'en', ['title' => 'Wood engraving']));

        $this->assertSaved($image('nl', ['media_id' => $media, 'main_image_alt' => 'Een gegraveerde plank']));
        $this->assertSaved($image('en', ['media_id' => $media, 'main_image_alt' => 'An "engraved" board']));

        self::assertSame('Een gegraveerde plank', $this->stored($type, 'nl')['main_image_alt']);
        self::assertSame($words, array_diff_key($this->stored($type, 'nl'), ['main_image_alt' => true]), 'the image form writes nothing but its alt text');
        self::assertSame(['title' => 'Wood engraving', 'main_image_alt' => 'An "engraved" board'], $this->stored($type, 'en'));

        // The section form keeps the alt text it does not show.
        $this->assertSaved($this->save($session, $type, 'en', ['title' => 'Engraving wood']));
        self::assertSame(['title' => 'Engraving wood', 'main_image_alt' => 'An "engraved" board'], $this->stored($type, 'en'));

        // Removing the image takes its alt text in every language, and nothing else.
        $this->assertSaved($image('nl', ['remove_image' => '1']));
        self::assertSame($words, $this->stored($type, 'nl'));
        self::assertSame(['title' => 'Engraving wood'], $this->stored($type, 'en'));
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, string $endpoint, array $fields): array
    {
        $response = self::$server->request('POST', $endpoint, $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
        ] + $fields);
        BlockLocalization::clearCache();

        return $response;
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function mediaItem(): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__block_words_editor_' . bin2hex(random_bytes(4)) . '__.webp',
            'original_filename' => 'plank.webp',
            'mime_type' => 'image/webp',
            'width' => 1600,
            'height' => 900,
            'file_size' => 100,
            'alt_text' => 'Plank',
            'checksum' => null,
        ]);

        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function place(string $type): void
    {
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        (new \App\Repository\PageSectionRepository())->create($this->pageId, self::KEY, $type, $key, $id);

        $this->blocks[$type] = ['id' => $id, 'section' => self::KEY . ':' . $key];

        // A clean start: create() may write starting words.
        BlockLocalization::deleteOwner(self::BLOCKS[$type]['table'], $id);
    }

    private function addGerman(): void
    {
        if (!SiteLanguages::exists('de')) {
            (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
            $this->addedGerman = true;
        }
        SiteLanguages::clearCache();
    }

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        if ($editingLanguage !== null) {
            (new AdminUserRepository())->updateContentEditingLanguage((int) $this->accounts->read($session, 'admin_user_id'), $editingLanguage);
        }

        return $session;
    }

    private function editor(string $session, string $type): string
    {
        $url = strtr(self::BLOCKS[$type]['screen'], [
            '{slug}' => rawurlencode(self::KEY),
            '{section}' => urlencode($this->blocks[$type]['section']),
        ]);
        $response = self::$server->request('GET', $url, $session);
        self::assertSame(200, $response['status'], $type);

        return $response['body'];
    }

    /**
     * @param array<string, string> $words
     * @param array<string, ?string> $settings over the block's valid settings; null leaves a checkbox unticked
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, string $type, string $language, array $words, array $settings = []): array
    {
        $block = self::BLOCKS[$type];
        $address = $block['address'] === 'slug'
            ? ['slug' => self::KEY]
            : ['section' => $this->blocks[$type]['section']];

        $fields = array_filter(
            ['csrf_token' => (string) $this->accounts->read($session, 'csrf_token'), 'language_code' => $language]
                + $address + $words + $settings + $block['settings'],
            static fn ($value): bool => $value !== null
        );

        $response = self::$server->request('POST', $block['endpoint'], $session, $fields);
        BlockLocalization::clearCache();

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
        self::assertStringStartsWith('/admin/', $response['location'], $what . ': back to the editor');
    }

    /** @return array<string, string> the stored words of one block in one language, in declaration order */
    private function stored(string $type, string $language): array
    {
        $table = self::BLOCKS[$type]['table'];
        $id = $this->blocks[$type]['id'];
        $words = (new BlockTranslationRepository())->findForOwners([$table => [$id]])[$table][$id][$language] ?? [];

        $ordered = [];
        foreach (array_keys(BlockLocalization::fields($table)) as $field) {
            if (array_key_exists($field, $words)) {
                $ordered[$field] = $words[$field];
            }
        }

        return $ordered;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function form(\DOMXPath $xpath, string $type): \DOMElement
    {
        $forms = $xpath->query('//form[@action="' . self::BLOCKS[$type]['endpoint'] . '"]');
        self::assertSame(1, $forms->length, $type);

        return $forms->item(0);
    }

    private function control(\DOMXPath $xpath, \DOMElement $form, string $path): \DOMElement
    {
        $controls = $xpath->query('.//' . $path, $form);
        self::assertSame(1, $controls->length, $path);

        return $controls->item(0);
    }

    /** The value of the input or textarea named $name. */
    private function valueOf(\DOMXPath $xpath, \DOMElement $form, string $name): string
    {
        $control = $this->control($xpath, $form, '*[(self::input or self::textarea) and @name="' . $name . '"]');

        return $control->nodeName === 'textarea' ? $control->textContent : $control->getAttribute('value');
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);

        if ($page !== null) {
            PageService::delete($page);
        }

        $this->blocks = [];
        PageContent::clearCache();
    }
}
