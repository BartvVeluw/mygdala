<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Service\Redirects\SlugChangeRedirects;

/**
 * The rules the Blog's admin side applies: what a post's fields may contain,
 * what a submitted tag line turns into, and what happens to a post's old URL
 * when its slug changes.
 *
 * The counterpart of App\Service\PageService for CMS pages, and deliberately
 * the same division of labour: the ENDPOINT reads the request and redirects,
 * the REPOSITORY writes SQL, and the decisions in between live here where a
 * test can reach them without a browser.
 */
final class BlogPostService
{
    /**
     * Matched to the column widths in the Blog migration — since Multilingual
     * 2.0 phase 5 wave B the widths of blog_post_translations, which
     * App\Service\Blog\BlogLocalization declares from these very constants.
     */
    public const MAX_TITLE_LENGTH = 200;
    public const MAX_EXCERPT_LENGTH = 500;
    public const MAX_AUTHOR_LENGTH = 120;
    public const MAX_META_TITLE_LENGTH = 255;
    public const MAX_META_DESCRIPTION_LENGTH = 500;

    /** How many tags one post may carry. A post is not a tag cloud. */
    public const MAX_TAGS_PER_POST = 12;

    /**
     * Everything wrong with a submitted post, in the order the form shows the
     * fields. An empty list means it may be saved.
     *
     * @param array<string, mixed> $values already-trimmed submitted values
     *
     * @return list<string>
     */
    public static function validate(array $values): array
    {
        $errors = [];

        // ONE LANGUAGE per save since Multilingual 2.0 phase 5 wave B, so
        // there is one set of word fields rather than a Dutch and an English
        // one. A title is required only in the DEFAULT language: a
        // translation is optional by definition, because it falls back.
        $language = (string) ($values['language_code'] ?? '');
        $title = trim((string) ($values['title'] ?? ''));

        if ($title === '' && $language === BlogLocalization::defaultLanguage()) {
            $errors[] = 'Titel is verplicht.';
        }

        foreach ([
            'Titel' => [$title, self::MAX_TITLE_LENGTH],
            'Samenvatting' => [$values['excerpt'] ?? '', self::MAX_EXCERPT_LENGTH],
            'Auteur' => [$values['author_name'] ?? '', self::MAX_AUTHOR_LENGTH],
            'SEO-titel' => [$values['meta_title'] ?? '', self::MAX_META_TITLE_LENGTH],
            'Meta description' => [$values['meta_description'] ?? '', self::MAX_META_DESCRIPTION_LENGTH],
            'Inhoud' => [$values['body'] ?? '', BlogLocalization::BODY_MAX_LENGTH],
        ] as $label => [$value, $max]) {
            if (mb_strlen(trim((string) $value)) > $max) {
                $errors[] = $label . ' mag maximaal ' . $max . ' tekens zijn.';
            }
        }

        $status = (string) ($values['status'] ?? '');
        if (!BlogPostStatus::isValid($status)) {
            $errors[] = 'Ongeldige status.';
        }

        // A scheduled post without a moment to go out at would sit invisible
        // for ever while claiming to be scheduled, which is the one state an
        // editor cannot debug from the overview.
        if ($status === BlogPostStatus::SCHEDULED && trim((string) ($values['published_at'] ?? '')) === '') {
            $errors[] = 'Een ingepland bericht heeft een publicatiedatum nodig.';
        }

        return $errors;
    }

    /**
     * The publication moment a save should store, given the status and what
     * the editor typed.
     *
     *   a typed moment                  -> that moment, whatever the status
     *   published without one           -> now, because it is going out now
     *   anything else without one       -> NULL
     *
     * The middle rule is what makes "Gepubliceerd" mean something on its own:
     * an editor who picks it and saves has published, and the date field is
     * then a refinement rather than a required extra step.
     */
    public static function resolvePublishedAt(string $status, mixed $submitted): ?string
    {
        $typed = BlogClock::fromFormInput($submitted);

        if ($typed !== null) {
            return $typed;
        }

        if (BlogPostStatus::normalize($status) === BlogPostStatus::PUBLISHED) {
            return BlogClock::nowForSql();
        }

        return null;
    }

