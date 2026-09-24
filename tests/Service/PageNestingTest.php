<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\RedirectRepository;
use App\Service\Breadcrumbs\PageBreadcrumb;
use App\Service\PageAdminGroup;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\SlugChangeRedirects;
use App\Service\Routing\RequestLanguage;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;

/**
 * Nested pages against the real schema (Pagina's 2.0, docs/pages/NESTING.md):
 * pages.parent_id and pages.admin_group as the migration left them, the write
 * rules of App\Service\PageService, the one-query tree of App\Service\PagePath,
 * and the redirects a move of a whole subtree leaves behind
 * (App\Service\Redirects\SlugChangeRedirects::recordMoves()).
 *
 * Every page, translation and redirect here is the test's own (content keys
 * and slugs start with zz-nest) and is removed in tearDown(), children before
 * their parents, because the key refuses the other order.
 */
final class PageNestingTest extends TestCase
{
    private const PREFIX = 'zz-nest';

    private PageRepository $pages;

    /** @var list<int> in creation order */
    private array $created = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->cleanUp();
        PageContent::clearCache();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        RequestLanguage::reset();
        PageContent::clearCache();
    }

    // ----------------------------------------------------------- the schema

    public function testEveryExistingPageIsARootPageInTheWebsiteGroup(): void
    {
        $rows = Database::connection()->query(
            "SELECT parent_id, admin_group FROM pages WHERE content_key NOT LIKE 'zz-%'"
        )->fetchAll();

        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertNull($row['parent_id']);
            self::assertSame(PageAdminGroup::WEBSITE, $row['admin_group']);
        }
    }

    public function testTheParentKeyRefusesToLeaveAChildWithoutItsParent(): void
    {
        $parent = $this->page('ouder');
        $child = $this->page('kind', $parent);

        $this->expectException(\PDOException::class);

        try {
            $this->pages->delete($parent);
        } finally {
            self::assertNotNull($this->pages->findById($child));
        }
    }

    // ------------------------------------------------------------ the rules

    public function testAPageWithChildrenIsNotDeletedAndSaysWhy(): void
    {
        $parent = $this->page('ouder');
        $this->page('kind', $parent);

        try {
            PageService::delete((array) $this->pages->findById($parent));
            self::fail('a page with children was deleted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('onderliggende pagina', $e->getMessage());
        }

        self::assertNotNull($this->pages->findById($parent));
    }

    public function testACycleIsRefusedHoweverItIsAsked(): void
    {
        $a = $this->page('a');
        $b = $this->page('b', $a);
        $c = $this->page('c', $b);
        PagePath::clearCache();

        $pageA = (array) $this->pages->findById($a);

        self::assertNotNull(PageService::validateParent($pageA, $a), 'self-parent');
        self::assertNotNull(PageService::validateParent($pageA, $b), 'a descendant');
        self::assertNotNull(PageService::validateParent($pageA, $c), 'C can never become the parent of A');
        self::assertNotContains($c, PageService::parentCandidates($pageA));
        self::assertContains($a, PageService::parentCandidates((array) $this->pages->findById($c)));
    }

    public function testANewChildComesAfterItsSiblings(): void
    {
        $parent = $this->page('ouder');
        $first = $this->page('eerste', $parent);
        $second = $this->page('tweede', $parent);
        PagePath::clearCache();

        self::assertSame([$first, $second], PagePath::childIds($parent));

        // A page moved under the same parent lands after them too.
        $moved = $this->page('verhuisd');
        $this->pages->updatePlacement($moved, $parent, PageAdminGroup::WEBSITE, true);
        PagePath::clearCache();

        self::assertSame([$first, $second, $moved], PagePath::childIds($parent));
    }

    public function testAnExistingRootPageBecomesAChildAndAGrandchild(): void
    {
        $root = $this->page('root');
        $child = $this->page('child');
        $grandchild = $this->page('grandchild');

        $this->pages->updatePlacement($child, $root, PageAdminGroup::WEBSITE, true);
        $this->pages->updatePlacement($grandchild, $child, PageAdminGroup::WEBSITE, true);
        PageContent::clearCache();

        self::assertSame('/' . self::PREFIX . '-root', PagePath::for($root, 'nl'));
        self::assertSame('/' . self::PREFIX . '-root/' . self::PREFIX . '-child', PagePath::for($child, 'nl'));
        self::assertSame(
            '/' . self::PREFIX . '-root/' . self::PREFIX . '-child/' . self::PREFIX . '-grandchild',
            PagePath::for($grandchild, 'nl')
        );
    }

    // --------------------------------------------------------- the lookup

    public function testAPageAnswersOnlyAtItsWholePath(): void
    {
        $metal = $this->page('metaal');
        $wood = $this->page('hout');
        $card = $this->page('kaartje', $metal);
        PageContent::clearCache();

        $found = PageContent::forPath([self::PREFIX . '-metaal', self::PREFIX . '-kaartje'], 'nl');
        self::assertSame($card, (int) ($found['id'] ?? 0));

        self::assertNull(PageContent::forPath([self::PREFIX . '-hout', self::PREFIX . '-kaartje'], 'nl'), 'wrong parent');
        self::assertNull(PageContent::forPath([self::PREFIX . '-kaartje'], 'nl'), 'not a root page');
        self::assertNull(PageContent::forPath([self::PREFIX . '-metaal', self::PREFIX . '-hout'], 'nl'), 'a root page is no child');
        self::assertSame($wood, (int) (PageContent::forPath([self::PREFIX . '-hout'], 'nl')['id'] ?? 0));
    }

    public function testTheBreadcrumbFollowsTheAncestorsWithTheirLinks(): void
    {
        $services = $this->page('diensten');
        $metal = $this->page('metaal', $services);
        $card = $this->page('kaartje', $metal);
        PageContent::clearCache();
        RequestLanguage::set('nl', false);

        $items = PageBreadcrumb::forPage((array) $this->pages->findById($card))?->items() ?? [];

        self::assertSame(
            ['Home', 'Nest diensten', 'Nest metaal', 'Nest kaartje'],
            array_map(static fn ($item): string => $item->label, $items)
        );
        self::assertSame('/' . self::PREFIX . '-diensten', $items[1]->href);
        self::assertSame('/' . self::PREFIX . '-diensten/' . self::PREFIX . '-metaal', $items[2]->href);
        self::assertNull($items[3]->href, 'the page itself is never a link');
    }

    public function testTheSitemapListsTheNestedPath(): void
    {
        $parent = $this->page('sitemap-ouder');
        $this->page('sitemap-kind', $parent);
        PageContent::clearCache();

        $xml = Sitemap::xml();

        self::assertStringContainsString('/' . self::PREFIX . '-sitemap-ouder/' . self::PREFIX . '-sitemap-kind</loc>', $xml);
        // Never also at the path it would have as a root page.
        self::assertDoesNotMatchRegularExpression('#<loc>https?://[^/<]+/' . self::PREFIX . '-sitemap-kind</loc>#', $xml);
    }

    // ----------------------------------------------------------- the links

    /**
     * A link stores the page id, never a path, so it follows the page into
     * the tree: a menu item, a footer link or a block button that names a
     * page (LinkResolver, LinkTargets / LinkChoice), and an address an editor
     * typed (TypedLink), which is translated only when it is that page's
     * whole default-language path.
     */
    public function testLinksToANestedPageFollowItInEveryLanguage(): void
    {
        $parent = $this->page('ouder', null, 'parent');
        $child = $this->page('kind', $parent, 'child');
        PageContent::clearCache();

        $p = '/' . self::PREFIX;
        RequestLanguage::set('nl', false);
        self::assertSame($p . '-ouder' . $p . '-kind', \App\Service\LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $child])['href'] ?? null);
        self::assertSame($p . '-ouder' . $p . '-kind', \App\Service\Routing\LinkChoice::href('page', $child, ''));

        RequestLanguage::set('en', true);
        self::assertSame('/en' . $p . '-parent' . $p . '-child', \App\Service\LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $child])['href'] ?? null);
        self::assertSame('/en' . $p . '-parent' . $p . '-child#team', \App\Service\Routing\TypedLink::href($p . '-ouder' . $p . '-kind#team', 'en'));
        self::assertSame($p . '-kind', \App\Service\Routing\TypedLink::href($p . '-kind', 'en'), 'not a page path: left as typed');

        // Moved to the top: the same stored id, the new path.
        $this->pages->updatePlacement($child, null, PageAdminGroup::WEBSITE, true);
        PageContent::clearCache();
        \App\Service\LinkResolver::clearCache();
        self::assertSame('/en' . $p . '-child', \App\Service\LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $child])['href'] ?? null);
    }

    // ------------------------------------------------------- the redirects

    /**
     * /materiaal/metaal/aluminium: the root is renamed and every path below it
     * changes with it. Each one gets its own permanent redirect, in the URL
     * space of each language; a draft below gets none.
     */
    public function testRenamingARootRedirectsItsWholeSubtreeInEveryLanguage(): void
    {
        $root = $this->page('materiaal', null, 'materials');
        $metal = $this->page('metaal', $root, 'metal');
        $aluminium = $this->page('aluminium', $metal, 'aluminium-en');
        $draft = $this->page('concept', $metal, null, PageContent::STATUS_DRAFT);
        PageContent::clearCache();

        $moving = [$root, ...PagePath::descendantIds($root)];
        $before = PagePath::snapshot($moving);

        $this->pages->update($root, ['slug' => self::PREFIX . '-materialen', 'status' => PageContent::STATUS_PUBLISHED]);
        PageLocalization::save($root, 'nl', [PageTranslation::TITLE => 'Nest materialen'], self::PREFIX . '-materialen');
        PageContent::clearCache();

        $redirectable = [$root => true, $metal => true, $aluminium => true, $draft => false];
        $moves = PageService::pathMoves($before, PagePath::snapshot($moving), $redirectable);

        self::assertSame(3, (new SlugChangeRedirects())->recordMoves($moves));

        $p = '/' . self::PREFIX;
        self::assertSame(
            [
                $p . '-materiaal' => $p . '-materialen',
                $p . '-materiaal' . $p . '-metaal' => $p . '-materialen' . $p . '-metaal',
                $p . '-materiaal' . $p . '-metaal' . $p . '-aluminium' => $p . '-materialen' . $p . '-metaal' . $p . '-aluminium',
            ],
            $this->redirectsFrom($p . '-materiaal')
        );
        self::assertSame([], $this->redirectsFrom('/en' . $p), 'the English slugs did not change');
    }

    public function testNestingAPageRedirectsItsOldPathsAndMovingBackLeavesNoLoop(): void
    {
        $parent = $this->page('ouder', null, 'parent');
        $child = $this->page('kind', null, 'child');
        $grandchild = $this->page('kleinkind', $child, 'grandchild');
        PageContent::clearCache();

        $p = '/' . self::PREFIX;
        $this->move($child, $parent);

        self::assertSame(
            [
                $p . '-kind' => $p . '-ouder' . $p . '-kind',
                $p . '-kind' . $p . '-kleinkind' => $p . '-ouder' . $p . '-kind' . $p . '-kleinkind',
            ],
            $this->redirectsFrom($p . '-kind')
        );
        self::assertSame(
            [
                '/en' . $p . '-child' => '/en' . $p . '-parent' . $p . '-child',
                '/en' . $p . '-child' . $p . '-grandchild' => '/en' . $p . '-parent' . $p . '-child' . $p . '-grandchild',
            ],
            $this->redirectsFrom('/en' . $p . '-child')
        );

        // Back to the top: the old paths are live again, so nothing may
        // redirect away from them, and the paths under the parent now redirect
        // back. No row points at itself, no chain loops.
        $this->move($child, null);

        self::assertSame([], $this->redirectsFrom($p . '-kind'));
        self::assertSame(
            [
                $p . '-ouder' . $p . '-kind' => $p . '-kind',
                $p . '-ouder' . $p . '-kind' . $p . '-kleinkind' => $p . '-kind' . $p . '-kleinkind',
            ],
            $this->redirectsFrom($p . '-ouder' . $p . '-kind')
        );
        self::assertSame($grandchild, (int) (PageContent::forPath([self::PREFIX . '-kind', self::PREFIX . '-kleinkind'], 'nl')['id'] ?? 0));
    }

    public function testAManualRedirectOnAnOldPathIsLeftAlone(): void
    {
        $parent = $this->page('ouder');
        $child = $this->page('kind');
        $p = '/' . self::PREFIX;

        (new RedirectRepository())->create([
            'source_path' => $p . '-kind',
            'target_type' => 'internal',
            'target_value' => '/',
            'status_code' => Redirect::STATUS_PERMANENT,
            'is_active' => true,
            'origin' => Redirect::ORIGIN_MANUAL,
        ]);

        $this->move($child, $parent);

        $row = (new RedirectRepository())->findBySourcePath($p . '-kind');
        self::assertSame('/', $row['target_value'] ?? null);
        self::assertSame(Redirect::ORIGIN_MANUAL, $row['origin'] ?? null);
    }

    // ------------------------------------------------------------ queries

    /**
     * The whole tree is one query and every slug in it one more, however many
     * pages there are: twenty nested pages cost what one does. Before the
     * slugs were loaded with the tree, twenty cost 22.
     */
    public function testTwentyNestedPathsCostNoMoreQueriesThanOne(): void
    {
        $parent = $this->page('bulk-ouder');
        $children = [];
        for ($i = 1; $i <= 20; $i++) {
            $children[] = $this->page('bulk-' . $i, $parent);
        }

        $one = $this->selectsFor([$children[0]]);
        $twenty = $this->selectsFor($children);

        self::assertSame($one, $twenty);
        self::assertSame(2, $twenty, 'the tree, and every slug in it');
    }

    // ------------------------------------------------------------ helpers

    private function page(
        string $name,
        ?int $parentId = null,
        ?string $english = null,
        string $status = PageContent::STATUS_PUBLISHED
    ): int {
        $slug = self::PREFIX . '-' . $name;
        $id = PageFixture::create(['content_key' => $slug, 'slug' => $slug, 'status' => $status, 'parent_id' => $parentId], 'Nest ' . $name);

        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => 'Nest ' . $name], $slug);
        if ($english !== null) {
            PageLocalization::save($id, 'en', [PageTranslation::TITLE => 'Nest EN ' . $name], self::PREFIX . '-' . $english);
        }

        $this->created[] = $id;

        return $id;
    }

    /** The move api/admin/update-page.php makes, minus the form. */
    private function move(int $pageId, ?int $parentId): void
    {
        PageContent::clearCache();
        $moving = [$pageId, ...PagePath::descendantIds($pageId)];
        $before = PagePath::snapshot($moving);

        $this->pages->updatePlacement($pageId, $parentId, PageAdminGroup::WEBSITE, true);
        PageContent::clearCache();

        $moves = PageService::pathMoves($before, PagePath::snapshot($moving), array_fill_keys($moving, true));
        (new SlugChangeRedirects())->recordMoves($moves);
        PageContent::clearCache();
    }

    /** @return array<string, string> source => target, for sources starting with $prefix */
    private function redirectsFrom(string $prefix): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT source_path, target_value FROM redirects WHERE source_path LIKE ? ORDER BY source_path'
        );
        $stmt->execute([$prefix . '%']);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['source_path']] = (string) $row['target_value'];
        }

        return $found;
    }

    /** @param list<int> $pageIds */
    private function selectsFor(array $pageIds): int
    {
        PageContent::clearCache();
        $before = $this->selects();

        foreach ($pageIds as $pageId) {
            self::assertNotNull(PagePath::for($pageId, 'nl'));
        }

        return $this->selects() - $before;
    }

    private function selects(): int
    {
        return (int) Database::connection()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
    }

    private function cleanUp(): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM redirects WHERE source_path LIKE ? OR source_path LIKE ?')
            ->execute(['/' . self::PREFIX . '%', '/en/' . self::PREFIX . '%']);

        // Children before their parents: the key refuses the other order.
        for ($round = 0; $round < 10; $round++) {
            $db->prepare('DELETE FROM pages WHERE content_key LIKE ? AND id NOT IN (SELECT parent_id FROM (SELECT parent_id FROM pages WHERE parent_id IS NOT NULL) AS parents)')
                ->execute([self::PREFIX . '%']);
        }

        $this->created = [];
    }
}
