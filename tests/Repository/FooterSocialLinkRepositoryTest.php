<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\FooterSocialLinkRepository;
use App\Service\SocialProfiles;
use PHPUnit\Framework\TestCase;

/**
 * footer_social_links against the test database (Footer phase B,
 * HEADER-FOOTER.md): storing, the one list order, ↑/↓, visibility, and what
 * App\Service\SocialProfiles::forFooter() makes of real rows.
 *
 * Every row is this test's own and removed by exact id in tearDown(). Other
 * rows the database may hold do not matter: orders are asserted between this
 * test's own rows.
 */
final class FooterSocialLinkRepositoryTest extends TestCase
{
    private FooterSocialLinkRepository $repository;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->repository = new FooterSocialLinkRepository();
        SocialProfiles::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            $this->repository->delete($id);
        }
        $this->ids = [];
        SocialProfiles::clearCache();
    }

    private function make(string $network, string $url, bool $visible = true): int
    {
        $id = $this->repository->create(['network' => $network, 'url' => $url, 'is_visible' => $visible]);
        $this->ids[] = $id;

        return $id;
    }

    /** @return list<int> this test's own ids, in stored order */
    private function ownOrder(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $row): int => (int) $row['id'], $this->repository->findAll()),
            fn (int $id): bool => in_array($id, $this->ids, true)
        ));
    }

    public function testARowIsStoredAsGivenAndGoesToTheEnd(): void
    {
        $first = $this->make('instagram', 'https://www.instagram.com/eerste/');
        $second = $this->make('etsy', 'https://www.etsy.com/shop/tweede', false);

        $row = $this->repository->findById($second);

        $this->assertIsArray($row);
        $this->assertSame(['etsy', 'https://www.etsy.com/shop/tweede', 0], [$row['network'], $row['url'], (int) $row['is_visible']]);
        $this->assertGreaterThan(
            (int) $this->repository->findById($first)['sort_order'],
            (int) $row['sort_order']
        );
        $this->assertSame([$first, $second], $this->ownOrder());
    }

    public function testAnUpdateChangesNetworkAddressAndVisibilityButNotTheOrder(): void
    {
        $id = $this->make('instagram', 'https://www.instagram.com/oud/');
        $before = (int) $this->repository->findById($id)['sort_order'];

        $this->repository->update($id, ['network' => 'facebook', 'url' => 'https://www.facebook.com/nieuw', 'is_visible' => false]);

        $row = $this->repository->findById($id);
        $this->assertSame(
            ['facebook', 'https://www.facebook.com/nieuw', 0, $before],
            [$row['network'], $row['url'], (int) $row['is_visible'], (int) $row['sort_order']]
        );
    }

    public function testUpAndDownSwapWithTheNeighbourOnly(): void
    {
        $a = $this->make('instagram', 'https://www.instagram.com/a/');
        $b = $this->make('facebook', 'https://www.facebook.com/b');
        $c = $this->make('etsy', 'https://www.etsy.com/shop/c');

        $this->repository->move($c, 'up');
        $this->assertSame([$a, $c, $b], $this->ownOrder());

        $this->repository->move($a, 'down');
        $this->assertSame([$c, $a, $b], $this->ownOrder());

        // The last row does not move down; a nonsense direction does nothing.
        $this->repository->move($b, 'down');
        $this->repository->move($b, 'sideways');
        $this->assertSame([$c, $a, $b], $this->ownOrder());

        $all = $this->repository->findAll();
        $this->assertSame($b, (int) end($all)['id'], 'the last row stays last');
        $orders = array_map(static fn (array $row): int => (int) $row['sort_order'], $all);
        $this->assertSame(range(0, count($all) - 1), $orders, 'a move rewrites one clean order');
    }

    public function testTheFirstRowDoesNotMoveUp(): void
    {
        $id = $this->make('instagram', 'https://www.instagram.com/a/');
        $this->make('facebook', 'https://www.facebook.com/b');

        // Bring it to the very top, then once more.
        for ($i = 0; $i < count($this->repository->findAll()) + 1; $i++) {
            $this->repository->move($id, 'up');
        }

        $this->assertSame($id, (int) $this->repository->findAll()[0]['id']);
    }

    public function testDeleteRemovesOnlyThatRow(): void
    {
        $keep = $this->make('instagram', 'https://www.instagram.com/blijft/');
        $gone = $this->make('instagram', 'https://www.instagram.com/weg/');

        $this->assertTrue($this->repository->delete($gone));
        $this->assertFalse($this->repository->delete($gone), 'a second delete finds nothing');
        $this->assertNull($this->repository->findById($gone));
        $this->assertNotNull($this->repository->findById($keep));
    }

    /**
     * What the footer gets from real rows: only the visible ones, in their
     * order, two on one network numbered, and nothing a hidden row holds.
     */
    public function testTheFooterRendersTheVisibleRowsInTheirOrder(): void
    {
        $second = $this->make('instagram', 'https://www.instagram.com/phase-b-tweede/');
        $hidden = $this->make('facebook', 'https://www.facebook.com/phase-b-verborgen', false);
        $first = $this->make('instagram', 'https://www.instagram.com/phase-b-eerste/');
        $this->repository->move($first, 'up');
        $this->repository->move($first, 'up');

        $visibleIds = array_map(static fn (array $row): int => (int) $row['id'], $this->repository->findVisible());
        $this->assertNotContains($hidden, $visibleIds);

        SocialProfiles::clearCache();
        $own = array_values(array_filter(
            SocialProfiles::forFooter(),
            static fn (array $profile): bool => str_contains($profile['url'], 'phase-b-')
        ));

        $this->assertSame(
            ['https://www.instagram.com/phase-b-eerste/', 'https://www.instagram.com/phase-b-tweede/'],
            array_column($own, 'url')
        );
        $this->assertNotNull($own[0]['number'], 'two Instagram profiles are numbered');
    }
}
