<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageOptions;
use App\Service\PagePath;
use App\Service\PageTranslation;
use App\Service\PageTree;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The one order and text of a page in a list (App\Service\PageOptions, Pages &
 * Destinations 3.0), on a tree of the test's own through PagePath's seam:
 *
 *     1 Homepage (fixed URL)
 *     2 Diensten
 *     └ 3 Metaal graveren
 *       └ 4 Aluminium visitekaartjes
 *     └ 5 Hout graveren
 *     6 Contact
 *     7 Portfolio (a list may leave it out)
 *     └ 8 Wolven
 *
 * What it proves: the order is the Pages overview's — roots in their order,
 * a child directly under its parent, a grandchild under the child, a second
 * root after the whole first tree — never by id; the text indents and marks
 * only a page under another, with characters a screen reader keeps quiet
 * about; a page a list leaves out but that stands above one it offers stays
 * as context; a stored choice is kept. And that every page list of the CMS
 * goes through this one class.
 */
final class PageOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        PageContent::clearCache();

        // Sort orders deliberately not in id order: the tree decides.
        PagePath::overrideForTests([
            self::row(1, null, 5, '/'),
            self::row(2, null, 30),
            self::row(3, 2, 20),
            self::row(4, 3, 10),
            self::row(5, 2, 40),
            self::row(6, null, 50),
            self::row(7, null, 40),
            self::row(8, 7, 10),
        ]);

        foreach ([1 => 'Homepage', 2 => 'Diensten', 3 => 'Metaal graveren', 4 => 'Aluminium visitekaartjes', 5 => 'Hout graveren', 6 => 'Contact', 7 => 'Portfolio', 8 => 'Wolven'] as $id => $title) {
            PageLocalization::overrideForTests($id, [new PageTranslation($id, 'nl', $title, null, null, 'pagina-' . $id)]);
        }
    }

    protected function tearDown(): void
    {
        PageContent::clearCache();
        SiteLanguageFixture::reset();
    }

    public function testTheOrderIsThePagesOverviewsTree(): void
    {
        $options = PageOptions::tree();

        self::assertSame([1, 2, 3, 4, 5, 7, 8, 6], array_column($options, 'id'), 'roots in their order, every child under its parent');
        self::assertSame(array_column(PageTree::ordered(), 'id'), array_column($options, 'id'), 'exactly the overview\'s order');
        self::assertSame([0, 0, 1, 2, 1, 0, 1, 0], array_column($options, 'depth'));
    }

    public function testTheTextIndentsAndMarksOnlyAPageUnderAnother(): void
    {
        $labels = array_column(PageOptions::tree(), 'label', 'id');

        self::assertSame('Diensten', $labels[2], 'a root page is its name');
        self::assertSame("\u{00A0}\u{00A0}\u{00A0}\u{2013}\u{00A0}Metaal graveren", $labels[3]);
        self::assertSame("\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{2013}\u{00A0}Aluminium visitekaartjes", $labels[4]);

        foreach ($labels as $label) {
            self::assertLessThanOrEqual(1, substr_count($label, "\u{2013}"), 'one mark, never a row of them');
            self::assertStringNotContainsString('  ', $label, 'no plain spaces for the indent');
            self::assertDoesNotMatchRegularExpression('/[\x{2500}-\x{257F}]/u', $label, 'no box-drawing characters to read out');
        }
    }

    public function testAPageAboveAnOfferedOneStaysAsContext(): void
    {
        $options = PageOptions::tree(static fn (array $page): bool => (int) $page['id'] !== 7 && (int) $page['id'] !== 2);
        $byId = array_column($options, null, 'id');

        self::assertTrue($byId[7]['context'], 'Portfolio is listed to keep Wolven under it');
        self::assertTrue($byId[2]['context'], 'and Diensten above its pages');
        self::assertFalse($byId[8]['context']);
        self::assertSame([1, 2, 3, 4, 5, 7, 8, 6], array_column($options, 'id'), 'the order does not move');

        $leaf = PageOptions::tree(static fn (array $page): bool => (int) $page['id'] === 6);
        self::assertSame([6], array_column($leaf, 'id'), 'nothing above a root page to keep');
    }

    public function testAStoredChoiceIsKeptWhateverTheFilterSays(): void
    {
        $options = PageOptions::tree(static fn (array $page): bool => false, [5]);

        self::assertSame([2, 5], array_column($options, 'id'));
        self::assertFalse(array_column($options, null, 'id')[5]['context'], 'the kept page can be chosen');
        self::assertTrue(array_column($options, null, 'id')[2]['context']);
    }

    /**
     * Every list in which an editor picks a CMS page goes through this class:
     * a menu item's and a footer link's page, a page's parent, a block
     * button's page and the Shop's product overview. None of them may sort
     * pages its own way again.
     */
    public function testEveryPageListOfTheCmsGoesThroughIt(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'admin/navigation-item.php',
            'admin/footer-link.php',
            'admin/_page_placement.php',
            'src/Service/Routing/LinkTargets.php',
            'admin/shop-settings.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('PageOptions::tree(', $source, $file);
            self::assertStringNotContainsString('PageTree::ordered(', $source, $file . ' builds no tree of its own');
            self::assertStringNotContainsString('"\u{00A0}\u{00A0}\u{00A0}"', $source, $file . ' indents nothing itself');
        }

        foreach (['admin/navigation-item.php', 'admin/footer-link.php'] as $file) {
            self::assertStringNotContainsString('findAllForAdmin()', (string) file_get_contents($root . '/' . $file), $file . ' lists no flat page list any more');
        }
    }

    /** @return array<string, mixed> */
    private static function row(int $id, ?int $parentId, int $sortOrder, ?string $routePath = null): array
    {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'admin_group' => 'website',
            'content_key' => 'optie-' . $id,
            'slug' => 'pagina-' . $id,
            'route_path' => $routePath,
            'is_system' => $routePath !== null ? 1 : 0,
            'module_default' => 0,
            'status' => PageContent::STATUS_PUBLISHED,
            'sort_order' => $sortOrder,
        ];
    }
}
