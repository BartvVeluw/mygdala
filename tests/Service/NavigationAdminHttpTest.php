<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\NavigationLocalization;
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
 *     the label is stored per website language, a new item's in the default
 *     language (App\Service\NavigationLocalization; the per-language editor
 *     is Tests\Service\NavigationFooterLocalizationEditorHttpTest);
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

        // Newest first: a submenu item is always made after its parent, so
        // this deletes every level before the one above it (parent_id is
        // RESTRICT), then the pages they pointed at (target_page_id is
        // RESTRICT too), a subpage before its parent page.
        foreach (array_reverse(array_unique($this->navIds)) as $id) {
            $db->prepare('DELETE FROM nav_items WHERE id = :id')->execute(['id' => $id]);
        }
        foreach (array_reverse($this->pageIds) as $id) {
            $this->pages->delete($id);
        }

        $this->navIds = [];
        $this->pageIds = [];
        $this->accounts->forget();
        NavigationLocalization::clearCache();
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

        $this->assertSame('Offerte', $xpath->query('//input[@name="label"]')->item(0)?->getAttribute('value'), 'the default language, as stored');
        $this->assertSame('nl', $xpath->query('//input[@name="language_code"]')->item(0)?->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//input[@name="label_en"]')->length, 'no hidden pane of another language');
        $this->assertSame('button', $xpath->query('//select[@name="presentation"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame('ghost', $xpath->query('//select[@name="button_variant"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame('external', $xpath->query('//select[@name="link_type"]/option[@selected]')->item(0)?->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//input[@name="is_visible"][@role="switch"][@checked]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-save-bar]')->length, 'a normal settings form has the save bar');
        $this->assertSame(0, $xpath->query('//script[not(@src)][contains(., "data-nav-link-field")]')->length, 'no inline script');
    }

    // --------------------------------------------------------------- saving

    public function testAnInternalAndAnExternalLinkAreStoredWithTheirLabelInTheDefaultLanguage(): void
    {
        $pageId = $this->page('Contact');
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $internal = $this->create($session, $token, [
            'label' => 'Neem contact op',
            // A new item is always written in the default language; a posted
            // language is not the endpoint's to follow.
            'language_code' => 'en',
            'link_type' => 'page', 'target_page_id' => (string) $pageId,
            // A leftover of another kind never reaches the row.
            'external_url' => 'https://example.com/restje',
            'is_visible' => '1',
        ]);
        $this->assertSame(
            ['Neem contact op', '', 'page', $pageId, null, 'link', 1],
            [$this->label($internal, 'nl'), $this->label($internal, 'en'), $internal['link_type'], (int) $internal['target_page_id'], $internal['external_url'], $internal['presentation'], (int) $internal['is_visible']]
        );

        $external = $this->create($session, $token, [
            'label' => 'Webshop partner',
            'link_type' => 'external', 'external_url' => 'https://example.com/partner',
            'open_in_new_tab' => '1',
        ]);
        $this->assertSame(
            ['external', 'https://example.com/partner', null, 1, 0, ''],
            [$external['link_type'], $external['external_url'], $external['target_page_id'], (int) $external['open_in_new_tab'], (int) $external['is_visible'], $this->label($external, 'en')]
        );
    }

    public function testAnUnsafeAddressIsRefusedAndNothingIsStored(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $label = '__nav_http_' . bin2hex(random_bytes(4));

        $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, [
            'csrf_token' => $token, 'label' => $label, 'link_type' => 'external', 'external_url' => 'javascript:alert(1)',
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
                'csrf_token' => $token, 'label' => $label, 'presentation' => 'button', 'is_visible' => '1',
            ]);

            $this->assertSame(302, $response['status'], $what);
            $this->assertStringNotContainsString('saved=1', $response['location'], $what);
            $this->assertNull($this->findByLabel($label), $what . ' is not stored');
        }

        // A link that still has submenu items cannot become a button.
        $response = self::$server->request('POST', '/api/admin/update-nav-item.php', $session, [
            'csrf_token' => $token, 'id' => (string) $parent, 'language_code' => 'nl', 'label' => 'Met submenu', 'link_type' => 'none',
            'presentation' => 'button', 'is_visible' => '1',
        ]);
        $this->assertStringNotContainsString('saved=1', $response['location']);
        $this->assertSame('link', $this->navigation->findById($parent)['presentation']);
    }

    // ------------------------------------------------------- three levels

    /**
     * Level 3 can be made through the real endpoint; level 4 cannot, and
     * neither can a submenu under a button. The screen offers
     * "+ Submenu-item" exactly where the endpoint would accept one, and the
     * editor does not take an unacceptable parent from the address bar.
     */
    public function testAThirdLevelIsStoredAndAFourthIsRefused(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $top = $this->item(['label_nl' => 'Diensten', 'link_type' => 'external', 'external_url' => '/diensten']);

        $second = $this->create($session, $token, [
            'label' => 'Graveren', 'parent_id' => (string) $top, 'link_type' => 'external', 'external_url' => '/graveren', 'is_visible' => '1',
        ]);
        $third = $this->create($session, $token, [
            'label' => 'Hout', 'parent_id' => (string) $second['id'], 'link_type' => 'external', 'external_url' => '/graveren/hout', 'is_visible' => '1',
        ]);
        $this->assertSame((int) $second['id'], (int) $third['parent_id']);
        $this->assertSame(3, $this->navigation->depthOf((int) $third['id']));

        $refusals = [
            'a fourth level' => (int) $third['id'],
            'a submenu under a button' => $this->item(['label_nl' => 'Offerte', 'link_type' => 'external', 'external_url' => '/offerte', 'presentation' => 'button']),
            'a parent that does not exist' => 999999999,
        ];
        foreach ($refusals as $what => $parentId) {
            $label = '__nav_http_' . bin2hex(random_bytes(4));
            $response = self::$server->request('POST', '/api/admin/create-nav-item.php', $session, [
                'csrf_token' => $token, 'label' => $label, 'parent_id' => (string) $parentId,
                'link_type' => 'external', 'external_url' => '/te-diep', 'is_visible' => '1',
            ]);

            $this->assertSame(302, $response['status'], $what);
            $this->assertStringNotContainsString('saved=1', $response['location'], $what);
            $this->assertContains(
                'Hier kan geen submenu-item onder: het menu heeft maximaal 3 niveaus, en alleen een menulink kan submenu-items hebben.',
                (array) $this->accounts->read($session, 'admin_nav_item_errors'),
                $what
            );
            $this->assertNull($this->findByLabel($label), $what . ' is not stored');
        }

        $screen = $this->xpath(self::$server->request('GET', '/admin/navigation.php', $session)['body']);
        $addChild = static fn (int $id): int => $screen->query('//*[@id="nav-item-' . $id . '"]//a[@href="/admin/navigation-item.php?parent_id=' . $id . '"]')->length;
        $this->assertSame(1, $addChild($top), 'level 1 offers a submenu item');
        $this->assertSame(1, $addChild((int) $second['id']), 'level 2 offers a submenu item');
        $this->assertSame(0, $addChild((int) $third['id']), 'level 3 offers none');
        $this->assertSame(
            1,
            $screen->query('//*[@data-nav-zone][@data-parent-id="' . $second['id'] . '"]/*[@id="nav-item-' . $third['id'] . '"]')->length,
            'level 3 is its own ordering zone under its parent'
        );
        $this->assertSame(0, $screen->query('//form[@action="/api/admin/delete-nav-item.php"][.//input[@name="id"][@value="' . $second['id'] . '"]]')->length, 'level 2 with a submenu cannot be deleted yet');

        $editor = $this->xpath(self::$server->request('GET', '/admin/navigation-item.php?parent_id=' . $third['id'], $session)['body']);
        $this->assertSame(0, $editor->query('//input[@name="parent_id"]')->length, 'no level-4 form from the address bar');
        $editor = $this->xpath(self::$server->request('GET', '/admin/navigation-item.php?parent_id=' . $second['id'], $session)['body']);
        $this->assertSame((string) $second['id'], $editor->query('//input[@name="parent_id"]')->item(0)?->getAttribute('value'));
        $this->assertSame(0, $editor->query('//select[@name="link_type"]/option[@value="none"]')->length, 'a submenu item always has a destination');
    }

    /**
     * The public header over real HTTP, three levels deep: a parent's words
     * are its own link (a nested page, Pages 2.0), its toggle is a separate
     * button that names and controls its panel, a level-2 item with a
     * submenu is the same pair, and a heading without a destination is one
     * toggle that is not a fake link.
     */
    public function testThePublicHeaderRendersALinkAndASeparateToggleOnEveryLevel(): void
    {
        $parentPage = $this->page('Diensten ouder');
        $nestedPage = $this->page('Diensten', $parentPage);
        $nestedHref = '/' . $this->pages->findById($parentPage)['slug'] . '/' . $this->pages->findById($nestedPage)['slug'];

        $prefix = '__nav3_' . bin2hex(random_bytes(3)) . ' ';
        $top = $this->item(['label_nl' => $prefix . 'Diensten', 'link_type' => 'page', 'target_page_id' => $nestedPage]);
        $second = $this->item(['label_nl' => $prefix . 'Graveren', 'parent_id' => $top, 'link_type' => 'external', 'external_url' => '/graveren']);
        $third = $this->item(['label_nl' => $prefix . 'Hout', 'parent_id' => $second, 'link_type' => 'external', 'external_url' => 'https://example.com/hout', 'open_in_new_tab' => true]);
        $this->item(['label_nl' => $prefix . 'Snijden', 'parent_id' => $top, 'link_type' => 'external', 'external_url' => '/snijden']);
        $heading = $this->item(['label_nl' => $prefix . 'Werk', 'link_type' => 'none']);
        $this->item(['label_nl' => $prefix . 'Galerij', 'parent_id' => $heading, 'link_type' => 'external', 'external_url' => '/galerij']);

        $xpath = $this->xpath(self::$server->request('GET', '/index.php')['body']);
        $item = static fn (string $label): ?\DOMElement => $xpath->query('//nav[@id="main-nav"]//li[contains(@class, "main-nav__item--has-children")][./div[@class="main-nav__row"][contains(., "' . $label . '")]]')->item(0);

        $topItem = $item($prefix . 'Diensten');
        $this->assertInstanceOf(\DOMElement::class, $topItem);
        $link = $xpath->query('./div[@class="main-nav__row"]/a', $topItem)->item(0);
        $this->assertSame($nestedHref, $link?->getAttribute('href'), 'the parent is a real link to its nested page');
        $toggle = $xpath->query('./div[@class="main-nav__row"]/button', $topItem)->item(0);
        $this->assertSame(['button', 'false', 'main-nav-submenu-' . $top, 'Submenu ' . $prefix . 'Diensten'], [
            $toggle?->getAttribute('type'), $toggle?->getAttribute('aria-expanded'), $toggle?->getAttribute('aria-controls'), $toggle?->getAttribute('aria-label'),
        ]);
        $this->assertSame(0, $xpath->query('.//a', $toggle)->length, 'the toggle holds no link');
        $this->assertSame('main-nav__submenu main-nav__submenu--level-2', $xpath->query('./ul[@id="main-nav-submenu-' . $top . '"]', $topItem)->item(0)?->getAttribute('class'));

        $secondItem = $item($prefix . 'Graveren');
        $this->assertSame('/graveren', $xpath->query('./div[@class="main-nav__row"]/a', $secondItem)->item(0)?->getAttribute('href'));
        $this->assertSame('main-nav-submenu-' . $second, $xpath->query('./div[@class="main-nav__row"]/button', $secondItem)->item(0)?->getAttribute('aria-controls'));
        $flyout = $xpath->query('./ul[@id="main-nav-submenu-' . $second . '"]', $secondItem)->item(0);
        $this->assertSame('main-nav__submenu main-nav__submenu--level-3', $flyout?->getAttribute('class'));
        $hout = $xpath->query('./li/a', $flyout)->item(0);
        $this->assertSame(['https://example.com/hout', '_blank', 'noopener noreferrer'], [$hout?->getAttribute('href'), $hout?->getAttribute('target'), $hout?->getAttribute('rel')]);
        $this->assertSame(0, $xpath->query('.//button', $flyout)->length, 'level 3 has no toggle: there is no level 4');

        $this->assertSame('/snijden', $xpath->query('.//li[not(contains(@class, "has-children"))]/a[contains(., "' . $prefix . 'Snijden")]', $topItem)->item(0)?->getAttribute('href'), 'a level-2 item without a submenu is a plain link');

        $headingItem = $item($prefix . 'Werk');
        $this->assertSame(0, $xpath->query('./div[@class="main-nav__row"]/a', $headingItem)->length, 'a heading is not a fake link');
        $headingToggle = $xpath->query('./div[@class="main-nav__row"]/button', $headingItem)->item(0);
        $this->assertSame([$prefix . 'Werk', ''], [trim((string) $headingToggle?->textContent), (string) $headingToggle?->getAttribute('aria-label')], 'the heading\'s words name its toggle');
        $this->assertSame(0, $xpath->query('//nav[@id="main-nav"]//ul[@class="main-nav__list"]//a[@href="#" or @href=""]')->length, 'no placeholder link anywhere');
        $this->assertSame(0, $xpath->query('//nav[@id="main-nav"]//ul[@class="main-nav__list"]//*[@aria-haspopup]')->length, 'a disclosure, not a menu widget');
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
            'label' => $prefix . ' Offerte', 'link_type' => 'external', 'external_url' => '/zz-offerte',
            'presentation' => 'button', 'button_variant' => 'primary', 'is_visible' => '1',
        ])['id'];
        $this->assertSame([[$prefix . ' Offerte', '/zz-offerte', 'btn btn--sm']], $this->headerButtons($prefix));

        $second = (int) $this->create($session, $token, [
            'label' => $prefix . ' Bel ons', 'link_type' => 'external', 'external_url' => '/zz-bel',
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
            'csrf_token' => $token, 'id' => (string) $button, 'language_code' => 'nl', 'label' => $prefix . ' Naar de webwinkel',
            'link_type' => 'route', 'target_route' => 'shop', 'presentation' => 'button', 'button_variant' => 'primary', 'is_visible' => '1',
        ]);
        $this->assertStringContainsString('saved=1', $saved['location']);
        $row = $this->navigation->findById($button);
        $this->assertSame(['shop', $prefix . ' Naar de webwinkel'], [$row['target_route'], $this->label($row, 'nl')]);

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

    /**
     * A row of this test's own. `label_nl`/`label_en` in $overrides are the
     * words it gets in those languages, stored where the CMS stores them.
     *
     * @param array<string, mixed> $overrides
     */
    private function item(array $overrides): int
    {
        $labels = ['nl' => (string) ($overrides['label_nl'] ?? 'Item'), 'en' => (string) ($overrides['label_en'] ?? '')];
        unset($overrides['label_nl'], $overrides['label_en']);

        $id = $this->navigation->create(array_merge([
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
        foreach ($labels as $language => $label) {
            NavigationLocalization::save($id, $language, $label);
        }

        return $id;
    }

    /** @param array<string, mixed> $row */
    private function label(array $row, string $language): string
    {
        NavigationLocalization::clearCache();

        return NavigationLocalization::raw((int) $row['id'], $language);
    }

    private function page(string $title, ?int $parentId = null): int
    {
        $key = 'zz-nav-http-' . bin2hex(random_bytes(4));
        $id = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
            'parent_id' => $parentId,
        ], $title);
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
            if ($this->label($row, 'nl') === $label) {
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
