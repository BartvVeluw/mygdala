<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Module\BlogModule;
use App\Repository\BlogPostRepository;
use App\Service\ContentOwners\ContentOwner;

/**
 * A blog post as the owner of content blocks (Blog 2.0): the blocks of a
 * post in blocks mode (BlogContentMode), edited on the editor's Inhoud tab
 * through the same block list, picker and block editors a page, a product
 * and a project use. Contributed by App\Module\BlogModule::contentOwners();
 * the link table is `blog_post_content_pages` (db/migrations/20261011100000).
 *
 * The right is the Blog's own: whoever may manage posts manages their
 * blocks, and pages.manage alone does not (ContentBlockAccess).
 */
final class BlogPostContentOwner implements ContentOwner
{
    public const KIND = 'blog_post';

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Blogbericht';
    }

    public function moduleKey(): string
    {
        return 'blog';
    }

    public function linkTable(): string
    {
        return 'blog_post_content_pages';
    }

    public function linkColumn(): string
    {
        return 'blog_post_id';
    }

    public function exists(int $ownerId): bool
    {
        return $ownerId > 0 && (new BlogPostRepository())->find($ownerId) !== null;
    }

    public function name(int $ownerId): string
    {
        return BlogLocalization::postName($ownerId);
    }

    public function editUrl(int $ownerId): string
    {
        return '/admin/blog-post.php?id=' . $ownerId . '&tab=inhoud';
    }

    public function permission(): string
    {
        return BlogModule::BLOG_MANAGE;
    }
}
