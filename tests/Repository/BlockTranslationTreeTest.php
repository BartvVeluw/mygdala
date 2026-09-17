<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\BlockTranslationRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\TranslatableField;
use App\Service\Language\SiteLanguages;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Child rows as owners of block words (Multilingual 2.0 phase 3B,
 * docs/multilingual/ARCHITECTURE.md), on three real levels: a carousel, its
 * cards and the cards' tags.
 *
 * What BlockDefinition::childTables() buys: a block's child rows are found
 * through their parent, so their words are removed BEFORE the database's
 * cascade takes the rows, and a whole block with all its items loads its
 * words in one query. The declaration is pinned through BlockLocalization's
 * test seam, so this test does not depend on which blocks are converted.
 *
 * Every test runs inside a transaction on the shared connection and rolls it
 * back, like BlockTranslationRepositoryTest.
 */
final class BlockTranslationTreeTest extends TestCase
{
    private const CAROUSELS = 'card_carousels';
    private const CARDS = 'carousel_cards';
    private const TAGS = 'carousel_card_tags';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        SiteLanguages::clearCache();
        BlockLocalization::overrideRegistryForTests(
            [
                self::CAROUSELS => [TranslatableField::plain('title', 255)],
                self::CARDS => [TranslatableField::plain('title', 255), TranslatableField::plain('body', 1000)],
                self::TAGS => [TranslatableField::plain('label', 60)],
            ],
            [
                self::CARDS => ['parent' => self::CAROUSELS, 'column' => 'carousel_id'],
                self::TAGS => ['parent' => self::CARDS, 'column' => 'card_id'],
            ]
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        SiteLanguages::clearCache();
        BlockLocalization::overrideRegistryForTests(null);
        BlockLocalization::clearCache();
    }

    public function testDeletingABlockTakesTheWordsOfEveryChildAndGrandchildRow(): void
    {
        $tree = $this->carousel('zz-tree-a');
        $other = $this->carousel('zz-tree-b');

        BlockLocalization::deleteOwner(self::CAROUSELS, $tree['carousel']);

        self::assertSame(0, $this->wordCount(self::CAROUSELS, [$tree['carousel']]));
        self::assertSame(0, $this->wordCount(self::CARDS, $tree['cards']));
        self::assertSame(0, $this->wordCount(self::TAGS, $tree['tags']));

        self::assertSame(2, $this->wordCount(self::CAROUSELS, [$other['carousel']]), 'another carousel keeps its words');
        self::assertSame(6, $this->wordCount(self::CARDS, $other['cards']));
        self::assertSame(4, $this->wordCount(self::TAGS, $other['tags']));
    }

    public function testDeletingOneChildRowTakesItsOwnChildrenAndNothingElse(): void
    {
        $tree = $this->carousel('zz-tree-a');
        [$firstCard, $secondCard] = $tree['cards'];

        BlockLocalization::deleteOwner(self::CARDS, $firstCard);

        self::assertSame(0, $this->wordCount(self::CARDS, [$firstCard]));
        self::assertSame(0, $this->wordCount(self::TAGS, $this->tagsOf($firstCard)));
        self::assertSame(3, $this->wordCount(self::CARDS, [$secondCard]), 'the sibling card keeps its words');
        self::assertSame(2, $this->wordCount(self::TAGS, $this->tagsOf($secondCard)));
        self::assertSame(2, $this->wordCount(self::CAROUSELS, [$tree['carousel']]), 'the parent keeps its words');
    }

    public function testTheWordsGoBeforeTheRowsSoTheCascadeLeavesNoOrphan(): void
    {
        $tree = $this->carousel('zz-tree-a');

        BlockLocalization::deleteOwner(self::CAROUSELS, $tree['carousel']);
        $this->db->prepare('DELETE FROM card_carousels WHERE id = ?')->execute([$tree['carousel']]);

        $orphans = array_filter(
            BlockLocalization::orphans()['missing_owner'],
            static fn (array $row): bool => in_array($row['owner_id'], array_merge([$tree['carousel']], $tree['cards'], $tree['tags']), true)
        );

        self::assertSame([], array_values($orphans));
    }

