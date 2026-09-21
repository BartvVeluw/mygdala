<?php

namespace Tests\Service;

use App\Repository\PageRepository;
use App\Service\LinkResolver;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the 'page' case (needs a real `pages` row to
 * resolve against) plus pure unit coverage for the other link types.
 */
class LinkResolverTest extends TestCase
{
    private PageRepository $pageRepository;

    /** @var list<int> */
    private array $createdPageIds = [];

    protected function setUp(): void
    {
        $this->pageRepository = new PageRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPageIds as $id) {
            $this->pageRepository->delete($id);
        }

        LinkResolver::clearCache();
        \App\Service\PageLocalization::clearCache();
    }

    private function makePage(string $slug, bool $published = true): int
    {
        $id = \Tests\Support\PageFixture::create([
            'content_key' => $slug,
            'slug' => $slug,
            'status' => $published ? 'published' : 'draft',
        ], 'Test page ' . $slug);
        $this->createdPageIds[] = $id;

        return $id;
    }

    public function testRouteTypeResolvesToRegisteredUrl(): void
    {
        $result = LinkResolver::resolve(['link_type' => 'route', 'target_route' => 'shop']);

        $this->assertSame('/shop.php', $result['href']);
    }

    public function testUnknownRouteResolvesToNull(): void
    {
        $this->assertNull(LinkResolver::resolve(['link_type' => 'route', 'target_route' => 'nonexistent']));
    }

    public function testExternalTypeReturnsStoredUrlVerbatim(): void
    {
        $result = LinkResolver::resolve(['link_type' => 'external', 'external_url' => '/diensten.php#hout']);

        $this->assertSame('/diensten.php#hout', $result['href']);
    }

    public function testNoneTypeHasNoHrefButIsNotRejected(): void
    {
        $result = LinkResolver::resolve(['link_type' => 'none']);

        $this->assertNotNull($result);
        $this->assertNull($result['href']);
    }

    public function testActionTypeIsRestrictedToAllowlist(): void
    {
        $allowed = LinkResolver::resolve(['link_type' => 'action', 'action_key' => 'cookie_preferences']);
        $this->assertNotNull($allowed);
        $this->assertTrue($allowed['is_action']);

        $rejected = LinkResolver::resolve(['link_type' => 'action', 'action_key' => 'delete_everything']);
        $this->assertNull($rejected);
    }

    public function testPageTypeResolvesToCurrentSlug(): void
    {
        $pageId = $this->makePage('link-resolver-test-page');

        $result = LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $pageId], $this->pageRepository);

        $this->assertSame('/link-resolver-test-page', $result['href']);
    }

    public function testPageTypeFollowsSlugChangeAutomatically(): void
    {
        $pageId = $this->makePage('old-slug-test');

        $this->renameTo($pageId, 'new-slug-test');

        $result = LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $pageId], $this->pageRepository);

        $this->assertSame('/new-slug-test', $result['href']);
    }

    /**
     * The footer stores the same link shape as the navigation and resolves
     * it through the same LinkResolver, so a renamed page follows through
     * there too — this is the footer half of the guarantee above, asserted
     * on an actual footer_links row rather than on a hand-built array.
     */
    public function testFooterPageLinkAlsoFollowsSlugChange(): void
    {
        $pageId = $this->makePage('footer-link-old-slug');

        // A column of this test's own: whether the installation already has
        // a footer is ordinary CMS data and no precondition of this guarantee.
        $footerRepository = new \App\Repository\FooterRepository();
        $columnId = $footerRepository->createColumn(['is_visible' => false]);

        $linkId = $footerRepository->createLink([
            'column_id' => $columnId,
            'link_type' => 'page',
            'target_page_id' => $pageId,
            'target_route' => null,
            'external_url' => null,
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => false,
        ]);

        try {
            $this->renameTo($pageId, 'footer-link-new-slug');

            $link = $footerRepository->findLinkById($linkId);
            $resolved = LinkResolver::resolve($link, $this->pageRepository);

            $this->assertNotNull($resolved);
            $this->assertSame('/footer-link-new-slug', $resolved['href']);
        } finally {
            $footerRepository->deleteLink($linkId);
            $footerRepository->deleteColumn($columnId);
        }
    }

    /**
     * Setting a linked page back to Concept must not leave a public link
     * pointing at a 404 — LinkResolver returns null, and both
     * NavigationService and FooterService skip a null-resolving row entirely
     * (rather than deleting the admin's configuration).
     */
    public function testDraftingALinkedPageHidesTheLinkWithoutDeletingIt(): void
    {
        $pageId = $this->makePage('drafted-again-test');
        $row = ['link_type' => 'page', 'target_page_id' => $pageId];

        $this->assertNotNull(LinkResolver::resolve($row, $this->pageRepository));

        $this->renameTo($pageId, 'drafted-again-test', published: false);

        $this->assertNull(LinkResolver::resolve($row, $this->pageRepository));
    }

    private function renameTo(int $pageId, string $slug, bool $published = true): void
    {
        $this->pageRepository->update($pageId, [
            'slug' => $slug,
            'status' => $published ? 'published' : 'draft',
        ]);
    }

    public function testUnpublishedPageResolvesToNull(): void
    {
        $pageId = $this->makePage('unpublished-test-page', published: false);

        $this->assertNull(LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $pageId], $this->pageRepository));
    }

    public function testDeletedPageResolvesToNull(): void
    {
        $pageId = $this->makePage('deleted-test-page');
        $this->pageRepository->delete($pageId);
        $this->createdPageIds = array_values(array_diff($this->createdPageIds, [$pageId]));

        $this->assertNull(LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $pageId], $this->pageRepository));
    }

    /* ------------------------------------------------------------------ */
    /* Query behaviour (docs/multilingual/ROUTING.md, "Querygedrag")        */
    /* ------------------------------------------------------------------ */

    /**
     * REGRESSION, measured before the fix with Com_select on this connection:
     * with the addresses preloaded, every page link still asked `pages` for
     * its own row — 1 link cost 2 queries, 5 cost 6, 20 cost 21. The rows now
     * come in the same preload, so the count no longer depends on the length
     * of the list.
     */
    public function testTwentyPageLinksCostNoMoreQueriesThanOne(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['link_type' => 'page', 'target_page_id' => $this->makePage('zz-linkresolver-bulk-' . $i)];
        }
        $this->warmUp($rows[0]);

        $one = $this->selectsToResolve(array_slice($rows, 0, 1));
        $five = $this->selectsToResolve(array_slice($rows, 0, 5));
        $twenty = $this->selectsToResolve($rows);

        $this->assertSame(2, $one, 'the rows and the addresses, one query each');
        $this->assertSame($one, $five);
        $this->assertSame($one, $twenty, '20 page links must not cost 20 page queries');
    }

    /** The bulk lookup applies the same rule the single one does. */
    public function testAPreloadedDraftOrDeletedTargetStaysHiddenAtNoExtraCost(): void
    {
        $published = $this->makePage('zz-linkresolver-bulk-published');
        $draft = $this->makePage('zz-linkresolver-bulk-draft', published: false);
        $deleted = $this->makePage('zz-linkresolver-bulk-deleted');
        $this->pageRepository->delete($deleted);
        $this->createdPageIds = array_values(array_diff($this->createdPageIds, [$deleted]));

        $rows = [
            ['link_type' => 'page', 'target_page_id' => $published],
            ['link_type' => 'page', 'target_page_id' => $draft],
            ['link_type' => 'page', 'target_page_id' => $deleted],
        ];
        $this->warmUp($rows[0]);

        LinkResolver::preloadPages($rows);
        $before = $this->selects();

        $this->assertSame('/zz-linkresolver-bulk-published', LinkResolver::resolve($rows[0])['href'] ?? null);
        $this->assertNull(LinkResolver::resolve($rows[1]), 'a draft is not linked');
        $this->assertNull(LinkResolver::resolve($rows[2]), 'a deleted page is not linked');
        $this->assertSame(0, $this->selects() - $before, 'and knowing that took no second lookup');
    }

    /**
     * The same holds for the header as a whole: a menu of twenty page links
     * costs what a menu of one does.
     */
    public function testTheHeaderCostsTheSameWithOneOrTwentyPageLinks(): void
    {
        $navigation = new \App\Repository\NavigationRepository();
        $navIds = [];

        try {
            $add = function (int $i) use ($navigation, &$navIds): void {
                $navIds[] = $navigation->create([
                    'link_type' => 'page',
                    'target_page_id' => $this->makePage('zz-linkresolver-menu-' . $i),
                    'target_route' => null,
                    'external_url' => null,
                    'open_in_new_tab' => false,
                    'parent_id' => null,
                    'sort_order' => 900 + $i,
                    'is_visible' => true,
                ]);
            };

            $add(1);
            $this->headerSelects();
            $withOne = $this->headerSelects();

            for ($i = 2; $i <= 20; $i++) {
                $add($i);
            }
            $withTwenty = $this->headerSelects();

            $hrefs = array_column(\App\Service\NavigationService::header()['items'], 'href');
            $this->assertContains('/zz-linkresolver-menu-20', $hrefs, 'every added link is really in the menu');
            $this->assertSame($withOne, $withTwenty);
        } finally {
            foreach ($navIds as $id) {
                $navigation->delete($id);
            }
        }
    }

    /** SELECTs spent by preload + resolve for these rows, with cold caches. */
    private function selectsToResolve(array $rows): int
    {
        LinkResolver::clearCache();
        \App\Service\PageLocalization::clearCache();

        $before = $this->selects();
        LinkResolver::preloadPages($rows);
        foreach ($rows as $row) {
            $this->assertNotNull(LinkResolver::resolve($row));
        }

        return $this->selects() - $before;
    }

    /** SELECTs spent by one header() with cold per-render caches. */
    private function headerSelects(): int
    {
        LinkResolver::clearCache();
        \App\Service\PageLocalization::clearCache();
        \App\Service\NavigationLocalization::clearCache();

        $before = $this->selects();
        \App\Service\NavigationService::header();

        return $this->selects() - $before;
    }

    /** The registries (languages, modules) load once per request; do that outside the count. */
    private function warmUp(array $row): void
    {
        LinkResolver::preloadPages([$row]);
        LinkResolver::resolve($row);
    }

    private function selects(): int
    {
        return (int) \App\Database::connection()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
    }

    public function testValidateRejectsLinkTypeNotInAllowlist(): void
    {
        $error = LinkResolver::validate('action', null, null, null, 'cookie_preferences', LinkResolver::LINK_TYPES_NAV, $this->pageRepository);

        $this->assertNotNull($error);
    }

    public function testValidateRejectsInvalidExternalUrl(): void
    {
        $error = LinkResolver::validate('external', null, null, 'not a url', null, LinkResolver::LINK_TYPES_NAV, $this->pageRepository);

        $this->assertNotNull($error);
    }

    public function testValidateAcceptsRootRelativeUrl(): void
    {
        $error = LinkResolver::validate('external', null, null, '/diensten.php#hout', null, LinkResolver::LINK_TYPES_NAV, $this->pageRepository);

        $this->assertNull($error);
    }

    public function testValidateRejectsUnknownRoute(): void
    {
        $error = LinkResolver::validate('route', null, 'nonexistent', null, null, LinkResolver::LINK_TYPES_NAV, $this->pageRepository);

        $this->assertNotNull($error);
    }

    public function testValidateRejectsUnknownPageId(): void
    {
        $error = LinkResolver::validate('page', 999999, null, null, null, LinkResolver::LINK_TYPES_NAV, $this->pageRepository);

        $this->assertNotNull($error);
    }
}
