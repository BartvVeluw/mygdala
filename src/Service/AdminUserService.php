<?php

namespace App\Service;

use App\Repository\AdminUserRepository;

/**
 * Every rule about creating and changing a CMS user account lives here, so
 * it holds no matter which endpoint or screen reaches it — the two API
 * endpoints (api/admin/create-admin-user.php, update-admin-user.php) only
 * do the guard order the rest of the CMS does (login, permission, POST,
 * CSRF) and then hand the raw input to this class.
 *
 * The actor is passed in as an array rather than read from the session, so
 * these rules are testable without a browser and so a future CLI/recovery
 * path can reuse them unchanged.
 *
 * The safeguards, in one place:
 *
 *  - Only a Super Admin may create or edit another Super Admin.
 *  - Only a Super Admin may set is_super_admin, or grant/revoke the
 *    permissions in AdminPermissions::SUPER_ADMIN_GRANTABLE_ONLY. Anyone
 *    else's attempt is ignored (the stored value stays what it was) and
 *    logged — a crafted POST can therefore never widen its own author's
 *    reach.
 *  - Nobody edits their own is_active, is_super_admin or permissions. Not
 *    even a Super Admin: that is the single rule that makes "a user cannot
 *    grant themselves anything" true without exception, and it also stops
 *    the owner from accidentally locking themselves out.
 *  - The last active Super Admin can neither be deactivated nor demoted.
 *
 * Passwords are handled only as password_hash() output. A blank password on
 * an edit means "leave it alone"; there is no way to read an existing one
 * back out, and nothing here logs a password or a hash.
 */
class AdminUserService
{
    /**
     * Long enough to matter for an account that can change a live webshop,
     * short enough that nobody needs a generator. bcrypt only reads the
     * first 72 bytes, hence the upper bound.
     */
    public const PASSWORD_MIN_LENGTH = 12;
    public const PASSWORD_MAX_LENGTH = 72;

    private const USERNAME_PATTERN = '/^[a-zA-Z0-9._-]{3,60}$/';

    private AdminUserRepository $repository;

    public function __construct(?AdminUserRepository $repository = null)
    {
        $this->repository = $repository ?? new AdminUserRepository();
    }

