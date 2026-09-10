<?php

namespace App\Service;

use App\Install\SetupState;
use App\Repository\AdminUserRepository;
use Dotenv\Dotenv;

/**
 * Session-based authentication AND authorisation for /admin/ and the admin
 * APIs. Every access decision in the CMS goes through this class:
 *
 *   AdminAuth::requireLogin();                          // HTML page, redirects to the login form
 *   AdminAuth::requirePermission('products.manage');    // HTML page, 403 page when not allowed
 *   AdminAuth::requireLoginForApi();                    // endpoint, 401 when not logged in
 *   AdminAuth::requirePermissionForApi('orders.manage');// endpoint, 403 when not allowed
 *   AdminAuth::can('orders.manage');                    // for rendering decisions only
 *
 * Accounts live in the database (App\Repository\AdminUserRepository, table
 * `admin_users`). The session holds only `admin_logged_in` and the account's
 * id — never a name, never a permission list — so changing someone's
 * permissions or deactivating them takes effect on their very next request
 * rather than whenever they happen to log in again. The account row is read
 * once per request and cached in memory for that request only.
 *
 * The .env pair this class used to authenticate against (ADMIN_USERNAME /
 * ADMIN_PASSWORD_HASH) is still honoured, but only as a break-glass: it is
 * consulted exclusively when the database holds no active Super Admin at all
 * — the migration has not run yet, the database is unreachable, or the rows
 * are gone. In that situation it also re-provisions the Super Admin row when
 * it can, so the CMS heals itself instead of needing manual SQL. As long as
 * a real Super Admin account exists, the .env credential does nothing.
 *
 * Passwords are only ever handled as password_hash()/password_verify()
 * values; nothing here writes a password, a hash or a session id to a log.
 */
class AdminAuth
{
    private const SESSION_NAME = 'vvl_admin_session';

    /** Where an installation that has never been set up is sent. */
    public const SETUP_URL = '/admin/setup.php';

    /**
     * Admin pages that stay reachable while setup is unfinished: the wizard
     * itself (redirecting it to itself is the infinite loop this list exists
     * to prevent), the login form and signing out.
     *
     * Only HTML PAGES are gated. The admin APIs deliberately are not: the
     * wizard needs the Media Library's own upload and listing endpoints
     * while it runs, an unfinished setup is not an authorisation boundary,
     * and every one of those endpoints already enforces its own permission
     * and CSRF token.
     */
    private const SETUP_EXEMPT_SCRIPTS = ['setup.php', 'login.php', 'logout.php'];

