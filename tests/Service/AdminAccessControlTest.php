<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique and reasoning as
 * tests/Service/CollectionAdminSecurityTest.php and
 * PageBuilderSecurityTest.php — this project has no HTTP test harness for
 * authenticated admin requests) over the *complete* admin surface:
 *
 *  - every /admin page requires a permission, not just a login;
 *  - every /api/admin write endpoint requires a permission, before CSRF and
 *    before anything is written;
 *  - the permission each one asks for is a real, registered one.
 *
 * The point is coverage, not spot checks: the lists below are built by
 * scanning the directories, so a new admin page or endpoint added later
 * fails this test until it is given a guard. That is what makes "a forbidden
 * URL is denied" and "a crafted POST cannot bypass permissions" true for the
 * whole CMS rather than for the handful of files someone remembered.
 */
final class AdminAccessControlTest extends TestCase
{
    /** Pages that are deliberately reachable without being logged in. */
    private const PUBLIC_ADMIN_SCRIPTS = ['login.php', 'logout.php'];

    /**
     * Screens and endpoints that require a LOGIN but deliberately no
     * permission, because everything they touch belongs to the person making
     * the request rather than to the site.
     *
     * The bar for this list is high and there are two things on it, both of
     * them one person's own preference about how the CMS looks to them.
     * Anything that reads or writes site content, settings or another
     * account's data needs a permission, and the rest of this class still
     * checks that these guard login, method and CSRF like everything else.
     */
    private const PERSONAL_PREFERENCE_SCRIPTS = [
        // A person's own CMS interface language (MULTILINGUAL.md). Gating it
        // behind a permission would mean a colleague who may only edit one
        // block has to read the CMS in a language they do not speak. There is
        // no user id in the form, so it can only ever write to the account
        // that is signed in.
        'account.php',
        'update-account-preferences.php',
        // Which language version of the content this person is editing
        // (MULTILINGUAL.md). Same argument: it writes one column on the row
        // of the account making the request, there is no user id in the
        // form, and gating it would mean somebody who may edit a block
        // cannot choose which language of that block they are looking at.
        // It changes nothing a visitor sees and nothing a colleague sees.
        'update-content-language.php',
    ];

