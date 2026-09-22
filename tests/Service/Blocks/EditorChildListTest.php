<?php

declare(strict_types=1);

namespace Tests\Service\Blocks;

use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use PHPUnit\Framework\TestCase;

/**
 * The request half of App\Service\Blocks\EditorChildList, without a database:
 * which posted rows count, what a removal mark and an empty new row mean,
 * and what a refused save hands back. The saving half is covered over HTTP
 * by Tests\Service\BlockRowEditorsHttpTest.
 */
final class EditorChildListTest extends TestCase
{
    public function testOnlyTheBlocksOwnRowsAndNewRowsCountInThePostedOrder(): void
    {
        $list = EditorChildList::fromRequest([
            'items_present' => '1',
            'items' => [
                '12' => ['question' => 'Twaalf'],
                '99' => ['question' => 'Van een ander blok'],
                'new0' => ['question' => 'Nieuw'],
                '7' => ['question' => 'Zeven'],
            ],
        ], 'items', 'faq_items', [7, 12], null);

        self::assertTrue($list->posted);
        self::assertSame(['12', 'new0', '7'], array_column($list->rows(), 'key'));
    }

    public function testAMoveWithoutScriptIsAppliedToThePostedRows(): void
    {
        $list = EditorChildList::fromRequest([
            'items_present' => '1',
            'items' => ['12' => ['question' => 'a'], '7' => ['question' => 'b']],
        ], 'items', 'faq_items', [7, 12], EditorRows::parseAction('items:up:7'));

        self::assertSame(['7', '12'], array_column($list->rows(), 'key'));
    }

    public function testAFormWithoutTheListIsNoListAndKeepsEveryRow(): void
    {
        $list = EditorChildList::fromRequest(['items' => ['12' => ['question' => 'a']]], 'items', 'faq_items', [7, 12], null);

        self::assertFalse($list->posted);
        self::assertSame(2, $list->keptCount(), 'nothing is removed by a form that did not carry the list');
    }

    public function testAMarkRemovesAnEmptyNewRowIsNoRowAndAnUnpostedRowStays(): void
    {
        $list = EditorChildList::fromRequest([
            'items_present' => '1',
            'items' => [
                '12' => ['question' => 'a', 'remove' => '1'],
                'new0' => ['question' => '', 'answer' => '', 'active' => '1', 'present' => '1'],
                'new1' => ['question' => 'Nieuw'],
            ],
        ], 'items', 'faq_items', [7, 12], null);

        [$marked, $empty, $new] = $list->rows();
        self::assertTrue($list->isDropped($marked));
        self::assertTrue($list->isBlank($empty), 'a switch alone is nothing typed');
        self::assertFalse($list->isDropped($new));
        // new1, plus 7, which was not on the screen at all.
        self::assertSame(2, $list->keptCount());
    }

    public function testARefusedSaveHandsBackEveryRowAsPostedAndSummarisesPerPlace(): void
    {
        $list = EditorChildList::fromRequest([
            'items_present' => '1',
            'items' => [
                '7' => ['question' => 'b', 'remove' => '1'],
                'new0' => ['question' => ''],
                '12' => ['question' => 'a'],
            ],
        ], 'items', 'faq_items', [7, 12], null);

        self::assertSame([
            ['key' => '7', 'fields' => ['question' => 'b', 'remove' => '1']],
            ['key' => 'new0', 'fields' => ['question' => '']],
            ['key' => '12', 'fields' => ['question' => 'a']],
        ], $list->old());

        self::assertSame(
            ['Vraag 3: Dit veld is verplicht.'],
            $list->summary(['items.12.question' => 'Dit veld is verplicht.', 'items.12.answer' => 'Dit veld is verplicht.'], 'Vraag')
        );
    }
}
