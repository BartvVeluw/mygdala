<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\BlockTranslationRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
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
 * The one-form block editors with a list of child rows (PAGE-EDITOR.md, "Eén
 * formulier per blok-editor"; App\Service\Blocks\EditorChildList), over real
 * HTTP, the same contract for every list:
 *
 *  - the screen is one form with one "Opslaan", and the language bar once;
 *  - a change to the block and a change to a row are stored by one save;
 *  - a new order, a removal mark and a new row are stored with the block's
 *    other changes; ↑ without JavaScript stores what was typed with the move;
 *  - a refused save stores nothing at all, and the screen shows every typed
 *    value again (order and new rows included), unsaved, with the message
 *    next to its field;
 *  - a save in one language leaves the other languages alone, and a new row
 *    is written in the default language whatever the screen shows;
 *  - a removed row takes its words in every language along, and leaves no
 *    orphan behind; a third language is a row in site_languages and nothing
 *    else.
 *
 * Table-driven: CASES names, per list, its block, its screen and endpoint, a
 * valid save of the block, a word of the block to change (or none, then its
 * switch), and a valid new row. The page, its blocks, the accounts and the
 * library items are this test's own and are removed in tearDown(). Without a
 * server the test skips itself.
 */
final class BlockRowEditorsHttpTest extends TestCase
{
    private const KEY = 'zz-block-row-editors-test';

    /**
     * list case => how its editor reaches it.
     *
     *   block       the block type
     *   screen      the editor, `{section}` replaced by the block's address
     *   endpoint    the one write endpoint
     *   parent      the block's own table of words
     *   base        a valid save of the block's own fields, in Dutch
     *   word        a word of the block to change, or null: the block has none, its switch changes instead
     *   list        the list's name on the wire
     *   table       the child table
     *   row         a valid new row; `{media}` is a library item of this test's own
     *   image       'optional' when a row may have no picture (the others need one)
     */
    private const CASES = [
        'faq' => [
            'block' => 'faq',
            'screen' => '/admin/faq.php?section={section}',
            'endpoint' => '/api/admin/update-faq-section.php',
            'parent' => 'faq_sections',
            'base' => ['is_active' => '1', 'eyebrow' => 'Vragen', 'title' => 'Veelgestelde vragen'],
            'word' => 'title',
            'list' => 'items',
            'table' => 'faq_items',
            'row' => ['question' => 'Hoe lang duurt het?', 'answer' => 'Een week.', 'active' => '1'],
        ],
        'stat_strip' => [
            'block' => 'stat_strip',
            'screen' => '/admin/stat-strip.php?section={section}',
            'endpoint' => '/api/admin/update-stat-strip.php',
            'parent' => 'stat_strips',
            'base' => ['is_active' => '1'],
            'word' => null,
            'list' => 'items',
            'table' => 'stat_strip_items',
            'row' => ['primary_text' => '12+', 'secondary_text' => 'jaar ervaring', 'active' => '1'],
        ],
        'step_list' => [
            'block' => 'step_list',
            'screen' => '/admin/step-list.php?section={section}',
            'endpoint' => '/api/admin/update-step-list-section.php',
            'parent' => 'step_list_sections',
            'base' => ['is_active' => '1', 'eyebrow' => 'Werkwijze', 'title' => 'In drie stappen'],
            'word' => 'title',
            'list' => 'items',
            'table' => 'step_list_items',
            'row' => ['title' => 'Kennismaken', 'body' => 'We bespreken wat je nodig hebt.', 'active' => '1'],
        ],
        'marquee' => [
            'block' => 'marquee',
            'screen' => '/admin/marquee.php?section={section}',
            'endpoint' => '/api/admin/update-marquee-section.php',
            'parent' => 'marquee_sections',
            'base' => ['is_active' => '1'],
            'word' => null,
            'list' => 'items',
            'table' => 'marquee_items',
            'row' => ['label' => 'Duurzaam', 'active' => '1'],
        ],
        'feature_grid' => [
            'block' => 'feature_grid',
            'screen' => '/admin/feature-grid.php?section={section}',
            'endpoint' => '/api/admin/update-feature-grid.php',
            'parent' => 'feature_grids',
            'base' => ['is_active' => '1', 'eyebrow' => 'Waarom wij', 'title' => 'Wat je van ons krijgt', 'lead' => 'Kort gezegd.'],
            'word' => 'title',
            'list' => 'items',
            'table' => 'feature_grid_items',
            'row' => ['icon_key' => 'heart', 'title' => 'Snel geleverd', 'body' => 'Binnen een week in huis.', 'active' => '1'],
        ],
        // One per website, on the homepage: this test borrows it and puts it
        // back as it was (place(), tearDown()).
        'homepage_hero' => [
            'block' => 'homepage_hero',
            'screen' => '/admin/homepage-hero.php',
            'endpoint' => '/api/admin/update-homepage-hero.php',
            'parent' => 'homepage_hero',
            'base' => [
                'eyebrow' => 'Welkom', 'title' => 'Wij maken het', 'title_highlight' => '', 'lead' => '', 'primary_label' => 'Bekijk',
                'secondary_label' => '', 'image_alt' => 'Een werkplaats', 'badge_title' => '', 'badge_text' => '',
                'title_highlight_size' => '100', 'primary_link_type' => 'url', 'primary_url' => '/contact',
                'secondary_link_type' => 'none', 'secondary_url' => '', 'media_type' => 'image', 'layout' => 'media_right',
            ],
            'word' => 'title',
            'list' => 'stats',
            'table' => 'homepage_hero_stats',
            'row' => ['primary_text' => '300+', 'secondary_text' => 'projecten', 'active' => '1'],
        ],
        'detail_section_points' => [
            'block' => 'detail_section',
            'screen' => '/admin/detail-section.php?section={section}',
            'endpoint' => '/api/admin/update-detail-section.php',
            'parent' => 'detail_sections',
            'base' => [
                'anchor' => 'hout', 'image_position' => 'image_right', 'cta_url' => '/contact', 'is_active' => '1',
                'nav_label' => 'Hout', 'title' => 'Hout graveren', 'lead' => 'Warm en tijdloos.', 'body' => '<p>Elk stuk is uniek.</p>',
                'main_image_alt' => '', 'closing_note' => 'Op aanvraag.', 'cta_label' => 'Vraag een offerte',
            ],
            'word' => 'title',
            'list' => 'points',
            'table' => 'detail_section_points',
            'row' => ['title' => 'Massief eiken', 'body' => 'Uit Europese bossen.', 'active' => '1'],
        ],
        'detail_section_images' => [
            'block' => 'detail_section',
            'screen' => '/admin/detail-section.php?section={section}',
            'endpoint' => '/api/admin/update-detail-section.php',
            'parent' => 'detail_sections',
            'base' => [
                'anchor' => 'hout', 'image_position' => 'image_right', 'cta_url' => '/contact', 'is_active' => '1',
                'nav_label' => 'Hout', 'title' => 'Hout graveren', 'lead' => 'Warm en tijdloos.', 'body' => '<p>Elk stuk is uniek.</p>',
                'main_image_alt' => '', 'closing_note' => 'Op aanvraag.', 'cta_label' => 'Vraag een offerte',
            ],
            'word' => 'title',
            'list' => 'images',
            'table' => 'detail_section_images',
            'row' => ['media_id' => '{media}', 'alt' => 'Een eiken tafelblad'],
        ],
        // A picture is optional on an item (`image` => optional): an empty
        // choice removes it, which testAnItemsPictureIsOptionalAndAnEmptyItemIsRefused proves.
        'text_image_split_items' => [
            'block' => 'text_image_split',
            'screen' => '/admin/text-image-split.php?section={section}',
            'endpoint' => '/api/admin/update-text-image-split-section.php',
            'parent' => 'text_image_splits',
            'base' => ['is_active' => '1'],
            'word' => null,
            'list' => 'items',
            'table' => 'text_image_split_items',
            'row' => [
                'title' => 'Het verhaal', 'body' => '<p>Wij maken alles op maat.</p>', 'button_label' => 'Neem contact op',
                'media_id' => '{media}', 'alt' => 'De werkplaats van binnen', 'button_url' => '/contact',
                'image_side' => 'left', 'image_column' => '25', 'image_height' => 'small', 'image_focus' => 'bottom',
            ],
            'image' => 'optional',
        ],
    ];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    private int $parentId = 0;

    private string $section = '';

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var array{hero: array<string, mixed>, stats: list<array<string, mixed>>, words: list<array<string, mixed>>}|null the borrowed homepage hero as it was */
    private ?array $heroBefore = null;

    private bool $addedGerman = false;

    /** @var list<string> files this test wrote: temporary uploads and stored ones */
    private array $files = [];

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

        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Rijeneditortest'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();
        $this->restoreHero();

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];

        // After the page: a row that shows a library item keeps it from going.
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
    public static function cases(): array
    {
        $cases = [];
        foreach (array_keys(self::CASES) as $case) {
            $cases[$case] = [$case];
        }

        return $cases;
    }

    // ------------------------------------------------------------ the screen

    /**
     * @dataProvider cases
     */
    public function testTheScreenIsOneFormWithOneSaveAndOneLanguageBar(string $case): void
    {
        $this->place($case);
        $session = $this->signIn('en');
        $this->seed($session, $case, 2);

        $screen = $this->xpath($this->screen($session, $case));

        self::assertSame(1, $screen->query('//main//form[@method="post"]')->length, 'one form on the screen');
        self::assertSame(1, $screen->query('//main//form//button[@type="submit" and not(@name) and not(contains(@class, "admin-visually-hidden"))]')->length, 'one visible Opslaan');
        self::assertSame(0, $screen->query('//main//form//*[contains(@class, "admin-inline-form")]')->length, 'no form per action');
        self::assertLessThanOrEqual(1, $screen->query('//main//*[contains(@class, "admin-localized-bar")]')->length, 'the language bar once, not per row');
        foreach ($screen->query('//main//form//button[@name="editor_action"]') as $button) {
            self::assertMatchesRegularExpression('/^[a-z_]+:(?:up|down|add|edit):/', $button->getAttribute('value') . ':', 'an action is a button of the same form');
        }
    }

    // ------------------------------------------------------------ one save

    /**
     * @dataProvider cases
     */
    public function testOneSaveStoresTheBlockAndARowTogether(string $case): void
    {
        $this->place($case);
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, $case, 2);
        $field = $this->firstWord($case);

        $this->assertSaved($this->save($session, $case, 'nl', $this->blockChange($case), [
            (string) $a => [$field => 'Gewijzigd in dezelfde opslag'] + $this->row($case),
            (string) $b => $this->row($case),
        ]));

        $this->assertBlockChanged($case);
        self::assertSame('Gewijzigd in dezelfde opslag', $this->stored(self::CASES[$case]['table'], $a, 'nl')[$field]);
        self::assertSame([$a, $b], $this->rowIds($case));
    }

    /**
     * @dataProvider cases
     */
    public function testANewOrderAndABlockChangeAreOneSaveAndAMoveWithoutScriptKeepsWhatWasTyped(string $case): void
    {
        $this->place($case);
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, $case, 2);
        $field = $this->firstWord($case);

        $this->assertSaved($this->save($session, $case, 'nl', $this->blockChange($case), [
            (string) $b => $this->row($case),
            (string) $a => $this->row($case),
        ]));
        $this->assertBlockChanged($case);
        self::assertSame([$b, $a], $this->rowIds($case));

        // ↑ on the second row, pressed without JavaScript, with a word typed.
        $this->assertSaved($this->save($session, $case, 'nl', [], [
            (string) $b => $this->row($case),
            (string) $a => [$field => 'Getypt voor de verplaatsing'] + $this->row($case),
        ], self::CASES[$case]['list'] . ':up:' . $a));
        self::assertSame([$a, $b], $this->rowIds($case));
        self::assertSame('Getypt voor de verplaatsing', $this->stored(self::CASES[$case]['table'], $a, 'nl')[$field]);
    }

    /**
     * @dataProvider cases
     */
    public function testARemovedRowGoesWithItsWordsAndTheOtherChangesAreStored(string $case): void
    {
        $this->place($case);
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, $case, 2);
        $table = self::CASES[$case]['table'];
        $field = $this->firstWord($case);
        BlockLocalization::save($table, $a, 'en', [$field => 'English words']);

        $this->assertSaved($this->save($session, $case, 'nl', $this->blockChange($case), [
            (string) $a => ['remove' => '1'] + $this->row($case),
            (string) $b => [$field => 'Blijft en is gewijzigd'] + $this->row($case),
        ]));

        self::assertSame([$b], $this->rowIds($case));
        self::assertSame([], (new BlockTranslationRepository())->findForOwners([$table => [$a]]), 'no word of the removed row in any language');
        self::assertSame('Blijft en is gewijzigd', $this->stored($table, $b, 'nl')[$field]);
        $this->assertBlockChanged($case);
    }

    /**
     * @dataProvider cases
     */
    public function testANewRowIsStoredWithTheOtherChangesAndAnEmptyOneIsNoRow(string $case): void
    {
        $this->place($case);
        $session = $this->signIn(null);
        [$a] = $this->seed($session, $case, 1);
        $field = $this->firstWord($case);

        $this->assertSaved($this->save($session, $case, 'nl', $this->blockChange($case), [
            (string) $a => $this->row($case),
            'new0' => [$field => 'Nieuw toegevoegd'] + $this->row($case),
            'new1' => ['active' => '1'],
        ]));

        $ids = $this->rowIds($case);
        self::assertCount(2, $ids, 'the empty new row is no row');
        self::assertSame($a, $ids[0]);
        self::assertSame('Nieuw toegevoegd', $this->stored(self::CASES[$case]['table'], $ids[1], 'nl')[$field]);
        $this->assertBlockChanged($case);
    }

    // ------------------------------------------------------------ refused

    /**
     * @dataProvider cases
     */
    public function testARefusedSaveStoresNothingAndTheScreenShowsEverythingAsTyped(string $case): void
    {
        $this->place($case);
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, $case, 2);
        $table = self::CASES[$case]['table'];
        $list = self::CASES[$case]['list'];
        $field = $this->firstWord($case);
        $before = $this->snapshot($case);
        $tooLong = str_repeat('x', BlockLocalization::fields($table)[$field]->maxLength + 1);

        $this->assertRefused($this->save($session, $case, 'nl', $this->blockChange($case), [
            (string) $b => [$field => $tooLong] + $this->row($case),
            (string) $a => [$field => 'Getypt en geweigerd'] + $this->row($case),
            'new0' => [$field => 'Nieuwe rij die terugkomt'] + $this->row($case),
        ]));

        self::assertSame($before, $this->snapshot($case), 'nothing of the refused save is stored');

        $screen = $this->xpath($this->screen($session, $case));
        $form = $screen->query('//form[@action="' . self::CASES[$case]['endpoint'] . '"]')->item(0);
        self::assertTrue($form->hasAttribute('data-save-bar-unsaved'), 'the refused input starts out unsaved');
        self::assertSame('Getypt en geweigerd', $this->valueOf($screen, $list . '[' . $a . '][' . $field . ']'));
        self::assertSame('Nieuwe rij die terugkomt', $this->valueOf($screen, $list . '[new0][' . $field . ']'));
        self::assertSame('true', $this->control($screen, $list . '[' . $b . '][' . $field . ']')->getAttribute('aria-invalid'), 'the message is at its field');

        // The order as typed: the refused row first.
        $keys = [];
        foreach ($screen->query('//form//*[@data-row-list="' . $this->listId($case) . '"]/*[@data-row-list-row]//input[contains(@name, "[present]")]') as $marker) {
            preg_match('/\[([a-z0-9]+)\]\[present\]$/', $marker->getAttribute('name'), $match);
            $keys[] = $match[1];
        }
        self::assertSame([(string) $b, (string) $a, 'new0'], $keys);
    }

    // ------------------------------------------------------------ languages

    /**
     * @dataProvider cases
     */
    public function testASaveInOneLanguageLeavesTheOtherAloneAndANewRowIsDefaultLanguage(string $case): void
    {
        $this->place($case);
        [$a] = $this->seed($this->signIn(null), $case, 1);
        $table = self::CASES[$case]['table'];
        $field = $this->firstWord($case);
        $dutch = $this->stored($table, $a, 'nl');
        $session = $this->signIn('en');

        $this->assertSaved($this->save($session, $case, 'en', [], [
            (string) $a => [$field => 'English words'] + $this->neutral($case),
            'new0' => [$field => 'Typed on the English screen'] + $this->row($case),
        ]));

        self::assertSame($dutch, $this->stored($table, $a, 'nl'), 'the Dutch words are untouched');
        self::assertSame([$field => 'English words'], $this->stored($table, $a, 'en'));
        [, $new] = $this->rowIds($case);
        self::assertSame('Typed on the English screen', $this->stored($table, $new, 'nl')[$field], 'a new row is written in the default language');
        self::assertSame([], $this->stored($table, $new, 'en'));
    }

    /**
     * @dataProvider cases
     */
    public function testAThirdLanguageNeedsOnlyARowInTheRegistryAndARemovedRowLeavesNoOrphan(string $case): void
    {
        $this->place($case);
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        [$a, $b] = $this->seed($this->signIn(null), $case, 2);
        $table = self::CASES[$case]['table'];
        $field = $this->firstWord($case);
        $session = $this->signIn('de');

        $this->assertSaved($this->save($session, $case, 'de', [], [
            (string) $a => [$field => 'Deutsch'] + $this->neutral($case),
            (string) $b => $this->neutral($case),
        ]));
        self::assertSame([$field => 'Deutsch'], $this->stored($table, $a, 'de'));
        self::assertSame('Rij 1', $this->stored($table, $a, 'nl')[$field]);

        // Removed from the German screen: its words go in every language.
        $this->assertSaved($this->save($session, $case, 'de', [], [
            (string) $a => ['remove' => '1'] + $this->neutral($case),
            (string) $b => $this->neutral($case),
        ]));
        self::assertSame([$b], $this->rowIds($case));
        BlockLocalization::clearCache();
        self::assertSame([], array_values(array_filter(
            BlockLocalization::orphans()['missing_owner'],
            static fn (array $row): bool => $row['owner_table'] === $table && $row['owner_id'] === $a
        )), 'nothing is left for the orphan check to purge');
    }

    // ------------------------------------------------------------ rules of one list

    public function testACardsIconAndSwitchAreStoredWithItsWordsAndAnUnknownIconBecomesTheFirst(): void
    {
        $this->place('feature_grid');
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, 'feature_grid', 2);

        $this->assertSaved($this->save($session, 'feature_grid', 'nl', ['title' => 'Blok gewijzigd'], [
            (string) $a => ['icon_key' => 'diamond', 'title' => 'Andere titel', 'body' => 'Tekst'],
            (string) $b => ['icon_key' => '<svg onload=x>'] + $this->row('feature_grid'),
        ]));

        $icons = [];
        $stmt = Database::connection()->prepare('SELECT id, icon_key, is_active FROM feature_grid_items WHERE feature_grid_id = ? ORDER BY sort_order');
        $stmt->execute([$this->parentId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $icons[(int) $row['id']] = [$row['icon_key'], (int) $row['is_active']];
        }

        self::assertSame([$a => ['diamond', 0], $b => [(string) array_key_first(\App\Service\FeatureGridContent::ICON_KEYS), 1]], $icons);
        self::assertSame('Andere titel', $this->stored('feature_grid_items', $a, 'nl')['title']);
    }

    /**
     * @return array<string, array{string}> the lists whose rows show a Media Library item
     */
    public static function imageLists(): array
    {
        $cases = [];
        foreach (self::CASES as $case => $spec) {
            if (($spec['row']['media_id'] ?? null) === '{media}' && ($spec['image'] ?? 'required') === 'required') {
                $cases[$case] = [$case];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider imageLists
     */
    public function testChoosingAnotherImageIsStoredWithTheOtherChangesAndAnEmptyChoiceKeepsTheImage(string $case): void
    {
        $this->place($case);
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, $case, 2);
        $table = self::CASES[$case]['table'];
        $other = $this->mediaItem(1);

        // Another image for one row, an alt text for the other, a block word: one save.
        $this->assertSaved($this->save($session, $case, 'nl', $this->blockChange($case), [
            (string) $a => ['media_id' => (string) $other] + $this->row($case),
            (string) $b => ['alt' => 'Ander alt'] + $this->row($case),
        ]));
        self::assertSame([$a => $other, $b => $this->mediaItem()], $this->mediaOf($case));
        self::assertSame('Ander alt', $this->stored($table, $b, 'nl')['alt']);
        $this->assertBlockChanged($case);

        // A stored image whose field comes back empty keeps what it shows.
        $this->assertSaved($this->save($session, $case, 'nl', [], [
            (string) $a => ['media_id' => ''] + $this->row($case),
            (string) $b => $this->row($case),
        ]));
        self::assertSame([$a => $other, $b => $this->mediaItem()], $this->mediaOf($case));

        // A new row needs an image; an id the library does not have is refused.
        $before = $this->snapshot($case);
        $this->assertRefused($this->save($session, $case, 'nl', [], [
            (string) $a => $this->row($case),
            'new0' => ['media_id' => '', 'alt' => 'Zonder afbeelding'],
        ]));
        $this->assertRefused($this->save($session, $case, 'nl', [], [
            (string) $a => ['media_id' => '999999999'] + $this->row($case),
        ]));
        self::assertSame($before, $this->snapshot($case));
    }

    /**
     * Tekst met afbeelding 2.0: an item's layout is four closed lists, stored
     * with its words in the one save; a key the lists do not have becomes the
     * default. The body is rich text, sanitized on the way in.
     */
    public function testAnItemsLayoutAndRichBodyAreStoredAndAnUnknownKeyBecomesTheDefault(): void
    {
        $this->place('text_image_split_items');
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, 'text_image_split_items', 2);
        $row = $this->row('text_image_split_items');

        $this->assertSaved($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => ['image_side' => 'right', 'image_column' => '75', 'image_height' => 'large', 'image_focus' => 'top-right',
                'body' => '<p>Veilig <strong>vet</strong></p><script>alert(1)</script>'] + $row,
            (string) $b => ['image_side' => 'middle', 'image_column' => '33', 'image_height' => 'huge', 'image_focus' => 'nowhere'] + $row,
        ]));

        self::assertSame(
            [
                $a => ['image_side' => 'right', 'image_column' => '75', 'image_height' => 'large', 'image_focus' => 'top-right'],
                $b => ['image_side' => 'right', 'image_column' => '50', 'image_height' => 'medium', 'image_focus' => 'center'],
            ],
            $this->itemLayouts()
        );
        self::assertSame('<p>Veilig <strong>vet</strong></p>', $this->stored('text_image_split_items', $a, 'nl')['body']);
    }

    /**
     * An item's layout is the same in every language: changed on the English
     * screen it changes for Dutch too, and the Dutch words stay as they were.
     * An alt text sent back exactly as the library has it is the library's,
     * not a copy (BlockImage::ownAltInRows(), MEDIA.md); a changed one is the
     * item's own.
     */
    public function testAnItemsLayoutIsSharedByEveryLanguageAndTheLibrarysAltIsNoCopy(): void
    {
        $this->place('text_image_split_items');
        [$a] = $this->seed($this->signIn(null), 'text_image_split_items', 1);
        $dutch = $this->stored('text_image_split_items', $a, 'nl');
        $neutral = $this->neutral('text_image_split_items');

        $this->assertSaved($this->save($this->signIn('en'), 'text_image_split_items', 'en', [], [
            (string) $a => ['title' => 'The story', 'image_side' => 'right', 'image_column' => '75', 'image_height' => 'large', 'image_focus' => 'top-left'] + $neutral,
        ]));
        self::assertSame([$a => ['image_side' => 'right', 'image_column' => '75', 'image_height' => 'large', 'image_focus' => 'top-left']], $this->itemLayouts());
        self::assertSame($dutch, $this->stored('text_image_split_items', $a, 'nl'), 'the Dutch words are untouched');
        self::assertSame(['title' => 'The story'], $this->stored('text_image_split_items', $a, 'en'));

        $session = $this->signIn(null);
        $this->assertSaved($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => ['alt' => 'Plank'] + $this->row('text_image_split_items'),
        ]));
        self::assertArrayNotHasKey('alt', $this->stored('text_image_split_items', $a, 'nl'), 'the library\'s alt text is not copied');
        $this->assertSaved($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => ['alt' => 'Een plank van eiken'] + $this->row('text_image_split_items'),
        ]));
        self::assertSame('Een plank van eiken', $this->stored('text_image_split_items', $a, 'nl')['alt']);
    }

    /**
     * A picture is optional on an item, and "Wissen" removes it; a picture
     * from before the library, which the picker cannot show, stays while its
     * field comes back empty. An item needs text or a picture: an empty one
     * is refused at the item, and nothing of that save is stored.
     */
    public function testAnItemsPictureIsOptionalAndAnEmptyItemIsRefused(): void
    {
        $this->place('text_image_split_items');
        $session = $this->signIn(null);
        [$a, $b] = $this->seed($session, 'text_image_split_items', 2);
        $row = $this->row('text_image_split_items');
        $legacy = '/assets/images/__block_row_editors_legacy__.jpg';
        Database::connection()->prepare('UPDATE text_image_split_items SET media_id = NULL, image_path = ? WHERE id = ?')->execute([$legacy, $b]);

        // Cleared on the first item; the pre-library picture of the second
        // stays; a new item with only a picture is an item.
        $this->assertSaved($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => ['media_id' => ''] + $row,
            (string) $b => ['media_id' => ''] + $row,
            'new0' => ['title' => '', 'body' => '', 'button_label' => '', 'alt' => ''] + $row,
        ]));
        [$first, $second, $imageOnly] = $this->rowIds('text_image_split_items');
        self::assertSame([$a, $b], [$first, $second]);
        self::assertSame([$a => 0, $b => 0, $imageOnly => $this->mediaItem()], $this->mediaOf('text_image_split_items'));
        $stmt = Database::connection()->prepare('SELECT image_path FROM text_image_split_items WHERE id = ?');
        $stmt->execute([$b]);
        self::assertSame($legacy, $stmt->fetchColumn());
        $stmt->execute([$a]);
        self::assertNull($stmt->fetchColumn(), 'a cleared picture leaves no path behind');

        // Neither text nor a picture: refused, at the item, and nothing stored.
        $before = $this->snapshot('text_image_split_items');
        $empty = ['eyebrow' => '', 'title' => '', 'body' => '', 'button_label' => '', 'alt' => '', 'media_id' => ''];
        $this->assertRefused($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => $empty + $row,
            (string) $b => $row,
        ]));
        self::assertSame($before, $this->snapshot('text_image_split_items'));
        $screen = $this->xpath($this->screen($session, 'text_image_split_items'));
        self::assertSame('true', $this->control($screen, 'items[' . $a . '][title]')->getAttribute('aria-invalid'), 'the message is at the item');

        // A button label without an address is no button, so it is no content either.
        $this->assertRefused($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => ['button_label' => 'Alleen een knop', 'button_url' => ''] + $empty + $row,
        ]));
        $this->assertSaved($this->save($session, 'text_image_split_items', 'nl', [], [
            (string) $a => ['button_label' => 'Alleen een knop', 'button_url' => '/contact'] + $empty + $row,
        ]));
    }

    /**
     * The empty item a new row starts from carries everything a row needs on
     * the screen: the rich-text field, the Media picker, the alt text, the
     * three layout choices and the focus point. A row added without a
     * request works like one the server printed (row-list.js, admin.js and
     * image-focus.js are delegated or re-run for it).
     */
    public function testTheTemplateOfANewItemHasEveryPartOfAnItem(): void
    {
        $this->place('text_image_split_items');
        $html = $this->screen($this->signIn(null), 'text_image_split_items');
        self::assertSame(1, preg_match('~<template data-row-list-template="text-image-split-items">(.*?)</template>~s', $html, $match));

        foreach ([
            'data-richtext-field', 'name="items[__KEY__][body]"', 'data-media-picker', 'name="items[__KEY__][media_id]"',
            'name="items[__KEY__][alt]"', 'data-image-focus', 'name="items[__KEY__][image_focus]" value="center" data-object-position="50% 50%" checked',
            'name="items[__KEY__][image_side]" value="right" checked', 'name="items[__KEY__][image_column]" value="50" data-tis-column="50" checked',
            'name="items[__KEY__][image_height]" value="medium" data-tis-height="medium" checked',
        ] as $part) {
            self::assertStringContainsString($part, $match[1]);
        }
        self::assertStringContainsString('/admin/assets/image-focus.js', $html);
        self::assertStringContainsString('/admin/assets/vendor/quill/quill.min.js', $html);
    }

    public function testTheDetailSectionsMainImageIsPartOfTheOneSaveAndClearingItKeepsTheOtherChanges(): void
    {
        $this->place('detail_section_points');
        $session = $this->signIn(null);
        [$a] = $this->seed($session, 'detail_section_points', 1);
        $media = $this->mediaItem();
        $main = static fn (int $id): ?int => ($row = (new \App\Repository\DetailSectionRepository())->findById($id)) === null || $row['main_media_id'] === null ? null : (int) $row['main_media_id'];

        // Choosing the main image, typing its alt text, a title and a kenmerk: one save.
        $this->assertSaved($this->save($session, 'detail_section_points', 'nl', ['title' => 'Blok gewijzigd', 'main_media_id' => (string) $media, 'main_image_alt' => 'Een plank'], [
            (string) $a => ['title' => 'Kenmerk gewijzigd'] + $this->row('detail_section_points'),
        ]));
        self::assertSame($media, $main($this->parentId));
        self::assertSame('Een plank', $this->stored('detail_sections', $this->parentId, 'nl')['main_image_alt']);
        self::assertSame('Kenmerk gewijzigd', $this->stored('detail_section_points', $a, 'nl')['title']);
        $this->assertBlockChanged('detail_section_points');

        // Clearing it with other changes in the same save.
        $this->assertSaved($this->save($session, 'detail_section_points', 'nl', ['title' => 'Nog eens', 'main_media_id' => '', 'main_image_alt' => 'Een plank'], [
            (string) $a => ['title' => 'Ook dit'] + $this->row('detail_section_points'),
        ]));
        self::assertNull($main($this->parentId));
        self::assertArrayNotHasKey('main_image_alt', $this->stored('detail_sections', $this->parentId, 'nl'), 'no image, no alt text');
        self::assertSame('Nog eens', $this->stored('detail_sections', $this->parentId, 'nl')['title']);
        self::assertSame('Ook dit', $this->stored('detail_section_points', $a, 'nl')['title']);
    }

    public function testTheHeroKeepsAtMostThreeStats(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);
        $ids = $this->seed($session, 'homepage_hero', 3);
        $before = $this->snapshot('homepage_hero');

        $rows = [];
        foreach ($ids as $id) {
            $rows[(string) $id] = $this->row('homepage_hero');
        }
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd'], $rows + ['new0' => $this->row('homepage_hero')]));
        self::assertSame($before, $this->snapshot('homepage_hero'), 'a fourth stat stores nothing at all');

        // Marking one for removal makes room in the same save.
        $rows[(string) $ids[0]]['remove'] = '1';
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', [], $rows + ['new0' => $this->row('homepage_hero')]));
        self::assertCount(3, $this->rowIds('homepage_hero'));
        self::assertNotContains($ids[0], $this->rowIds('homepage_hero'));
    }

    public function testAnExistingImageWithoutAltTextDoesNotStopASaveOfTheOtherFields(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);
        $this->heroWithAnImageAndNoAlt();

        // Only the title changed; the alt text comes back as empty as it was.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd', 'image_alt' => ''], []));

        self::assertSame('Blok gewijzigd', $this->stored('homepage_hero', $this->parentId, 'nl')['title']);
        self::assertArrayNotHasKey('image_alt', $this->stored('homepage_hero', $this->parentId, 'nl'));
        self::assertSame('assets/images/sections/__cbux2_bestaand.jpg', (string) $this->parentRow('homepage_hero')['image_path'], 'the existing image stays');

        $screen = $this->xpath($this->screen($session, 'homepage_hero'));
        self::assertFalse($this->control($screen, 'image_alt')->hasAttribute('required'), 'the browser does not block the save on it either');
    }

    /**
     * The alt text is required as it always was — with a newly chosen image,
     * or when the editor changes it — but what must not be empty is the text
     * that will be USED: the Hero's own, else the library's. A text that only
     * repeats the library's is stored empty, so it keeps following it.
     */
    public function testANewImageOrAnEditedAltTextKeepsTheOldAltTextRules(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);
        $bare = $this->libraryItem('image/webp', '');
        $described = $this->libraryItem('image/webp', 'Werkplaats met laser');

        // A new image without an alt text anywhere: refused, nothing stored.
        $this->heroWithAnImageAndNoAlt();
        $before = $this->snapshot('homepage_hero');
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd', 'image_alt' => '', 'media_id' => (string) $bare], []));
        self::assertSame($before, $this->snapshot('homepage_hero'));
        $screen = $this->xpath($this->screen($session, 'homepage_hero'));
        self::assertSame('true', $this->control($screen, 'image_alt')->getAttribute('aria-invalid'));
        self::assertSame('Deze afbeelding heeft nog geen alt-tekst', $this->control($screen, 'image_alt')->getAttribute('placeholder'), 'the chosen image comes back, and says it has no alt text');

        // A new image whose library alt text is shown and sent back as it was:
        // saved, and stored as "the library's" — no copy.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd', 'image_alt' => 'Werkplaats met laser', 'media_id' => (string) $described], []));
        self::assertSame($described, (int) $this->parentRow('homepage_hero')['media_id']);
        self::assertArrayNotHasKey('image_alt', $this->stored('homepage_hero', $this->parentId, 'nl'));
        $screen = $this->xpath($this->screen($session, 'homepage_hero'));
        self::assertSame('Werkplaats met laser', $this->valueOf($screen, 'image_alt'), 'the field shows the alt text that is used');
        self::assertSame('media_id', $this->control($screen, 'image_alt')->getAttribute('data-media-alt-for'));
        \App\Service\HomepageHeroContent::clearCache();
        self::assertSame('Werkplaats met laser', \App\Service\HomepageHeroContent::current()['image_alt']);

        // An own alt text is the Hero's own.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['image_alt' => 'Laser aan het werk', 'media_id' => (string) $described], []));
        self::assertSame('Laser aan het werk', $this->stored('homepage_hero', $this->parentId, 'nl')['image_alt']);

        // Emptied, it falls back on the library's again: allowed, since that is not empty.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['image_alt' => '', 'media_id' => (string) $described], []));
        self::assertArrayNotHasKey('image_alt', $this->stored('homepage_hero', $this->parentId, 'nl'));

        // An own alt text emptied on an image the library has none for: refused.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['image_alt' => 'Eigen tekst', 'media_id' => (string) $bare], []));
        $before = $this->snapshot('homepage_hero');
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['image_alt' => '', 'media_id' => (string) $bare], []));
        self::assertSame($before, $this->snapshot('homepage_hero'));

        // Too long is too long, whatever else happens.
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['image_alt' => str_repeat('a', 256), 'media_id' => (string) $bare], []));
        self::assertSame($before, $this->snapshot('homepage_hero'));

        // A translation never requires it, and keeps what it was given.
        $this->assertSaved($this->save($this->signIn('en'), 'homepage_hero', 'en', ['title' => 'Changed', 'media_id' => (string) $described, 'image_alt' => 'Werkplaats met laser'], []));
        self::assertSame('Werkplaats met laser', $this->stored('homepage_hero', $this->parentId, 'en')['image_alt']);
    }

    /**
     * The image and the video are library items, saved with the words and the
     * stats in one save; a refused save stores none of it and hands the
     * choices back. Choosing video without a video keeps the choice.
     */
    public function testTheImageAndTheVideoComeFromTheLibraryInTheOneSave(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);
        [$a] = $this->seed($session, 'homepage_hero', 1);
        $image = $this->libraryItem('image/webp', 'Werkplaats');
        $video = $this->libraryItem('video/mp4', '');
        $before = $this->snapshot('homepage_hero');

        // Refused (the stat's words are too long): nothing stored, the choices come back.
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd', 'media_id' => (string) $image, 'video_media_id' => (string) $video], [
            (string) $a => ['primary_text' => str_repeat('x', 101)] + $this->row('homepage_hero'),
        ]));
        self::assertSame($before, $this->snapshot('homepage_hero'));
        $screen = $this->xpath($this->screen($session, 'homepage_hero'));
        self::assertSame((string) $image, $this->valueOf($screen, 'media_id'));
        self::assertSame((string) $video, $this->valueOf($screen, 'video_media_id'));

        // Accepted: both items, a title and a stat in one save.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd', 'media_id' => (string) $image, 'video_media_id' => (string) $video, 'media_type' => 'video', 'layout' => 'background'], [
            (string) $a => ['primary_text' => '12+'] + $this->row('homepage_hero'),
        ]));

        $hero = $this->parentRow('homepage_hero');
        self::assertSame([$image, $video], [(int) $hero['media_id'], (int) $hero['video_media_id']]);
        self::assertSame(MediaService::find($image)?->path, $hero['image_path'], 'the old column follows the chosen item');
        self::assertSame(['video', 'background'], [$hero['media_type'], $hero['layout']]);
        self::assertSame('Blok gewijzigd', $this->stored('homepage_hero', $this->parentId, 'nl')['title']);
        self::assertSame('12+', $this->stored('homepage_hero_stats', $a, 'nl')['primary_text']);

        \App\Service\HomepageHeroContent::clearCache();
        $content = \App\Service\HomepageHeroContent::current();
        self::assertSame(MediaService::find($video)?->publicPath(), $content['video_path']);
        self::assertSame(MediaService::find($image)?->publicPath(), $content['image_path']);
        self::assertSame('video', $content['media_type']);

        // The item that is used cannot be deleted from the library.
        $usages = (new \App\Service\Media\Usage\ContentBlockMediaUsage())->usagesFor([$image, $video]);
        self::assertSame('/admin/homepage-hero.php', $usages[$image][0]->editUrl);
        self::assertSame('/admin/homepage-hero.php', $usages[$video][0]->editUrl);
    }

    /** A video sent as the image, an image sent as the video, or an id that names nothing: refused at its field. */
    public function testAWrongOrUnknownMediaItemIsRefusedAtItsField(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);
        $image = $this->libraryItem('image/webp', 'Werkplaats');
        $video = $this->libraryItem('video/webm', '');
        $before = $this->snapshot('homepage_hero');

        foreach ([['media_id' => (string) $video], ['video_media_id' => (string) $image], ['media_id' => '999999999']] as $wrong) {
            $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd', 'image_alt' => 'x'] + $wrong, []), (string) json_encode($wrong));
            self::assertSame($before, $this->snapshot('homepage_hero'));
            $screen = $this->xpath($this->screen($session, 'homepage_hero'));
            self::assertSame('Blok gewijzigd', $this->valueOf($screen, 'title'), 'the typed title comes back');
        }
    }

    /**
     * A Hero from before the library keeps its own files until another is
     * chosen or they are ticked away; then an admin upload is deleted after
     * the commit.
     */
    public function testAPreLibraryImageAndVideoStayUntilReplacedOrRemoved(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);
        $root = dirname(__DIR__, 2);
        foreach (['images', 'videos'] as $folder) {
            if (!is_dir($root . '/assets/' . $folder . '/sections')) {
                mkdir($root . '/assets/' . $folder . '/sections', 0755, true);
            }
        }
        $oldImage = 'assets/images/sections/__mhux_oud_' . bin2hex(random_bytes(3)) . '.jpg';
        $oldVideo = 'assets/videos/sections/__mhux_oud_' . bin2hex(random_bytes(3)) . '.mp4';
        file_put_contents($root . '/' . $oldImage, 'x');
        file_put_contents($root . '/' . $oldVideo, 'x');
        $this->files[] = $root . '/' . $oldImage;
        $this->files[] = $root . '/' . $oldVideo;
        Database::connection()->prepare('UPDATE homepage_hero SET image_path = ?, video_path = ? WHERE id = ?')->execute([$oldImage, $oldVideo, $this->parentId]);

        // A save of the words keeps both.
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['title' => 'Blok gewijzigd'], []));
        $hero = $this->parentRow('homepage_hero');
        self::assertSame([$oldImage, $oldVideo], [$hero['image_path'], $hero['video_path']]);
        self::assertFileExists($root . '/' . $oldImage);
        \App\Service\HomepageHeroContent::clearCache();
        self::assertSame('/' . $oldImage, \App\Service\HomepageHeroContent::current()['image_path'], 'still shown on the website');

        // Another image chosen, the old video ticked away: both old files go.
        $image = $this->libraryItem('image/webp', 'Werkplaats');
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['media_id' => (string) $image, 'remove_legacy_video' => '1'], []));
        $hero = $this->parentRow('homepage_hero');
        self::assertSame($image, (int) $hero['media_id']);
        self::assertSame('', (string) $hero['video_path']);
        self::assertFileDoesNotExist($root . '/' . $oldImage);
        self::assertFileDoesNotExist($root . '/' . $oldVideo);
    }

    /**
     * Each button points at a page by id (following its address) or at an
     * own address; a Hero from before link types renders its address as it
     * always did. The secondary button may be "Geen knop", and needs a label
     * when it is one.
     */
    public function testTheButtonsPointAtAPageByIdOrAtAnOwnAddress(): void
    {
        $this->place('homepage_hero');
        $session = $this->signIn(null);

        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', [
            'primary_link_type' => 'page',
            'primary_link_target' => ['page' => (string) $this->pageId],
            'secondary_link_type' => 'url',
            'secondary_url' => 'https://example.com/folder',
            'secondary_label' => 'Folder',
        ], []));
        $hero = $this->parentRow('homepage_hero');
        self::assertSame(['page', $this->pageId, 'url'], [$hero['primary_link_type'], (int) $hero['primary_link_target_id'], $hero['secondary_link_type']]);

        \App\Service\HomepageHeroContent::clearCache();
        $content = \App\Service\HomepageHeroContent::current();
        $page = (new PageRepository())->findById($this->pageId);
        self::assertSame(PageContent::publicUrl($page), $content['primary_url'], 'the page, at its own address');
        self::assertSame('https://example.com/folder', $content['secondary_url']);

        // The page moves: the button follows without a save of the Hero.
        Database::connection()->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute([self::KEY . '-verhuisd', $this->pageId]);
        PageContent::clearCache();
        \App\Service\Routing\LinkTargets::reset();
        \App\Service\HomepageHeroContent::clearCache();
        self::assertStringContainsString(self::KEY . '-verhuisd', \App\Service\HomepageHeroContent::current()['primary_url']);

        // A secondary button without a label is refused; "Geen knop" with none is fine.
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['secondary_link_type' => 'url', 'secondary_url' => '/contact', 'secondary_label' => ''], []));
        $this->assertSaved($this->save($session, 'homepage_hero', 'nl', ['secondary_link_type' => 'none', 'secondary_url' => '', 'secondary_label' => ''], []));
        self::assertNull($this->parentRow('homepage_hero')['secondary_link_type']);

        // The primary button has no "Geen knop", and an own address must be one.
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['primary_link_type' => 'none'], []));
        $this->assertRefused($this->save($session, 'homepage_hero', 'nl', ['primary_link_type' => 'url', 'primary_url' => 'javascript:alert(1)'], []));

        // Written before link types existed: an address and no type, rendered as the address.
        Database::connection()->prepare("UPDATE homepage_hero SET primary_link_type = NULL, primary_link_target_id = NULL, primary_url = '/contact' WHERE id = ?")->execute([$this->parentId]);
        \App\Service\HomepageHeroContent::clearCache();
        self::assertSame('/contact', \App\Service\HomepageHeroContent::current()['primary_url']);
        // A fresh session: the refused saves above left what was typed in this one.
        $screen = $this->xpath($this->screen($this->signIn(null), 'homepage_hero'));
        self::assertSame('/contact', $this->valueOf($screen, 'primary_url'));
        self::assertSame('url', $screen->query('//select[@name="primary_link_type"]/option[@selected]')->item(0)?->getAttribute('value'), 'read as an own address');
        self::assertSame(0, $screen->query('//select[@name="primary_link_type"]/option[@value="none"]')->length, 'the primary button has no "Geen knop"');
    }

    // ------------------------------------------------------------ helpers

    /** Snapshot the homepage hero, its stats and all their words, and start from a known one without stats or image. */
    private function borrowHero(): void
    {
        $db = Database::connection();
        $heroes = new \App\Repository\HomepageHeroRepository();
        $hero = $heroes->findBySlug(\App\Service\HomepageHeroContent::PAGE_SLUG);
        if ($hero === null) {
            BlockDefinitions::get('homepage_hero')->create(\App\Service\HomepageHeroContent::PAGE_SLUG);
            $hero = $heroes->findBySlug(\App\Service\HomepageHeroContent::PAGE_SLUG);
        }
        $id = (int) $hero['id'];

        $stats = $db->prepare('SELECT * FROM homepage_hero_stats WHERE homepage_hero_id = ?');
        $stats->execute([$id]);
        $stats = $stats->fetchAll(\PDO::FETCH_ASSOC);
        $words = $db->prepare("SELECT * FROM block_translations WHERE (owner_table = 'homepage_hero' AND owner_id = ?) OR (owner_table = 'homepage_hero_stats' AND owner_id IN (SELECT id FROM homepage_hero_stats WHERE homepage_hero_id = ?))");
        $words->execute([$id, $id]);
        $this->heroBefore = ['hero' => $hero, 'stats' => $stats, 'words' => $words->fetchAll(\PDO::FETCH_ASSOC)];

        $this->clearHero($id);
        $db->prepare("UPDATE homepage_hero SET image_path = '', media_id = NULL, video_path = NULL, video_media_id = NULL, primary_link_type = 'url', primary_link_target_id = NULL, secondary_link_type = NULL, secondary_link_target_id = NULL WHERE id = ?")->execute([$id]);
        BlockLocalization::save('homepage_hero', $id, 'nl', array_intersect_key(self::CASES['homepage_hero']['base'], BlockLocalization::fields('homepage_hero')));
        BlockLocalization::clearCache();
        \App\Service\HomepageHeroContent::clearCache();

        $this->parentId = $id;
        $this->section = '';
    }

    /** The Hero as development has it: an image, and no alt text in the default language. */
    private function heroWithAnImageAndNoAlt(): void
    {
        Database::connection()->prepare("UPDATE homepage_hero SET image_path = 'assets/images/sections/__cbux2_bestaand.jpg' WHERE id = ?")->execute([$this->parentId]);
        BlockLocalization::save('homepage_hero', $this->parentId, 'nl', ['image_alt' => ''] + array_intersect_key(self::CASES['homepage_hero']['base'], BlockLocalization::fields('homepage_hero')));
        BlockLocalization::clearCache();
        \App\Service\HomepageHeroContent::clearCache();
    }

    /** Put the borrowed hero back exactly as it was: its row, its stats and every word, with their ids. */
    private function restoreHero(): void
    {
        if ($this->heroBefore === null) {
            return;
        }

        $db = Database::connection();
        $hero = $this->heroBefore['hero'];
        $this->clearHero((int) $hero['id']);

        $columns = array_keys($hero);
        $db->prepare('UPDATE homepage_hero SET ' . implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", $columns)) . ' WHERE id = ?')
            ->execute([...array_values($hero), $hero['id']]);
        foreach (['stats' => 'homepage_hero_stats', 'words' => 'block_translations'] as $key => $table) {
            foreach ($this->heroBefore[$key] as $row) {
                $db->prepare("INSERT INTO `{$table}` (`" . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                    ->execute(array_values($row));
            }
        }

        $this->heroBefore = null;
        BlockLocalization::clearCache();
        \App\Service\HomepageHeroContent::clearCache();
    }

    private function clearHero(int $id): void
    {
        $db = Database::connection();
        $db->prepare("DELETE FROM block_translations WHERE (owner_table = 'homepage_hero' AND owner_id = ?) OR (owner_table = 'homepage_hero_stats' AND owner_id IN (SELECT id FROM homepage_hero_stats WHERE homepage_hero_id = ?))")->execute([$id, $id]);
        $db->prepare('DELETE FROM homepage_hero_stats WHERE homepage_hero_id = ?')->execute([$id]);
    }

    private function place(string $case): void
    {
        $type = self::CASES[$case]['block'];

        if ($type === 'homepage_hero') {
            $this->borrowHero();

            return;
        }
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, $type, $key, $id);
        $this->parentId = (int) $id;
        $this->section = self::KEY . ':' . $key;
    }

    /**
     * Rows made through the editor itself, in one save.
     *
     * @return list<int>
     */
    private function seed(string $session, string $case, int $count): array
    {
        $field = $this->firstWord($case);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows['new' . $i] = [$field => 'Rij ' . ($i + 1)] + $this->row($case);
        }

        $this->assertSaved($this->save($session, $case, 'nl', [], $rows), 'seeding ' . $case);
        $ids = $this->rowIds($case);
        self::assertCount($count, $ids);

        return $ids;
    }

    /**
     * @param array<string, string> $block over the case's valid block fields
     * @param array<string, array<string, string>> $rows key => fields
     * @param array<string, \CURLFile> $files
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, string $case, string $language, array $block, array $rows, ?string $action = null, array $files = []): array
    {
        $spec = self::CASES[$case];
        $base = $language === 'nl' ? $spec['base'] : array_diff_key($spec['base'], BlockLocalization::fields($spec['parent']));

        $fields = $block + $base + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'section' => $this->section,
            'language_code' => $language,
            $spec['list'] . '_present' => '1',
            $spec['list'] => $rows,
        ];
        if ($action !== null) {
            $fields['editor_action'] = $action;
        }
        // An unticked checkbox is not sent.
        $fields = array_filter($fields, static fn ($value): bool => $value !== null);

        // A multipart request carries flat names, like a browser's.
        if ($files !== []) {
            $flat = [];
            foreach ($fields as $name => $value) {
                if (!is_array($value)) {
                    $flat[$name] = $value;
                    continue;
                }
                foreach ($value as $key => $row) {
                    foreach ($row as $field => $v) {
                        $flat[$name . '[' . $key . '][' . $field . ']'] = $v;
                    }
                }
            }
            $fields = $flat;
        }

        $response = self::$server->request('POST', $spec['endpoint'], $session, $fields, $files);
        BlockLocalization::clearCache();

        return $response;
    }

    /** @return array<string, ?string> a change to the block itself: one of its words, or its switch */
    private function blockChange(string $case): array
    {
        $word = self::CASES[$case]['word'];

        return $word === null ? ['is_active' => null] : [$word => 'Blok gewijzigd'];
    }

    private function assertBlockChanged(string $case): void
    {
        $spec = self::CASES[$case];

        if ($spec['word'] === null) {
            self::assertSame(0, (int) $this->parentRow($case)['is_active'], 'the block\'s switch is stored');

            return;
        }

        self::assertSame('Blok gewijzigd', $this->stored($spec['parent'], $this->parentId, 'nl')[$spec['word']] ?? null, 'the block\'s word is stored');
    }

    /** @return array<string, mixed> */
    private function parentRow(string $case): array
    {
        $table = BlockDefinitions::get(self::CASES[$case]['block'])->childTables()[self::CASES[$case]['table']]['parent'];
        $stmt = Database::connection()->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $stmt->execute([$this->parentId]);

        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, string> a valid row, with `{media}` replaced */
    private function row(string $case): array
    {
        return array_map(fn (string $value): string => $value === '{media}' ? (string) $this->mediaItem() : $value, self::CASES[$case]['row']);
    }

    /** @return array<string, string> the row's fields that are no words: the same in every language */
    private function neutral(string $case): array
    {
        return array_diff_key($this->row($case), BlockLocalization::fields(self::CASES[$case]['table']));
    }

    private function firstWord(string $case): string
    {
        return (string) array_key_first(BlockLocalization::fields(self::CASES[$case]['table']));
    }

    private function listId(string $case): string
    {
        return str_replace('_', '-', self::CASES[$case]['block']) . '-' . self::CASES[$case]['list'];
    }

    /** @return list<int> the ids of the list's rows, in their stored order */
    private function rowIds(string $case): array
    {
        $table = self::CASES[$case]['table'];
        $column = BlockDefinitions::get(self::CASES[$case]['block'])->childTables()[$table]['column'];
        $stmt = Database::connection()->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = ? ORDER BY sort_order, id");
        $stmt->execute([$this->parentId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> everything a save could change: the block row and words, the rows, their words */
    private function snapshot(string $case): array
    {
        $table = self::CASES[$case]['table'];
        $column = BlockDefinitions::get(self::CASES[$case]['block'])->childTables()[$table]['column'];
        $stmt = Database::connection()->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = ? ORDER BY sort_order, id");
        $stmt->execute([$this->parentId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $parent = $this->parentRow($case);
        unset($parent['updated_at']);

        return [
            'parent' => $parent,
            'parent_words' => (new BlockTranslationRepository())->findForOwners([self::CASES[$case]['parent'] => [$this->parentId]]),
            'rows' => $rows,
            'row_words' => (new BlockTranslationRepository())->findForOwners([$table => array_map(static fn (array $row): int => (int) $row['id'], $rows)]),
        ];
    }

    /** @return array<int, array<string, string>> the layout of every item of the block, by id */
    private function itemLayouts(): array
    {
        $stmt = Database::connection()->prepare('SELECT id, image_side, image_column, image_height, image_focus FROM text_image_split_items WHERE text_image_split_id = ? ORDER BY sort_order, id');
        $stmt->execute([$this->parentId]);

        $layouts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            unset($row['id']);
            $layouts[$id] = $row;
        }

        return $layouts;
    }

    /** @return array<int, int> row id => the library item it shows, in stored order */
    private function mediaOf(string $case): array
    {
        $table = self::CASES[$case]['table'];
        $column = BlockDefinitions::get(self::CASES[$case]['block'])->childTables()[$table]['column'];
        $stmt = Database::connection()->prepare("SELECT id, media_id FROM `{$table}` WHERE `{$column}` = ? ORDER BY sort_order, id");
        $stmt->execute([$this->parentId]);

        $media = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $media[(int) $row['id']] = (int) $row['media_id'];
        }

        return $media;
    }

    /** A library item of a given type and alt text, for a file that does not exist; removed in tearDown(). */
    private function libraryItem(string $mimeType, string $alt): int
    {
        $extension = $mimeType === 'video/mp4' ? 'mp4' : ($mimeType === 'video/webm' ? 'webm' : 'webp');
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__block_row_editors_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'bestand.' . $extension,
            'mime_type' => $mimeType,
            'alt_text' => $alt,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function mediaItem(int $index = 0): int
    {
        while (!isset($this->mediaIds[$index])) {
            $this->mediaIds[] = (new MediaRepository())->create([
                'path' => 'assets/media/__block_row_editors_' . bin2hex(random_bytes(4)) . '__.webp',
                'original_filename' => 'plank.webp',
                'mime_type' => 'image/webp',
                'width' => 1600,
                'height' => 900,
                'file_size' => 100,
                'alt_text' => 'Plank',
                'checksum' => null,
            ]);
            MediaService::clearCache();
        }

        return $this->mediaIds[$index];
    }

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        if ($editingLanguage !== null) {
            (new AdminUserRepository())->updateContentEditingLanguage((int) $this->accounts->read($session, 'admin_user_id'), $editingLanguage);
        }

        return $session;
    }

    private function screen(string $session, string $case): string
    {
        $response = self::$server->request('GET', strtr(self::CASES[$case]['screen'], ['{section}' => urlencode($this->section)]), $session);
        self::assertSame(200, $response['status'], $case);

        return $response['body'];
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

    private function control(\DOMXPath $xpath, string $name): \DOMElement
    {
        $controls = $xpath->query('//main//form//*[(self::input or self::textarea or self::select) and @name="' . $name . '"]');
        self::assertSame(1, $controls->length, $name);

        return $controls->item(0);
    }

    private function valueOf(\DOMXPath $xpath, string $name): string
    {
        $control = $this->control($xpath, $name);

        return $control->nodeName === 'textarea' ? $control->textContent : $control->getAttribute('value');
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
