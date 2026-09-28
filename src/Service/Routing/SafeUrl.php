<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\AdminTranslator;

/**
 * THE rule for an address an editor types (Pages & Destinations 3.0): a
 * block button's own address (App\Service\Routing\LinkChoice), a menu or
 * footer link's (App\Service\LinkResolver), the typed button address of the
 * Contactkaart, the Detailsectie and the Galerij, and the first gate of a
 * redirect target and a social profile. One rule, so no field can let
 * through what another refuses.
 *
 * WHAT IT GUARDS AGAINST is an address that runs code in a visitor's browser
 * (javascript:, data:, vbscript: …) or that turns into one on its way there.
 * A browser drops C0 control characters and spaces around an address and TAB,
 * LF and CR inside it before it reads the scheme, so "\x01javascript:" and
 * "java\tscript:" arrive as javascript:. Hence:
 *
 *   1. normalise() trims ASCII whitespace from both ends, like every
 *      endpoint's trim() already did — the value that is stored;
 *   2. problem() refuses ANY control character left anywhere in it, instead
 *      of guessing what a browser would make of it;
 *   3. a scheme, when there is one, must be on the list; an address without
 *      one (/contact, #form, ?q=1, contact.php, //cdn.example.com) is
 *      relative and fine.
 *
 * Output still goes through htmlspecialchars(), and TypedLink::href() and
 * LinkResolver ask isSafe() again at render time, so a value stored before
 * this rule existed never becomes a link either.
 *
 * NOT HERE: what a field wants beyond safety — a menu link wanting an
 * absolute http(s) address or a root-relative path, a social profile wanting
 * its network's own host. Those callers add their own rule after this one.
 */
final class SafeUrl
{
    /** What a button or typed link may start with: the web, e-mail and a phone number. */
    public const SCHEMES_LINK = ['http', 'https', 'mailto', 'tel'];

    /** The web only: a redirect, a menu link's own address, a social profile. */
    public const SCHEMES_WEB = ['http', 'https'];

    /** Why an address was refused: the three answers problem() can give. */
    public const PROBLEM_EMPTY = 'empty';
    public const PROBLEM_CONTROL = 'control';
    public const PROBLEM_SCHEME = 'scheme';

    /** The stored form: what was typed without the whitespace around it. */
    public static function normalise(string $raw): string
    {
        return trim($raw);
    }

    /**
     * Why this (normalised) address may not be stored, or null.
     *
     * @param list<string> $schemes the schemes this field allows
     */
    public static function problem(string $url, array $schemes = self::SCHEMES_LINK): ?string
    {
        if ($url === '') {
            return self::PROBLEM_EMPTY;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return self::PROBLEM_CONTROL;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $scheme) === 1
            && !in_array(strtolower($scheme[1]), $schemes, true)) {
            return self::PROBLEM_SCHEME;
        }

        return null;
    }

    /**
     * Is a stored value safe to print as a link? The render-time half of the
     * rule, for a value that may predate it.
     *
     * @param list<string> $schemes
     */
    public static function isSafe(string $url, array $schemes = self::SCHEMES_LINK): bool
    {
        return self::problem(self::normalise($url), $schemes) === null;
    }

    /**
     * The editor's sentence for an OPTIONAL address field (empty is fine), or
     * null: what the typed-address fields of the Contactkaart, Detailsectie
     * and Galerij say.
     *
     * @param list<string> $schemes
     */
    public static function optionalFieldMessage(string $url, array $schemes = self::SCHEMES_LINK): ?string
    {
        $problem = self::problem(self::normalise($url), $schemes);

        return match ($problem) {
            null, self::PROBLEM_EMPTY => null,
            self::PROBLEM_CONTROL => AdminTranslator::trans('link_choice.error_url_control'),
            default => AdminTranslator::trans('link_choice.error_url_scheme'),
        };
    }
}
