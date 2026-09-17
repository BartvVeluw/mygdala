<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * What a website language code may look like, decided in one place
 * (docs/multilingual/ARCHITECTURE.md).
 *
 * V1 accepts exactly two lowercase ASCII letters, the shape of an ISO 639-1
 * code. There is no list of languages here, and there must never be one: a
 * site gets a new language by adding a registry row, not by changing this
 * class. Everything else is refused rather than repaired: a region or script
 * variant (`pt-BR`, `en_GB`, `zh-Hans`), a longer code, whitespace inside, a
 * path, markup. The storage leaves room for those subtags (MAX_LENGTH), so
 * allowing them later is a change to this class and not to the schema.
 *
 * Only the SHAPE is checked here. Whether a code is a language this site has
 * is the registry's question (App\Service\Language\SiteLanguages), so a code
 * that passes here can still be unknown, and a pair of letters that is no
 * language at all passes as well.
 *
 * Not for the CMS interface language: App\Service\Language\AdminLocale keeps
 * its own list.
 */
final class LanguageCode
{
    /** The width of every stored language code column. */
    public const MAX_LENGTH = 12;

    private const PATTERN = '/\A[a-z]{2}\z/';

    /**
     * The code in its stored form, or null when it is not a valid code.
     * Surrounding whitespace and upper case are forgiven, because a form or a
     * hand-written config sends them; nothing else is.
     */
    public static function normalise(?string $code): ?string
    {
        $candidate = strtolower(trim((string) $code));

        return self::isValid($candidate) ? $candidate : null;
    }

    /** Is this exactly a code in its stored form? */
    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}