    /**
     * A bcrypt hash of a value nobody knows, used to spend the same time on
     * a login attempt for an unknown account as for a known one — otherwise
     * response timing tells an attacker which usernames exist.
     */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    /** Per-request cache of the resolved account; never survives the request. */
    private static ?array $currentUser = null;
    private static bool $currentUserLoaded = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
        session_start();
    }

    /**
     * $identifier is whatever was typed in the login form: a username or an
     * email address. Both are accepted so the account that existed before CMS
     * users moved into the database ("admin") keeps working unchanged.
     */
    public static function attemptLogin(string $identifier, string $password): bool
    {
        $identifier = trim($identifier);

        $repository = null;
        $account = null;
        $storageAvailable = true;

        try {
            $repository = new AdminUserRepository();
            $account = $repository->findByLogin($identifier);
        } catch (\Throwable $e) {
            // Table missing (migration not run yet) or database unreachable.
            error_log('[AdminAuth] admin_users unavailable: ' . $e->getMessage());
            $storageAvailable = false;
        }

        if ($storageAvailable && $account !== null) {
            // Verified before the is_active check so a disabled account can't
            // be told apart from a wrong password.
            $passwordMatches = password_verify($password, (string) $account['password_hash']);

            if (!$passwordMatches || $account['is_active'] !== true) {
                return false;
            }

            self::rehashIfNeeded($repository, (int) $account['id'], $password, (string) $account['password_hash']);
            self::establishSession((int) $account['id'], false);

            try {
                $repository->touchLastLogin((int) $account['id']);
            } catch (\Throwable $e) {
                // A failed bookkeeping write must never cost a valid login.
                error_log('[AdminAuth] last_login_at not updated: ' . $e->getMessage());
            }

            return true;
        }

        // Spend the same time on an unknown identifier as on a known one.
        password_verify($password, self::DUMMY_HASH);

        return self::attemptBreakGlassLogin($repository, $storageAvailable, $identifier, $password);
    }

    /**
     * The .env credential, honoured only while the CMS has no active Super
     * Admin to let anybody in — see the class docblock. When the database is
     * reachable the account is (re)created from .env, so the very next login
     * is an ordinary database login.
     */
    private static function attemptBreakGlassLogin(
        ?AdminUserRepository $repository,
        bool $storageAvailable,
        string $identifier,
        string $password
    ): bool {
        if ($storageAvailable && $repository !== null) {
            try {
                if ($repository->activeSuperAdminCount() > 0) {
                    return false;
                }
            } catch (\Throwable $e) {
                error_log('[AdminAuth] super admin count unavailable: ' . $e->getMessage());
                $storageAvailable = false;
            }
        }

        $credentials = self::envCredentials();

        if ($credentials === null) {
            error_log('[AdminAuth] no admin account in the database and no ADMIN_USERNAME/ADMIN_PASSWORD_HASH in .env');
            return false;
        }

        $usernameMatches = hash_equals($credentials['username'], $identifier);
        $passwordMatches = password_verify($password, $credentials['password_hash']);

        if (!$usernameMatches || !$passwordMatches) {
            return false;
        }

        if ($storageAvailable && $repository !== null) {
            try {
                $userId = self::provisionSuperAdminFromEnv($repository, $credentials);
                self::establishSession($userId, false);
                $repository->touchLastLogin($userId);

                return true;
            } catch (\Throwable $e) {
                error_log('[AdminAuth] could not provision the first super admin: ' . $e->getMessage());
            }
        }

        // No usable storage at all: a Super Admin session with no account row
        // behind it, purely so the owner can reach the CMS and fix things.
        self::establishSession(null, true);

        return true;
    }

    /**
     * @param array{username: string, password_hash: string} $credentials
     */
    private static function provisionSuperAdminFromEnv(AdminUserRepository $repository, array $credentials): int
    {
        $existing = $repository->findByLogin($credentials['username']);

        if ($existing !== null) {
            // The account is there but was deactivated or demoted, which is
            // exactly how the CMS ends up with no Super Admin. Restore it.
            $repository->update((int) $existing['id'], [
                'name' => (string) $existing['name'],
                'username' => (string) $existing['username'],
                'email' => $existing['email'] !== null ? (string) $existing['email'] : null,
                'is_super_admin' => true,
                'is_active' => true,
            ]);

            return (int) $existing['id'];
        }

        return $repository->create([
            'name' => 'Beheerder',
            'username' => $credentials['username'],
            'email' => null,
            'password_hash' => $credentials['password_hash'],
            'is_super_admin' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Keeps stored hashes current when PHP's default algorithm/cost changes.
     * Failing to rehash is never worth failing a valid login over.
     */
    private static function rehashIfNeeded(
        ?AdminUserRepository $repository,
        int $userId,
        string $password,
        string $currentHash
    ): void {
        if ($repository === null || !password_needs_rehash($currentHash, PASSWORD_DEFAULT)) {
            return;
        }

        try {
            $repository->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
        } catch (\Throwable $e) {
            error_log('[AdminAuth] password hash not upgraded: ' . $e->getMessage());
        }
    }

    private static function establishSession(?int $userId, bool $isBreakGlass): void
    {
        self::start();
        // Prevents session fixation: a fresh session id is issued on every successful login.
        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user_id'] = $userId;

        if ($isBreakGlass) {
            $_SESSION['admin_break_glass'] = true;
        } else {
            unset($_SESSION['admin_break_glass']);
        }

        self::$currentUser = null;
        self::$currentUserLoaded = false;
    }

    /**
     * The signed-in account, re-read from the database once per request:
     * ['id', 'name', 'username', 'email', 'is_super_admin', 'is_active',
     * 'permissions' (expanded), 'granted_permissions'].
     *
     * Returns null — and ends the session — when the account has since been
     * deleted or deactivated, so revoking access takes effect immediately
     * even for someone who is already logged in.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$currentUserLoaded) {
            return self::$currentUser;
        }

        self::$currentUserLoaded = true;
        self::$currentUser = null;

        self::start();

        if (($_SESSION['admin_logged_in'] ?? false) !== true) {
            return null;
        }

        if (($_SESSION['admin_break_glass'] ?? false) === true) {
            return self::$currentUser = self::breakGlassUser();
        }

        $userId = $_SESSION['admin_user_id'] ?? null;

        if (!is_int($userId) || $userId < 1) {
            self::logout();
            return null;
        }

        try {
            $account = (new AdminUserRepository())->findById($userId);
        } catch (\Throwable $e) {
            error_log('[AdminAuth] session account could not be loaded: ' . $e->getMessage());
            self::logout();
            return null;
        }

        if ($account === null || $account['is_active'] !== true) {
            self::logout();
            return null;
        }

        return self::$currentUser = $account;
    }

    /**
     * The stand-in account for a break-glass session (see the class
     * docblock): a Super Admin with no database row behind it.
     *
     * @return array<string, mixed>
     */
    private static function breakGlassUser(): array
    {
        $credentials = self::envCredentials();

        return [
            'id' => null,
            'name' => $credentials['username'] ?? 'Beheerder',
            'username' => $credentials['username'] ?? 'admin',
            'email' => null,
            'is_super_admin' => true,
            'is_active' => true,
            'is_break_glass' => true,
            'granted_permissions' => AdminPermissions::enabled(),
            'permissions' => AdminPermissions::enabled(),
        ];
    }

    /**
     * The signed-in account's id, or null in a break-glass session. Whatever
     * later records "who changed this order" reads this.
     */
    public static function userId(): ?int
    {
        $user = self::user();

        return $user === null ? null : $user['id'];
    }

    /** Display name for the signed-in account; empty string when nobody is. */
    public static function userName(): string
    {
        $user = self::user();

        return $user === null ? '' : (string) $user['name'];
    }

    public static function isSuperAdmin(): bool
    {
        $user = self::user();

        return $user !== null && $user['is_super_admin'] === true;
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    /**
     * The authorisation question the whole CMS asks. A Super Admin always
     * answers true; everyone else is checked against their own grants, with
     * the implied ones already folded in (see App\Service\AdminPermissions).
     */
    public static function can(string $permission): bool
    {
        $user = self::user();

        return $user !== null && AdminPermissions::userHas($user, $permission);
    }

    /**
     * @param list<string> $permissions
     */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guard for HTML admin pages: redirects to the login form when not
     * logged in, and — on an installation that has never been set up — on to
     * the Setup Wizard.
     *
     * The setup redirect lives HERE and nowhere else because every admin
     * page already comes through this method (requirePermission() calls it),
     * so there is one gate rather than a line copied into forty screens.
     * It only ever fires for a from-zero installation whose wizard has not
     * been completed — never for an existing site, and never when the
     * database cannot answer (App\Install\SetupState).
     */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /admin/login.php');
            exit;
        }

        self::requireCompletedSetup();
    }

    /**
     * Sends a signed-in administrator to the Setup Wizard while the
     * installation still needs it. Never called for anything but an admin
     * HTML page.
     */
    private static function requireCompletedSetup(): void
    {
        if (!SetupState::isSetupRequired()) {
            return;
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (in_array($script, self::SETUP_EXEMPT_SCRIPTS, true)) {
            return;
        }

        header('Location: ' . self::SETUP_URL);
        exit;
    }

    /**
     * Guard for admin API endpoints: responds 401 JSON when not logged in,
     * instead of redirecting (there is no browser navigation to redirect).
     */
    public static function requireLoginForApi(): void
    {
        if (!self::isLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    /**
     * Guard for HTML admin pages: log in first, then hold $permission — or
     * get a real 403 with the CMS's own "no access" page. Hiding a sidebar
     * link is presentation; this is the enforcement.
     */
    public static function requirePermission(string $permission): void
    {
        self::requireLogin();

        if (!self::can($permission)) {
            self::denyPage();
        }
    }

    /**
     * As requirePermission(), for a page that any one of several permissions
     * may open.
     *
     * @param list<string> $permissions
     */
    public static function requireAnyPermission(array $permissions): void
    {
        self::requireLogin();

        if (!self::canAny($permissions)) {
            self::denyPage();
        }
    }

    /**
     * Guard for admin write endpoints. Deliberately mirrors the plain-text
     * 403 the CSRF check already returns, so a rejected request looks the
     * same whichever guard rejected it.
     */
    public static function requirePermissionForApi(string $permission): void
    {
        self::requireLoginForApi();

        if (!self::can($permission)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Forbidden: missing permission.');
        }
    }

    /**
     * For the few screens reserved to the owner regardless of any grant.
     */
    public static function requireSuperAdmin(): void
    {
        self::requireLogin();

        if (!self::isSuperAdmin()) {
            self::denyPage();
        }
    }

    private static function denyPage(): never
    {
        http_response_code(403);
        require dirname(__DIR__, 2) . '/admin/_forbidden.php';
        exit;
    }

    /**
     * Where to send someone straight after logging in: the first section
     * their permissions actually open. Without this, a user who may only
     * manage products would land on a dashboard they are not allowed to see.
     *
     * On an installation that has never been set up this is the wizard, so
     * the very first login lands there instead of bouncing off requireLogin()
     * one page later.
     */
    public static function landingUrl(): string
    {
        if (SetupState::isSetupRequired()) {
            return self::SETUP_URL;
        }

        return AdminNavigation::firstAccessibleUrl() ?? '/admin/index.php';
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        // Cleared rather than cached-as-null: the next user() call should
        // re-derive from the (now empty) session instead of trusting a
        // leftover in-memory answer.
        self::$currentUser = null;
        self::$currentUserLoaded = false;
    }

    /**
     * The legacy/break-glass credential pair from .env, or null when it is
     * not configured. Never logged, never returned to a browser.
     *
     * @return array{username: string, password_hash: string}|null
     */
    public static function envCredentials(): ?array
    {
        self::loadEnv();

        $username = trim((string) ($_ENV['ADMIN_USERNAME'] ?? ''));
        $hash = (string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? '');

        if ($username === '' || $hash === '' || !str_starts_with($hash, '$')) {
            return null;
        }

        return ['username' => $username, 'password_hash' => $hash];
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }

    private static function loadEnv(): void
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
