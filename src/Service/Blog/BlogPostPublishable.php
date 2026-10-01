<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogPostRepository;
use App\Service\Language\AdminTranslator;
use App\Service\Publishing\Publishable;
use App\Service\Publishing\PublishingClock;

/**
 * A blog post as the Publishing Engine sees it (docs/publishing/ARCHITECTURE.md): the Blog's
 * ADAPTER onto the shared contract, over the tables the Blog already has.
 * No column moved and no row changed for this — the engine asks, the Blog
 * answers from `blog_posts` and `blog_post_translations` as before.
 *
 * Contributed by App\Module\BlogModule::publishables(), so with the Blog off
 * the type "blog_post" does not exist to the engine at all.
 *
 * "Can publish" is what the editor already demands of a post: a title in the
 * default language and an address. A body is not required — it never was,
 * and adding it here would quietly change what the Blog allows.
 */
final class BlogPostPublishable implements Publishable
{
    public const TYPE = 'blog_post';

    public function __construct(private ?BlogPostRepository $posts = null)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function module(): string
    {
        return 'blog';
    }

    public function permission(): string
    {
        return 'blog.manage';
    }

    public function statuses(): array
    {
        return BlogPostStatus::ALL;
    }

    public function publication(int $id): ?array
    {
        $post = $this->posts()->find($id);

        if ($post === null) {
            return null;
        }

        return [
            'status' => (string) $post['status'],
            'published_at' => $post['published_at'] === null ? null : (string) $post['published_at'],
        ];
    }

    public function publishErrors(int $id, string $status): array
    {
        $post = $this->posts()->find($id);

        if ($post === null) {
            return [AdminTranslator::trans('publishing.error.not_found')];
        }

        $errors = [];

        if (trim(BlogLocalization::post($id, BlogLocalization::TITLE, BlogLocalization::defaultLanguage())) === '') {
            $errors[] = AdminTranslator::trans('publishing.error.blog_title');
        }

        if (trim((string) ($post['slug'] ?? '')) === '') {
            $errors[] = AdminTranslator::trans('publishing.error.blog_slug');
        }

        return $errors;
    }

    /**
     * Status and moment only. No redirect is due: a publication change never
     * changes a slug, and BlogPostService::recordSlugChange() writes only
     * between two public states with different slugs — the editor's save
     * does that part.
     */
    public function savePublication(int $id, string $status, ?string $publishedAt): void
    {
        $this->posts()->updatePublication($id, BlogPostStatus::normalize($status), $publishedAt);
    }

    public function adminPath(int $id): string
    {
        return '/admin/blog-post.php?id=' . $id;
    }

    public function publicPath(int $id, string $language): ?string
    {
        return $this->alternates($id)[$language] ?? null;
    }

    public function alternates(int $id): array
    {
        $post = $this->posts()->findReachableById($id, PublishingClock::nowForSql());

        return $post === null ? [] : BlogContent::postAlternates($post);
    }

    private function posts(): BlogPostRepository
    {
        return $this->posts ??= new BlogPostRepository();
    }
}
