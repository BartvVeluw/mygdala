<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\NavigationRepository;
use App\Service\AdminPermissions;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Moving menu items over real HTTP (HEADER-FOOTER.md, "Verplaatsen" and
 * "Verwijderen"):
 *
 *   - a drag is ONE request to api/admin/place-nav-item.php, with the four
 *     guards in their order, and every forged id, parent or position is
 *     refused with a reason and changes nothing;
 *   - "Bovenliggend item" in the editor lists the menu as a tree with
 *     "Geen (hoofdniveau)" first, the current parent chosen, never the item
 *     itself or anything below it; saving a new parent moves the item to the
 *     end of its new submenu, a forged one is refused;
 *   - the overview tells the drag script each row's level and height, and
 *     offers deleting a parent, whose submenu then moves up into its place;
 *   - the public header shows the tree as it is stored after every move.
 *
 * One built-in server on this checkout (Tests\Support\BuiltInServer). Every
 * row and account is this test's own and removed by exact id in tearDown(),
 * parents cleared first, so moves never trip the RESTRICT key.
 */
final class NavigationPlacementHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;
    private NavigationRepository $navigation;

    /** @var list<int> */
    private array $navIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PERSONALIZATION_ENABLED' => 'true']);
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
        $this->navigation = new NavigationRepository();
    }

    protected function tearDown(): void
    {
        if ($this->navIds !== []) {
            $db = Database::connection();
            $in = implode(',', array_map('intval', array_unique($this->navIds)));
            $db->exec("UPDATE nav_items SET parent_id = NULL WHERE id IN ({$in})");
            $db->exec("DELETE FROM nav_items WHERE id IN ({$in})");
        }
        $this->navIds = [];
        $this->accounts->forget();
        NavigationLocalization::clearCache();
    }

    // ------------------------------------------------------------ the drag

    public function testOneDragIsOneGuardedRequest(): void
    {
        $m = $this->menu();
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$other, $otherToken] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $fields = ['id' => (string) $m['metaal'], 'parent_id' => '', 'position' => '0'];

        $this->assertSame(401, $this->placeRequest(null, $fields + ['csrf_token' => $token])['status'], 'signed out');
        $this->assertSame(403, $this->placeRequest($other, $fields + ['csrf_token' => $otherToken])['status'], 'without managing pages');
        $this->assertSame(405, self::$server->request('GET', '/api/admin/place-nav-item.php', $editor)['status']);
        $this->assertSame(403, $this->placeRequest($editor, $fields + ['csrf_token' => str_repeat('0', 64)])['status'], 'a wrong token');
        $this->assertSame([$m['diensten'], 0], $this->where($m['metaal']), 'nothing moved yet');

        $response = $this->placeRequest($editor, ['id' => (string) $m['metaal'], 'parent_id' => (string) $m['portfolio'], 'position' => '', 'csrf_token' => $token]);
        $this->assertSame(200, $response['status']);
        $this->assertSame(['ok' => true], json_decode($response['body'], true));
        $this->assertSame([$m['portfolio'], 0], $this->where($m['metaal']));
        $this->assertSame([$m['hout'], 0], [$this->navigation->tree()->childIds($m['diensten'])[0], $this->where($m['hout'])[1]], 'the old list closed its gap');

        // Back to the top level, in front of Portfolio.
        $topBefore = $this->navigation->tree()->childIds(null);
        $position = array_search($m['portfolio'], $topBefore, true);
        $response = $this->placeRequest($editor, ['id' => (string) $m['metaal'], 'parent_id' => '', 'position' => (string) $position, 'csrf_token' => $token]);
        $this->assertSame(['ok' => true], json_decode($response['body'], true));
        $top = $this->navigation->tree()->childIds(null);
        $this->assertSame($m['metaal'], $top[array_search($m['portfolio'], $top, true) - 1]);
    }

    public function testEveryForgedMoveIsRefusedWithAReasonAndChangesNothing(): void
    {
        $m = $this->menu();
        $button = $this->item('Offerte', null, NavigationPresentation::BUTTON);
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $before = $this->snapshot();

        $cases = [
            'self' => [$m['diensten'], (string) $m['diensten'], 422, 'onder zichzelf'],
            'a direct child' => [$m['diensten'], (string) $m['hout'], 422, 'eigen submenu-items'],
            'a deep descendant' => [$m['diensten'], (string) $m['snijplanken'], 422, 'eigen submenu-items'],
            'a button from the other list' => [$m['contact'], (string) $button, 422, 'bestaat niet in het menu'],
            'an unknown parent' => [$m['contact'], '999999999', 422, 'bestaat niet in het menu'],
            'a negative parent' => [$m['contact'], '-3', 422, 'bestaat niet in het menu'],
            'a parent that is no number' => [$m['contact'], '1 OR 1=1', 422, 'bestaat niet in het menu'],
            'a fourth level' => [$m['contact'], (string) $m['snijplanken'], 422, 'drie niveaus'],
            'a button moved into the menu' => [$button, (string) $m['portfolio'], 422, 'lijst Knoppen'],
            'an unknown item' => [999999999, '', 404, 'bestaat niet'],
        ];
        foreach ($cases as $what => [$id, $parent, $status, $words]) {
            $response = $this->placeRequest($editor, ['id' => (string) $id, 'parent_id' => $parent, 'position' => '0', 'csrf_token' => $token]);
            $this->assertSame($status, $response['status'], $what);
            $body = json_decode($response['body'], true);
            $this->assertFalse($body['ok'], $what);
            $this->assertStringContainsString($words, (string) $body['error'], $what);
        }

        foreach (['-1', 'abc', '0'] as $forgedId) {
            $this->assertSame(404, $this->placeRequest($editor, ['id' => $forgedId, 'parent_id' => '', 'csrf_token' => $token])['status'], "id {$forgedId}");
        }
        $this->assertSame(422, $this->placeRequest($editor, ['id' => (string) $m['contact'], 'parent_id' => '', 'position' => '-1', 'csrf_token' => $token])['status'], 'a negative position');

        $this->assertSame($before, $this->snapshot(), 'not one row changed');
    }

    // ------------------------------------------------------ the Parent list

    public function testTheParentListIsTheTreeWithoutTheItemAndItsSubmenu(): void
    {
        $m = $this->menu();
        [$editor] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $select = function (int $id) use ($editor): array {
            $xpath = $this->xpath(self::$server->request('GET', '/admin/navigation-item.php?id=' . $id, $editor)['body']);
            $options = [];
            foreach ($xpath->query('//select[@name="parent_id"]/option') as $option) {
                $options[(int) $option->getAttribute('value')] = [
                    'text' => $option->textContent,
                    'selected' => $option->hasAttribute('selected'),
                ];
            }
            $this->assertStringContainsString('Bovenliggend item', (string) $xpath->query('//label[@for="nav-parent"]')->item(0)?->textContent, 'a real label');

            return $options;
        };

        $hout = $select($m['hout']);
        $this->assertSame('Geen (hoofdniveau)', $hout[0]['text'], 'the top level comes first');
        $this->assertFalse($hout[0]['selected']);
        $this->assertTrue($hout[$m['diensten']]['selected'], 'the current parent is chosen');
        $this->assertArrayNotHasKey($m['hout'], $hout, 'never itself');
        $this->assertArrayNotHasKey($m['snijplanken'], $hout, 'never its own submenu');
        $this->assertArrayNotHasKey($m['metaal'], $hout, 'never where its submenu would pass three levels');
        $this->assertArrayHasKey($m['portfolio'], $hout);

        $contact = $select($m['contact']);
        $this->assertTrue($contact[0]['selected'], 'a top-level item has no parent');
        $this->assertSame("\u{00A0}\u{00A0}\u{00A0}\u{2013}\u{00A0}Hout" . $this->suffix, $contact[$m['hout']]['text'], 'indented like the Pages list');
        $this->assertArrayNotHasKey($m['snijplanken'], $contact, 'nothing on the deepest level');

        $diensten = $select($m['diensten']);
        $this->assertSame([0], array_keys($diensten), 'with three levels of its own, only the top level is left');
    }

    public function testSavingANewParentMovesTheItemLastAndAForgedOneIsRefused(): void
    {
        $m = $this->menu();
        $portfolioChild = $this->item('Brons', $m['portfolio']);
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $save = fn (int $id, string $parent): array => self::$server->request('POST', '/api/admin/update-nav-item.php', $editor, [
            'csrf_token' => $token, 'id' => (string) $id, 'language_code' => 'nl', 'label' => NavigationLocalization::raw($id, 'nl'),
            'link_type' => 'external', 'external_url' => '/zz-nav-placement-http', 'is_visible' => '1', 'parent_id' => $parent,
        ]);

        $response = $save($m['hout'], (string) $m['portfolio']);
        $this->assertSame('/admin/navigation-item.php?id=' . $m['hout'] . '&saved=1', $response['location'], (string) json_encode($this->accounts->read($editor, 'admin_nav_item_errors')));
        $this->assertSame([$portfolioChild, $m['hout']], $this->navigation->tree()->childIds($m['portfolio']), 'last in its new submenu');
        $this->assertSame([$m['hout'], 0], $this->where($m['snijplanken']), 'its submenu came along');
        $this->assertSame([$m['metaal']], $this->navigation->tree()->childIds($m['diensten']));
        $this->assertSame(0, $this->where($m['metaal'])[1]);

        // An unchanged parent keeps the position.
        $this->navigation->place($m['hout'], $m['portfolio'], 0);
        $save($m['hout'], (string) $m['portfolio']);
        $this->assertSame([$m['hout'], $portfolioChild], $this->navigation->tree()->childIds($m['portfolio']));

        // "Geen (hoofdniveau)" posts 0.
        $save($m['metaal'], '0');
        $this->assertNull($this->where($m['metaal'])[0]);

        $before = $this->snapshot();
        foreach ([
            'its own submenu' => [$m['hout'], (string) $m['snijplanken'], 'eigen submenu-items'],
            'itself' => [$m['hout'], (string) $m['hout'], 'onder zichzelf'],
            'past three levels' => [$m['hout'], (string) $portfolioChild, 'drie niveaus'],
            'not a number' => [$m['contact'], 'x', 'bestaat niet in het menu'],
        ] as $what => [$id, $parent, $words]) {
            $response = $save($id, $parent);
            $this->assertStringNotContainsString('saved=1', $response['location'], $what);
            $errors = implode(' ', (array) $this->accounts->read($editor, 'admin_nav_item_errors'));
            $this->assertStringContainsString($words, $errors, $what);
        }
        $this->assertSame($before, $this->snapshot(), 'a refused save moves nothing');
    }

    // ------------------------------------------------------- the overview

    public function testTheOverviewHandsTheTreeToTheDragScriptAndLetsAParentGo(): void
    {
        $m = $this->menu();
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $xpath = $this->xpath(self::$server->request('GET', '/admin/navigation.php', $editor)['body']);
        $tree = $xpath->query('//*[@data-nav-tree]')->item(0);
        $this->assertSame(['3', '/api/admin/place-nav-item.php'], [$tree?->getAttribute('data-max-depth'), $tree?->getAttribute('data-place-url')]);
        $row = static fn (int $id): ?\DOMElement => $xpath->query('//*[@id="nav-item-' . $id . '"]')->item(0);
        $this->assertSame(['1', '3'], [$row($m['diensten'])?->getAttribute('data-nav-level'), $row($m['diensten'])?->getAttribute('data-nav-height')]);
        $this->assertSame(['2', '2'], [$row($m['hout'])?->getAttribute('data-nav-level'), $row($m['hout'])?->getAttribute('data-nav-height')]);
        $this->assertSame(['3', '1'], [$row($m['snijplanken'])?->getAttribute('data-nav-level'), $row($m['snijplanken'])?->getAttribute('data-nav-height')]);
        $this->assertStringContainsString('1 submenu-item', (string) $row($m['hout'])?->textContent, 'a parent says how many it has');
        $this->assertSame(1, $xpath->query('//*[@id="nav-children-' . $m['diensten'] . '"]/*[@id="nav-item-' . $m['hout'] . '"]')->length, 'a child is drawn inside its parent');
        $this->assertSame(1, $xpath->query('//form[@action="/api/admin/delete-nav-item.php"][.//input[@name="id"][@value="' . $m['hout'] . '"]]')->length, 'a parent may be deleted');

        $response = self::$server->request('POST', '/api/admin/delete-nav-item.php', $editor, ['csrf_token' => $token, 'id' => (string) $m['hout']]);
        $this->assertSame('/admin/navigation.php?deleted=link', $response['location']);
        $this->assertSame([$m['metaal'], $m['snijplanken']], $this->navigation->tree()->childIds($m['diensten']), 'its submenu took its place');

        $moved = $this->xpath(self::$server->request('GET', '/admin/navigation.php?moved=' . $m['metaal'], $editor)['body']);
        $this->assertSame('Menu-item verplaatst.', trim((string) $moved->query('//p[@role="status"]')->item(0)?->textContent));
    }

    public function testThePublicHeaderShowsTheTreeAsStoredAfterAMove(): void
    {
        $m = $this->menu();
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->placeRequest($editor, ['id' => (string) $m['contact'], 'parent_id' => (string) $m['hout'], 'position' => '0', 'csrf_token' => $token]);

        $xpath = $this->xpath(self::$server->request('GET', '/index.php')['body']);
        $panel = $xpath->query('//nav[@id="main-nav"]//ul[@id="main-nav-submenu-' . $m['hout'] . '"]')->item(0);
        $this->assertSame('main-nav__submenu main-nav__submenu--level-3', $panel?->getAttribute('class'));
        $this->assertSame(
            ['Contact' . $this->suffix, 'Snijplanken' . $this->suffix],
            array_map(static fn (\DOMNode $a): string => trim($a->textContent), iterator_to_array($xpath->query('./li/a', $panel)))
        );
        $this->assertSame('https://example.com/zz-contact', $xpath->query('./li/a', $panel)->item(0)?->getAttribute('href'), 'an external link keeps its address');
        $this->assertSame(0, $xpath->query('//nav[@id="main-nav"]/ul/li/div/a[normalize-space()="Contact' . $this->suffix . '"]')->length, 'no longer on the top level');
    }

    // --------------------------------------------------------------- helpers

    private string $suffix = '';

    /** @return array<string, int> Diensten [Metaal, Hout [Snijplanken]], Portfolio, Contact */
    private function menu(): array
    {
        $this->suffix = ' ' . bin2hex(random_bytes(3));
        $m = [];
        $m['diensten'] = $this->item('Diensten');
        $m['metaal'] = $this->item('Metaal', $m['diensten']);
        $m['hout'] = $this->item('Hout', $m['diensten']);
        $m['snijplanken'] = $this->item('Snijplanken', $m['hout']);
        $m['portfolio'] = $this->item('Portfolio');
        $m['contact'] = $this->item('Contact', null, NavigationPresentation::LINK, 'https://example.com/zz-contact');

        return $m;
    }

    private function item(string $label, ?int $parentId = null, string $presentation = NavigationPresentation::LINK, ?string $url = null): int
    {
        $id = $this->navigation->create([
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => $url ?? '/zz-nav-placement-' . strtolower($label),
            'open_in_new_tab' => false,
            'parent_id' => $parentId,
            'is_visible' => true,
            'presentation' => $presentation,
        ]);
        $this->navIds[] = $id;
        // A suffix of this test's own, so another menu never matches.
        NavigationLocalization::save($id, 'nl', $label . $this->suffix);

        return $id;
    }

    /** @return array{0: ?int, 1: int} */
    private function where(int $id): array
    {
        NavigationLocalization::clearCache();
        $row = $this->navigation->findById($id);

        return [$row['parent_id'] === null ? null : (int) $row['parent_id'], (int) $row['sort_order']];
    }

    /** @return array<int, array{0: ?int, 1: int}> this test's rows, where they are */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (array_unique($this->navIds) as $id) {
            $snapshot[$id] = $this->where($id);
        }

        return $snapshot;
    }

    /** @param array<string, string> $fields */
    private function placeRequest(?string $session, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/place-nav-item.php', $session, $fields);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
