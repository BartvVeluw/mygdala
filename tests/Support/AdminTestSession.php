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

        $this->sessionIds[] = $sessionId;

        return [$sessionId, $csrf];
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
