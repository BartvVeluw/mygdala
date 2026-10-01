<?php

declare(strict_types=1);

namespace App\Module;

use App\Repository\ArticleRepository;
use App\Repository\ArticleTopicRepository;
use App\Service\AdminPermissions;
use App\Service\Articles\ArticleContent;
use App\Service\Articles\ArticleContentOwner;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleMediaUsage;
use App\Service\Articles\ArticlePublishable;
use App\Service\Articles\ArticleSearchProvider;
use App\Service\Articles\ArticleSeo;
use App\Service\Articles\ArticleUrls;
use App\Service\Language\AdminTranslator;
use App\Service\Language\SiteLanguages;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Publishing\PublishingClock;
use App\Service\Routing\LanguageResolver;
use App\Service\Routing\RequestLanguage;
use App\Service\Sitemap;

/**
 * Articles 1.0 (ARTICLES.md): standalone editorial content — evergreen
 * pieces that are read for what they explain, not for when they appeared —
 * as an optional module on the Publishing Engine.
 *
 * NOT THE BLOG UNDER ANOTHER NAME. It shares the infrastructure (Publishing
 * Engine, content blocks, Multilingual 2.0, SEO, Media Library, redirects,
 * owner-aware permissions, sitemap, search, link targets) and owns its own
 * data, CMS section, routes, listing, topics and type ("article"). It has no
 * feed, no date archives, no tags, no previous/next and no "related": that
 * is what makes the Blog a blog.
 *
 * Everything reaches Core through this class. With the module off it
 * contributes nothing (no menu, no permission anyone holds, no publishable
 * type, no link target, no linked image, no media usage, no sitemap entries,
 * no search results, and a 404 at every public URL through ModuleGuard) and
 * touches no row. It keeps its reserved words, as every module does.
 *
 * OFF BY DEFAULT, like the Blog: an empty /artikelen is worse than none.
 */
final class ArticlesModule extends ModuleDefinition
{
    public const ARTICLES_MANAGE = 'articles.manage';

    public function key(): string
    {
        return 'articles';
    }

    public function label(): string
    {
        return 'Artikelen';
    }

