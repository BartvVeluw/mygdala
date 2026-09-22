<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\BlockTranslationRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
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
 *  - a removed row takes its words in every language along.
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
    ];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    private int $parentId = 0;

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

        // After the page: a row that shows a library item keeps it from going.
        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];
        MediaService::clearCache();

        $this->accounts->forget();
        BlockLocalization::clearCache();
        PageContent::clearCache();
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

    // ------------------------------------------------------------ helpers

    private function place(string $case): void
    {
        $type = self::CASES[$case]['block'];
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
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, string $case, string $language, array $block, array $rows, ?string $action = null): array
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

        $response = self::$server->request('POST', $spec['endpoint'], $session, $fields);
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

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function mediaItem(): int
    {
        if ($this->mediaIds === []) {
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

        return $this->mediaIds[0];
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