    /**
     * @param array<string, mixed> $actor  AdminAuth::user()
     * @param array<string, mixed> $input  raw $_POST
     * @return int the new account's id
     *
     * @throws AdminUserForbiddenException
     * @throws AdminUserValidationException
     */
    public function create(array $actor, array $input): int
    {
        $this->assertMayManageUsers($actor);

        $fields = self::normalizeInput($input);
        $errors = $this->validateFields($fields, null);

        $password = (string) $fields['password'];
        if ($password === '') {
            $errors[] = 'Kies een wachtwoord voor de nieuwe gebruiker.';
        } else {
            array_push($errors, ...$this->validatePassword($password, (string) $fields['password_confirmation'], $fields));
        }

        if ($errors !== []) {
            throw new AdminUserValidationException($errors);
        }

        $isSuperAdmin = $this->resolveSuperAdminFlag($actor, null, (bool) $fields['is_super_admin']);
        $permissions = $this->resolvePermissions($actor, null, $fields['permissions'], $isSuperAdmin);

        $userId = $this->repository->create([
            'name' => (string) $fields['name'],
            'username' => (string) $fields['username'],
            'email' => (string) $fields['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_super_admin' => $isSuperAdmin,
            'is_active' => (bool) $fields['is_active'],
        ]);

        $this->repository->setPermissions($userId, $permissions);

        return $userId;
    }

    /**
     * @param array<string, mixed> $actor  AdminAuth::user()
     * @param array<string, mixed> $input  raw $_POST
     *
     * @throws AdminUserForbiddenException
     * @throws AdminUserValidationException
     */
    public function update(array $actor, int $targetId, array $input): void
    {
        $this->assertMayManageUsers($actor);

        $target = $this->repository->findById($targetId);

        if ($target === null) {
            throw new AdminUserValidationException(['Gebruiker niet gevonden.']);
        }

        // A users.manage holder manages colleagues, not the owner.
        if ($target['is_super_admin'] === true && ($actor['is_super_admin'] ?? false) !== true) {
            throw new AdminUserForbiddenException('Alleen een Super Admin kan een Super Admin wijzigen.');
        }

        $fields = self::normalizeInput($input);
        $errors = $this->validateFields($fields, $targetId);

        $password = (string) $fields['password'];
        if ($password !== '') {
            array_push($errors, ...$this->validatePassword($password, (string) $fields['password_confirmation'], $fields));
        }

        if ($errors !== []) {
            throw new AdminUserValidationException($errors);
        }

        $isSelf = $this->isSelf($actor, $targetId);

        $isSuperAdmin = $isSelf
            ? $target['is_super_admin'] === true
            : $this->resolveSuperAdminFlag($actor, $target, (bool) $fields['is_super_admin']);

        $isActive = $isSelf ? $target['is_active'] === true : (bool) $fields['is_active'];

        $permissions = $isSelf
            ? $target['granted_permissions']
            : $this->resolvePermissions($actor, $target, $fields['permissions'], $isSuperAdmin);

        if ($isSelf) {
            $this->logIgnoredSelfEscalation($actor, $target, $fields);
        }

        $this->assertLastSuperAdminSurvives($target, $isSuperAdmin, $isActive);

        $this->repository->update($targetId, [
            'name' => (string) $fields['name'],
            'username' => (string) $fields['username'],
            'email' => (string) $fields['email'],
            'is_super_admin' => $isSuperAdmin,
            'is_active' => $isActive,
        ]);

        $this->repository->setPermissions($targetId, $permissions);

        if ($password !== '') {
            $this->repository->updatePasswordHash($targetId, password_hash($password, PASSWORD_DEFAULT));
        }
    }

    /**
     * Trims and types every field this service understands, so validation
     * and persistence never touch $_POST directly.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeInput(array $input): array
    {
        $string = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        return [
            'name' => $string('name'),
            'username' => $string('username'),
            'email' => $string('email'),
            // Not trimmed: leading/trailing spaces are legitimate password characters.
            'password' => is_string($input['password'] ?? null) ? $input['password'] : '',
            'password_confirmation' => is_string($input['password_confirmation'] ?? null) ? $input['password_confirmation'] : '',
            'is_active' => !empty($input['is_active']),
            'is_super_admin' => !empty($input['is_super_admin']),
            'permissions' => AdminPermissions::sanitize($input['permissions'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private function validateFields(array $fields, ?int $excludeId): array
    {
        $errors = [];

        $name = (string) $fields['name'];
        if ($name === '') {
            $errors[] = 'Naam is verplicht.';
        } elseif (mb_strlen($name) > 120) {
            $errors[] = 'Naam mag maximaal 120 tekens lang zijn.';
        }

        $username = (string) $fields['username'];
        if ($username === '') {
            $errors[] = 'Gebruikersnaam is verplicht.';
        } elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors[] = 'Gebruikersnaam mag alleen letters, cijfers, punt, streepje en underscore bevatten (3 tot 60 tekens).';
        } elseif ($this->repository->usernameExists($username, $excludeId)) {
            $errors[] = 'Deze gebruikersnaam is al in gebruik.';
        }

        $email = (string) $fields['email'];
        if ($email === '') {
            $errors[] = 'E-mailadres is verplicht.';
        } elseif (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Vul een geldig e-mailadres in.';
        } elseif ($this->repository->emailExists($email, $excludeId)) {
            $errors[] = 'Dit e-mailadres is al in gebruik.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private function validatePassword(string $password, string $confirmation, array $fields): array
    {
        $errors = [];
        $length = strlen($password);

        if ($length < self::PASSWORD_MIN_LENGTH) {
            $errors[] = 'Wachtwoord moet minimaal ' . self::PASSWORD_MIN_LENGTH . ' tekens lang zijn.';
        } elseif ($length > self::PASSWORD_MAX_LENGTH) {
            // bcrypt ignores everything past 72 bytes, so a longer password
            // would silently be shorter than it looks.
            $errors[] = 'Wachtwoord mag maximaal ' . self::PASSWORD_MAX_LENGTH . ' tekens lang zijn.';
        }

        if (!hash_equals($password, $confirmation)) {
            $errors[] = 'De twee wachtwoorden zijn niet gelijk — herhaal het wachtwoord in het tweede veld.';
        }

        foreach (['username', 'email'] as $key) {
            $value = (string) $fields[$key];
            if ($value !== '' && strcasecmp($password, $value) === 0) {
                $errors[] = 'Wachtwoord mag niet gelijk zijn aan de gebruikersnaam of het e-mailadres.';
                break;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function assertMayManageUsers(array $actor): void
    {
        $isSuperAdmin = ($actor['is_super_admin'] ?? false) === true;
        $permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];

        if (!$isSuperAdmin && !in_array(AdminPermissions::USERS_MANAGE, $permissions, true)) {
            throw new AdminUserForbiddenException('Je hebt geen rechten om CMS-gebruikers te beheren.');
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function isSelf(array $actor, int $targetId): bool
    {
        $actorId = $actor['id'] ?? null;

        return is_int($actorId) && $actorId === $targetId;
    }

    /**
     * Only a Super Admin decides who else is one. For anybody else the
     * submitted checkbox is ignored entirely: a new account stays a normal
     * account, an existing one keeps the flag it already had.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $target
     */
    private function resolveSuperAdminFlag(array $actor, ?array $target, bool $requested): bool
    {
        if (($actor['is_super_admin'] ?? false) === true) {
            return $requested;
        }

        $current = $target !== null && $target['is_super_admin'] === true;

        if ($requested !== $current) {
            self::logIgnoredEscalation($actor, $target, 'is_super_admin');
        }

        return $current;
    }

    /**
     * The permission list to store. Restricted permissions (users.manage)
     * are only ever taken from the request when a Super Admin made it;
     * otherwise they are carried over from the account's current grants, so
     * a non-Super-Admin can neither hand them out nor take them away.
     *
     * A Super Admin account stores no rows at all: it holds everything by
     * definition, and keeping stale checkboxes around would make a later
     * demotion silently grant whatever was ticked before.
     *
     * A grant for a module that is currently switched off is carried over
     * unchanged, for the same reason users.manage is: the form could not
     * render it, so the request says nothing about it, and treating silence
     * as "unticked" would erase a colleague's product rights the first time
     * anyone edited their account on a CMS-only deployment. See
     * App\Service\AdminPermissions.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $target
     * @param list<string> $requested already sanitised against the registry
     * @return list<string>
     */
    private function resolvePermissions(array $actor, ?array $target, array $requested, bool $isSuperAdmin): array
    {
        if ($isSuperAdmin) {
            return [];
        }

        $current = $target !== null ? $target['granted_permissions'] : [];

        $disabledModuleGrants = array_values(array_filter(
            $current,
            static fn (string $permission): bool => !AdminPermissions::isEnabled($permission)
        ));

        if (($actor['is_super_admin'] ?? false) === true) {
            return AdminPermissions::sanitize(array_merge($requested, $disabledModuleGrants));
        }

        $resolved = array_values(array_filter(
            $requested,
            static fn (string $permission): bool => !in_array($permission, AdminPermissions::SUPER_ADMIN_GRANTABLE_ONLY, true)
        ));

        $resolved = array_merge($resolved, $disabledModuleGrants);

        foreach (AdminPermissions::SUPER_ADMIN_GRANTABLE_ONLY as $restricted) {
            $held = in_array($restricted, $current, true);

            if ($held) {
                $resolved[] = $restricted;
            }

            if ($held !== in_array($restricted, $requested, true)) {
                self::logIgnoredEscalation($actor, $target, $restricted);
            }
        }

        return AdminPermissions::sanitize($resolved);
    }

    /**
     * The CMS must never end up with nobody who can manage users. Applies to
     * a Super Admin editing another Super Admin as well as to the break-glass
     * session, which has no account of its own and therefore is not covered
     * by the "nobody edits their own flags" rule.
     *
     * @param array<string, mixed> $target
     */
    private function assertLastSuperAdminSurvives(array $target, bool $isSuperAdmin, bool $isActive): void
    {
        $wasActiveSuperAdmin = $target['is_super_admin'] === true && $target['is_active'] === true;

        if (!$wasActiveSuperAdmin || ($isSuperAdmin && $isActive)) {
            return;
        }

        if ($this->repository->activeSuperAdminCount() <= 1) {
            throw new AdminUserValidationException([
                'Dit is de laatste actieve Super Admin — deactiveren of degraderen zou niemand overlaten die het CMS kan beheren.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $target
     * @param array<string, mixed> $fields
     */
    private function logIgnoredSelfEscalation(array $actor, array $target, array $fields): void
    {
        $changedFlags = (bool) $fields['is_super_admin'] !== ($target['is_super_admin'] === true)
            || (bool) $fields['is_active'] !== ($target['is_active'] === true)
            || $fields['permissions'] !== $target['granted_permissions'];

        if ($changedFlags) {
            self::logIgnoredEscalation($actor, $target, 'self-edit of own access');
        }
    }

    /**
     * Deliberately terse and free of any credential: a one-line trace that a
     * privilege change was submitted and dropped, which is exactly what a
     * future audit log would want to record.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $target
     */
    private static function logIgnoredEscalation(array $actor, ?array $target, string $what): void
    {
        error_log(sprintf(
            '[AdminUserService] ignored privilege change "%s" submitted by user #%s for user #%s',
            $what,
            var_export($actor['id'] ?? null, true),
            var_export($target['id'] ?? null, true)
        ));
    }
}
