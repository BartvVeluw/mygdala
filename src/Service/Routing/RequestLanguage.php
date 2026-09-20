<?php

declare(strict_types=1);

namespace App\Service\Routing;

/**
 * THE language of the request being answered right now.
 *
 * One value, read by everything that renders or links: `<html lang>`, every
 * piece of editor text (App\Service\Language\SiteText), every URL an internal
 * link is built from (App\Service\Routing\LocalizedUrl), every canonical tag
 * and every breadcrumb. Static and per-request, the same convention as
 * App\Service\SiteSettings and App\Service\PageContent.
 *
 * WHO SETS IT. dispatcher.php, as soon as it has peeled the language segment
 * off the URL and before it requires a single template. Nothing else should:
 * a template that decided its own language would be a second answer to a
 * question that has one.
 *
 * WHO DOES NOT HAVE TO. A request that reaches a public template DIRECTLY —
 * /shop.php, /cart.php, /product.php?id=… are real files, and Apache serves
 * them without the dispatcher ever running — never named a language, so the
 * first read below resolves the chain itself
 * (App\Service\Routing\LanguageResolver). That is why current() is lazy
 * rather than requiring a setter to have run: there is no entrypoint in this
 * project that can be made to answer "unset".
 *
 * isFromUrl() is the one thing callers occasionally need beyond the code
 * itself: a canonical URL and a language switch behave differently for a
 * language a visitor was GIVEN and one they were GUESSED into.
 */
final class RequestLanguage
{
    private static ?string $code = null;
    private static bool $fromUrl = false;

    /**
     * The language this request is answered in.
     *
     * NOT CACHED when nothing pinned it. Resolving costs one lookup in an
     * already-cached registry (App\Service\Language\SiteLanguages), and a
     * value remembered here would be a second, staler copy of the site's
     * default language — which is exactly the kind of state that outlives the
     * thing it describes. Only an explicit set() is remembered, because only
     * then is there something to remember that cannot be derived again.
     */
    public static function current(): string
    {
        return self::$code ?? LanguageResolver::forRequest(null);
    }

    /** Did the URL itself name this language, rather than it being the default? */
    public static function isFromUrl(): bool
    {
        return self::$code !== null && self::$fromUrl;
    }

    /** Is this request answered in the language whose URLs carry no prefix? */
    public static function isDefault(): bool
    {
        return LanguageResolver::isDefault(self::current());
    }

    /**
     * Pin the language for this request. Called once, by dispatcher.php.
     *
     * $fromUrl says whether the URL named it: true for /en/…, false for an
     * unprefixed URL answered in the default language.
     */
    public static function set(string $code, bool $fromUrl): void
    {
        self::$code = $code;
        self::$fromUrl = $fromUrl;
    }

    /** Forget it, so the next read resolves again. Tests, and nothing else. */
    public static function reset(): void
    {
        self::$code = null;
        self::$fromUrl = false;
    }
}
