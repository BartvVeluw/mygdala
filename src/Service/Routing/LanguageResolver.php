<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

/**
 * WHICH LANGUAGE this request is answered in, decided in one place
 * (docs/multilingual/ROUTING.md).
 *
 * THE CHAIN, and there is exactly one:
 *
 *   1. the language segment in the URL     an explicit, linkable, shareable
 *                                          statement, and therefore final
 *   2. the visitor's stored preference     App\Service\Routing\LanguagePreference
 *   3. `Accept-Language`                   App\Service\Routing\AcceptLanguage
 *   4. the site's default language         App\Service\Language\SiteLanguages
 *
 * Steps 2 and 3 are negotiate(); step 1 with step 4 as its fallback is
 * forRequest(). Which of the two a caller wants is not a detail: see
 * "STEPS 2 AND 3 ONLY MOVE A VISITOR AT THE SITE ROOT" below.
 *
 * Only ACTIVE website languages take part. A code that is registered but
 * switched off is skipped at every step, so switching a language off takes it
 * out of negotiation without any other change.
 *
 * STEP 1 IS ABSOLUTE. Steps 2 and 3 exist for a URL that named no language at
 * all; they can never overrule one that did. That is what makes a link
 * somebody sends you open in the language it was written in.
 *
 * STEPS 2 AND 3 ONLY MOVE A VISITOR AT THE SITE ROOT. Everywhere else an
 * unprefixed URL *is* the default language's URL and is answered as such —
 * negotiating there would make every canonical URL of the site answer
 * differently per visitor. dispatcher.php asks negotiate() for `/` and
 * nothing else, and answers with a temporary redirect so no permanent signal
 * is attached to a per-visitor decision.
 *
 * THIS IS NOT THE CMS INTERFACE LANGUAGE. That is a preference per
 * administrator (App\Service\Language\AdminLocale) with its own list and its
 * own storage, and the two never read each other.
 */
final class LanguageResolver
{
    /**
     * The language of the CURRENT request, given whatever its URL said.
     *
     * @param string|null $urlLanguage the code the URL named, or null when it
     *                                 named none
     */
    public static function forRequest(?string $urlLanguage): string
    {
        $fromUrl = self::usable($urlLanguage, SiteLanguages::activeCodes());

        // An UNPREFIXED URL is the default language's URL, full stop. It is
        // not negotiated here, and that is the whole point: /over-ons has a
        // canonical tag saying it is the Dutch version of that page, so it
        // may not quietly answer in English because this particular visitor
        // has a cookie. Steps 2 and 3 of the chain live in negotiate(), which
        // dispatcher.php asks at the site root and nowhere else — where there
        // is no canonical promise to break and the answer is a redirect
        // rather than different content at the same address.
        return $fromUrl ?? self::defaultLanguage();
    }

    /**
     * What a visitor who named no language should get: their stored
     * preference, else what their browser asks for, else the site default.
     *
     * @param list<string>|null $active the active codes, looked up when omitted
     */
    public static function negotiate(?array $active = null): string
    {
        $active ??= SiteLanguages::activeCodes();

        $stored = self::usable(LanguagePreference::stored(), $active);
        if ($stored !== null) {
            return $stored;
        }

        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        if (is_string($header)) {
            $accepted = AcceptLanguage::best($header, $active);
            if ($accepted !== null) {
                return $accepted;
            }
        }

        return self::defaultLanguage();
    }

    /**
     * The site's default language, or — when the registry cannot answer at
     * all — the one language every installation of this CMS is guaranteed to
     * have content in.
     *
     * Never throws. A public request that cannot read the language registry
     * still has to render something, and App\Service\Language\SiteLanguages
     * has already logged the reason.
     */
    public static function defaultLanguage(): string
    {
        return \App\Service\Language\LanguageFallback::defaultLanguage();
    }

    /** Is this the language whose URLs carry no prefix? */
    public static function isDefault(string $code): bool
    {
        return $code === self::defaultLanguage();
    }

    /**
     * The code in stored form when it is an active website language, else
     * null. The one filter every step of the chain runs its candidate
     * through, so no step can produce a language the site does not publish.
     *
     * @param list<string> $active
     */
    private static function usable(?string $code, array $active): ?string
    {
        $normalised = LanguageCode::normalise($code);

        return ($normalised !== null && in_array($normalised, $active, true)) ? $normalised : null;
    }
}
