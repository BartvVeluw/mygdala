<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The search field above admin/pages.php: which pages stay in the list for
 * what an editor typed (PageContent::matchesAdminSearch()).
 *
 * Plain input and output. The overview filters the rows it has already
 * loaded, so this is the whole behaviour and it needs no database: the page's
 * name — its title in the default language, PageLocalization::name() — is
 * handed to App\Service\PageLocalization in memory.
 */
final class PagesOverviewSearchTest extends TestCase
{
    private static int $nextId = 900;

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        PageLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    /** @return array<string, mixed> */
    private static function page(string $title, string $slug, string $routePath = ''): array
    {
        $id = ++self::$nextId;
        PageLocalization::overrideForTests($id, [new PageTranslation($id, 'nl', $title, null, null)]);

        return ['id' => $id, 'slug' => $slug, 'route_path' => $routePath];
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

    public function testTheNameIsTheDefaultLanguagesTitle(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $page = self::page('Over ons', 'about');
        PageLocalization::overrideForTests((int) $page['id'], [
            new PageTranslation((int) $page['id'], 'nl', 'Over ons', null, null),
            new PageTranslation((int) $page['id'], 'en', 'About us', null, null),
        ]);

        $this->assertTrue(PageContent::matchesAdminSearch($page, 'about us'));
        $this->assertFalse(PageContent::matchesAdminSearch($page, 'over ons'));
    }

    public function testAnythingElseLeavesThePageOut(): void
    {
        $this->assertFalse(PageContent::matchesAdminSearch(self::page('Over ons', 'over-ons'), 'contact'));
    }
}
