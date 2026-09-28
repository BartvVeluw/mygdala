<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\PageLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The Pages overview 3.0 (admin/pages.php, docs/pages/NESTING.md "Het
 * overzicht"): what every row holds, in which order, and the layout rules
 * that keep the rows one height and the page free of sideways scrolling.
 *
 * THE CONTRACT
 *   - a FIXED table layout with four set columns: title, status, type,
 *     actions — no column takes its width from what a row holds;
 *   - every row: the name and, on a line of its own, the public address,
 *     both always on screen (never only on hover) and cut with an ellipsis,
 *     the whole text kept in a title;
 *   - one line of actions: Bewerken first (the primary one), then Bekijken
 *     (a published page) or Voorbeeld (a draft), then Subpagina toevoegen;
 *     Verwijderen only in the row's "…" menu, asked first in the CMS's own
 *     dialog, or — when it cannot be done — disabled with the reason beside it;
 *   - three layouts by the TREE's own width (container queries at 56rem and
 *     40rem), the phone one a card per page.
 *
 * The tree markup, folding and search are Tests\Service\PageNestingHttpTest.
 * Pages are this test's own (zz-overview-…), removed in tearDown(), a child
 * before its parent.
 */
final class PagesOverviewLayoutTest extends TestCase
{
    private const P = 'zz-overview';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var array<string, int> */
    private array $ids = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->accounts = new AdminTestSession();

