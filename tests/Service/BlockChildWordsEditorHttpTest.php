<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\BlockTranslationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The child rows of the blocks phase 3B moved onto per-language storage
 * (Multilingual 2.0, docs/multilingual/ARCHITECTURE.md) — a question, a card,
 * a step — through the endpoints an editor uses on them:
 *
 *  - a new item is written in the default language, whatever the screen shows;
 *  - saving an item in one language keeps its id and leaves every other
 *    language of that item, and every other item, exactly as it was;
 *  - an item's words are required only in the default language;
 *  - a third language is a row in site_languages and nothing else;
 *  - deleting an item takes its words in every language in the same
 *    transaction, and nothing of its siblings; no orphan is left to purge.
 *
 * Table-driven: CHILDREN names, per child table, the block that owns it, the
 * three endpoints and the request fields that address an item or its parent.
 * Over real HTTP against PHP's built-in server; the page, its blocks, their
 * words, the accounts and the German registry row are this test's own and
 * are removed in tearDown(). Without a server the test skips itself.
 */
final class BlockChildWordsEditorHttpTest extends TestCase
{
    private const KEY = 'zz-block-child-words-editor-test';

    /**
     * child table => how an editor reaches its rows.
     *
     *   block     the block type that owns the child table
     *   create    endpoint that adds a row; `parent` the request field naming the block row
     *   update    endpoint that saves a row; `item` the request field naming the row
     *   delete    endpoint that deletes a row
     *   settings  language-neutral fields a valid create/update carries
     *   words     Dutch words for every field, valid
     */
    private const CHILDREN = [
        'faq_items' => [
            'block' => 'faq',
            'create' => '/api/admin/create-faq-item.php',
            'update' => '/api/admin/update-faq-item.php',
            'delete' => '/api/admin/delete-faq-item.php',
            'parent' => 'section_id',
            'item' => 'item_id',
            'settings' => ['is_active' => '1'],
            'words' => ['question' => 'Hoe lang duurt het?', 'answer' => 'Een week.'],
        ],
        'feature_grid_items' => [
            'block' => 'feature_grid',
            'create' => '/api/admin/create-feature-grid-item.php',
            'update' => '/api/admin/update-feature-grid-item.php',
            'delete' => '/api/admin/delete-feature-grid-item.php',
            'parent' => 'grid_id',
            'item' => 'item_id',
            'settings' => ['icon_key' => 'heart', 'is_active' => '1'],
            'words' => ['title' => 'Snel geleverd', 'body' => 'Binnen een week in huis.'],
        ],
        'step_list_items' => [
            'block' => 'step_list',
            'create' => '/api/admin/create-step-list-item.php',
            'update' => '/api/admin/update-step-list-item.php',
            'delete' => '/api/admin/delete-step-list-item.php',
            'parent' => 'section_id',
            'item' => 'item_id',
            'settings' => ['is_active' => '1'],
            'words' => ['title' => 'Kennismaken', 'body' => 'We bespreken wat je nodig hebt.'],
        ],
        'stat_strip_items' => [
            'block' => 'stat_strip',
            'create' => '/api/admin/create-stat-strip-item.php',
            'update' => '/api/admin/update-stat-strip-item.php',
            'delete' => '/api/admin/delete-stat-strip-item.php',
            'parent' => 'strip_id',
            'item' => 'item_id',
            'settings' => ['is_active' => '1'],
            'words' => ['primary_text' => '12+', 'secondary_text' => 'jaar ervaring'],
        ],
        'marquee_items' => [
            'block' => 'marquee',
            'create' => '/api/admin/create-marquee-item.php',
            'update' => '/api/admin/update-marquee-item.php',
            'delete' => '/api/admin/delete-marquee-item.php',
            'parent' => 'section_id',
            'item' => 'item_id',
            'settings' => ['is_active' => '1'],
            'words' => ['label' => 'Duurzaam'],
        ],
        // The homepage hero is one per page and cannot be added or deleted by
        // hand, so this test gives it a row of its own (block()).
        'homepage_hero_stats' => [
            'block' => 'homepage_hero',
            'create' => '/api/admin/create-homepage-hero-stat.php',
            'update' => '/api/admin/update-homepage-hero-stat.php',
            'delete' => '/api/admin/delete-homepage-hero-stat.php',
            'parent' => 'hero_id',
            'item' => 'item_id',
            'settings' => ['is_active' => '1'],
            'words' => ['primary_text' => '300+', 'secondary_text' => 'projecten'],
        ],
    ];

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    private bool $addedGerman = false;

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
            'Kindrijentest'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();

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
    public static function children(): array
    {
        $cases = [];
        foreach (array_keys(self::CHILDREN) as $table) {
            $cases[$table] = [$table];
        }

        return $cases;
    }