    /**
     * A comma-separated tag line as tag ids, creating the tags that are
     * genuinely new.
     *
     * De-duplication happens twice and on purpose: once here, so "laser,
     * Laser" submitted in one line becomes one id, and once in the repository,
     * where the normalised slug decides whether a row already exists. Neither
     * is decoration — the first prevents a pointless second insert attempt,
     * the second is what keeps the tag list clean across saves and posts.
     *
     * @return list<int>
     */
    public static function resolveTagIds(string $submitted, ?BlogTagRepository $tags = null): array
    {
        $tags ??= new BlogTagRepository();

        $ids = [];
        $seenSlugs = [];

        foreach (preg_split('/[,\n]+/', $submitted) ?: [] as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $slug = BlogSlug::sanitize($name);

            if ($slug === '' || in_array($slug, $seenSlugs, true)) {
                continue;
            }

            $seenSlugs[] = $slug;

            $existing = $tags->findBySlug($slug);
            $id = $tags->findOrCreateByName($name);

            if ($id !== null) {
                $ids[] = $id;

                // A NEW tag is named in the DEFAULT language, like every new
                // row since phase 3B: its slug is made from that name, and a
                // tag that had a name in no language would render an empty
                // chip. An existing tag keeps every name it has — typing it on
                // a post is reusing it, never renaming it (that is what the
                // Blogtags screen is for).
                if ($existing === null) {
                    BlogLocalization::saveTagName($id, BlogLocalization::defaultLanguage(), $name);
                }
            }

            if (count($ids) >= self::MAX_TAGS_PER_POST) {
                break;
            }
        }

        return $ids;
    }

    /**
     * The tag line an editor sees when the editor opens a post: the names of
     * its tags, in the order the repository returns them.
     *
     * @param array<int, array<string, mixed>> $tags
     */
    public static function tagLine(array $tags): string
    {
        // What the CMS calls a tag, since its name is stored per website
        // language (Multilingual 2.0 phase 5 wave B). The editing language is
        // deliberately not used: this one line both shows the tags and, when
        // it is saved back, names them, and a tag is named in the default
        // language — see resolveTagIds().
        return implode(', ', array_map(
            static fn (array $tag): string => BlogLocalization::tagLabel((int) $tag['id']),
            $tags
        ));
    }

    /**
     * Keeps a renamed post's old URL working.
     *
     * The same four conditions api/admin/update-page.php applies to a page,
     * with "published" read as "was actually public": a draft's URL was never
     * live, and a post scheduled for next month has no old URL to preserve
     * either. Renaming a post while taking it back to draft in one save
     * writes nothing, because that would point one dead URL at another.
     *
     * It uses the SAME App\Service\Redirects\SlugChangeRedirects a page uses,
     * so there is one redirect table, one origin value, one set of rules
     * about not overwriting an editor's own row, and repeated renames
     * collapse into a single hop exactly as they do for pages (REDIRECTS.md).
     *
     * @param array<string, mixed> $before the stored row as it was
     * @param array<string, mixed> $after  the row as it has just been saved
     */
    public static function recordSlugChange(array $before, array $after): bool
    {
        $oldSlug = trim((string) ($before['slug'] ?? ''));
        $newSlug = trim((string) ($after['slug'] ?? ''));

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return false;
        }

        if (!BlogPostStatus::isPublic($before) || !BlogPostStatus::isPublic($after)) {
            return false;
        }

        return (new SlugChangeRedirects())->record(
            BlogUrls::postRedirectPath($oldSlug),
            BlogUrls::postRedirectPath($newSlug)
        );
    }

    /**
     * A unique slug for a post, from what the editor typed or from the title.
     *
     * ONE SLUG, LANGUAGE-NEUTRAL. $title is the title in the DEFAULT language,
     * so /blog/<slug> is one address whatever a visitor reads; translating a
     * post never moves it, and the old-URL redirect above keeps working
     * unchanged. A slug per language needs a router that uses it, which is
     * phase 6 of Multilingual 2.0.
     */
    public static function slugFor(
        string $submitted,
        string $title,
        BlogPostRepository $repository,
        ?int $excludeId = null
    ): string {
        return BlogSlug::unique(
            $submitted,
            $title,
            static fn (string $candidate): bool => $repository->slugExists($candidate, $excludeId)
        );
    }
}
