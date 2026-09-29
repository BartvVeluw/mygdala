<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Repository\AdminUserRepository;

/**
 * Test support, never part of the application: a signed-in CMS account for a
 * test that talks to a real web server (Tests\Support\BuiltInServer).
 *
 * It signs in the way App\Service\AdminAuth does at login — the session holds
 * the account id and nothing else — and puts a CSRF token in that session the
 * way a rendered form would have, so a test can post to an endpoint through
 * every one of its guards. The same steps Tests\Service\PagePreviewAccessTest
 * takes by hand.
 *
 * Whatever it creates, forget() removes again: the accounts by exact id and
 * the sessions as signing out does.
 */
final class AdminTestSession
{
    /** App\Service\AdminAuth's session cookie. */
    public const COOKIE = 'vvl_admin_session';

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    /**
     * @param list<string> $permissions
     * @return array{0: string, 1: string} the session id and its CSRF token
     */
    public function signIn(array $permissions, bool $superAdmin = false): array
    {
        $users = new AdminUserRepository();
        $username = '__test_admin_user_' . bin2hex(random_bytes(4));

        $userId = $users->create([
            'name' => 'Testaccount',
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
            'is_super_admin' => $superAdmin,
            'is_active' => true,
        ]);
        $this->userIds[] = $userId;
        $users->setPermissions($userId, $permissions);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $csrf = bin2hex(random_bytes(32));

        session_name(self::COOKIE);
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION = ['admin_logged_in' => true, 'admin_user_id' => $userId, 'csrf_token' => $csrf];
        $sessionId = session_id();
        session_write_close();
        $_SESSION = [];
        self::shareWithWebServer($sessionId);

        $this->sessionIds[] = $sessionId;

        return [$sessionId, $csrf];
    }

    /**
     * Lets the web server in the same container open the session file.
     *
     * In the php_test container PHPUnit runs as root and Apache as www-data:
     * the file PHP just wrote is root's with mode 0600, so a request over the
     * real web server would find it unreadable and arrive signed out. Handing
     * the file to www-data fixes that; root can still read and destroy it.
     *
     * Does nothing when the test process is not root, when there is no
     * www-data account, or when the file is not where PHP's files handler
     * keeps it — which covers `php -S` (Tests\Support\BuiltInServer), where
     * the test and the server are the same user.
     */
    private static function shareWithWebServer(string $sessionId): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $webServer = posix_getpwnam('www-data');
        if ($webServer === false) {
            return;
        }

        // session.save_path may be "N;/path" or "N;MODE;/path".
        $savePath = (string) session_save_path();
        $directory = $savePath === '' ? sys_get_temp_dir() : substr($savePath, (int) strrpos(';' . $savePath, ';'));
        $file = rtrim($directory, '/') . '/sess_' . $sessionId;

        if (is_file($file)) {
            chown($file, (int) $webServer['uid']);
            chgrp($file, (int) $webServer['gid']);
        }
    }

    /**
     * What an endpoint left in the session for the screen it redirects to —
     * a list of errors, say.
     */
    public function read(string $sessionId, string $key): mixed
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name(self::COOKIE);
        session_id($sessionId);
        session_start();
        $value = $_SESSION[$key] ?? null;
        session_write_close();
        $_SESSION = [];

        return $value;
    }

    public function forget(): void
    {
        $db = Database::connection();

        foreach ($this->userIds as $id) {
            $db->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
        }

        foreach ($this->sessionIds as $sessionId) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            session_name(self::COOKIE);
            session_id($sessionId);
            session_start();
            session_destroy();
            $_SESSION = [];
        }

        $this->userIds = [];
        $this->sessionIds = [];
    }
}
