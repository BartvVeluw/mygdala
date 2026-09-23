<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Instellingen and Updates in the CMS shell, over real HTTP:
 *
 *  - the sidebar says "Instellingen", not "Site-instellingen";
 *  - Updates has no line of its own for who can open Instellingen: it is a
 *    tab there, and its own screen lights up Instellingen;
 *  - who may install updates without settings.manage still gets a line;
 *  - who may not install updates sees neither the line nor the tab;
 *  - /admin/updates.php keeps its address and its own guard.
 *
 * The accounts are this test's own and are removed in tearDown(). Without a
 * server the test skips itself.
 */
final class SettingsNavigationHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $this->accounts->forget();
    }

    public function testUpdatesIsATabUnderInstellingenForASuperAdmin(): void
    {
        [$session] = $this->accounts->signIn([], true);

        $settings = self::$server->request('GET', '/admin/settings.php', $session)['body'];
        $sidebar = $this->sidebar($settings);
        self::assertStringContainsString('<span>Instellingen</span>', $sidebar);
        self::assertStringNotContainsString('Site-instellingen', $settings);
        self::assertStringNotContainsString('href="/admin/updates.php"', $sidebar, 'no line of its own');
        self::assertStringContainsString('data-admin-tab-panel="updates"', $settings, 'an Updates tab');
        self::assertStringContainsString('<a class="admin-btn-primary" href="/admin/updates.php">', $settings);

        $updates = self::$server->request('GET', '/admin/updates.php', $session);
        self::assertSame(200, $updates['status'], 'the address still works');
        self::assertMatchesRegularExpression('#href="/admin/settings.php" class="admin-sidebar__link is-active"#', $this->sidebar($updates['body']), 'its screen lights up Instellingen');
        self::assertStringContainsString('href="/admin/settings.php?section=updates"', $updates['body'], 'the way back to the tab');

        $back = self::$server->request('GET', '/admin/settings.php?section=updates', $session)['body'];
        self::assertMatchesRegularExpression('/data-admin-tab-panel="algemeen"[^>]*hidden/', $back, 'coming back from Updates opens its tab, not Algemeen');
        self::assertDoesNotMatchRegularExpression('/data-admin-tab-panel="updates"[^>]*hidden/', $back);
    }

    public function testWithoutUpdatesManageThereIsNoTabNoLineAndNoScreen(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $settings = self::$server->request('GET', '/admin/settings.php', $session)['body'];
        self::assertStringNotContainsString('/admin/updates.php', $settings);

        $updates = self::$server->request('GET', '/admin/updates.php', $session);
        self::assertNotSame(200, $updates['status'], 'updates.manage still guards the screen');
    }

    /** Somebody who may install updates but cannot open Instellingen keeps a line, or could not get there. */
    public function testUpdatesKeepsItsOwnLineWithoutSettingsManage(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::UPDATES_MANAGE, AdminPermissions::PAGES_MANAGE]);

        $page = self::$server->request('GET', '/admin/updates.php', $session);
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('#href="/admin/updates.php" class="admin-sidebar__link is-active"#', $this->sidebar($page['body']));
        self::assertStringNotContainsString('href="/admin/settings.php"', $this->sidebar($page['body']));
    }

    public function testTheNavigationDeclaresUpdatesWithinInstellingen(): void
    {
        $items = array_column(AdminNavigation::items(), null, 'key');

        self::assertSame('settings', $items['updates']['within'] ?? null);
        self::assertSame('/admin/updates.php', $items['updates']['url']);
        self::assertSame(AdminPermissions::UPDATES_MANAGE, $items['updates']['permission']);
        self::assertSame('Instellingen', $items['settings']['label']);
    }

    private function sidebar(string $html): string
    {
        $start = strpos($html, '<nav class="admin-sidebar__nav"');
        self::assertNotFalse($start, 'the shell renders its sidebar');

        return substr($html, (int) $start, (int) strpos($html, '</nav>', (int) $start) - (int) $start);
    }
}
