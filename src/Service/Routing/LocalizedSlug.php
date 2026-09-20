<?php

declare(strict_types=1);

namespace App\Service\Routing;

/**
 * THE address of one thing in one language, when part of it is stored per
 * language and part of it is not (docs/multilingual/ROUTING.md).
 *
 * Every entity this project routes by slug has two columns now: the neutral
 * one it always had (`pages.slug`, `blog_posts.slug`, `collections.slug`) and
 * the localized one phase 6 added. The rule that turns those two into a URL
 * is the same everywhere, and it is stated here once rather than three times:
 *
 *     the localized slug, when this language has one
 *     -> otherwise the NEUTRAL slug, but only for the DEFAULT language
 *     -> otherwise: no address, so no public route in this language
 *
 * WHY THE NEUTRAL COLUMN COUNTS FOR THE DEFAULT LANGUAGE. The phase-6
 * migrations keep the two byte-identical, which is what makes every existing
 * URL of every existing installation keep answering. Honouring it here keeps
 * that true for a row created AFTERWARDS by something that only wrote the
 * neutral column — a fixture, an import, a script — instead of letting it
 * become a thing that exists and cannot be visited.
 *
 * WHY NOT FOR ANY OTHER LANGUAGE. Doing it there would answer /de/over-ons
 * with the Dutch page under a German canonical URL, which is the single thing
 * this phase exists to make impossible. A language has an address when
 * somebody gave it one, and not otherwise.
 */
final class LocalizedSlug
{
    /**
     * @param string|null $localized what the translation store holds for this
     *                               language, or null when it holds nothing
     * @param string|null $neutral   the row's own language-neutral slug
     */
    public static function resolve(?string $localized, ?string $neutral, string $language): ?string
    {
        $localized = trim((string) $localized);

        if ($localized !== '') {
            return $localized;
        }

        if ($language !== LanguageResolver::defaultLanguage()) {
            return null;
        }

        $neutral = trim((string) $neutral);

        return $neutral === '' ? null : $neutral;
    }

    /**
     * The same rule read BACKWARDS: a row was found by its NEUTRAL slug — is
     * that slug really its address in this language?
     *
     * Every lookup (App\Service\PageContent::forSlug(), the Blog's and the
     * Shop's) asks the translation store first and the neutral column second.
     * That second step used to accept whatever it found, which is only right
     * while the two columns agree. They stop agreeing the moment the default
     * language changes: `pages.slug` still holds "over-ons", the new default's
     * own address is "about-us", and /over-ons answered with the English page
     * — a second URL for one version, under a slug resolve() would never have
     * produced. Found by flipping the default language on a real site.
     *
     * So the neutral match counts only when resolve() agrees with it, and a
     * row's address can never be answered two ways.
     */
    public static function answersTo(string $requested, ?string $localized, ?string $neutral, string $language): bool
    {
        return self::resolve($localized, $neutral, $language) === $requested;
    }
}
