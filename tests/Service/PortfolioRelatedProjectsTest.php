<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PortfolioRelatedProjects;
use App\Service\RandomOrder;
use PHPUnit\Framework\TestCase;

/**
 * THE CHOICE of a project page's related projects
 * (App\Service\PortfolioRelatedProjects::choose()), without a database: a
 * made-up catalogue of visible projects with their categories and when they
 * were added, and a hand-picked list.
 *
 *   - off by default, and every setting read against its closed list;
 *   - automatic: only projects that share a category, the ones sharing more
 *     first, newest first on a tie; newest, oldest, by title, random;
 *   - manual: exactly the picked ones, in their order, a hidden or deleted
 *     one left out;
 *   - hybrid: the picked ones first, then automatic ones for the places left,
 *     never one twice;
 *   - the project itself never, whichever way; never more than the maximum;
 *   - too few that share a category: only those, or topped up;
 *   - random draws only from the valid pool, once per choice, and a seeded
 *     engine (App\Service\RandomOrder::useEngineForTests()) makes it
 *     repeatable, so nothing here depends on luck.
 *
 * The database half (the page, the editor, the module switched off) is
 * Tests\Module\PortfolioRelatedProjectsPageTest and PortfolioRelatedEditorHttpTest.
 */
final class PortfolioRelatedProjectsTest extends TestCase
{
    private const CURRENT = 10;

    protected function tearDown(): void
    {
        RandomOrder::useEngineForTests(null);
    }

    public function testItIsOffByDefaultAndEverySettingIsReadAgainstItsList(): void
    {
        self::assertSame(
            [
                'related_enabled' => false,
                'related_mode' => 'automatic',
                'related_max' => 3,
                'related_sort' => 'relevance',
                'related_fallback' => 'available',
                'related_layout' => 'normal',
                'related_show_text' => true,
            ],
            PortfolioRelatedProjects::settings([])
        );

        $garbage = PortfolioRelatedProjects::settings([
            'related_enabled' => 1,
            'related_mode' => 'everything',
            'related_max' => 99,
            'related_sort' => 'popular',
            'related_fallback' => 'guess',
            'related_layout' => 'huge',
            'related_show_text' => 0,
        ]);
        self::assertTrue($garbage['related_enabled']);
        self::assertSame(['automatic', 3, 'relevance', 'available', 'normal', false], [
            $garbage['related_mode'], $garbage['related_max'], $garbage['related_sort'],
            $garbage['related_fallback'], $garbage['related_layout'], $garbage['related_show_text'],
        ]);
    }

    public function testAutomaticShowsOnlyProjectsThatShareACategoryTheMostSharedFirst(): void
    {
        $ids = $this->choose(['related_max' => 8]);

        // 11 shares two categories, 12 and 13 one each (13 newer), 14 none.
        self::assertSame([11, 13, 12], $ids);
    }

    public function testEqualRelevanceIsBrokenByNewestThenHighestId(): void
    {
        $visible = self::visible();
        $visible[12]['created_at'] = '2026-01-05 10:00:00';
        $visible[13]['created_at'] = '2026-01-05 10:00:00';

        self::assertSame([11, 13, 12], $this->choose(['related_max' => 8], $visible), 'the same moment: the higher id first');
    }

    public function testTheMaximumIsNeverExceeded(): void
    {
        self::assertSame([11, 13], $this->choose(['related_max' => 2]));
        self::assertCount(2, $this->choose(['related_max' => 2, 'related_fallback' => 'fill']));
        self::assertCount(2, $this->choose(['related_mode' => 'manual', 'related_max' => 2], null, [14, 12, 11]));
    }

    public function testTooFewThatShareACategoryShowOnlyThoseOrAreToppedUp(): void
    {
        self::assertSame([11, 13, 12], $this->choose(['related_max' => 6, 'related_fallback' => 'available']), 'nothing unrelated unasked');
        self::assertSame([11, 13, 12, 15, 14], $this->choose(['related_max' => 6, 'related_fallback' => 'fill']), 'topped up with the others, newest first');

        // A project without any category has nothing related, and says so.
        $visible = self::visible();
        $categories = self::categories();
        $categories[self::CURRENT] = [];
        self::assertSame([], $this->choose(['related_max' => 4], $visible, [], $categories));
        self::assertCount(4, $this->choose(['related_max' => 4, 'related_fallback' => 'fill'], $visible, [], $categories));
    }

    public function testTheOtherAutomaticOrders(): void
    {
        self::assertSame([13, 12, 11], $this->choose(['related_max' => 8, 'related_sort' => 'newest']));
        self::assertSame([11, 12, 13], $this->choose(['related_max' => 8, 'related_sort' => 'oldest']));
        self::assertSame([12, 11, 13], $this->choose(['related_max' => 8, 'related_sort' => 'title']), 'Beer, Hert, Wolf');
    }

    public function testManualShowsExactlyThePickedInTheirOrderAndNothingElse(): void
    {
        self::assertSame([14, 12], $this->choose(['related_mode' => 'manual', 'related_max' => 8], null, [14, 12]));
    }

    public function testAHiddenADeletedARepeatedAndTheProjectItselfAreLeftOut(): void
    {
        // 16 is hidden (not among the visible), 99 is deleted, 12 twice, 10 is this project.
        $picked = [16, 99, 12, 12, self::CURRENT, 11];

        self::assertSame([12, 11], $this->choose(['related_mode' => 'manual', 'related_max' => 8], null, $picked));
    }

