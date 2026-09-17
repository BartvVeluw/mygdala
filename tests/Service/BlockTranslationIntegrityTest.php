<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\BlockTranslationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;

/**
 * The integrity contract of block_translations (Multilingual 2.0 phase 3,
 * docs/multilingual/ARCHITECTURE.md), through the real delete paths: a block's
 * words in every language leave with the block, whichever way the CMS deletes
 * it, and a delete that fails takes nothing with it.
 *
 * `owner_id` can have no foreign key, so these paths ARE the guard. The page,
 * its blocks and their words are this test's own and are removed in
 * tearDown(); SectionRegistry::delete() opens its own transaction, so this
 * test cannot run inside one.
 */
final class BlockTranslationIntegrityTest extends TestCase
{
    private const KEY = 'zz-block-translation-integrity';

    private PageSectionRepository $sections;
    private int $pageId = 0;

    protected function setUp(): void
    {
        $this->sections = new PageSectionRepository();
        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_DRAFT],
            'Integriteitstest'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();
        BlockLocalization::clearCache();
        PageContent::clearCache();
    }

    public function testDeletingABlockTakesItsWordsInEveryLanguage(): void
    {
        [$sectionId, $pageSection] = $this->attach('rich_text');
        [$otherId] = $this->attach('rich_text');
        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', ['body' => '<p>Weg</p>']);
        BlockLocalization::save('rich_text_sections', $sectionId, 'en', ['body' => '<p>Gone</p>']);
        BlockLocalization::save('rich_text_sections', $otherId, 'nl', ['body' => '<p>Blijft</p>']);

        SectionRegistry::delete($pageSection, $this->sections);

        self::assertSame(0, $this->wordsOf('rich_text_sections', $sectionId));
        self::assertSame(1, $this->wordsOf('rich_text_sections', $otherId), 'another block on the same page keeps its words');
        self::assertSame([], BlockLocalization::translations('rich_text_sections', $sectionId));
    }

    /**
     * Every deletable block type whose words are on per-language storage.
     *
     * @return array<string, array{string}>
     */
    public static function convertedTypes(): array
    {
        $cases = [];
        foreach (\App\Service\Blocks\BlockDefinitions::all() as $type => $definition) {
            if ($definition->translatableFields() !== [] && SectionRegistry::isDeletable($type) && SectionRegistry::isManuallyAddable($type)) {
                $cases[$type] = [$type];
            }
        }

        return $cases;
    }

    /**
     * The same guarantee for every converted block type, through the one
     * delete path they share: words in two languages for every field its own
     * row declares, and none left once the block is gone.
     *
     * @dataProvider typesWithWordsOnTheirOwnRow
     */
    public function testDeletingAnyConvertedBlockTakesItsWordsInEveryLanguage(string $type): void
    {
        [$sectionId, $pageSection] = $this->attach($type);
        $table = (string) SectionRegistry::contentTable($type);
        $fields = array_keys(BlockLocalization::fields($table));
        self::assertNotSame([], $fields, $type . ' declares words for its own row');

        foreach (['nl', 'en'] as $language) {
            BlockLocalization::save($table, $sectionId, $language, array_fill_keys($fields, 'Woorden ' . $language));
        }
        self::assertSame(2, $this->wordsOf($table, $sectionId), $type . ': the words are there, in both languages');

        SectionRegistry::delete($pageSection, $this->sections);

        self::assertSame(0, $this->wordsOf($table, $sectionId), $type . ': not one word outlives the block');
        self::assertSame([], array_values(array_filter(
            BlockLocalization::orphans()['missing_owner'],
            static fn (array $row): bool => $row['owner_table'] === $table && $row['owner_id'] === $sectionId
        )));
    }

    /**
     * Every converted type whose own content row has words (a Cijferbalk's or
     * a Woordenband's words are all on its items).
     *
     * @return array<string, array{string}>
     */
    public static function typesWithWordsOnTheirOwnRow(): array
    {
        return array_filter(
            self::convertedTypes(),
            static fn (array $case): bool => array_key_exists(
                (string) SectionRegistry::contentTable($case[0]),
                \App\Service\Blocks\BlockDefinitions::get($case[0])?->translatableFields() ?? []
            )
        );
    }

    /**
     * Every deletable block type that declares child tables.
     *
     * @return array<string, array{string}>
     */
    public static function typesWithChildRows(): array
    {
        return array_filter(
            self::convertedTypes(),
            static fn (array $case): bool => \App\Service\Blocks\BlockDefinitions::get($case[0])?->childTables() !== []
        );
    }

    /**
     * The child-row guarantee, for every block type that has child rows: the
     * block, two rows of every child table and (where a child table has
     * children of its own) two rows under each of those, all with words in
     * two languages. Deleting the block through SectionRegistry::delete()
     * leaves not one word behind, however deep; the database's cascade takes
     * the rows, and the words were gone before it ran.
     *
     * The child rows are inserted with nothing but the column that ties them
     * to their parent: what they hold besides their words is not the point.
     *
     * @dataProvider typesWithChildRows
     */
    public function testDeletingABlockTakesTheWordsOfAllItsChildRows(string $type): void
    {
        [$sectionId, $pageSection] = $this->attach($type);
        $definition = \App\Service\Blocks\BlockDefinitions::get($type);
        $owners = [(string) $definition->contentTable() => [$sectionId]];

        // Parents before children: a child table's parent is always the
        // content table or a child table handled earlier.
        $children = $definition->childTables();
        while ($children !== []) {
            foreach ($children as $child => $link) {
                if (!isset($owners[$link['parent']])) {
                    continue;
                }

                foreach ($owners[$link['parent']] as $parentId) {
                    foreach ([0, 1] as $order) {
                        Database::connection()
                            ->prepare("INSERT INTO `{$child}` (`{$link['column']}`) VALUES (?)")
                            ->execute([$parentId]);
                        $owners[$child][] = (int) Database::connection()->lastInsertId();
                    }
                }

                unset($children[$child]);
            }
        }

        foreach ($owners as $table => $ids) {
            $fields = array_keys(BlockLocalization::fields($table));
            foreach ($ids as $id) {
                if ($fields !== []) {
                    BlockLocalization::save($table, $id, 'nl', array_fill_keys($fields, 'Woorden'));
                    BlockLocalization::save($table, $id, 'en', array_fill_keys($fields, 'Words'));
                }
            }
        }

        SectionRegistry::delete($pageSection, $this->sections);

        foreach ($owners as $table => $ids) {
            foreach ($ids as $id) {
                self::assertSame(0, $this->wordsOf($table, $id), "{$type}: {$table} #{$id} left words behind");
            }
        }
        self::assertSame([], array_values(array_filter(
            BlockLocalization::orphans()['missing_owner'],
            static fn (array $row): bool => in_array($row['owner_id'], $owners[$row['owner_table']] ?? [], true)
        )), $type . ': the orphan check finds nothing to purge');
    }

    public function testDeletingAPageTakesTheWordsOfEveryBlockOnIt(): void
    {
        [$richId] = $this->attach('rich_text');
        [$cardId] = $this->attach('contact_card');
        BlockLocalization::save('rich_text_sections', $richId, 'nl', ['body' => '<p>Tekst</p>']);
        BlockLocalization::save('contact_cards', $cardId, 'en', ['title' => 'Card']);

        PageService::delete((new PageRepository())->findById($this->pageId));
        $this->pageId = 0;

        self::assertSame(0, $this->wordsOf('rich_text_sections', $richId));
        self::assertSame(0, $this->wordsOf('contact_cards', $cardId));
    }

    public function testAFailedDeleteKeepsTheBlockAndItsWords(): void
    {
        [$sectionId, $pageSection] = $this->attach('rich_text');
        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', ['body' => '<p>Blijft staan</p>']);

        $failing = new class () extends PageSectionRepository {
            public function delete(int $id): bool
            {
                throw new \RuntimeException('the page_sections delete failed');
            }
        };

        try {
            SectionRegistry::delete($pageSection, $failing);
            self::fail('the failing delete was not reported');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }

        self::assertSame(1, $this->wordsOf('rich_text_sections', $sectionId), 'rolled back together with the block');
        self::assertNotNull($this->sections->findById((int) $pageSection['id']));
    }

    public function testNoDeletePathLeavesAnOrphanBehind(): void
    {
        [$richId, $richSection] = $this->attach('rich_text');
        [$cardId] = $this->attach('contact_card');
        BlockLocalization::save('rich_text_sections', $richId, 'nl', ['body' => '<p>Een</p>']);
        BlockLocalization::save('contact_cards', $cardId, 'nl', ['title' => 'Twee']);

        SectionRegistry::delete($richSection, $this->sections);
        PageService::delete((new PageRepository())->findById($this->pageId));
        $this->pageId = 0;

        $orphans = BlockLocalization::orphans()['missing_owner'];
        self::assertNotContains(['owner_table' => 'rich_text_sections', 'owner_id' => $richId, 'rows' => 1], $orphans);
        self::assertSame([], array_values(array_filter(
            $orphans,
            static fn (array $row): bool => in_array([$row['owner_table'], $row['owner_id']], [['rich_text_sections', $richId], ['contact_cards', $cardId]], true)
        )));
    }

    public function testAContentRowDeletedBehindTheCmsBackIsFoundAndPurged(): void
    {
        [$cardId] = $this->attach('contact_card');
        BlockLocalization::save('contact_cards', $cardId, 'nl', ['title' => 'Handmatig weg']);

        // What an Adminer delete or a restored backup does: the content row
        // goes, nothing else runs.
        Database::connection()->prepare('DELETE FROM contact_cards WHERE id = ?')->execute([$cardId]);

        self::assertContains(
            ['owner_table' => 'contact_cards', 'owner_id' => $cardId, 'rows' => 1],
            BlockLocalization::orphans()['missing_owner']
        );

        BlockLocalization::purgeOrphans();

        self::assertSame(0, $this->wordsOf('contact_cards', $cardId));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>} the content row id and the page_sections row
     */
    private function attach(string $type): array
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, self::KEY);
        $id = $this->sections->create($this->pageId, self::KEY, $type, $sectionKey, $sectionId);

        return [$sectionId, $this->sections->findById($id)];
    }

    private function wordsOf(string $table, int $id): int
    {
        return count((new BlockTranslationRepository())->findForOwners([$table => [$id]])[$table][$id] ?? []);
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);

        if ($page !== null) {
            foreach ($this->sections->findForPage((int) $page['id']) as $section) {
                $definition = \App\Service\Blocks\BlockDefinitions::get((string) $section['section_type']);
                if ($definition?->contentTable() !== null) {
                    BlockLocalization::deleteOwner((string) $definition->contentTable(), (int) $section['section_id']);
                }
            }
            PageService::delete($page);
        }

        $db = Database::connection();
        foreach (['rich_text_sections', 'contact_cards', 'form_blocks', 'contact_form_sections', 'item_galleries'] as $table) {
            $db->prepare("DELETE t FROM block_translations t JOIN `{$table}` o ON o.id = t.owner_id WHERE t.owner_table = ? AND o.page_slug = ?")
                ->execute([$table, self::KEY]);
            $db->prepare("DELETE FROM `{$table}` WHERE page_slug = ?")->execute([self::KEY]);
        }
    }
}
