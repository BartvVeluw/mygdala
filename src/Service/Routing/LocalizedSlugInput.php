<?php

declare(strict_types=1);

namespace App\Service\Routing;

/**
 * What an editor's slug field MEANS for the language they are editing — the
 * write-side half of App\Service\Routing\LocalizedSlug
 * (docs/multilingual/ROUTING.md, §8).
 *
 * LocalizedSlug answers "where does this row live in this language" for a
 * reader. This class answers the four questions every save endpoint has to
 * ask before it writes, so that they are answered the same way everywhere:
 * five endpoints with five slightly different readings of "the field was
 * left empty" is exactly how one language ends up publishing another
 * language's URL. The category, tag and collection editors ask it; the page
 * and the post editor (api/admin/update-page.php,
 * api/admin/update-blog-post.php) still state the same rule inline.
 *
 * THE FOUR QUESTIONS
 *
 *   1. MUST this language have an address at all? Only the default language
 *      must: its address is kept byte-identical to the row's neutral `slug`
 *      column, every existing link names it, and a row without one would not
 *      be reachable. A translation without an address is an ordinary state —
 *      that language simply has no public URL yet — and demanding one would
 *      mean demanding that every translation be published the moment a word
 *      of it is written.
 *
 *   2. Should this save MAKE a first address? Only when the field was left
 *      blank, the language has no address yet, and there are words to make
 *      one from. That is the "empty field, so take the title" convention
 *      every create endpoint in this project already has, applied per
 *      language: writing the English version publishes an English URL without
 *      anybody having to think about slugs.
 *
 *   3. What goes in the NEUTRAL column? The submitted slug when the default
 *      language is being saved, and otherwise what is already there. An
 *      English rename must never silently rewrite the Dutch URL that the
 *      stored redirects and `content_key` were derived from.
 *
 *   4. What goes in the TRANSLATION row? The submitted slug, or NULL. '' is
 *      not an address: it is "this language has no public route", which is
 *      what clearing the field on a translation asks for.
 *
 * WHAT IS DELIBERATELY NOT HERE. Sanitising, uniqueness and the reserved-word
 * check stay with the domain that owns the slug — App\Service\PageService,
 * App\Service\Blog\BlogSlug, App\Service\CollectionService — because each
 * knows its own charset, its own length and its own namespace. This class
 * never touches the characters of a slug; it only says which language a slug
 * belongs to and where it is stored.
 */
final class LocalizedSlugInput
{
    /**
     * Must this language have an address? Only the default language must; see
     * question 1 above.
     */
    public static function addressIsRequired(string $language): bool
    {
        return $language !== '' && $language === LanguageResolver::defaultLanguage();
    }

    /**
     * Should this save give the language its FIRST address, made from the
     * words it was given?
     *
     * $current is the address this language has right now (null when it has
     * none) and $words the text a generated slug would be made from — the
     * title of a post, the name of a category. An address that already exists
     * is never regenerated, so renaming a thing leaves its URL where it is.
     */
    public static function needsFirstAddress(
        string $submitted,
        string $language,
        ?string $current,
        string $words
    ): bool {
        return trim($submitted) === ''
            && $language !== ''
            && !self::addressIsRequired($language)
            && $current === null
            && trim($words) !== '';
    }

    /**
     * The value the row's own language-neutral `slug` column keeps after this
     * save: the submitted one for the default language, what is stored for
     * every other; see question 3 above.
     */
    public static function neutralSlug(string $submitted, string $language, string $stored): string
    {
        $submitted = trim($submitted);

        return ($submitted !== '' && self::addressIsRequired($language)) ? $submitted : $stored;
    }

    /**
     * The value this language's translation row keeps: the submitted address,
     * or NULL for "no public route in this language"; see question 4 above.
     */
    public static function stored(string $submitted): ?string
    {
        $submitted = trim($submitted);

        return $submitted === '' ? null : $submitted;
    }
}