    /** Shared includes rendered by other pages, never requested directly. */
    private const ADMIN_PARTIALS = [
        '_header.php',
        '_forbidden.php',
        '_labels.php',
        '_translate.php',
        '_richtext_field.php',
        // The language tabs on a content editor: a tab strip plus a wrapper
        // around fields the calling editor already renders behind its own
        // permission check (MULTILINGUAL.md). It reads no content of its own,
        // and the one endpoint it points at
        // (api/admin/translate-fields.php) checks pages.manage itself.
        '_language_fields.php',
        '_order_personalization.php',
        '_personalization_builder.php',
        // The website-statistics block on the dashboard: rendered by
        // admin/index.php behind that page's own dashboard.view check, never
        // requested on its own.
        '_dashboard_analytics.php',
        // The Shop's dashboard panel, contributed by
        // App\Module\ShopModule::dashboardPanels() and rendered by
        // admin/index.php behind that same check. Its own sections each ask
        // AdminAuth::can() before querying anything.
        '_dashboard_shop.php',
        // The reusable Media picker: a field plus one shared modal, included
        // by whichever editor renders an image field. It has no URL and no
        // guard of its own on purpose — it renders no data, and the two
        // endpoints behind it (media-list.php, media-upload.php) each check
        // media.view themselves.
        '_media_picker.php',
        // The block picker: the button under a page's block list plus the
        // panel it opens, included by admin/page.php behind that page's own
        // pages.manage check. It renders no data of its own — the caller
        // hands it the blocks SectionRegistry already said were allowed —
        // and adding still goes through api/admin/add-page-section.php,
        // which guards itself.
        '_block_picker.php',
        // The schematic drawing and icon on a block card, shared by the
        // picker and the Contentblokken catalogue. Two output functions over
        // constant, first-party markup; no URL, no data, nothing to guard.
        '_block_visual.php',
        // The Contentblokken library's cards and its preview dialog, printed
        // by admin/content-blocks.php behind that page's own pages.manage
        // check. Output functions over the registered definitions; the frame
        // it opens (admin/block-preview.php) guards itself.
        '_block_library.php',
        // The page editor's save/status bar: markup plus one <script> tag.
        // It owns no fields and posts nothing — every save still goes through
        // the form's own guarded endpoint.
        '_save_bar.php',
        // Tabs over a long screen: output functions that wrap markup the
        // including page already rendered behind its own guard. They read no
        // data, decide nothing, and hide only what is already on the page.
        '_admin_tabs.php',
        // The collapsible-list convention plus one <script> tag. Same shape
        // as the save bar: no URL, no data, no decision.
        '_admin_collapse.php',
        // Field help, the help switch and the file input (ADMIN-UI.md):
        // output functions over CMS text the including screen hands in, plus
        // one <script> tag the shell prints. No URL, no data, no decision.
        '_admin_ui.php',
        // What a form field type is called and the radio cards it is picked
        // from, printed by admin/form.php and admin/form-field.php behind
        // their own forms.manage check. Output functions over the closed
        // type registry and the catalogue; creating or changing a field
        // still goes through its own guarded endpoint.
        '_form_fields.php',
        // Where a menu item or footer link goes, in words, printed by
        // admin/navigation.php and admin/footer.php behind their own
        // pages.manage check. Output functions over rows the caller already
        // read; no URL, no data of its own, no decision.
        '_link_destination.php',
    ];

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function adminPages(): array
    {
        $files = array_map(
            'basename',
            (array) glob(self::projectRoot() . '/admin/*.php')
        );

        return array_values(array_diff($files, self::PUBLIC_ADMIN_SCRIPTS, self::ADMIN_PARTIALS));
    }

    /**
     * @return list<string>
     */
    private static function adminEndpoints(): array
    {
        $files = array_map('basename', (array) glob(self::projectRoot() . '/api/admin/*.php'));

        // `_*.php` are validation/helper includes, required by the endpoints
        // that already guarded themselves.
        return array_values(array_filter($files, static fn (string $f): bool => !str_starts_with($f, '_')));
    }

    private function source(string $relativePath): string
    {
        $path = self::projectRoot() . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return list<string> the permission names a file asks for
     */
    private function requiredPermissions(string $source): array
    {
        preg_match_all(
            "/AdminAuth::require(?:Permission|PermissionForApi)\(\s*'([^']+)'/",
            $source,
            $matches
        );

        return $matches[1];
    }

    // --- every admin page ------------------------------------------------

    public function testThereAreAdminPagesAndEndpointsToCheck(): void
    {
        $this->assertGreaterThan(30, count(self::adminPages()));
        $this->assertGreaterThan(140, count(self::adminEndpoints()));
    }

    public function testEveryAdminPageRequiresLoginAndThenAPermission(): void
    {
        foreach (self::adminPages() as $page) {
            $source = $this->source('admin/' . $page);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, $page);

            if (in_array($page, self::PERSONAL_PREFERENCE_SCRIPTS, true)) {
                continue;
            }

            $permissions = $this->requiredPermissions($source);
            $this->assertNotEmpty($permissions, "{$page} must require a permission, not only a login");

            $loginPos = strpos($source, 'AdminAuth::requireLogin()');
            $permissionPos = strpos($source, 'AdminAuth::requirePermission(');

            $this->assertNotFalse($permissionPos, $page);
            $this->assertLessThanOrEqual($permissionPos, $loginPos, "{$page}: login is checked first");
        }
    }

    public function testEveryAdminPageAsksForARegisteredPermission(): void
    {
        foreach (self::adminPages() as $page) {
            foreach ($this->requiredPermissions($this->source('admin/' . $page)) as $permission) {
                $this->assertTrue(
                    AdminPermissions::isValid($permission),
                    "{$page} requires unknown permission \"{$permission}\""
                );
            }
        }
    }

