<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Install\SetupWizard;
use App\Module\ModuleRegistry;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use App\Service\PageTemplates\PageTemplates;
use PHPUnit\Framework\TestCase;

/**
 * Who may reach the Setup Wizard, what it may be handed, and — the part that
 * would be easy to get wrong — that shipping it did not introduce a default
 * password.
 *
 * Static source inspection, the same technique and for the same reason as
 * Tests\Service\AdminAccessControlTest and PageBuilderSecurityTest: this
 * project has no HTTP harness for authenticated admin requests, and the
 * guards are the thing worth asserting.
 *
 * No database and no web server.
 */
final class SetupAccessTest extends TestCase
{
    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function source(string $relativePath): string
    {
        $path = self::projectRoot() . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /* ------------------------------------------------------------------ */
    /* The wizard is an authenticated admin screen like any other          */
    /* ------------------------------------------------------------------ */

    public function testTheWizardRequiresALoginAndThenAPermissionBeforeItRendersAnything(): void
    {
        $source = $this->source('admin/setup.php');

        $loginPos = strpos($source, 'AdminAuth::requireLogin()');
        $permissionPos = strpos($source, "AdminAuth::requirePermission('" . AdminPermissions::SETTINGS_MANAGE . "')");
        $documentPos = strpos($source, '<!doctype html>');

        $this->assertNotFalse($loginPos, 'the wizard must require a login');
        $this->assertNotFalse($permissionPos, 'the wizard must require settings.manage, not merely a login');
        $this->assertNotFalse($documentPos);

        $this->assertLessThan($permissionPos, $loginPos);
        $this->assertLessThan($documentPos, $permissionPos, 'the guard must run before a single byte is rendered');
    }

    public function testTheCompletionEndpointGuardsInTheOrderEveryWriteEndpointDoes(): void
    {
        $source = $this->source('api/admin/complete-setup.php');

        $login = strpos($source, 'AdminAuth::requireLoginForApi()');
        $permission = strpos($source, "AdminAuth::requirePermissionForApi('" . AdminPermissions::SETTINGS_MANAGE . "')");
        $method = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $csrf = strpos($source, "Csrf::validate(\$_POST['csrf_token'] ?? null)");
        $write = strpos($source, 'SetupWizard::complete(');

        foreach (['login' => $login, 'permission' => $permission, 'method' => $method, 'csrf' => $csrf, 'write' => $write] as $name => $position) {
            $this->assertNotFalse($position, "the endpoint has no {$name} check");
        }

        $this->assertLessThan($permission, $login);
        $this->assertLessThan($method, $permission);
        $this->assertLessThan($csrf, $method);
        $this->assertLessThan($write, $csrf, 'nothing may be written before the CSRF token is checked');
    }

    /**
     * Setup is not a button that reconfigures a live site. An already
     * configured installation — every existing one — must be turned away
     * before the submission is even looked at.
     */
    public function testTheCompletionEndpointRefusesToRunOnAnAlreadyConfiguredInstallation(): void
    {
        $source = $this->source('api/admin/complete-setup.php');

        $guard = strpos($source, 'SetupState::isSetupRequired()');
        $validate = strpos($source, 'SetupWizard::validate(');

        $this->assertNotFalse($guard, 'the endpoint must ask whether setup is still required');
        $this->assertNotFalse($validate);
        $this->assertLessThan($validate, $guard);
    }

    /* ------------------------------------------------------------------ */
    /* The redirect that guides an unfinished install, and its way out     */
    /* ------------------------------------------------------------------ */

    public function testTheSetupRedirectLivesInOnePlaceAndCannotLoop(): void
    {
        $source = $this->source('src/Service/AdminAuth.php');

        $this->assertStringContainsString(
            'SETUP_EXEMPT_SCRIPTS',
            $source,
            'the wizard itself must be exempt from the redirect that leads to it'
        );

        // The exemption list is read out of the source rather than trusted
        // from a comment: it is the difference between a redirect and an
        // infinite loop.
        $this->assertMatchesRegularExpression(
            "/SETUP_EXEMPT_SCRIPTS\s*=\s*\['setup\.php', 'login\.php', 'logout\.php'\]/",
            $source
        );

        $this->assertSame('/admin/setup.php', AdminAuth::SETUP_URL);
    }

    /**
     * Only HTML pages are gated. The admin APIs are not — the wizard needs
     * the Media Library's own endpoints while it runs, and every one of them
     * already enforces its own permission and CSRF token.
     */
    public function testTheApiGuardsAreNotEntangledWithSetupState(): void
    {
        $source = $this->source('src/Service/AdminAuth.php');

        $apiGuard = (string) preg_replace(
            '/.*public static function requireLoginForApi\(\): void\s*\{(.*?)\n    \}.*/s',
            '$1',
            $source
        );

        $this->assertStringNotContainsString('SetupState', $apiGuard);
        $this->assertStringNotContainsString('requireCompletedSetup', $apiGuard);
    }

    /* ------------------------------------------------------------------ */
    /* Nothing the request sends becomes a name                            */
    /* ------------------------------------------------------------------ */

    public function testEveryStarterPageKeyResolvesThroughTheClosedTemplateRegistry(): void
    {
        foreach (SetupWizard::STARTER_PAGES as $key => $page) {
            $this->assertTrue(PageTemplates::has($page['template']), $key);
        }

        $source = $this->source('src/Install/SetupWizard.php');

        $this->assertStringNotContainsString('new $', $source, 'no class name may be built from data');
        $this->assertStringNotContainsString('call_user_func', $source);
        $this->assertStringNotContainsString('eval(', $source);
    }

    public function testAModuleKeyFromARequestCanOnlyHitOrMissTheRegistry(): void
    {
        $source = $this->source('src/Module/ModuleSettings.php');

        $this->assertStringContainsString(
            'ModuleRegistry::has($moduleKey)',
            $source,
            'an unregistered key must be dropped rather than stored'
        );

        foreach (['shop', 'personalization'] as $key) {
            $this->assertTrue(ModuleRegistry::has($key));
        }
    }

    /* ------------------------------------------------------------------ */
    /* No default credentials, anywhere                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The single worst thing a productised CMS can ship. The first
     * administrator comes from ADMIN_USERNAME/ADMIN_PASSWORD_HASH in the
     * server's own .env, and neither the wizard, the migration nor the auth
     * layer may invent, hardcode or generate one.
     */
    public function testNothingInTheInstallPathCreatesAnAccountOrAPassword(): void
    {
        foreach ([
            'src/Install/SetupWizard.php',
            'src/Install/SetupState.php',
            'admin/setup.php',
            'api/admin/complete-setup.php',
            'db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php',
        ] as $file) {
            $source = $this->source($file);

            $this->assertStringNotContainsString('password_hash(', $source, $file . ' must not mint a password');
            $this->assertStringNotContainsString('admin_users', $source, $file . ' must not touch accounts');
            $this->assertStringNotContainsString('$2y$', $source, $file . ' must not carry a hash');
        }
    }

    public function testTheFirstAdministratorsCredentialsComeFromTheEnvironmentAndAreNeverInvented(): void
    {
        $migration = $this->source('db/migrations/20260908150000_create_admin_users_tables.php');

        $this->assertStringContainsString("\$_ENV['ADMIN_USERNAME']", $migration);
        $this->assertStringContainsString("\$_ENV['ADMIN_PASSWORD_HASH']", $migration);

        // No hash configured means no row, and the break-glass path then
        // provisions one from the same pair on first login. What must never
        // happen is a fallback password.
        $this->assertStringNotContainsString('password_hash(', $migration);
        $this->assertStringNotContainsString('$2y$', $migration);
    }

    /**
     * The shipped example configuration must not be usable as-is: a
     * placeholder that happens to be a valid bcrypt hash would be a default
     * password on every install that forgot to change it.
     */
    public function testTheExampleEnvironmentShipsNoUsableAdminHash(): void
    {
        $example = $this->source('.env.example');

        $this->assertMatchesRegularExpression('/^ADMIN_PASSWORD_HASH=.+$/m', $example, 'the variable must be documented');
        $this->assertDoesNotMatchRegularExpression(
            '/^ADMIN_PASSWORD_HASH=\$2[aby]\$/m',
            $example,
            '.env.example must not ship a hash anybody could log in with'
        );
    }

    /**
     * A hash that is not a hash is refused rather than half-accepted, so a
     * .env still holding the placeholder cannot authenticate anyone.
     */
    public function testAPlaceholderHashCannotAuthenticateAnybody(): void
    {
        $source = $this->source('src/Service/AdminAuth.php');

        $this->assertStringContainsString(
            "!str_starts_with(\$hash, '\$')",
            $source,
            'a credential whose hash is not a hash must be treated as absent'
        );
    }
}
