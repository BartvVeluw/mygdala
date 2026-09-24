<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PageAdminGroup;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PageTree;
use App\Service\Routing\RouteTable;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The public path of a nested page, without a database
 * (App\Service\PagePath, docs/pages/NESTING.md): the tree and the slugs are
 * the test's own, through the seams PagePath and PageLocalization offer.
 *
 *     1 Diensten                    /diensten-overzicht        /en/services
 *     └ 2 Metaal graveren           /…/metaal-graveren         /en/…/metal-engraving
 *       └ 3 Aluminium visitekaartjes /…/aluminium-visitekaartjes /en/…/aluminium-business-cards
 *     4 Over ons                    /over-ons                  (no English address)
 *     └ 5 Team                      /over-ons/team             none: its parent has no English one
 *     6 Shop (fixed URL)            /shop.php
 *     7 Voorwaarden (service root)  /algemene-voorwaarden
 *     └ 8 Retourneren               /algemene-voorwaarden/retourneren
 *
 * What it proves: every path is its ancestors' slugs plus its own, per
 * language; a language in which any page on the chain has no address has no
 * path — the Multilingual 2.0 route rule, unchanged; a loop, a missing
 * parent and a fixed-URL parent give no path instead of a wrong one or a hang;
 * the tree order keeps the root order; the admin group is the root's; and
 * the redirects a move owes are exactly the paths that changed.
 */
