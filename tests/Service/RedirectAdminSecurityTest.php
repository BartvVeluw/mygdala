<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;

/**
 * The same static-source guard NavigationFooterSecurityTest applies to the
 * navigation endpoints, applied to the Redirect Manager: every write endpoint
 * checks login, then the permission, then that the method is POST, then CSRF,
 * before it can change anything — and both admin screens are behind the same
 * permission.
 *
 * It also pins the two things that must NEVER be readable from a request: a
 * row's origin (which decides whether a later rename may overwrite it) and a
 * redirect destination taken from public input, which is what an open-redirect
 * vulnerability is made of.
 *
 * Reads source files; no database and no web server.
 */
class RedirectAdminSecurityTest extends TestCase
{
    private const ENDPOINTS = [
        'create-redirect.php',
        'update-redirect.php',
        'toggle-redirect.php',
        'delete-redirect.php',
    ];

    private const SCREENS = [
        'redirects.php',
        'redirect.php',
    ];

    private const PERMISSION = 'settings.manage';

    public function testEveryEndpointChecksLoginAndThePermissionBeforeAnythingElse(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $loginPos = strpos($source, 'AdminAuth::requireLoginForApi()');
            $permissionPos = strpos($source, "AdminAuth::requirePermissionForApi('" . self::PERMISSION . "')");

            $this->assertNotFalse($loginPos, "{$endpoint} must call AdminAuth::requireLoginForApi()");
            $this->assertNotFalse(
                $permissionPos,
                "{$endpoint} must require the " . self::PERMISSION . ' permission'
            );
            $this->assertLessThan($permissionPos, $loginPos, "{$endpoint} must check login before the permission");
        }
    }

    public function testEveryEndpointValidatesCsrfAfterCheckingLogin(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertMatchesRegularExpression(
                '/Csrf::validate\(\$_POST\[.csrf_token.\] \?\? null\)/',
                $source,
                "{$endpoint} must validate the csrf_token POST field"
            );

            $loginPos = strpos($source, 'AdminAuth::requireLoginForApi()');
            $csrfPos = strpos($source, 'Csrf::validate(');
            $this->assertNotFalse($loginPos);
            $this->assertNotFalse($csrfPos);
            $this->assertLessThan($csrfPos, $loginPos, "{$endpoint} must check login before CSRF");
        }
    }

    public function testEveryEndpointRejectsNonPostMethods(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->assertStringContainsString(
                "\$_SERVER['REQUEST_METHOD'] !== 'POST'",
                $this->endpointSource($endpoint),
                "{$endpoint} must reject non-POST methods"
            );
        }
    }

    public function testBothAdminScreensAreBehindTheSamePermission(): void
    {
        foreach (self::SCREENS as $screen) {
            $source = $this->screenSource($screen);

            $this->assertStringContainsString(
                'AdminAuth::requireLogin();',
                $source,
                "admin/{$screen} must require a login"
            );
            $this->assertStringContainsString(
                "AdminAuth::requirePermission('" . self::PERMISSION . "')",
                $source,
                "admin/{$screen} must require the " . self::PERMISSION . ' permission'
            );
        }
    }

    /**
     * The sidebar entry and the screens behind it must agree, or the menu
     * would offer a section that then refuses to open.
     */
    public function testTheSidebarEntryMatchesTheScreensItLinksTo(): void
    {
        $entries = array_values(array_filter(
            AdminNavigation::items(),
            static fn (array $item): bool => $item['key'] === 'redirects'
        ));

        $this->assertCount(1, $entries, 'the admin sidebar must have exactly one Redirects entry');

        $entry = $entries[0];

        $this->assertSame(AdminPermissions::SETTINGS_MANAGE, $entry['permission']);
        $this->assertSame('/admin/redirects.php', $entry['url']);
        $this->assertSame(self::SCREENS, $entry['scripts']);
    }

    /**
     * A row's origin is the application's to set. Were it readable from the
     * form, an editor could mark their own redirect as automatic and have the
     * next rename of an unrelated page silently overwrite it.
     */
    public function testTheOriginIsNeverTakenFromTheRequest(): void
    {
        foreach ([...self::ENDPOINTS, '_redirect_input.php'] as $endpoint) {
            $this->assertStringNotContainsString(
                "\$_POST['origin']",
                $this->endpointSource($endpoint),
                "{$endpoint} must not read a redirect's origin from the request"
            );
        }

        $this->assertStringContainsString(
            'Redirect::ORIGIN_MANUAL',
            $this->endpointSource('create-redirect.php'),
            'a hand-written redirect must be stored as manual'
        );
    }

    /**
     * Nothing on the public side may take a destination from the request. The
     * redirect table is the only source of a Location header, and only an
     * authenticated editor writes to it.
     */
    public function testThePublicSideNeverBuildsADestinationFromRequestInput(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['404.php', 'src/Service/Redirects/RedirectResolver.php', 'src/Service/Redirects/RedirectGate.php'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);

            foreach (["\$_GET", "\$_POST", "\$_REQUEST"] as $superglobal) {
                $this->assertStringNotContainsString(
                    $superglobal,
                    $source,
                    "{$file} must not read request parameters — a redirect destination comes from the database only"
                );
            }
        }
    }

    private function endpointSource(string $endpoint): string
    {
        $path = dirname(__DIR__, 2) . '/api/admin/' . $endpoint;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function screenSource(string $screen): string
    {
        $path = dirname(__DIR__, 2) . '/admin/' . $screen;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
