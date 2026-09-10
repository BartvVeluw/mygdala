<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Repository\AdminUserRepository;
use App\Service\AdminAuth;

/**
 * Which language the CMS INTERFACE runs in, for the person currently signed
 * in.
 *
 * Per admin user, not per site, because two administrators of one website
 * may genuinely read different languages — a Dutch owner and an English
 * developer working on the same shop. A site-wide setting would force one of
 * them into a language they do not speak in order to leave the other alone.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE: changing the CMS interface language
 * changes not one character of public website content. It is a preference
 * about a person, stored on that person's row, and nothing here reads or
 * writes App\Service\Language\ContentLanguages. The reverse holds too: an
 * English-primary website can be administered in Dutch.
 *
 * A break-glass session (App\Service\AdminAuth, no database row) has nowhere
 * to store a preference and gets the default. That is the safe direction:
 * break-glass exists to fix a broken installation, and it should behave the
 * same every time somebody uses it.
 */
final class AdminLocale
{
    /** Column on `admin_users`; NULL means "this person has not chosen". */
    public const COLUMN = 'interface_language';

    private static ?string $current = null;
    private static bool $resolved = false;

    /**
     * The signed-in account's CMS language, once per request.
     *
     * Falls back to the project default whenever there is no session, no
     * stored preference, or a stored code this build does not know. A CMS
     * that cannot read a preference must still render.
     */
    public static function current(): string
    {
        if (self::$resolved) {
            return (string) self::$current;
        }

        self::$resolved = true;

        $user = null;
        try {
            $user = AdminAuth::user();
        } catch (\Throwable $e) {
            error_log('[AdminLocale] falling back to the default locale: ' . $e->getMessage());
        }

        return self::$current = self::normalise($user[self::COLUMN] ?? null);
    }

    /** True when the CMS interface is running in $code right now. */
    public static function is(string $code): bool
    {
        return self::current() === $code;
    }

    /**
     * Keep only a code this CMS actually has an interface translation for.
     * Anything else — null, empty, a website-only language, a code from a
     * newer version — becomes the default.
     */
    public static function normalise(?string $code): string
    {
        $wanted = trim((string) $code);

        $definition = LanguageRegistry::get($wanted);
        if ($definition !== null && $definition->availableAsAdminLocale) {
            return $definition->code;
        }

        return LanguageRegistry::DEFAULT_LANGUAGE;
    }

    /** @return array<string, LanguageDefinition> what an account may choose from */
    public static function choices(): array
    {
        return LanguageRegistry::adminLocales();
    }

    /**
     * Store a preference for one account.
     *
     * Returns the code that was actually stored, which is the normalised one:
     * an endpoint may hand over whatever the form sent, and this is where it
     * stops being request input.
     */
    public static function persist(int $userId, ?string $code): string
    {
        $normalised = self::normalise($code);

        (new AdminUserRepository())->updateInterfaceLanguage($userId, $normalised);

        self::clearCache();

        return $normalised;
    }

    /**
     * Drop the per-request cache. Called by the endpoint that just saved a
     * preference, and by tests — the cache is static and outlives one test.
     */
    public static function clearCache(): void
    {
        self::$current = null;
        self::$resolved = false;
    }

    /**
     * Test seam: pretend the CMS interface is running in $code, without a
     * session or a database. Pass null to go back to reading the account.
     * Always reset it in tearDown().
     */
    public static function overrideForTests(?string $code): void
    {
        if ($code === null) {
            self::clearCache();

            return;
        }

        self::$current = self::normalise($code);
        self::$resolved = true;
    }
}