final class PagePathTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        PageContent::clearCache();

        PagePath::overrideForTests([
            self::row(1, null, 'diensten-overzicht', 10),
            self::row(2, 1, 'metaal-graveren', 20),
            self::row(3, 2, 'aluminium-visitekaartjes', 30),
            self::row(4, null, 'over-ons', 40),
            self::row(5, 4, 'team', 50),
            self::row(6, null, 'shop', 5, '/shop.php', 1),
            self::row(7, null, 'algemene-voorwaarden', 60, null, 0, PageAdminGroup::SERVICE),
            self::row(8, 7, 'retourneren', 70),
        ]);

        $this->slugs(1, 'diensten-overzicht', 'services');
        $this->slugs(2, 'metaal-graveren', 'metal-engraving');
        $this->slugs(3, 'aluminium-visitekaartjes', 'aluminium-business-cards');
        $this->slugs(4, 'over-ons', null);
        $this->slugs(5, 'team', 'team');
        $this->slugs(7, 'algemene-voorwaarden', null);
        $this->slugs(8, 'retourneren', null);
    }

    protected function tearDown(): void
    {
        PageContent::clearCache();
        SiteLanguageFixture::reset();
    }

    // ------------------------------------------------------------- the paths

    public function testARootPageIsItsOwnSlugAsItAlwaysWas(): void
    {
        self::assertSame('/diensten-overzicht', PagePath::path(self::node(1), 'nl'));
        self::assertSame('/diensten-overzicht', PageContent::localizedPath(self::node(1), 'nl'));
        self::assertSame('/en/services', PageContent::localizedPath(self::node(1), 'en'));
    }

    public function testAChildAndAGrandchildCarryEveryAncestorInEveryLanguage(): void
    {
        self::assertSame('/diensten-overzicht/metaal-graveren', PageContent::localizedPath(self::node(2), 'nl'));
        self::assertSame(
            '/diensten-overzicht/metaal-graveren/aluminium-visitekaartjes',
            PageContent::localizedPath(self::node(3), 'nl')
        );
        self::assertSame(
            '/en/services/metal-engraving/aluminium-business-cards',
            PageContent::localizedPath(self::node(3), 'en')
        );
        self::assertSame(
            ['nl' => '/diensten-overzicht/metaal-graveren/aluminium-visitekaartjes', 'en' => '/en/services/metal-engraving/aluminium-business-cards'],
            PageContent::localizedPaths(self::node(3))
        );
    }

    /**
     * Route existence is not field fallback (docs/multilingual/ROUTING.md
     * §2): /en/over-ons does not exist, so /en/over-ons/team cannot either,
     * even though Team has an English slug. A link then goes to the default
     * language's version, as for any page without a version there.
     */
    public function testAnUntranslatedAncestorMeansNoPathInThatLanguage(): void
    {
        self::assertNull(PageContent::localizedPath(self::node(5), 'en'));
        self::assertSame(['nl' => '/over-ons/team'], PageContent::localizedPaths(self::node(5)));
        self::assertSame('/over-ons/team', PageContent::publicUrl(self::node(5), 'en'));
    }

    public function testAPageIsFoundOnlyByItsWholeChain(): void
    {
        // The reverse question pagina.php asks, minus the database lookup of
        // the last segment: the computed segments must be exactly the URL's.
        self::assertSame(['diensten-overzicht', 'metaal-graveren', 'aluminium-visitekaartjes'], PagePath::segments(self::node(3), 'nl'));
        self::assertNotSame(['over-ons', 'aluminium-visitekaartjes'], PagePath::segments(self::node(3), 'nl'));
        self::assertNotSame(['aluminium-visitekaartjes'], PagePath::segments(self::node(3), 'nl'));
    }

    public function testAFixedUrlPageKeepsItsRouteAndIsNoParent(): void
    {
        self::assertSame('/shop.php', PageContent::localizedPath(self::node(6), 'nl'));
        self::assertSame('/en/shop.php', PageContent::localizedPath(self::node(6), 'en'));

        // A row edited in SQL to sit under the Shop has no path at all.
        self::assertNull(PagePath::path(['id' => 99, 'parent_id' => 6, 'slug' => 'onder-de-shop'], 'nl'));
    }

    public function testALoopOrAMissingParentGivesNoPathAndNeverHangs(): void
    {
        PagePath::overrideForTests([
            self::row(1, 2, 'a', 10),
            self::row(2, 1, 'b', 20),
            self::row(3, 404, 'wees', 30),
        ]);
        $this->slugs(1, 'a', null);
        $this->slugs(2, 'b', null);
        $this->slugs(3, 'wees', null);

        self::assertNull(PagePath::path(self::node(1), 'nl'));
        self::assertNull(PagePath::ancestorIds(1));
        self::assertNull(PagePath::path(self::node(3), 'nl'));

        // Still listed, so an editor can open them and repair the tree.
        self::assertEqualsCanonicalizing([1, 2, 3], array_column(PageTree::ordered(), 'id'));
    }

    public function testNoPathIsDeeperThanTheRouterAccepts(): void
    {
        $rows = [];
        for ($id = 1; $id <= RouteTable::MAX_PAGE_SEGMENTS + 1; $id++) {
            $rows[] = self::row($id, $id === 1 ? null : $id - 1, 'n' . $id, $id);
        }
        PagePath::overrideForTests($rows);
        for ($id = 1; $id <= RouteTable::MAX_PAGE_SEGMENTS + 1; $id++) {
            $this->slugs($id, 'n' . $id, null);
        }

        self::assertCount(RouteTable::MAX_PAGE_SEGMENTS, PagePath::segments(self::node(RouteTable::MAX_PAGE_SEGMENTS), 'nl'));
        self::assertNull(PagePath::segments(self::node(RouteTable::MAX_PAGE_SEGMENTS + 1), 'nl'));
    }

    public function testAProspectivePathIsWhatASaveWouldGive(): void
    {
        self::assertSame('/over-ons/aluminium-visitekaartjes', PagePath::prospective(4, 'aluminium-visitekaartjes', 'nl', 3));
        self::assertSame('/aluminium-visitekaartjes', PagePath::prospective(null, 'aluminium-visitekaartjes', 'nl', 3));
        self::assertNull(PagePath::prospective(4, 'about-team', 'en', 5), 'Over ons has no English address');
        // Under one of its own descendants the chain meets the page itself.
        self::assertNull(PagePath::prospective(3, 'metaal-graveren', 'nl', 2));
    }

    // ------------------------------------------------------------ the tree

    public function testTheTreeKeepsTheRootOrderAndPutsChildrenUnderTheirParent(): void
    {
        self::assertSame(
            [[6, 0], [1, 0], [2, 1], [3, 2], [4, 0], [5, 1], [7, 0], [8, 1]],
            array_map(static fn (array $row): array => [$row['id'], $row['depth']], PageTree::ordered())
        );
        self::assertSame([2, 3], PagePath::descendantIds(1));
        self::assertSame([1, 2], PagePath::ancestorIds(3));
        self::assertSame(2, PagePath::subtreeHeight(1));
    }

    public function testAChildIsListedInItsRootsGroup(): void
    {
        self::assertSame(PageAdminGroup::SERVICE, PagePath::effectiveGroup(8));
        self::assertSame(PageAdminGroup::WEBSITE, PagePath::effectiveGroup(3));
        self::assertSame([7, 8], array_column(PageTree::ordered(PageAdminGroup::SERVICE), 'id'));
        self::assertSame(PageAdminGroup::SERVICE, PageService::resolveAdminGroup(7, PageAdminGroup::WEBSITE, PageAdminGroup::WEBSITE));
        self::assertSame(PageAdminGroup::SERVICE, PageService::resolveAdminGroup(null, PageAdminGroup::SERVICE, PageAdminGroup::WEBSITE));
        self::assertSame(PageAdminGroup::WEBSITE, PageService::resolveAdminGroup(null, 'onzin', PageAdminGroup::WEBSITE));
    }

    // ---------------------------------------------------------- the parents

    public function testNoPageCanSitUnderItselfOrAnythingBelowIt(): void
    {
        self::assertNotNull(PageService::validateParent(self::node(1), 1), 'self');
        self::assertNotNull(PageService::validateParent(self::node(1), 2), 'child');
        self::assertNotNull(PageService::validateParent(self::node(1), 3), 'grandchild: A -> B -> C, C never parent of A');
        self::assertNotNull(PageService::validateParent(self::node(2), 3), 'B under its own child');
        self::assertNotNull(PageService::validateParent(self::node(4), 6), 'a fixed-URL page is no parent');
        self::assertNotNull(PageService::validateParent(self::node(6), 4), 'a fixed-URL page sits at the top');
        self::assertNotNull(PageService::validateParent(self::node(4), 404), 'a parent that does not exist');

        self::assertNull(PageService::validateParent(self::node(3), 4));
        self::assertNull(PageService::validateParent(self::node(4), 3));
        self::assertNull(PageService::validateParent(self::node(3), null));
        self::assertNull(PageService::validateParent(null, 3), 'a new page under a grandchild');
    }

    public function testTheParentListOffersExactlyWhatIsAllowed(): void
    {
        self::assertSame([4, 5, 7, 8], PageService::parentCandidates(self::node(1)));
        self::assertSame([1, 2, 3, 4, 5, 7, 8], PageService::parentCandidates(null));
        self::assertSame([], PageService::parentCandidates(self::node(6)));
    }

    // ------------------------------------------------------- what a move owes

    public function testAMoveOwesOneRedirectPerChangedPathAndLanguage(): void
    {
        $before = [
            1 => ['nl' => '/diensten-overzicht', 'en' => '/services'],
            2 => ['nl' => '/diensten-overzicht/metaal-graveren', 'en' => '/services/metal-engraving'],
            3 => ['nl' => '/diensten-overzicht/metaal-graveren/aluminium-visitekaartjes', 'en' => null],
        ];
        $after = [
            1 => ['nl' => '/diensten', 'en' => '/services'],
            2 => ['nl' => '/diensten/metaal-graveren', 'en' => '/services/metal-engraving'],
            3 => ['nl' => '/diensten/metaal-graveren/aluminium-visitekaartjes', 'en' => '/services/metal-engraving/aluminium'],
        ];

        self::assertSame(
            [
                ['from' => '/diensten-overzicht', 'to' => '/diensten'],
                ['from' => '/diensten-overzicht/metaal-graveren/aluminium-visitekaartjes', 'to' => '/diensten/metaal-graveren/aluminium-visitekaartjes'],
            ],
            PageService::pathMoves($before, $after, [1 => true, 2 => false, 3 => true]),
            'a draft (2) owes nothing, an unchanged language nothing, a language that GAINED a path nothing'
        );

        self::assertSame(
            [['from' => '/en/services', 'to' => '/en/services-new']],
            PageService::pathMoves([1 => ['en' => '/services']], [1 => ['en' => '/services-new']], [1 => true]),
            'in the URL space of its own language'
        );
        self::assertSame([], PageService::pathMoves([1 => ['nl' => '/a']], [1 => ['nl' => null]], [1 => true]), 'a path that went away is no move');
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private static function row(
        int $id,
        ?int $parentId,
        string $slug,
        int $sortOrder,
        ?string $routePath = null,
        int $isSystem = 0,
        string $group = PageAdminGroup::WEBSITE
    ): array {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'admin_group' => $group,
            'slug' => $slug,
            'route_path' => $routePath,
            'is_system' => $isSystem,
            'status' => PageContent::STATUS_PUBLISHED,
            'sort_order' => $sortOrder,
        ];
    }

    /** @return array<string, mixed> */
    private static function node(int $id): array
    {
        return PagePath::node($id) ?? [];
    }

    private function slugs(int $pageId, string $nl, ?string $en): void
    {
        $translations = [new PageTranslation($pageId, 'nl', 'Pagina ' . $pageId, null, null, $nl)];
        if ($en !== null) {
            $translations[] = new PageTranslation($pageId, 'en', 'Page ' . $pageId, null, null, $en);
        }

        PageLocalization::overrideForTests($pageId, $translations);
    }
}