        $this->ids['parent'] = $this->page('parent', 'zz Overzicht ouder', PageContent::STATUS_PUBLISHED);
        $this->ids['child'] = $this->page('child', 'zz Overzicht kind met een heel lange naam die in geen enkele kolom past', PageContent::STATUS_DRAFT, $this->ids['parent']);
    }

    protected function tearDown(): void
    {
        $pages = new PageRepository();
        foreach (array_reverse($this->ids) as $id) {
            $pages->delete($id);
        }

        $this->ids = [];
        if (isset($this->accounts)) {
            $this->accounts->forget();
        }
        PageLocalization::clearCache();
    }

    // ---------------------------------------------------------------- rows

    public function testEveryRowHasTheSameFourCellsInAFixedLayout(): void
    {
        $xpath = $this->overview();

        foreach ($xpath->query('//table[contains(@class, "admin-page-tree__table")]') as $table) {
            $columns = [];
            foreach ($xpath->query('./colgroup/col', $table) as $col) {
                $columns[] = $col->getAttribute('class');
            }
            self::assertSame(['admin-page-tree__col-title', 'admin-page-tree__col-status', 'admin-page-tree__col-type', 'admin-page-tree__col-actions'], $columns);
        }

        foreach (['parent', 'child'] as $name) {
            $cells = [];
            foreach ($xpath->query('//tr[@data-page-row="' . $this->ids[$name] . '"]/td') as $cell) {
                $cells[] = $cell->getAttribute('class');
            }
            self::assertSame(['admin-page-tree__cell-title', 'admin-page-tree__cell-status', 'admin-page-tree__cell-type', 'admin-page-tree__cell-actions'], $cells, $name);
        }

        $css = self::css();
        self::assertMatchesRegularExpression('/\.admin-page-tree__table\{\s*table-layout:\s*fixed;/', $css);
        self::assertMatchesRegularExpression('/\.admin-page-tree__table td\{[^}]*height:\s*var\(--tree-row-height\)/', $css, 'every row one set height');
    }

    public function testTheNameAndTheAddressAreOnScreenAndEndInAnEllipsis(): void
    {
        $xpath = $this->overview();
        $row = '//tr[@data-page-row="' . $this->ids['child'] . '"]';

        $name = $xpath->query($row . '//a[contains(@class, "admin-page-tree__name")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $name);
        self::assertSame('zz Overzicht kind met een heel lange naam die in geen enkele kolom past', trim($name->textContent));
        self::assertSame(trim($name->textContent), $name->getAttribute('title'), 'the whole name stays reachable');

        // The address is a line of its own under the name, as text — not a
        // tooltip that only a mouse can reach.
        $url = $xpath->query($row . '//*[contains(@class, "admin-page-tree__line--url")]/code[contains(@class, "admin-page-tree__url")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $url);
        self::assertSame('/' . self::P . '-parent/' . self::P . '-child', trim($url->textContent));
        self::assertSame(trim($url->textContent), $url->getAttribute('title'));

        $css = self::css();
        self::assertMatchesRegularExpression('/\.admin-page-tree__name,\s*\.admin-page-tree__url\{[^}]*text-overflow:\s*ellipsis/', $css);
        self::assertDoesNotMatchRegularExpression('/:hover[^{]*admin-page-tree__(url|line)/', $css, 'nothing about the address depends on hovering');
    }

    public function testTheActionsAreOneLineWithBewerkenFirstAndDeleteOnlyInTheMenu(): void
    {
        $xpath = $this->overview();

        $kinds = [];
        foreach ($xpath->query('//tr[@data-page-row="' . $this->ids['parent'] . '"]//div[contains(@class, "admin-page-tree__actions")]/*') as $action) {
            $class = $action->getAttribute('class');
            $kinds[] = match (true) {
                str_contains($class, 'admin-page-tree__edit') => 'edit',
                str_contains($class, 'admin-page-tree__action--view') => 'view',
                str_contains($class, 'admin-page-tree__action--child') => 'child',
                str_contains($class, 'admin-row-menu') => 'menu',
                default => $class,
            };
        }
        self::assertSame(['edit', 'view', 'child', 'menu'], $kinds);

        // Bewerken is the primary action and says which page it edits.
        $edit = $xpath->query('//tr[@data-page-row="' . $this->ids['parent'] . '"]//a[contains(@class, "admin-page-tree__edit")]')->item(0);
        self::assertSame('/admin/page.php?id=' . $this->ids['parent'], $edit?->getAttribute('href'));
        self::assertStringContainsString('zz Overzicht ouder', (string) $edit?->textContent);

        // Bekijken opens the published page; a draft offers its preview.
        $view = $xpath->query('//tr[@data-page-row="' . $this->ids['parent'] . '"]//a[contains(@class, "admin-page-tree__action--view")]')->item(0);
        self::assertSame(['/' . self::P . '-parent', '_blank', 'noopener'], [$view?->getAttribute('href'), $view?->getAttribute('target'), $view?->getAttribute('rel')]);
        self::assertStringStartsWith('Bekijken', trim((string) $view?->textContent));
        $preview = $xpath->query('//tr[@data-page-row="' . $this->ids['child'] . '"]//a[contains(@class, "admin-page-tree__action--view")]')->item(0);
        self::assertSame('/admin/page-preview.php?id=' . $this->ids['child'], $preview?->getAttribute('href'));
        self::assertStringStartsWith('Voorbeeld', trim((string) $preview?->textContent));

        // Subpagina toevoegen: the long words for a screen reader and a wide
        // screen, the short ones hidden from a screen reader.
        $child = $xpath->query('//tr[@data-page-row="' . $this->ids['parent'] . '"]//a[contains(@class, "admin-page-tree__action--child")]')->item(0);
        self::assertSame('/admin/page-new.php?parent=' . $this->ids['parent'], $child?->getAttribute('href'));
        self::assertStringContainsString('Subpagina toevoegen onder zz Overzicht ouder', preg_replace('/\s+/', ' ', (string) $xpath->query('.//*[contains(@class, "admin-page-tree__label-long")]', $child)->item(0)?->textContent));
        self::assertSame('true', $xpath->query('.//*[contains(@class, "admin-page-tree__label-short")]', $child)->item(0)?->getAttribute('aria-hidden'));

        // Verwijderen never stands in the row itself.
        foreach (['parent', 'child'] as $name) {
            $outside = $xpath->query('//tr[@data-page-row="' . $this->ids[$name] . '"]//form[@action="/api/admin/delete-page.php"][not(ancestor::details[@data-row-menu])]');
            self::assertSame(0, $outside->length, $name);
        }

        $summary = $xpath->query('//tr[@data-page-row="' . $this->ids['parent'] . '"]//details[@data-row-menu]/summary')->item(0);
        self::assertSame('Meer acties voor zz Overzicht ouder', $summary?->getAttribute('aria-label'));
    }

    public function testDeletingAsksFirstOrSaysWhyItCannot(): void
    {
        $xpath = $this->overview();

        // The child can go: a real form, asked first in the CMS's own dialog.
        $form = $xpath->query('//tr[@data-page-row="' . $this->ids['child'] . '"]//details[@data-row-menu]//form[@action="/api/admin/delete-page.php"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame('post', $form->getAttribute('method'));
        self::assertNotSame('', $form->getAttribute('data-admin-confirm'));
        self::assertStringContainsString('zz Overzicht kind', $form->getAttribute('data-admin-confirm'));
        self::assertSame(1, $xpath->query('.//input[@name="csrf_token"]', $form)->length);
        self::assertSame((string) $this->ids['child'], $xpath->query('.//input[@name="id"]', $form)->item(0)?->getAttribute('value'));

        // The parent cannot while it has a page under it: no form, a disabled
        // item and the reason, tied to it for a screen reader.
        $row = '//tr[@data-page-row="' . $this->ids['parent'] . '"]//details[@data-row-menu]';
        self::assertSame(0, $xpath->query($row . '//form')->length);
        $button = $xpath->query($row . '//button[@disabled]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $button);
        $note = $xpath->query('//*[@id="' . $button->getAttribute('aria-describedby') . '"]')->item(0);
        self::assertStringContainsString('onderliggende', (string) $note?->textContent);
    }

    // ------------------------------------------------------------- layouts

    public function testTheThreeLayoutsFollowTheTreesOwnWidth(): void
    {
        $css = self::css();

        self::assertMatchesRegularExpression('/\.admin-page-tree\{[^}]*container:\s*page-tree\s*\/\s*inline-size/', $css);

        // Sideways scrolling is never the answer: the wrap does not scroll,
        // and must not clip the "…" menu either.
        self::assertMatchesRegularExpression('/\.admin-page-tree \.admin-table-wrap\{[^}]*overflow:\s*visible/', $css);

        $narrow = self::block($css, '@container page-tree (max-width: 56rem)');
        self::assertStringContainsString('.admin-page-tree__label-short{ display: inline; }', $narrow, 'the short "+ Subpagina" on a narrower tree');
        self::assertMatchesRegularExpression('/grid-area:\s*edit/', $narrow, 'the actions on two lines, still one row per page');

        $phone = self::block($css, '@container page-tree (max-width: 40rem)');
        self::assertMatchesRegularExpression('/\.admin-page-tree__table tbody tr\{[^}]*display:\s*grid/', $phone, 'a card per page');
        self::assertStringContainsString('.admin-page-tree__table colgroup{ display: none; }', $phone);
        self::assertStringContainsString('.admin-page-tree__table tbody tr[hidden]{ display: none; }', $phone, 'a folded row stays folded as a card');
        self::assertMatchesRegularExpression('/\.admin-page-tree__actions\{[^}]*flex-wrap:\s*wrap/', $phone, 'only on a phone may the actions wrap');
    }

    public function testTheRowMenuClosesWithEscape(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/page-tree.js');

        self::assertStringContainsString("'[data-row-menu]'", $script);
        self::assertStringContainsString("'Escape'", $script);
        self::assertStringContainsString('/admin/assets/page-tree.js', $this->get('/admin/pages.php'));
    }

    // ------------------------------------------------------------- helpers

    private function page(string $name, string $title, string $status, ?int $parentId = null): int
    {
        $slug = self::P . '-' . $name;

        return PageFixture::create(
            ['content_key' => $slug, 'slug' => $slug, 'status' => $status, 'parent_id' => $parentId],
            $title
        );
    }

    private function overview(): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $this->get('/admin/pages.php'));
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    private function get(string $path): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $response = self::$server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    private static function css(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/admin.css'));
    }

    /** The body of one block of $css, found by its exact header, braces balanced. */
    private static function block(string $css, string $header): string
    {
        $start = strpos($css, $header . '{');
        self::assertNotFalse($start, $header);

        $depth = 0;
        $length = strlen($css);
        for ($i = $start + strlen($header); $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $start, $i - $start + 1);
                }
            }
        }

        self::fail('unbalanced block: ' . $header);
    }
}