    /**
     * The guard has to be the first thing that runs, before the page reads
     * anything out of the database and before it renders — otherwise a
     * forbidden URL could still leak data through an error message or a
     * partially rendered page.
     */
    public function testEveryAdminPageGuardsBeforeItLoadsOrRendersAnything(): void
    {
        foreach (self::adminPages() as $page) {
            $source = $this->source('admin/' . $page);
            $permissionPos = (int) strpos($source, 'AdminAuth::requirePermission(');

            foreach (['Repository(', '<!doctype html>'] as $marker) {
                $markerPos = strpos($source, $marker);

                if ($markerPos !== false) {
                    $this->assertLessThan(
                        $markerPos,
                        $permissionPos,
                        "{$page}: the permission check must come before \"{$marker}\""
                    );
                }
            }
        }
    }

    // --- every write endpoint -------------------------------------------

    public function testEveryAdminEndpointRequiresAPermissionBeforeCsrf(): void
    {
        foreach (self::adminEndpoints() as $endpoint) {
            $source = $this->source('api/admin/' . $endpoint);

            if (in_array($endpoint, self::PERSONAL_PREFERENCE_SCRIPTS, true)) {
                // Still guarded, just not by a permission: login, method and
                // CSRF are asserted for every endpoint further down.
                $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $endpoint);
                $this->assertStringContainsString('Csrf::validate(', $source, $endpoint);
                continue;
            }

            $permissions = $this->requiredPermissions($source);
            $this->assertNotEmpty($permissions, "{$endpoint} must require a permission, not only a login");
            $this->assertCount(1, $permissions, "{$endpoint} should ask for exactly one permission");

            $this->assertTrue(
                AdminPermissions::isValid($permissions[0]),
                "{$endpoint} requires unknown permission \"{$permissions[0]}\""
            );

            $loginPos = strpos($source, 'AdminAuth::requireLogin');
            $permissionPos = strpos($source, 'AdminAuth::requirePermission');
            $csrfPos = strpos($source, 'Csrf::validate(');

            $this->assertNotFalse($loginPos, $endpoint);
            $this->assertNotFalse($permissionPos, $endpoint);
            $this->assertLessThan($permissionPos, $loginPos, "{$endpoint}: login before permission");

            if ($csrfPos !== false) {
                $this->assertLessThan(
                    $csrfPos,
                    $permissionPos,
                    "{$endpoint}: the permission must be checked before the CSRF token"
                );
            }
        }
    }

    /**
     * A crafted POST reaches the endpoint the same way the form does, so the
     * guard must sit above every branch — no endpoint may decide its
     * permission from anything the request sent.
     */
    public function testNoEndpointDerivesItsPermissionFromTheRequest(): void
    {
        foreach (self::adminEndpoints() as $endpoint) {
            $source = $this->source('api/admin/' . $endpoint);

            preg_match_all('/AdminAuth::requirePermission(?:ForApi)?\(([^)]*)\)/', $source, $matches);

            foreach ($matches[1] as $argument) {
                $this->assertMatchesRegularExpression(
                    "/^\s*'[a-z_]+\.[a-z_]+'\s*$/",
                    $argument,
                    "{$endpoint}: the required permission must be a literal, got \"{$argument}\""
                );
            }
        }
    }

    /**
     * The user-management endpoints are the ones an attacker would aim at, so
     * they get the same scrutiny the collection endpoints already get.
     */
    public function testTheUserManagementEndpointsFollowTheFullGuardOrder(): void
    {
        foreach (['create-admin-user.php', 'update-admin-user.php'] as $endpoint) {
            $source = $this->source('api/admin/' . $endpoint);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $endpoint);
            $this->assertStringContainsString("AdminAuth::requirePermissionForApi('users.manage')", $source, $endpoint);
            $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source, $endpoint);
            $this->assertStringContainsString('http_response_code(405)', $source, $endpoint);
            $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source, $endpoint);

            $writePos = strpos($source, 'AdminUserService()');
            $this->assertNotFalse($writePos, $endpoint);

            foreach ([
                'AdminAuth::requireLoginForApi()',
                "AdminAuth::requirePermissionForApi('users.manage')",
                "\$_SERVER['REQUEST_METHOD'] !== 'POST'",
                'Csrf::validate(',
            ] as $guard) {
                $this->assertLessThan(
                    $writePos,
                    strpos($source, $guard),
                    "{$endpoint}: \"{$guard}\" must run before anything is written"
                );
            }
        }

        // The id an update targets is validated server-side, like every other
        // id-carrying admin endpoint.
        $update = $this->source('api/admin/update-admin-user.php');
        $this->assertStringContainsString('FILTER_VALIDATE_INT', $update);
        $this->assertStringContainsString('$id < 1', $update);
    }

    /**
     * A password, a hash or a confirmation must never be flashed back into a
     * session, a page or a log.
     */
    public function testTheUserEndpointsNeverEchoOrStoreASubmittedPassword(): void
    {
        foreach (['create-admin-user.php', 'update-admin-user.php'] as $endpoint) {
            $source = $this->source('api/admin/' . $endpoint);

            $this->assertStringContainsString(
                "unset(\$fields['password'], \$fields['password_confirmation'])",
                $source,
                $endpoint
            );
            $this->assertStringNotContainsString("\$_SESSION['admin_user_old'] = \$_POST", $source, $endpoint);
        }

        $form = $this->source('admin/user-form.php');
        $this->assertStringNotContainsString('password_hash', $form);
        $this->assertStringNotContainsString("name=\"password\" value=", $form);
    }

    // --- the sidebar is a mirror, never the enforcement -------------------

    public function testEverySidebarEntryPointsAtAPageThatEnforcesTheSamePermission(): void
    {
        foreach (AdminNavigation::items() as $item) {
            $this->assertTrue(
                AdminPermissions::isValid($item['permission']),
                "sidebar entry {$item['key']} uses unknown permission {$item['permission']}"
            );

            $target = basename((string) $item['url']);
            $source = $this->source('admin/' . $target);

            $this->assertContains(
                $item['permission'],
                $this->requiredPermissions($source),
                "{$target} must itself require {$item['permission']}, not rely on the menu hiding it"
            );
        }
    }

    /**
     * Admin pages that deliberately belong to no sidebar entry.
     *
     * The Setup Wizard is the only one, and it is the exception that proves
     * the rule: it renders no sidebar at all, because an installation that
     * has not been set up has nowhere else to go until it is
     * (App\Service\AdminAuth sends every other admin page here). Once setup
     * is complete it becomes a page of links to the screens that do have
     * entries, so giving it one of its own would put a permanent menu item
     * in front of a screen nobody should open twice. See SETUP.md.
     *
     * My account is the second, for a related reason: it belongs to the
     * PERSON rather than to a section of the CMS, so it is reached from the
     * account block at the bottom of the sidebar where their name already is
     * (MULTILINGUAL.md). A nav entry would put a personal preference among
     * the site's content sections, and it would need a permission to be
     * hidden by — which is exactly what this screen deliberately does not
     * have.
     */
    private const SIDEBAR_EXEMPT_SCRIPTS = ['setup.php', 'account.php'];

    public function testEveryAdminPageIsClaimedByExactlyOneSidebarEntry(): void
    {
        $claimed = [];
        foreach (AdminNavigation::items() as $item) {
            foreach ($item['scripts'] as $script) {
                $this->assertArrayNotHasKey($script, $claimed, "{$script} is listed under two sidebar entries");
                $claimed[$script] = $item['key'];
            }
        }

        foreach (array_diff(self::adminPages(), self::SIDEBAR_EXEMPT_SCRIPTS) as $page) {
            $this->assertArrayHasKey(
                $page,
                $claimed,
                "{$page} belongs to no sidebar entry, so it can never be highlighted or hidden correctly"
            );
        }
    }

    /**
     * A sidebar entry groups the screens of ONE section, so every script
     * under it must guard a permission from that same family — the entry's
     * own permission, or one that implies it (a products.view entry may
     * cover the products.manage-only editor, since products.manage is just a
     * wider grant on the same section). What it must never cover is an
     * unrelated section, which would make the highlight and the "hidden
     * section" logic lie about where the user is.
     */
    public function testEveryScriptUnderASidebarEntryGuardsThatSameSection(): void
    {
        foreach (AdminNavigation::items() as $item) {
            $entryPermission = $item['permission'];

            foreach ($item['scripts'] as $script) {
                foreach ($this->requiredPermissions($this->source('admin/' . $script)) as $permission) {
                    $sameFamily = $permission === $entryPermission
                        || in_array($entryPermission, AdminPermissions::expand([$permission]), true)
                        || in_array($permission, AdminPermissions::expand([$entryPermission]), true);

                    $this->assertTrue(
                        $sameFamily,
                        "{$script} requires {$permission}, which is not the same section as the "
                        . "\"{$item['label']}\" entry's {$entryPermission}"
                    );
                }
            }
        }
    }

    /**
     * The page a sidebar entry actually links to must be openable by exactly
     * the permission that made the entry visible — otherwise a user would see
     * a link that leads straight into a 403.
     */
    public function testASidebarEntryNeverLinksIntoAPageItsPermissionCannotOpen(): void
    {
        foreach (AdminNavigation::items() as $item) {
            $holder = [
                'is_super_admin' => false,
                'permissions' => AdminPermissions::expand([$item['permission']]),
            ];

            foreach ($this->requiredPermissions($this->source('admin/' . basename((string) $item['url']))) as $permission) {
                $this->assertTrue(
                    AdminPermissions::userHas($holder, $permission),
                    "{$item['url']} requires {$permission}, which {$item['permission']} does not grant"
                );
            }
        }
    }

    // --- the login/session surface ---------------------------------------

    public function testLoginRegeneratesTheSessionAndStoresOnlyTheAccountId(): void
    {
        $source = $this->source('src/Service/AdminAuth.php');

        $this->assertStringContainsString('session_regenerate_id(true)', $source);
        $this->assertStringContainsString("\$_SESSION['admin_user_id'] = \$userId", $source);

        // The permission list must never be cached in the session: it is
        // re-read per request so a revoked grant applies immediately.
        $this->assertStringNotContainsString("\$_SESSION['permissions']", $source);
        $this->assertStringNotContainsString("\$_SESSION['admin_permissions']", $source);
        $this->assertStringNotContainsString("\$_SESSION['admin_is_super_admin']", $source);
    }

    public function testNothingInTheAuthLayerLogsACredential(): void
    {
        foreach ([
            'src/Service/AdminAuth.php',
            'src/Service/AdminUserService.php',
            'src/Repository/AdminUserRepository.php',
            'api/admin/create-admin-user.php',
            'api/admin/update-admin-user.php',
        ] as $file) {
            $source = $this->source($file);

            preg_match_all('/error_log\(([^;]*)\);/s', $source, $matches);

            foreach ($matches[1] as $argument) {
                foreach (["\$password", "password_hash'", '$hash', "'password'"] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        $argument,
                        "{$file}: a log line must never carry {$forbidden}"
                    );
                }
            }
        }
    }

    public function testLogoutIsStillAPostWithCsrfProtection(): void
    {
        $logout = $this->source('admin/logout.php');

        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $logout);
        $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $logout);
        $this->assertStringContainsString('AdminAuth::logout()', $logout);

        $this->assertStringContainsString(
            'action="/admin/logout.php"',
            $this->source('admin/_header.php')
        );
    }
}
