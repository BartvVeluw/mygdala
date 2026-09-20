<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\SiteLanguages;
use App\Service\ReservedRoutes;

/**
 * THE one check for "may a slug claim this word", now that a URL can start
 * with a language (docs/multilingual/ROUTING.md).
 *
 * App\Service\ReservedRoutes answers the part of that question that is fixed
 * at build time: the root-level PHP files, the top-level directories and
 * every module's namespaces. This class adds the two things that are only
 * knowable at runtime, and hands the whole answer to
 * App\Service\PageService's slug validation so an editor is told rather than
 * left to discover the collision:
 *
 *   1. EVERY REGISTERED WEBSITE LANGUAGE CODE. A page named "en" would be
 *      indistinguishable from the English prefix — /en/over-ons would have to
 *      be both "the page `en`, sub-path over-ons" and "over-ons in English",
 *      and the dispatcher peels the prefix first, so the page would simply be
 *      unreachable. Registered, not active: switching a language off must not
 *      hand out its word as a slug that stops working the moment somebody
 *      switches it back on. Same reasoning as reserving a disabled module's
 *      names.
 *
 *   2. EVERY WORD A FIXED ROUTE SEGMENT CAN SPELL, in any language
 *      (App\Service\Routing\RouteSegments). `collecties` was already reserved
 *      by the Shop; `collections` is the same namespace in English and has to
 *      be reserved for exactly the same reason.
 *
 * Reading the language registry means reading the database, and a slug check
 * must not fatal on a database that is briefly unreachable. SiteLanguages
 * already degrades to "no languages" and logs it; the result here is then
 * simply ReservedRoutes' own answer, which is what this project did before
 * languages had rows at all.
 */
final class ReservedPaths
{
    /** May a page, a post or a collection be reachable at this single word? */
    public static function isReserved(string $segment): bool
    {
        $segment = trim($segment);

        if ($segment === '') {
            return true;
        }

        return in_array($segment, self::all(), true);
    }

    /**
     * Every reserved word, for an admin screen that wants to explain the rule
     * rather than just refuse a save.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(
            ReservedRoutes::all(),
            self::languageWords(),
            RouteSegments::allWords()
        )));
    }

    /**
     * The codes of every REGISTERED website language, active or not.
     *
     * @return list<string>
     */
    public static function languageWords(): array
    {
        $codes = [];
        foreach (SiteLanguages::all() as $language) {
            $codes[] = $language->code;
        }

        return $codes;
    }
}
