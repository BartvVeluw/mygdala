<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Repository\ArticleRepository;
use App\Service\Language\AdminTranslator;
use App\Service\Publishing\Publishable;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublishingClock;

/**
 * An article as the Publishing Engine sees it (docs/publishing/ARCHITECTURE.md):
 * the second kind on the engine, and the first that was built for it rather
 * than adapted to it. All four statuses; the visibility rule, the clock and
 * the validation are the engine's, unchanged.
 *
 * Contributed by App\Module\ArticlesModule::publishables(), so with Articles
 * off the type "article" does not exist to the engine.
 */
final class ArticlePublishable implements Publishable
{
    public const TYPE = 'article';

    public function __construct(private ?ArticleRepository $articles = null)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function module(): string
    {
        return 'articles';
    }

    public function permission(): string
    {
        return \App\Module\ArticlesModule::ARTICLES_MANAGE;
    }

    public function statuses(): array
    {
        return PublicationStatus::ALL;
    }

    public function publication(int $id): ?array
    {
        $article = $this->articles()->find($id);

        return $article === null ? null : [
            'status' => PublicationStatus::normalize($article['status']),
            'published_at' => $article['published_at'] === null ? null : (string) $article['published_at'],
        ];
    }

    public function publishErrors(int $id, string $status): array
    {
        if ($this->articles()->find($id) === null) {
            return [AdminTranslator::trans('publishing.error.not_found')];
        }

        return ArticleService::publishErrors($id);
    }

    /** Status and moment only: a publication change never moves an address. */
    public function savePublication(int $id, string $status, ?string $publishedAt): void
    {
        $this->articles()->updatePublication($id, $status, $publishedAt);
    }

    public function adminPath(int $id): string
    {
        return '/admin/article.php?id=' . $id;
    }

    public function publicPath(int $id, string $language): ?string
    {
        return $this->alternates($id)[$language] ?? null;
    }

    public function alternates(int $id): array
    {
        return $this->articles()->findReachableById($id, PublishingClock::nowForSql()) === null
            ? []
            : ArticleContent::alternates($id);
    }

    private function articles(): ArticleRepository
    {
        return $this->articles ??= new ArticleRepository();
    }
}
