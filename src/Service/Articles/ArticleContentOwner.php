<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Module\ArticlesModule;
use App\Repository\ArticleRepository;
use App\Service\ContentOwners\ContentOwner;

/**
 * An article as the owner of content blocks: its whole body is its blocks,
 * edited on the editor's Inhoud tab through the same block list, picker and
 * block editors a page, a product, a project and a blog post use.
 * Contributed by App\Module\ArticlesModule::contentOwners(); the link table
 * is `article_content_pages` (db/migrations/20261012100000).
 *
 * Which blocks: every block that does not limit itself to other owners
 * (BlockDefinition::meta()['owners']) — so not the Paginakop and not
 * Projectinformatie. No list of allowed blocks here.
 *
 * The right is Articles' own (articles.manage); pages.manage alone does not
 * reach these blocks (ContentBlockAccess).
 */
final class ArticleContentOwner implements ContentOwner
{
    public const KIND = 'article';

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Artikel';
    }

    public function moduleKey(): string
    {
        return 'articles';
    }

    public function linkTable(): string
    {
        return 'article_content_pages';
    }

    public function linkColumn(): string
    {
        return 'article_id';
    }

    public function exists(int $ownerId): bool
    {
        return $ownerId > 0 && (new ArticleRepository())->find($ownerId) !== null;
    }

    public function name(int $ownerId): string
    {
        return ArticleLocalization::name($ownerId);
    }

    public function editUrl(int $ownerId): string
    {
        return '/admin/article.php?id=' . $ownerId . '&tab=inhoud';
    }

    public function permission(): string
    {
        return ArticlesModule::ARTICLES_MANAGE;
    }
}
