<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\PageContent;
use App\Service\RouteRegistry;

/**
 * The address a block prints for a URL an editor TYPED (a button, a card, a
 * gallery's "view all") in the language the page is being read in
 * (Multilingual 2.0 phase 7, docs/multilingual/ROUTING.md).
 *
 * An editor types an address once, as it reads on the default-language site:
 * '/contact', '/shop.php', '/over-ons#team'. Printed as typed, every such
 * button sends a visitor on /en/ back into the default language. This class
 * reads the typed address the way the link picker's stored links are read
 * (App\Service\LinkResolver) and gives it back in the request's language —
 * and anything it cannot place exactly, it leaves exactly as typed:
 *
 *   - an external address (a scheme, '//host', mailto:, tel:)   as typed
 *   - '#anchor' or '?query' on the same page                      as typed
 *   - a path that already names a website language ('/en/...')   as typed
 *   - '/' — the site root                                         that language's home
 *   - the path of a published default-language page              that page in this
 *     ('/<slug>', or '/<parent>/<slug>' when it is nested)
 *                                                                 language, or its
 *                                                                 default address when
 *                                                                 it has no version here
 *   - the address of a registered route ('/shop.php', '/blog')    that route in this
 *                                                                 language, exactly as a
 *                                                                 menu link to it
 *   - anything else                                               as typed
 *
 * The query string and the fragment travel along unchanged. Nothing is
 * stored: the editor's words stay what they typed, and the translation of the
 * address happens per render, so a later slug change or a new translation
 * follows through by itself. No naive '/en' . $path: a path this class does
 * not recognise is a path it does not rewrite.
 */
final class TypedLink
{
    /** The href to print for $typed, in $language (the request's when null). */
    public static function href(string $typed, ?string $language = null): string
    {
        $typed = trim($typed);
        if ($typed === '' || !str_starts_with($typed, '/') || str_starts_with($typed, '//')) {
            return $typed;
        }

        $language ??= RequestLanguage::current();
        if (LanguageResolver::isDefault($language)) {
            return $typed;
        }

        $cut = strcspn($typed, '?#');
        $path = substr($typed, 0, $cut);
        $suffix = substr($typed, $cut);

        [$bare, $prefixLanguage] = LocalizedUrl::strip($path);
        if ($prefixLanguage !== null) {
            return $typed;
        }

        if ($bare === '/') {
            return LocalizedUrl::home($language) . $suffix;
        }

        $segments = array_values(array_filter(explode('/', $bare), static fn (string $segment): bool => $segment !== ''));

        // A page's whole default-language path, a nested one included
        // (/metaal-graveren/rvs-graveren): the same check pagina.php makes, so
        // only an address that really is that page is translated.
        $page = PageContent::forPath(array_map('rawurldecode', $segments), LanguageResolver::defaultLanguage());
        if ($page !== null) {
            return PageContent::publicUrl($page, $language) . $suffix;
        }

        foreach (RouteRegistry::all() as $route) {
            $url = (string) ($route['url'] ?? '');
            if ($url !== '' && rtrim($url, '/') === rtrim($bare, '/')) {
                return LocalizedUrl::path($url, $language) . $suffix;
            }
        }

        return $typed;
    }
}
