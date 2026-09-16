<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\NavigationPresentation;
use App\Service\PageContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Header & navigatie over real HTTP: the screen, its endpoints and what the
 * public header renders from what they store (HEADER-FOOTER.md, "De knoppen
 * in de header").
 *
 * THE CONTRACT
 *   - the menu and the header buttons are two lists on one screen, each row
 *     saying where it goes in words, with ↑/↓ and a delete that asks first;
 *   - an internal link stores the page id, an external link its address, and
 *     both labels are kept separately;
 *   - a button is a navigation item presented as a button: it gets a style
 *     from a closed list, renders in the header's action area in its own
 *     order, and cannot be a submenu item, a submenu heading or a link that
 *     still has submenu items;
 *   - a button pointing at a route of a switched-off module is not in the
 *     header, and saving it keeps that route, so switching the module back on
 *     brings the button back.
 *
 * Two built-in servers on this checkout (Tests\Support\BuiltInServer): one
 * with the modules this process runs with, one with the Shop off — the way
 * Tests\Service\BlockPreviewAccessTest compares the two. Every row, page and
 * account is this test's own and removed again by exact id in tearDown().
 * The public header is read from /index.php; only this test's own buttons are
 * asserted, so whatever else the test database holds does not matter.
 */
final class NavigationAdminHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;
    private static ?BuiltInServer $shopOff = null;

    private AdminTestSession $accounts;
    private NavigationRepository $navigation;
    private PageRepository $pages;

    /** @var list<int> */
    private array $navIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PERSONALIZATION_ENABLED' => 'true']);
        self::$shopOff = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PERSONALIZATION_ENABLED' => 'false']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$shopOff?->stop();
        self::$server = null;
        self::$shopOff = null;
    }

    protected function setUp(): void
    {
        foreach ([self::$server, self::$shopOff] as $server) {
            if ($server === null || !$server->answers()) {
                $this->markTestSkipped("could not start PHP's built-in web server for this test");
            }
        }

        $this->accounts = new AdminTestSession();
        $this->navigation = new NavigationRepository();
        $this->pages = new PageRepository();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        // Children first (parent_id is RESTRICT), then the rest, then the
        // pages they pointed at (target_page_id is RESTRICT too).
        foreach ([true, false] as $childrenOnly) {
            foreach ($this->navIds as $id) {
                $db->prepare('DELETE FROM nav_items WHERE id = :id' . ($childrenOnly ? ' AND parent_id IS NOT NULL' : ''))
                    ->execute(['id' => $id]);
            }
        }
        foreach ($this->pageIds as $id) {
            $this->pages->delete($id);
        }

        $this->navIds = [];
        $this->pageIds = [];
        $this->accounts->forget();
    }

    // ------------------------------------------------------------ the screen

    public function testTheScreenShowsTheMenuAndTheButtonsAsTwoListsInWords(): void
    {
        $pageId = $this->page('Onze diensten');
        $link = $this->item(['label_nl' => 'Diensten', 'link_type' => 'page', 'target_page_id' => $pageId]);
        $child = $this->item(['label_nl' => 'Graveren', 'parent_id' => $link, 'link_type' => 'external', 'external_url' => 'https://example.com/graveren']);
        $button = $this->item(['label_nl' => 'Offerte', 'link_type' => 'external', 'external_url' => '/offerte', 'presentation' => 'button', 'button_variant' => 'ghost']);

        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $response = self::$server->request('GET', '/admin/navigation.php', $session);
        $this->assertSame(200, $response['status']);
        $xpath = $this->xpath($response['body']);

        $menu = $this->section($xpath, 'navigation-menu-heading');
        $buttons = $this->section($xpath, 'navigation-buttons-heading');

        $this->assertSame(1, $xpath->query('.//*[@id="nav-item-' . $link . '"]', $menu)->length, 'the link is in the menu list');
        $this->assertSame(1, $xpath->query('.//*[@id="nav-item-' . $child . '"]', $menu)->length, 'its submenu item too');
        $this->assertSame(0, $xpath->query('.//*[@id="nav-item-' . $button . '"]', $menu)->length, 'a button is not a menu link');
        $this->assertSame(1, $xpath->query('.//*[@id="nav-item-' . $button . '"]', $buttons)->length, 'the button is in the button list');

        $this->assertStringContainsString('Pagina: Onze diensten', $this->rowText($xpath, $link), 'the destination in words');
        $this->assertStringContainsString('Rustige knop', $this->rowText($xpath, $button));

        // ↑/↓ on every row, and a delete that asks in the CMS dialog — except
        // on a link that still has submenu items.
        foreach ([$link, $child, $button] as $id) {
            foreach (['up', 'down'] as $direction) {
                $this->assertSame(1, $xpath->query('//form[@action="/api/admin/move-nav-item.php"][.//input[@name="id"][@value="' . $id . '"]][.//input[@name="direction"][@value="' . $direction . '"]]')->length, "move {$direction} for {$id}");
            }
        }
        $this->assertSame(0, $xpath->query('//form[@action="/api/admin/delete-nav-item.php"][.//input[@name="id"][@value="' . $link . '"]]')->length, 'no delete that the endpoint would refuse');
        $delete = $xpath->query('//form[@action="/api/admin/delete-nav-item.php"][.//input[@name="id"][@value="' . $button . '"]]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $delete);
        $this->assertSame('Knop verwijderen?', $delete->getAttribute('data-admin-confirm-title'));
        $this->assertStringContainsString('Offerte', $delete->getAttribute('data-admin-confirm'));
        $this->assertSame(1, $xpath->query('//dialog[@data-admin-confirm-dialog]')->length);

        $this->assertStringNotContainsString('onsubmit', $response['body']);
        $this->assertSame('true', $xpath->query('//*[@id="nav-item-' . $button . '"]//span[contains(@class, "admin-drag-handle")]')->item(0)?->getAttribute('aria-hidden'));
        foreach (['link_type', 'target_route', 'route_path', 'nav_type', 'Applicatieroute'] as $jargon) {
            $this->assertStringNotContainsString($jargon, strip_tags($response['body']), 'no technical name reaches the editor: ' . $jargon);
        }
    }

    public function testTheEditorShowsAButtonWithItsChoicesAndTheSaveBar(): void
    {
        $button = $this->item(['label_nl' => 'Offerte', 'label_en' => 'Quote', 'link_type' => 'external', 'external_url' => '/offerte', 'presentation' => 'button', 'button_variant' => 'ghost']);

        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $response = self::$server->request('GET', '/admin/navigation-item.php?id=' . $button, $session);
        $this->assertSame(200, $response['status']);
        $xpath = $this->xpath($response['body']);

        $this->assertSame('Offerte', $xpath->query('//input[@name="label_nl"]')->item(0)?->getAttribute('value'));
        $this->assertSame('Quote', $xpath->query('//input[@name="label_en"]')->item(0)?->getAttribute('value'));
        $this->assertSame('button', $xpath->query('//select[@name="presentation"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame('ghost', $xpath->query('//select[@name="button_variant"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame('external', $xpath->query('//select[@name="link_type"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//input[@name="is_visible"][@role="switch"][@checked]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-save-bar]')->length, 'a normal settings form has the save bar');
        $this->assertSame(0, $xpath->query('//script[not(@src)][contains(., "data-nav-link-field")]')->length, 'no inline script');
    }

    // --------------------------------------------------------------- saving

    public function testAnInternalAndAnExternalLinkAreStoredWithBothLabels(): void
    {
        $pageId = $this->page('Contact');
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $internal = $this->create($session, $token, [
            'label_nl' => 'Neem contact op', 'label_en' => 'Get in touch',
            'link_type' => 'page', 'target_page_id' => (string) $pageId,
            // A leftover of another kind never reaches the row.
            'external_url' => 'https://example.com/restje',
            'is_visible' => '1',
        ]);
        $this->assertSame(
            ['Neem contact op', 'Get in touch', 'page', $pageId, null, 'link', 1],
            [$internal['label_nl'], $internal['label_en'], $internal['link_type'], (int) $internal['target_page_id'], $internal['external_url'], $internal['presentation'], (int) $internal['is_visible']]
        );

        $external = $this->create($session, $token, [
            'label_nl' => 'Webshop partner', 'label_en' => '',
            'link_type' => 'external', 'external_url' => 'https://example.com/partner',
            'open_in_new_tab' => '1',
        ]);
        $this->assertSame(
            ['external', 'https://example.com/partner', null, 1, 0, ''],
            [$external['link_type'], $external['external_url'], $external['target_page_id'], (int) $external['open_in_new_tab'], (int) $external['is_visible'], (string) $external['label_en']]
        );
    }

    public function testAnUnsafeAddressIsRefusedAndNothingIsStored(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $label = '__nav_http_' . bin2hex(random_bytes(4));

        $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, [
            'csrf_token' => $token, 'label_nl' => $label, 'link_type' => 'external', 'external_url' => 'javascript:alert(1)',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/navigation-item.php', $response['location']);
        $this->assertNotEmpty($this->accounts->read($session, 'admin_nav_item_errors'));
        $this->assertNull($this->findByLabel($label));
    }

    public function testWhatCannotBeAButtonIsRefused(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $parent = $this->item(['label_nl' => 'Met submenu', 'link_type' => 'none']);
        $this->item(['label_nl' => 'Kind', 'parent_id' => $parent, 'link_type' => 'external', 'external_url' => '/kind']);

        $refusals = [
            'a button without a destination' => ['link_type' => 'none'],
            'a button inside a submenu' => ['parent_id' => (string) $parent, 'link_type' => 'external', 'external_url' => '/x'],
            'an unknown style' => ['link_type' => 'external', 'external_url' => '/x', 'button_variant' => 'btn--danger'],
        ];

        foreach ($refusals as $what => $fields) {
            $label = '__nav_http_' . bin2hex(random_bytes(4));
            $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, $fields + [
                'csrf_token' => $token, 'label_nl' => $label, 'presentation' => 'button', 'is_visible' => '1',
            ]);

            $this->assertSame(302, $response['status'], $what);
            $this->assertStringNotContainsString('saved=1', $response['location'], $what);
            $this->assertNull($this->findByLabel($label), $what . ' is not stored');
        }

        // A link that still has submenu items cannot become a button.
        $response = self::$server->request('POST', '/api/admin/update-nav-item.php', $session, [
            'csrf_token' => $token, 'id' => (string) $parent, 'label_nl' => 'Met submenu', 'link_type' => 'none',
            'presentation' => 'button', 'is_visible' => '1',
        ]);
        $this->assertStringNotContainsString('saved=1', $response['location']);
        $this->assertSame('link', $this->navigation->findById($parent)['presentation']);
    }

    // ---------------------------------------------------- the public header

    /**
     * One button, a second one, their order, hiding one, and deleting one —
     * each as the visitor's header renders it, on desktop and in the mobile
     * menu alike: the buttons sit inside #main-nav, the panel the menu toggle
     * opens on a phone.
     */
    public function testHeaderButtonsRenderInTheirOwnOrderAndFollowEveryChange(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $prefix = '__nav_http_' . bin2hex(random_bytes(3));

        $first = (int) $this->create($session, $token, [
            'label_nl' => $prefix . ' Offerte', 'label_en' => $prefix . ' Quote', 'link_type' => 'external', 'external_url' => '/zz-offerte',
            'presentation' => 'button', 'button_variant' => 'primary', 'is_visible' => '1',
        ])['id'];
        $this->assertSame([[$prefix . ' Offerte', '/zz-offerte', 'btn btn--sm']], $this->headerButtons($prefix));

        $second = (int) $this->create($session, $token, [
            'label_nl' => $prefix . ' Bel ons', 'link_type' => 'external', 'external_url' => '/zz-bel',
            'presentation' => 'button', 'button_variant' => 'ghost', 'is_visible' => '1',
        ])['id'];
        $this->assertSame(
            [[$prefix . ' Offerte', '/zz-offerte', 'btn btn--sm'], [$prefix . ' Bel ons', '/zz-bel', 'btn btn--sm btn--ghost']],
            $this->headerButtons($prefix)
        );

        $moved = self::$server->request('POST', '/api/admin/move-nav-item.php', $session, ['csrf_token' => $token, 'id' => (string) $second, 'direction' => 'up']);
        $this->assertSame('/admin/navigation.php#nav-item-' . $second, $moved['location']);
        $this->assertSame([$prefix . ' Bel ons', $prefix . ' Offerte'], array_column($this->headerButtons($prefix), 0));

        self::$server->request('POST', '/api/admin/toggle-nav-item.php', $session, ['csrf_token' => $token, 'id' => (string) $second, 'is_visible' => '0']);
        $this->assertSame([$prefix . ' Offerte'], array_column($this->headerButtons($prefix), 0), 'a hidden button is not rendered');

        $deleted = self::$server->request('POST', '/api/admin/delete-nav-item.php', $session, ['csrf_token' => $token, 'id' => (string) $first]);
        $this->assertSame('/admin/navigation.php?deleted=button', $deleted['location']);
        $this->assertNull($this->navigation->findById($first));
        $this->assertSame([], $this->headerButtons($prefix), 'with none of them left, none is rendered');

        $overview = self::$server->request('GET', '/admin/navigation.php?deleted=button', $session);
        $this->assertStringContainsString('Knop verwijderd.', $overview['body']);
    }

    /**
     * The whole point of one link model: a button aimed at a Shop route is
     * not in the header while the Shop is off, the editor can still save it
     * without losing that route, and with the Shop on it is back.
     */
    public function testAButtonForASwitchedOffModuleIsHiddenKeptAndBack(): void
    {
        $prefix = '__nav_http_' . bin2hex(random_bytes(3));
        $button = $this->item([
            'label_nl' => $prefix . ' Naar de winkel', 'link_type' => 'route', 'target_route' => 'shop', 'presentation' => 'button',
        ]);

        $this->assertSame([], $this->headerButtons($prefix, self::$shopOff), 'no link into a 404');

        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $editor = self::$shopOff->request('GET', '/admin/navigation-item.php?id=' . $button, $session);
        $xpath = $this->xpath($editor['body']);
        $this->assertSame('shop', $xpath->query('//select[@name="target_route"]/option[@selected]')->item(0)?->getAttribute('value'), 'the stored route stays selected');
        $this->assertSame(1, $xpath->query('//*[contains(@class, "admin-alert--warning")]')->length, 'the editor is told why it is not on the website');

        $saved = self::$shopOff->request('POST', '/api/admin/update-nav-item.php', $session, [
            'csrf_token' => $token, 'id' => (string) $button, 'label_nl' => $prefix . ' Naar de webwinkel',
            'link_type' => 'route', 'target_route' => 'shop', 'presentation' => 'button', 'button_variant' => 'primary', 'is_visible' => '1',
        ]);
        $this->assertStringContainsString('saved=1', $saved['location']);
        $row = $this->navigation->findById($button);
        $this->assertSame(['shop', $prefix . ' Naar de webwinkel'], [$row['target_route'], $row['label_nl']]);

        $this->assertSame([[$prefix . ' Naar de webwinkel', '/shop.php', 'btn btn--sm']], $this->headerButtons($prefix, self::$server));
    }

    public function testTheMoveEndpointKeepsItsGuards(): void
    {
        $item = $this->item(['label_nl' => 'Bewaakt', 'link_type' => 'external', 'external_url' => '/x']);
        [$editor, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$other, $otherToken] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $fields = ['id' => (string) $item, 'direction' => 'up'];

        $this->assertSame(401, self::$server->request('POST', '/api/admin/move-nav-item.php', null, $fields + ['csrf_token' => $token])['status'], 'signed out');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/move-nav-item.php', $other, $fields + ['csrf_token' => $otherToken])['status'], 'without managing pages');
        $this->assertSame(405, self::$server->request('GET', '/api/admin/move-nav-item.php', $editor)['status']);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/move-nav-item.php', $editor, $fields + ['csrf_token' => str_repeat('0', 64)])['status'], 'a wrong token');
        $this->assertSame(400, self::$server->request('POST', '/api/admin/move-nav-item.php', $editor, ['id' => (string) $item, 'direction' => 'sideways', 'csrf_token' => $token])['status']);
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string, mixed> $overrides */
    private function item(array $overrides): int
    {
        $id = $this->navigation->create(array_merge([
            'label_nl' => 'Item',
            'label_en' => '',
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
            'presentation' => NavigationPresentation::LINK,
            'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
        ], $overrides));
        $this->navIds[] = $id;

        return $id;
    }

    private function page(string $title): int
    {
        $key = 'zz-nav-http-' . bin2hex(random_bytes(4));
        $id = $this->pages->create([
            'content_key' => $key,
            'slug' => $key,
            'title' => $title,
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->pageIds[] = $id;

        return $id;
    }

    /**
     * Posts the editor's form for a new item and returns the stored row.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function create(string $session, string $token, array $fields): array
    {
        $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, $fields + ['csrf_token' => $token]);

        $this->assertSame(302, $response['status']);
        $this->assertMatchesRegularExpression('#^/admin/navigation-item\.php\?id=(\d+)&saved=1$#', $response['location'], (string) json_encode($this->accounts->read($session, 'admin_nav_item_errors')));
        preg_match('#id=(\d+)#', $response['location'], $match);

        $id = (int) $match[1];
        $this->navIds[] = $id;

        $row = $this->navigation->findById($id);
        $this->assertNotNull($row);

        return $row;
    }

    private function findByLabel(string $label): ?array
    {
        foreach ($this->navigation->findAllForAdmin() as $row) {
            if ($row['label_nl'] === $label) {
                $this->navIds[] = (int) $row['id'];
                return $row;
            }
        }

        return null;
    }

    /**
     * This test's own header buttons as /index.php renders them: label, href
     * and class, in document order.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function headerButtons(string $prefix, ?BuiltInServer $server = null): array
    {
        $response = ($server ?? self::$server)->request('GET', '/index.php');
        $this->assertSame(200, $response['status'], 'the homepage renders');

        $buttons = [];
        foreach ($this->xpath($response['body'])->query('//nav[@id="main-nav"]//div[contains(@class, "header-actions")]/div[@class="header-buttons"]/a') as $anchor) {
            $label = trim($anchor->textContent);
            if (str_starts_with($label, $prefix)) {
                $buttons[] = [$label, $anchor->getAttribute('href'), $anchor->getAttribute('class')];
            }
        }

        return $buttons;
    }

    private function section(\DOMXPath $xpath, string $headingId): \DOMElement
    {
        $section = $xpath->query('//section[@aria-labelledby="' . $headingId . '"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $section, $headingId);

        return $section;
    }

    private function rowText(\DOMXPath $xpath, int $id): string
    {
        return (string) $xpath->query('//*[@id="nav-item-' . $id . '"]')->item(0)?->textContent;
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
