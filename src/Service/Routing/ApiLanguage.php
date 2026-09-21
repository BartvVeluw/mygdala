<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

/**
 * The website language of a JSON request from a public page.
 *
 * A page is answered in the language of its URL (dispatcher.php), but the
 * data a page's script fetches comes from /api/*.php, which has no language
 * prefix of its own. So the script says which language its page is in —
 * `<html lang>`, sent as ?lang= or as a body field — and this class decides
 * whether to believe it: only an ACTIVE website language is taken, anything
 * else (missing, malformed, unknown, switched off) is the default language.
 * The same rule api/checkout.php applies to the language an order returns to.
 *
 * It then pins RequestLanguage, so everything the endpoint reads afterwards —
 * a product's name, a label from a code catalogue — comes back in that
 * language, exactly as it would have on the page itself. The value picks
 * words and nothing else: never a price, never an identity, never a URL of
 * another origin.
 */
final class ApiLanguage
{
    /** Pin the request's language from what the browser sent, and return it. */
    public static function apply(mixed $sent): string
    {
        $code = is_string($sent) ? LanguageCode::normalise($sent) : null;

        try {
            $usable = $code !== null && SiteLanguages::isActive($code);
        } catch (\RuntimeException) {
            // The registry could not be read (and said why): words in the
            // default language are still better than no answer at all.
            $usable = false;
        }

        if (!$usable) {
            $code = LanguageResolver::defaultLanguage();
        }

        RequestLanguage::set($code, !LanguageResolver::isDefault($code));

        return $code;
    }
}
