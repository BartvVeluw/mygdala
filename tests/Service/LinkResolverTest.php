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
    }

    private function makePage(string $slug, bool $published = true): int
    {
        $id = $this->pageRepository->create([
            'content_key' => $slug,
            'slug' => $slug,
            'title' => 'Test page ' . $slug,
            'status' => $published ? 'published' : 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
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
        $columnId = $footerRepository->createColumn(['title_nl' => 'Zz testkolom', 'title_en' => 'Zz test column', 'is_visible' => false]);

        $linkId = $footerRepository->createLink([
            'column_id' => $columnId,
            'label_nl' => 'Testlink',
            'label_en' => 'Test link',
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
            'title' => 'Test page ' . $slug,
            'status' => $published ? 'published' : 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
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