    /**
     * @dataProvider children
     */
    public function testANewItemIsWrittenInTheDefaultLanguageWhateverTheScreenShows(string $table): void
    {
        $parentId = $this->block($table);
        $session = $this->signIn('en');

        $this->assertSaved($this->create($session, $table, $parentId, self::CHILDREN[$table]['words'] + ['language_code' => 'en']));

        [$itemId] = $this->itemIds($table, $parentId);
        self::assertSame(self::CHILDREN[$table]['words'], $this->stored($table, $itemId, 'nl'), 'the words are the default language\'s');
        self::assertSame([], $this->stored($table, $itemId, 'en'));
    }

    /**
     * @dataProvider children
     */
    public function testANewItemNeedsItsRequiredWords(string $table): void
    {
        $parentId = $this->block($table);
        $session = $this->signIn(null);
        $required = array_keys(array_filter(BlockLocalization::fields($table), static fn ($field): bool => $field->required));

        foreach ($required as $field) {
            $this->assertRefused($this->create($session, $table, $parentId, [$field => ''] + self::CHILDREN[$table]['words']), $table . ': ' . $field);
        }

        self::assertSame([], $this->itemIds($table, $parentId), 'a refused item leaves no row behind');
    }

    /**
     * @dataProvider children
     */
    public function testSavingAnItemInOneLanguageLeavesTheOtherLanguagesAndItemsAlone(string $table): void
    {
        $parentId = $this->block($table);
        $session = $this->signIn(null);
        $words = self::CHILDREN[$table]['words'];
        $field = array_key_first($words);

        $this->assertSaved($this->create($session, $table, $parentId, $words));
        $this->assertSaved($this->create($session, $table, $parentId, [$field => 'Tweede ' . $words[$field]] + $words));
        [$first, $second] = $this->itemIds($table, $parentId);

        $this->assertSaved($this->update($session, $table, $first, 'en', [$field => 'English ' . $field]));
        $this->assertSaved($this->update($session, $table, $first, 'nl', [$field => 'Nieuw ' . $words[$field]] + $words));

        self::assertSame([$first, $second], $this->itemIds($table, $parentId), 'saving keeps every id');
        self::assertSame([$field => 'English ' . $field], $this->stored($table, $first, 'en'), 'saving Dutch did not remove the English words');
        self::assertSame([$field => 'Nieuw ' . $words[$field]] + $words, $this->stored($table, $first, 'nl'));
        self::assertSame([$field => 'Tweede ' . $words[$field]] + $words, $this->stored($table, $second, 'nl'), 'the sibling is untouched');

        // A translation needs no words at all; emptying it falls back again.
        $this->assertSaved($this->update($session, $table, $first, 'en', []));
        self::assertSame([], $this->stored($table, $first, 'en'));
        self::assertSame([$field => 'Nieuw ' . $words[$field]] + $words, $this->stored($table, $first, 'nl'));

        // The default language keeps what it requires.
        foreach (array_keys(array_filter(BlockLocalization::fields($table), static fn ($spec): bool => $spec->required)) as $required) {
            $this->assertRefused($this->update($session, $table, $first, 'nl', [$required => ''] + $words), $table . ': ' . $required);
        }
        $this->assertRefused($this->update($session, $table, $first, 'xx', $words), 'a language the website does not have');
    }

