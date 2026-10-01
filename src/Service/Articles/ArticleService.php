<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Repository\ArticleRepository;
use App\Service\ContentOwners\ContentPages;
use App\Service\Language\AdminTranslator;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Redirects\SlugChangeRedirects;

/**
 * The rules of the Articles admin side, where a test can reach them without
 * a browser: what may be published, and what happens to an old address. The
 * endpoints read the request and redirect; ArticleRepository writes SQL.
 */
final class ArticleService
{
    public const MAX_AUTHOR_LENGTH = 120;

    /**
     * Articles' own "can publish" (Publishable::publishErrors()), read from
     * what is STORED. An article goes out only with:
     *
     *   - a title in the default language;
     *   - an address in the default language;
     *   - at least one block that says something
     *     (ContentPages::hasMeaningfulBlocks(): not hidden, not a spacer,
     *     not an empty text).
     *
     * An image is not required, and neither is a topic: a topic is a way to
     * browse, not a condition for being readable.
     *
     * The editor's own save runs this INSIDE its transaction, after writing,
     * so it judges what the article is about to be (api/admin/update-article.php).
     *
     * @return list<string>
     */
    public static function publishErrors(int $articleId): array
    {
        $default = ArticleLocalization::defaultLanguage();
        $errors = [];

        if (trim(ArticleLocalization::word($articleId, ArticleLocalization::TITLE, $default)) === '') {
            $errors[] = AdminTranslator::trans('articles.error.publish_title');
        }

        if (ArticleLocalization::slug($articleId, $default) === null) {
            $errors[] = AdminTranslator::trans('articles.error.publish_slug');
        }

        if (!ContentPages::hasMeaningfulBlocks(ArticleContentOwner::KIND, $articleId)) {
            $errors[] = AdminTranslator::trans('articles.error.publish_blocks');
        }

        return $errors;
    }

    /**
     * Removes one article as ONE database change
     * (ContentPages::deleteOwner()): its content page with every block, the
     * blocks' words, child rows and drafts, the link, then the article row,
     * whose translations go with it by CASCADE. A failure anywhere rolls all
     * of it back, so there is never an article without its blocks nor blocks
     * without their article. Its topic stays (it belongs to every article
     * that has it); Media Library items are never deleted (MEDIA.md).
     *
     * @return bool false when there was no such article
     */
    public static function delete(int $articleId, ?ArticleRepository $articles = null): bool
    {
        $articles ??= new ArticleRepository();

        if ($articleId < 1 || $articles->find($articleId) === null) {
            return false;
        }

        $deleted = false;
        ContentPages::deleteOwner(ArticleContentOwner::KIND, $articleId, static function () use ($articles, $articleId, &$deleted): void {
            $deleted = $articles->delete($articleId);
        });
        ArticleLocalization::articles()->forget($articleId);

        return $deleted;
    }

    /**
     * Keeps a renamed article's old address working in ONE language:
     * /artikelen/oud -> /artikelen/nieuw, or /en/articles/old -> /en/articles/new.
     *
     * Only between two REACHABLE states (an archived article keeps its
     * address, so moving it keeps its links); a draft's address was never
     * live. The same SlugChangeRedirects a page and a blog post use, so
     * repeated renames collapse into one hop (REDIRECTS.md).
     *
     * @param array<string, mixed> $before the row before the save
     * @param array<string, mixed> $after  the row after it
     */
    public static function recordSlugChange(array $before, array $after, string $language, ?string $oldSlug, ?string $newSlug): bool
    {
        $oldSlug = trim((string) $oldSlug);
        $newSlug = trim((string) $newSlug);

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return false;
        }

        if (
            !PublicationVisibility::isReachable($before['status'] ?? null, $before['published_at'] ?? null)
            || !PublicationVisibility::isReachable($after['status'] ?? null, $after['published_at'] ?? null)
        ) {
            return false;
        }

        return (new SlugChangeRedirects())->record(
            ArticleUrls::articleRedirectPath($oldSlug, $language),
            ArticleUrls::articleRedirectPath($newSlug, $language),
            $language
        );
    }

    /** The same for a renamed topic. A topic has no status: its address is always live. */
    public static function recordTopicSlugChange(string $language, ?string $oldSlug, ?string $newSlug): bool
    {
        $oldSlug = trim((string) $oldSlug);
        $newSlug = trim((string) $newSlug);

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return false;
        }

        return (new SlugChangeRedirects())->record(
            ArticleUrls::topicRedirectPath($oldSlug, $language),
            ArticleUrls::topicRedirectPath($newSlug, $language),
            $language
        );
    }
}
