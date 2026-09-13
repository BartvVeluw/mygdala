<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PageContent;
use PHPUnit\Framework\TestCase;

/**
 * The search field above admin/pages.php: which pages stay in the list for
 * what an editor typed (PageContent::matchesAdminSearch()).
 *
 * Plain input and output. The overview filters the rows it has already
 * loaded, so this is the whole behaviour and it needs no database.
 */
final class PagesOverviewSearchTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function page(string $title, string $slug, string $routePath = ''): array
    {
        return ['title' => $title, 'slug' => $slug, 'route_path' => $routePath];
    }

    public function testAnEmptySearchKeepsEveryPage(): void
    {
        $this->assertTrue(PageContent::matchesAdminSearch(self::page('Over ons', 'over-ons'), ''));
        $this->assertTrue(PageContent::matchesAdminSearch(self::page('Over ons', 'over-ons'), '   '));
    }

    public function testTheTitleMatchesWhateverTheCase(): void
    {
        $this->assertTrue(PageContent::matchesAdminSearch(self::page('Veelgestelde vragen', 'faq'), 'VRAGEN'));
        $this->assertTrue(PageContent::matchesAdminSearch(self::page('Één keer per jaar', 'jaarlijks'), 'één'));
    }

    public function testTheAddressAPageIsServedAtMatchesToo(): void
    {
        $this->assertTrue(PageContent::matchesAdminSearch(self::page('Veelgestelde vragen', 'faq'), '/faq'));

        // A page on a fixed route is found by that route, the address the
        // overview shows, and not by a slug nobody sees.
        $this->assertTrue(PageContent::matchesAdminSearch(self::page('Winkel', 'winkel-slug', '/shop.php'), 'shop.php'));
        $this->assertFalse(PageContent::matchesAdminSearch(self::page('Winkel', 'winkel-slug', '/shop.php'), 'winkel-slug'));
    }

    public function testAnythingElseLeavesThePageOut(): void
    {
        $this->assertFalse(PageContent::matchesAdminSearch(self::page('Over ons', 'over-ons'), 'contact'));
    }
}
