<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Service\Routing\RequestLanguage;

/**
 * Which language a public page is printed in, and the one way a template
 * prints text that the APPLICATION CODE owns.
 *
 * ONE LANGUAGE PER RESPONSE since Multilingual 2.0 phase 7. Every language has
 * its own URL (docs/multilingual/ROUTING.md), the server renders the page in
 * the language of that URL, and the browser receives exactly that text and
 * nothing else: no `data-nl`/`data-en` pairs, no `data-lang-html` and no
 * script that rewrites the document. The language switch is a row of links to
 * the other URLs, so there is nothing left to swap in place.
 *
 * TWO KINDS OF TEXT reach a public page, and neither is decided here:
 *
 *   - words an EDITOR stored per website language arrive already resolved,
 *     from the domain API that owns them (PageLocalization,
 *     BlockLocalization, NavigationLocalization, ShopLocalization, ...), with
 *     THE fallback of App\Service\Language\LanguageFallback applied;
 *   - words the CODE owns — a checkout heading, a button, an error, the
 *     cookie banner — are written per language right where they are used,
 *     as a small closed catalogue keyed by language code, and ::pick()
 *     chooses one.
 *
 * Escaping stays with the caller for editor words: plain text through
 * htmlspecialchars(), sanitized rich text as it is. For code-owned words
 * ::escaped() is pick() plus htmlspecialchars(). Nothing here returns
 * markup.
 */
final class SiteText
{
    /**
     * The language code of the response being rendered: what `<html lang>`
     * carries and what every piece of text on the page is printed in.
     *
     * The language OF THE REQUEST (App\Service\Routing\RequestLanguage), not
     * the site's default language: /en/about-us renders English and says so.
     * On an unprefixed URL the two are the same value.
     */
    public static function documentLanguage(): string
    {
        return RequestLanguage::current();
    }

    /**
     * One piece of code-owned website text, in the language of this request.
     *
     *     SiteText::pick(['nl' => 'Winkelwagen', 'en' => 'Shopping cart'])
     *
     * The catalogue is keyed by language code, never by a fixed pair of
     * parameters: a language the code has words for is a key, and adding one
     * is adding a key — no `_de` field, no schema, no second method.
     *
     * Chosen in this order:
     *
     *   1. the request's language;
     *   2. the site's default language;
     *   3. the catalogue's first entry — the language it was written in.
     *
     * The third step is what keeps a German-default site that the code has
     * no German words for from printing an empty button: it prints the words
     * the text was written in rather than nothing. It never decides which
     * language a page is IN — that is the request's, and `<html lang>` says
     * so.
     *
     * $languageCode names another language than the request's for the one
     * case that needs it: a record kept in the site's default language (an
     * order snapshot), whatever language the visitor was reading.
     *
     * @param array<string, string> $byLanguage language code => text; at least one entry
     */
    public static function pick(array $byLanguage, ?string $languageCode = null): string
    {
        if ($byLanguage === []) {
            throw new \InvalidArgumentException('A text catalogue needs at least one language.');
        }

        $language = $languageCode ?? self::documentLanguage();
        if (isset($byLanguage[$language])) {
            return $byLanguage[$language];
        }

        $default = LanguageFallback::defaultLanguage();
        if (isset($byLanguage[$default])) {
            return $byLanguage[$default];
        }

        return (string) reset($byLanguage);
    }

    /**
     * pick(), escaped for HTML text or a double-quoted attribute: how a
     * template prints code-owned words, in any scope.
     *
     *     <label for="email"><?= SiteText::escaped(['nl' => 'E-mailadres', 'en' => 'Email address']) ?></label>
     *
     * @param array<string, string> $byLanguage language code => text; at least one entry
     */
    public static function escaped(array $byLanguage): string
    {
        return htmlspecialchars(self::pick($byLanguage), ENT_QUOTES, 'UTF-8');
    }
}
