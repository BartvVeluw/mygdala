<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Redirects\SlugChangeRedirects;

/**
 * The rules the two taxonomy screens apply: what happens to an archive's old
 * URL when its slug changes, and what deleting one means.
 *
 * WHY AN ARCHIVE RENAME REDIRECTS TOO. /blog/categorie/<slug> is a real,
 * linkable, indexable URL — it is in the sitemap, and a category's own page
 * is exactly the kind of address somebody links to from elsewhere. Renaming
 * it therefore goes through the SAME App\Service\Redirects\SlugChangeRedirects
 * a renamed page or post uses: one redirect table, one origin value, one set
 * of rules about never overwriting an editor's own row, and repeated renames
 * collapsing into a single hop (REDIRECTS.md). There is no second redirect
 * mechanism here and there will not be one.
 *
 * A TAG ARCHIVE REDIRECTS ON THE SAME TERMS, even though it is noindex: a
 * redirect is about not breaking links people already have, which is
 * independent of whether a search engine was invited to index the page.
 *
 * DELETION WRITES NOTHING. Removing a category or a tag leaves its archive
 * URL 404ing, and inventing a destination for content that is simply gone is
 * how a clean 404 becomes a soft 404 — the same decision this project already
 * made for a deleted page. An editor who wants /blog/categorie/oud to go
 * somewhere can add that redirect by hand.
 */
final class BlogTaxonomy
{
    /**
     * Keeps a renamed category archive's old URL working.
     *
     * Only when the archive was reachable before the save and still is: an
     * inactive category has no live URL to preserve, and one that is being
     * deactivated in the same save would get a redirect to a URL that 404s —
     * two dead URLs where there was one.
     */
    public static function recordCategorySlugChange(
        string $oldSlug,
        string $newSlug,
        bool $wasActive,
        bool $isActive
    ): bool {
        if (!$wasActive || !$isActive) {
            return false;
        }

        return self::record(
            BlogUrls::categoryRedirectPath($oldSlug),
            BlogUrls::categoryRedirectPath($newSlug),
            $oldSlug,
            $newSlug
        );
    }

    /**
     * The same for a tag. A tag has no active flag — it exists or it does
     * not — so there is no visibility condition to check.
     */
    public static function recordTagSlugChange(string $oldSlug, string $newSlug): bool
    {
        return self::record(
            BlogUrls::tagRedirectPath($oldSlug),
            BlogUrls::tagRedirectPath($newSlug),
            $oldSlug,
            $newSlug
        );
    }

    private static function record(string $oldPath, string $newPath, string $oldSlug, string $newSlug): bool
    {
        if (trim($oldSlug) === '' || trim($newSlug) === '' || $oldSlug === $newSlug) {
            return false;
        }

        return (new SlugChangeRedirects())->record($oldPath, $newPath);
    }
}