    public function testAWholeBlockLoadsTheWordsOfAllItsRowsInOneQuery(): void
    {
        $a = $this->carousel('zz-tree-a');
        $b = $this->carousel('zz-tree-b');
        $wordless = $this->insert(self::TAGS, ['card_id' => $a['cards'][0], 'sort_order' => 9]);
        BlockLocalization::clearCache();

        $before = $this->selects();
        BlockLocalization::preloadBlocks([self::CAROUSELS => [$a['carousel'], $b['carousel']]]);
        self::assertSame(1, $this->selects() - $before, 'one query for two blocks, their cards and their tags');

        $before = $this->selects();
        self::assertSame('Tag 0 a NL', BlockLocalization::value(self::TAGS, $a['tags'][0], 'label', 'nl'));
        self::assertSame('Card 1 b EN', BlockLocalization::value(self::CARDS, $b['cards'][1], 'title', 'en'));
        self::assertSame('Card body 1 b NL', BlockLocalization::value(self::CARDS, $b['cards'][1], 'body', 'en'), 'an untranslated field falls back');
        self::assertSame('Carousel b NL', BlockLocalization::value(self::CAROUSELS, $b['carousel'], 'title', 'nl'));
        self::assertSame([], BlockLocalization::translations(self::TAGS, $wordless), 'a row without words counts as loaded');
        self::assertSame(0, $this->selects() - $before, 'reading any row afterwards costs nothing');

        $before = $this->selects();
        BlockLocalization::preloadBlocks([self::CAROUSELS => [$a['carousel']]]);
        BlockLocalization::preload([self::CARDS => $a['cards'], self::TAGS => $a['tags']]);
        self::assertSame(0, $this->selects() - $before, 'a block that is already loaded is not loaded again');
    }

    public function testABlockWithoutChildRowsStillLoadsItsOwnWords(): void
    {
        $carousel = $this->insert(self::CAROUSELS, ['page_slug' => 'zz-tree-empty', 'section_key' => 'custom-empty', 'is_active' => 1]);
        BlockLocalization::save(self::CAROUSELS, $carousel, 'nl', ['title' => 'Leeg']);
        BlockLocalization::clearCache();

        BlockLocalization::preloadBlocks([self::CAROUSELS => [$carousel]]);

        $before = $this->selects();
        self::assertSame('Leeg', BlockLocalization::value(self::CAROUSELS, $carousel, 'title', 'en'));
        self::assertSame(0, $this->selects() - $before);
    }

    public function testChildTableAndColumnNamesAreRefusedUnlessPlainIdentifiers(): void
    {
        $repository = new BlockTranslationRepository($this->db);

        $this->expectException(\InvalidArgumentException::class);
        $repository->findChildOwnerIds('carousel_cards`; DROP TABLE x; --', 'carousel_id', [1]);
    }

    /**
     * A carousel with words in nl (title), two cards with words in nl and en,
     * and two tags per card with nl words.
     *
     * @return array{carousel: int, cards: list<int>, tags: list<int>}
     */
    private function carousel(string $slug): array
    {
        $suffix = substr($slug, -1);
        $carousel = $this->insert(self::CAROUSELS, ['page_slug' => $slug, 'section_key' => 'custom-' . $suffix, 'is_active' => 1]);
        BlockLocalization::save(self::CAROUSELS, $carousel, 'nl', ['title' => 'Carousel ' . $suffix . ' NL']);
        BlockLocalization::save(self::CAROUSELS, $carousel, 'en', ['title' => 'Carousel ' . $suffix . ' EN']);

        $cards = [];
        $tags = [];
        foreach ([0, 1] as $i) {
            $card = $this->insert(self::CARDS, ['carousel_id' => $carousel, 'sort_order' => $i, 'is_active' => 1]);
            BlockLocalization::save(self::CARDS, $card, 'nl', ['title' => "Card {$i} {$suffix} NL", 'body' => "Card body {$i} {$suffix} NL"]);
            BlockLocalization::save(self::CARDS, $card, 'en', ['title' => "Card {$i} {$suffix} EN"]);
            $cards[] = $card;

            foreach ([0, 1] as $t) {
                $tag = $this->insert(self::TAGS, ['card_id' => $card, 'sort_order' => $t]);
                BlockLocalization::save(self::TAGS, $tag, 'nl', ['label' => "Tag {$t} {$suffix} NL"]);
                $tags[] = $tag;
            }
        }

        return ['carousel' => $carousel, 'cards' => $cards, 'tags' => $tags];
    }

    /** @return list<int> */
    private function tagsOf(int $card): array
    {
        $stmt = $this->db->prepare('SELECT id FROM carousel_card_tags WHERE card_id = ? ORDER BY id');
        $stmt->execute([$card]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $this->db->prepare(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ', NOW(), NOW())'
        )->execute(array_values($row));

        return (int) $this->db->lastInsertId();
    }

    /** @param list<int> $ids */
    private function wordCount(string $table, array $ids): int
    {
        $rows = (new BlockTranslationRepository($this->db))->findForOwners([$table => $ids]);

        $count = 0;
        foreach ($rows[$table] ?? [] as $languages) {
            foreach ($languages as $fields) {
                $count += count($fields);
            }
        }

        return $count;
    }

    private function selects(): int
    {
        return (int) $this->db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
    }
}
