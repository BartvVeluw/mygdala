<?php

declare(strict_types=1);

namespace Tests\Service\Blocks;

use App\Service\Blocks\EditorRows;
use PHPUnit\Framework\TestCase;

/**
 * The rows of one list inside one block-editor form (App\Service\Blocks\EditorRows):
 * the posted order is the stored order, and ↑/↓/× without JavaScript act on
 * the rows that came along — never a save of their own.
 */
final class EditorRowsTest extends TestCase
{
    public function testRowsKeepThePostedOrderAndOnlyWellFormedKeys(): void
    {
        $rows = EditorRows::fromPost([
            '12' => ['label' => '  Hout  '],
            'new0' => ['label' => 'Nieuw'],
            '0' => ['label' => 'nul'],
            'x7' => ['label' => 'vreemd'],
            '3' => 'geen rij',
            '4' => ['label' => ['geneste array'], 'active' => '1'],
        ]);

        self::assertSame([
            ['key' => '12', 'id' => 12, 'fields' => ['label' => 'Hout']],
            ['key' => 'new0', 'id' => 0, 'fields' => ['label' => 'Nieuw']],
            ['key' => '4', 'id' => 4, 'fields' => ['active' => '1']],
        ], $rows);
        self::assertSame([], EditorRows::fromPost('niets'));
    }

    public function testAnActionNamesAListAVerbAndARow(): void
    {
        self::assertSame(['list' => 'tags', 'verb' => 'up', 'key' => '12'], EditorRows::parseAction('tags:up:12'));
        self::assertSame(['list' => 'cards', 'verb' => 'add', 'key' => ''], EditorRows::parseAction('cards:add'));
        self::assertNull(EditorRows::parseAction(null));
        self::assertNull(EditorRows::parseAction(''));
        self::assertNull(EditorRows::parseAction('tags:up:12; DROP'));
        self::assertNull(EditorRows::parseAction(['tags:up:12']));
    }

    public function testMoveAndRemoveActOnThePostedRowsOfTheirOwnList(): void
    {
        $rows = EditorRows::fromPost(['1' => ['label' => 'a'], '2' => ['label' => 'b'], 'new0' => ['label' => 'c']]);
        $keys = static fn (array $rows): array => array_column($rows, 'key');

        self::assertSame(['2', '1', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:up:2'), 'tags')));
        self::assertSame(['1', 'new0', '2'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:down:2'), 'tags')));
        self::assertSame(['1', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:remove:2'), 'tags')));

        // The ends, another list, another verb and an unknown row change nothing.
        self::assertSame(['1', '2', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:up:1'), 'tags')));
        self::assertSame(['1', '2', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:down:new0'), 'tags')));
        self::assertSame(['1', '2', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('cards:up:2'), 'tags')));
        self::assertSame(['1', '2', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:edit:2'), 'tags')));
        self::assertSame(['1', '2', 'new0'], $keys(EditorRows::apply($rows, EditorRows::parseAction('tags:up:99'), 'tags')));
        self::assertSame(['1', '2', 'new0'], $keys(EditorRows::apply($rows, null, 'tags')));

        // Whatever was typed travels with the row it belongs to.
        self::assertSame('b', EditorRows::apply($rows, EditorRows::parseAction('tags:up:2'), 'tags')[0]['fields']['label']);
    }
}
