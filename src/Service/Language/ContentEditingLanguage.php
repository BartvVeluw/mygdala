<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Repository\AdminUserRepository;
use App\Service\AdminAuth;

/**
 * WHICH LANGUAGE VERSION OF THE WEBSITE CONTENT the signed-in administrator
 * is editing right now.
 *
 * This is the third of the three language states this project keeps apart,
 * and the one Multilingual V1 did not have (MULTILINGUAL.md):
 *
 *   AdminLocale              the language the CMS INTERFACE is shown in
 *   ContentEditingLanguage   the language the CONTENT FIELDS are shown in   <- here
 *   the visitor's own choice the language the PUBLIC SITE is read in
 *
 * All three are independent. An administrator may read a Dutch CMS while
 * editing the English version of a page, or an English CMS while editing the
 * Dutch version, and neither choice changes one character of what a visitor
 * sees.
 *
 * WHY IT IS EDITOR STATE AND NOT SITE CONFIGURATION. "Which language am I
 * writing in" is a property of the person at the keyboard, not of the
 * website. Deriving it from site settings — which is what V1 did — meant the
 * only way to edit English content was to change the website's own
 * configuration, which is both wrong and shared with every colleague.
 *
 * PER ACCOUNT, NOT PER BROWSER, for the same reason as AdminLocale: somebody
 * who moves between two machines should find the CMS in the state they left
 * it. A break-glass session (App\Service\AdminAuth, no database row) has
 * nowhere to store a preference and gets the site's default website
 * language — the safe, repeatable direction.
 *
 * WHICH LANGUAGES, since Multilingual 2.0 phase 2: every active language of
 * the website language registry (App\Service\Language\SiteLanguages), plus
 * the V1 languages the `_nl`/`_en` columns store, which stay editable until
 * the frontend flip whatever the registry's active flag says
 * (ContentLanguages::enabled()). A third language is therefore one row away
 * from being choosable here. A screen that can only store the V1 pair shows
 * the default language instead of it (admin/_language_fields.php); a screen
 * converted to per-language storage shows exactly this language
 * (admin/_localized_fields.php).
 *
 * NOTHING HERE READS OR WRITES AdminLocale, and nothing here writes
 * site_settings. Tests\Service\MultilingualBoundaryTest fails the build if
 * that changes.
 */
final class ContentEditingLanguage
{
    /** Column on `admin_users`; NULL means "this person has not chosen". */
    public const COLUMN = 'content_editing_language';

    private static ?string $current = null;
    private static bool $resolved = false;

    /**
     * The language whose content fields every editor screen should show,
     * resolved once per request.
     *
     * Falls back to the site's default website language whenever there is no
     * session, no stored preference, or a stored code this build cannot edit
     * content in. An editor that cannot read a preference must still render
     * a usable form.
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
            error_log('[ContentEditingLanguage] falling back to the default content language: ' . $e->getMessage());
        }

        return self::$current = self::normalise($user[self::COLUMN] ?? null);
    }

    /** True when the editor is currently writing in $code. */
    public static function is(string $code): bool
    {
        return self::current() === $code;
    }

    /** Is the editor writing in the language everything else falls back to? */
    public static function isPrimary(): bool
    {
        return self::current() === ContentLanguages::primary();
    }

    /**
     * The language an editor would translate FROM while writing the current
     * one: the site's default website language, or — when that IS the current
     * one — the other language this site publishes.
     *
     * Null on a site with only one content language, which V1 does not have
     * but the class must not assume away.
     */
    public static function source(): ?string
    {
        $current = self::current();

        foreach (ContentLanguages::enabled() as $code) {
            if ($code !== $current) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Keep only a language an administrator may choose (::choices()).
     * Anything else — null, empty, an interface-only language, a language
     * the website does not have, a malformed code — becomes the site's
     * default website language.
     */
    public static function normalise(?string $code): string
    {
        $wanted = trim((string) $code);

        foreach (self::choices() as $language) {
            if ($language->code === $wanted) {
                return $wanted;
            }
        }

        return self::defaultCode();
    }

    /**
     * What an administrator may switch between: the website's active
     * languages and the V1 pair, the default first and the rest in the
     * registry's order.
     *
     * A V1 language the registry cannot describe (an unreadable registry, or
     * a row switched off before the flip) is still offered, described by the
     * closed V1 registry, so no editor ever loses a language the old columns
     * hold.
     *
     * @return list<SiteLanguage>
     */
    public static function choices(): array
    {
        $default = self::defaultCode();
        $choices = [];

        foreach (SiteLanguages::active() as $language) {
            $choices[$language->code] = $language;
        }

        foreach (ContentLanguages::definitions() as $definition) {
            $choices[$definition->code] ??= new SiteLanguage(
                code: $definition->code,
                name: $definition->englishLabel,
                nativeName: $definition->nativeLabel,
                isDefault: $definition->code === $default,
                isActive: true,
                sortOrder: PHP_INT_MAX,
            );
        }

        $ordered = isset($choices[$default]) ? [$choices[$default]] : [];
        foreach ($choices as $code => $language) {
            if ($code !== $default) {
                $ordered[] = $language;
            }
        }

        return $ordered;
    }

    /**
     * The website's default language, or the V1 adapter's answer when the
     * registry cannot give one.
     */
    private static function defaultCode(): string
    {
        try {
            return SiteLanguages::defaultCode();
        } catch (\RuntimeException) {
            return ContentLanguages::primary();
        }
    }

    /**
     * Store a preference for one account.
     *
     * Returns the code that was actually stored, which is the normalised
     * one: an endpoint may hand over whatever the form sent, and this is
     * where it stops being request input and starts being a language code.
     */
    public static function persist(int $userId, ?string $code): string
    {
        $normalised = self::normalise($code);

        (new AdminUserRepository())->updateContentEditingLanguage($userId, $normalised);

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
     * Test seam: pretend the editor is writing in $code, without a session or
     * a database. Pass null to go back to reading the account. Always reset
     * it in tearDown().
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