    /**
     * @dataProvider children
     */
    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(string $table): void
    {
        $parentId = $this->block($table);
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        $session = $this->signIn('de');
        $words = self::CHILDREN[$table]['words'];
        $field = array_key_first($words);

        $this->assertSaved($this->create($session, $table, $parentId, $words));
        [$itemId] = $this->itemIds($table, $parentId);
        $this->assertSaved($this->update($session, $table, $itemId, 'de', [$field => 'Deutsch']));

        self::assertSame([$field => 'Deutsch'], $this->stored($table, $itemId, 'de'));
        self::assertSame($words, $this->stored($table, $itemId, 'nl'));
    }

    /**
     * @dataProvider children
     */
    public function testDeletingAnItemTakesItsWordsInEveryLanguageAndNothingElse(string $table): void
    {
        $parentId = $this->block($table);
        $session = $this->signIn(null);
        $words = self::CHILDREN[$table]['words'];
        $field = array_key_first($words);

        $this->assertSaved($this->create($session, $table, $parentId, $words));
        $this->assertSaved($this->create($session, $table, $parentId, $words));
        [$gone, $kept] = $this->itemIds($table, $parentId);
        $this->assertSaved($this->update($session, $table, $gone, 'en', [$field => 'Gone']));

        $response = self::$server->request('POST', self::CHILDREN[$table]['delete'], $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            self::CHILDREN[$table]['item'] => (string) $gone,
        ]);
        $this->assertSaved($response);

        self::assertSame([$kept], $this->itemIds($table, $parentId));
        self::assertSame([], (new BlockTranslationRepository())->findForOwners([$table => [$gone]]), 'no word of the deleted item in any language');
        self::assertSame($words, $this->stored($table, $kept, 'nl'));

        BlockLocalization::clearCache();
        self::assertSame([], array_values(array_filter(
            BlockLocalization::orphans()['missing_owner'],
            static fn (array $row): bool => $row['owner_table'] === $table && $row['owner_id'] === $gone
        )), 'nothing is left for the orphan check to purge');
    }

    // ------------------------------------------------------------ helpers

    /** Places the owning block on the test page and returns the id of the row its children hang under. */
    private function block(string $table): int
    {
        $type = self::CHILDREN[$table]['block'];

        if ($type === 'homepage_hero') {
            $heroes = new \App\Repository\HomepageHeroRepository();
            $heroes->upsert(self::KEY, \App\Service\HomepageHeroContent::startingValues() + ['is_active' => true]);

            return (int) $heroes->findBySlug(self::KEY)['id'];
        }

        [$id, $key] = SectionRegistry::create($type, self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, $type, $key, $id);

        return (int) $id;
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
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function create(string $session, string $table, int $parentId, array $fields): array
    {
        $child = self::CHILDREN[$table];

        $response = self::$server->request('POST', $child['create'], $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            $child['parent'] => (string) $parentId,
        ] + $fields + $child['settings']);
        BlockLocalization::clearCache();

        return $response;
    }

    /**
     * @param array<string, string> $words
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function update(string $session, string $table, int $itemId, string $language, array $words): array
    {
        $child = self::CHILDREN[$table];

        $response = self::$server->request('POST', $child['update'], $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            $child['item'] => (string) $itemId,
            'language_code' => $language,
        ] + $words + $child['settings']);
        BlockLocalization::clearCache();

        return $response;
    }

    /** @return list<int> the ids of the child rows under one parent, in id order */
    private function itemIds(string $table, int $parentId): array
    {
        $block = \App\Service\Blocks\BlockDefinitions::get(self::CHILDREN[$table]['block']);
        $column = $block->childTables()[$table]['column'];

        $stmt = Database::connection()->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = ? ORDER BY id");
        $stmt->execute([$parentId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
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

        // The test's own homepage hero row, with its stats and their words.
        $hero = (new \App\Repository\HomepageHeroRepository())->findBySlug(self::KEY);
        if ($hero !== null) {
            BlockLocalization::deleteOwner('homepage_hero', (int) $hero['id']);
            Database::connection()->prepare('DELETE FROM homepage_hero WHERE id = ?')->execute([(int) $hero['id']]);
        }

        PageContent::clearCache();
    }
}