    public function description(): string
    {
        return 'Zelfstandige artikelen opgebouwd uit contentblokken, met onderwerpen en een publieke artikelenpagina.';
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    /** Two entries in the content group, after the Blog. */
    public function adminNavigationItems(): array
    {
        return [
            [
                'key' => 'articles',
                'label' => 'Artikelen',
                'url' => '/admin/articles.php',
                'icon' => 'articles',
                'permission' => self::ARTICLES_MANAGE,
                'order' => 450,
                'scripts' => ['articles.php', 'article.php'],
            ],
            [
                'key' => 'article_topics',
                'label' => 'Artikelonderwerpen',
                'url' => '/admin/article-topics.php',
                'icon' => 'article_topics',
                'permission' => self::ARTICLES_MANAGE,
                'order' => 455,
                'scripts' => ['article-topics.php'],
            ],
        ];
    }

    /**
     * ONE right. Articles has no read-only screen worth a permission of its
     * own and no approval workflow: who manages articles writes, publishes,
     * archives and deletes them, edits their blocks and their topics.
     */
    public function permissionGroups(): array
    {
        return [
            [
                'label' => 'Artikelen',
                'order' => 455,
                'permissions' => [
                    self::ARTICLES_MANAGE => [
                        'label' => 'Artikelen beheren',
                        'description' => 'Artikelen schrijven, publiceren, archiveren en verwijderen, hun contentblokken en de onderwerpen beheren. Bevat de mediabibliotheek bekijken.',
                    ],
                ],
            ],
        ];
    }

    /** Picking a featured image needs the library (MEDIA.md). */
    public function permissionImplications(): array
    {
        return [self::ARTICLES_MANAGE => [AdminPermissions::MEDIA_VIEW]];
    }

    /** The listing as a menu or footer destination, in the language being read (as the Blog). */
    public function routes(): array
    {
        return [
            'articles' => ['url' => ArticleUrls::indexPath(), 'label' => ArticleSeo::LISTING_TITLE, 'order' => 55],
        ];
    }

    /** The root word and the two templates, reserved whether the module runs or not. */
    public function reservedSlugs(): array
    {
        return [ArticleUrls::ROOT, 'articles', 'article'];
    }

    /** The topic route before the article route, so neither can shadow the other. */
    public function publicRoutes(): array
    {
        return [
            ['key' => 'articles.index', 'pattern' => '{articles.root}', 'template' => 'articles.php'],
            [
                'key' => 'articles.topic',
                'pattern' => '{articles.root}/{articles.topic}/{slug}',
                'template' => 'articles.php',
                'query' => ['topic' => 'slug'],
            ],
            [
                'key' => 'articles.article',
                'pattern' => '{articles.root}/{slug}',
                'template' => 'article.php',
                'query' => ['slug' => 'slug'],
            ],
        ];
    }

    /** Both fixed words are language: /artikelen/onderwerp/…, /en/articles/topic/…. */
    public function routeSegments(): array
    {
        return [
            ArticleUrls::ROOT_SEGMENT_KEY => ['default' => ArticleUrls::ROOT, 'en' => 'articles'],
            ArticleUrls::TOPIC_SEGMENT_KEY => ['default' => ArticleUrls::TOPIC_SEGMENT, 'en' => 'topic'],
        ];
    }

    /**
     * The listing in every active language, every listed and indexable
     * article in the languages it has a version in (with its other versions
     * as hreflang), and every topic that holds one. One query for the rows,
     * one for all their words.
     */
    public function sitemapCollectors(): array
    {
        return [
            'articles' => static function (): array {
                $now = PublishingClock::nowForSql();
                $repository = new ArticleRepository();

                $indexPaths = [];
                foreach (SiteLanguages::activeCodes() as $code) {
                    $indexPaths[$code] = ArticleUrls::indexPath(1, $code);
                }
                $entries = Sitemap::entriesForVersions($indexPaths, null);

                $rows = array_values(array_filter($repository->findListedForSitemap($now), [ArticleSeo::class, 'isIndexable']));
                ArticleLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));

                foreach ($rows as $row) {
                    foreach (Sitemap::entriesForVersions(ArticleContent::alternates((int) $row['id']), $row['updated_at'] ?? null) as $entry) {
                        $entries[] = $entry;
                    }
                }

                $counts = $repository->listedCountsByTopic($now);
                $topics = (new ArticleTopicRepository())->all();
                ArticleLocalization::preloadTopics(array_map(static fn (array $topic): int => (int) $topic['id'], $topics));

                foreach ($topics as $topic) {
                    if (($counts[(int) $topic['id']] ?? 0) < 1) {
                        continue;
                    }
                    foreach (Sitemap::entriesForVersions(ArticleContent::topicAlternates((int) $topic['id']), $topic['updated_at'] ?? null) as $entry) {
                        $entries[] = $entry;
                    }
                }

                return $entries;
            },
        ];
    }

    public function searchProviders(): array
    {
        return ['article' => new ArticleSearchProvider()];
    }

    public function contentOwners(): array
    {
        return [new ArticleContentOwner()];
    }

    public function publishables(): array
    {
        return [ArticlePublishable::TYPE => new ArticlePublishable()];
    }

    /**
     * An article as the destination of a button or a linked picture
     * (App\Service\Routing\LinkTargets). The href follows the REACHABLE rule,
     * as the article's own address does: a button to an archived article
     * keeps working, a draft or a future article renders no link. It points
     * at the version in the language being read, else the default
     * language's, else any version — a link to a real article in another
     * language beats no link; the article itself is never shown in a
     * language it does not have.
     */
    public function linkTargets(): array
    {
        return [
            ArticlePublishable::TYPE => [
                'label' => ['nl' => 'Artikel', 'en' => 'Article'],
                'order' => 25,
                'picker' => \App\Service\Routing\LinkTargets::PICKER_SEARCH,
                'choices' => static function (): array {
                    $rows = (new ArticleRepository())->findForAdmin([], 500);
                    ArticleLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));

                    $choices = [];
                    foreach ($rows as $row) {
                        $choice = ['id' => (int) $row['id'], 'label' => ArticleLocalization::name((int) $row['id'])];
                        if (!PublicationVisibility::isReachable($row['status'] ?? null, $row['published_at'] ?? null)) {
                            $choice['note'] = 'draft';
                        }
                        $choices[] = $choice;
                    }

                    return $choices;
                },
                'href' => static function (int $id): ?string {
                    if ((new ArticleRepository())->findReachableById($id, PublishingClock::nowForSql()) === null) {
                        return null;
                    }

                    $paths = ArticleContent::alternates($id);

                    return $paths[RequestLanguage::current()]
                        ?? $paths[LanguageResolver::defaultLanguage()]
                        ?? (reset($paths) ?: null);
                },
                'title' => static function (int $id, string $language): ?string {
                    $title = ArticleLocalization::word($id, ArticleLocalization::TITLE, $language);

                    return $title !== '' ? $title : (ArticleLocalization::name($id) ?: null);
                },
            ],
        ];
    }

    public function mediaUsageProviders(): array
    {
        return [new ArticleMediaUsage()];
    }

    /** An article's featured image where a block shows it as a linked picture (Detailsectie). */
    public function linkedImages(): array
    {
        return [
            ArticlePublishable::TYPE => static function (int $id): ?array {
                $row = (new ArticleRepository())->find($id);

                return $row === null || (int) ($row['featured_media_id'] ?? 0) < 1
                    ? null
                    : \App\Service\Media\BlockImage::fromOwner($row, null, 'featured_media_id', 'featured_image_path');
            },
        ];
    }

    public function dashboardCards(): array
    {
        return [
            [
                'icon' => 'articles',
                'title' => AdminTranslator::trans('dashboard.card_articles_title'),
                'desc' => AdminTranslator::trans('dashboard.card_articles_desc'),
                'href' => '/admin/articles.php',
                'cta' => AdminTranslator::trans('dashboard.card_articles_cta'),
                'permission' => self::ARTICLES_MANAGE,
                'order' => 255,
            ],
        ];
    }
}