    public function testHybridPutsThePickedFirstAndFillsTheRestAutomaticallyWithoutRepeats(): void
    {
        // Picked: 14 (unrelated) and 11. Automatic fills with 13 and 12, never 11 again.
        self::assertSame([14, 11, 13, 12], $this->choose(['related_mode' => 'hybrid', 'related_max' => 4], null, [14, 11]));
        self::assertSame([14, 11], $this->choose(['related_mode' => 'hybrid', 'related_max' => 2], null, [14, 11, 13]), 'the picked ones fill the maximum first');
    }

    public function testAutomaticIgnoresAStoredHandPickedList(): void
    {
        self::assertSame([11, 13, 12], $this->choose(['related_max' => 8], null, [14, 15]));
        self::assertSame(
            [11, 13, 12, 15, 14],
            $this->choose(['related_max' => 8, 'related_fallback' => 'fill'], null, [14, 15]),
            'a list kept for a later switch back excludes nothing from the top-up'
        );
    }

    public function testTheProjectItselfNeverAppearsWhicheverWay(): void
    {
        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(7));

        foreach (['automatic', 'manual', 'hybrid'] as $mode) {
            foreach (['relevance', 'random'] as $sort) {
                foreach (['available', 'fill'] as $fallback) {
                    $ids = $this->choose(
                        ['related_mode' => $mode, 'related_max' => 8, 'related_sort' => $sort, 'related_fallback' => $fallback],
                        null,
                        [self::CURRENT, 11, 14]
                    );
                    self::assertNotContains(self::CURRENT, $ids, $mode . '/' . $sort . '/' . $fallback);
                    self::assertSame(array_values(array_unique($ids)), $ids, 'never twice: ' . $mode . '/' . $sort . '/' . $fallback);
                }
            }
        }
    }

    public function testRandomDrawsOnlyFromTheValidPoolAndOncePerChoice(): void
    {
        $valid = [11, 12, 13];

        for ($seed = 1; $seed <= 25; $seed++) {
            RandomOrder::useEngineForTests(new \Random\Engine\Mt19937($seed));
            $ids = $this->choose(['related_max' => 2, 'related_sort' => 'random']);

            self::assertCount(2, $ids);
            self::assertSame([], array_diff($ids, $valid), 'only projects that share a category');
            self::assertSame(array_values(array_unique($ids)), $ids);
            self::assertSame(1, RandomOrder::calls(), 'one draw for one choice');
        }

        // The same seed, the same answer: a test never depends on luck.
        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(3));
        $first = $this->choose(['related_max' => 3, 'related_sort' => 'random']);
        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(3));
        self::assertSame($first, $this->choose(['related_max' => 3, 'related_sort' => 'random']));
    }

    public function testEveryChoiceDrawsAgain(): void
    {
        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(11));

        $this->choose(['related_sort' => 'random']);
        $this->choose(['related_sort' => 'random']);
        $this->choose(['related_sort' => 'random', 'related_fallback' => 'fill']);

        self::assertSame(4, RandomOrder::calls(), 'two single draws, then one for the related and one for the top-up');
    }

    /**
     * @param array<string, mixed>                  $settings
     * @param array<int, array<string, mixed>>|null $visible
     * @param list<int>                             $manual
     * @param array<int, list<int>>|null            $categories
     *
     * @return list<int>
     */
    private function choose(array $settings, ?array $visible = null, array $manual = [], ?array $categories = null): array
    {
        $visible ??= self::visible();
        $titles = [11 => 'Hert', 12 => 'Beer', 13 => 'Wolf', 14 => 'Uil', 15 => 'Vos', self::CURRENT => 'Huidig'];

        return PortfolioRelatedProjects::choose(
            $settings + ['related_mode' => 'automatic', 'related_max' => 3, 'related_sort' => 'relevance', 'related_fallback' => 'available'],
            self::CURRENT,
            $visible,
            $categories ?? self::categories(),
            $manual,
            static function (array $ids, string $sort) use ($visible, $titles): array {
                $created = static fn (int $id): string => (string) $visible[$id]['created_at'];
                usort($ids, match ($sort) {
                    'newest' => static fn (int $a, int $b): int => [$created($b), $b] <=> [$created($a), $a],
                    'oldest' => static fn (int $a, int $b): int => [$created($a), $a] <=> [$created($b), $b],
                    default => static fn (int $a, int $b): int => strcmp($titles[$a], $titles[$b]) ?: $a <=> $b,
                });

                return $ids;
            }
        );
    }

    /**
     * The visible projects, in the Portfolio's own order. 16 is hidden, so it
     * is not here.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function visible(): array
    {
        return [
            self::CURRENT => ['id' => self::CURRENT, 'created_at' => '2026-01-01 10:00:00'],
            11 => ['id' => 11, 'created_at' => '2026-01-02 10:00:00'],
            12 => ['id' => 12, 'created_at' => '2026-01-03 10:00:00'],
            13 => ['id' => 13, 'created_at' => '2026-01-04 10:00:00'],
            14 => ['id' => 14, 'created_at' => '2026-01-05 10:00:00'],
            15 => ['id' => 15, 'created_at' => '2026-01-06 10:00:00'],
        ];
    }

    /**
     * This project is a Wolf keyring (1, 2). 11 shares both, 12 and 13 one,
     * 14 and 15 none; 16 is hidden but would share one.
     *
     * @return array<int, list<int>>
     */
    private static function categories(): array
    {
        return [
            self::CURRENT => [1, 2],
            11 => [1, 2],
            12 => [2],
            13 => [1, 3],
            14 => [3],
            15 => [],
            16 => [1],
        ];
    }
}
