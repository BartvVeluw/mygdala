<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\Language\AdminTranslator;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminPermissions;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogContent;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostMediaUsage;
use App\Service\Blog\BlogSeo;
use App\Service\Blog\BlogUrls;
use App\Service\Sitemap;

/**
 * The Blog as an optional first-party module: posts, categories, tags, the
 * public listing and its archives, the RSS feed, and its own CMS section.
 *
 * WHY IT IS A MODULE AND NOT CORE. Every site this CMS serves has pages,
 * images and forms; not every site writes articles. A blog that is always
 * present costs the sites that do not want one four sidebar entries, a
 * reserved URL namespace and a set of permissions nobody uses. So the Blog
 * contributes everything through this class and nothing anywhere else — Core
 * never names a post, a category or a tag — and it is the first module in
 * this project to start OFF (enabledByDefault() below).
 *
 * WHAT SWITCHING IT OFF DOES. Nothing to the data, exactly like the Shop
 * (MODULES.md): the six tables keep every row, the four admin screens keep
 * their files, and the module simply stops contributing. No sidebar entries,
 * no holdable permissions (so every Blog screen and endpoint refuses on the
 * permission check they already do), no /blog route in the link picker, no
 * sitemap entries, no media-usage answers, and — through
 * App\Module\ModuleGuard at the top of each public template — a 404 at every
 * Blog URL that is indistinguishable from a URL that never existed.
 *
 * ONE THING IT KEEPS WHILE OFF: its reserved slug. `blog` stays reserved
 * whether or not the module runs, because blog.php is still on disk and a CMS
 * page that claimed that slug would be permanently shadowed by it — the same
 * rule App\Service\ReservedRoutes applies to the Shop's names.
 *
 * NO CONTENT BLOCKS. V1 contributes no block type: a "latest posts" block is
 * a real feature with real design decisions, and shipping a half-considered
 * one is worse than shipping none (BLOG.md, "Bewust niet gedaan").
 */
final class BlogModule extends ModuleDefinition
{
    public const BLOG_VIEW = 'blog.view';
    public const BLOG_MANAGE = 'blog.manage';

    public function key(): string
    {
        return 'blog';
    }

    public function label(): string
    {
        return 'Blog';
    }

    public function description(): string
    {
        return 'Blogberichten met categorieën, tags, een publieke blogpagina en een RSS-feed.';
    }

    /**
     * OFF until somebody asks for it.
     *
     * The Blog is optional in the strong sense: a site that does not write
     * articles should not have to switch it off, and an empty blog with a
     * live /blog URL is worse than no blog at all. Existing deployments are
     * unaffected — they have no stored preference for a module that did not
     * exist, and this is precisely the case where the code default decides
     * (App\Module\ModuleConfig).
     *
     * The Shop's default is untouched by this and stays ON: symmetry is not a
     * reason to change what a running site does.
     */
    public function enabledByDefault(): bool
    {
        return false;
    }

    /**
     * The CMS's Blog area. Four entries rather than a nested menu, because
     * this sidebar is deliberately flat — it groups by spacing, not by
     * headings (admin/_header.php) — so each entry names itself. They sit in
     * the 400 group beside Portfolio, the other collection of content items
     * that is not a page.
     *
     * "Nieuw bericht" is not one of them: this CMS creates from an overview
     * (Pagina's, Formulieren, Producten all do), and admin/blog.php carries
     * that button.
     */
    public function adminNavigationItems(): array
    {
        return [
            [
                'key' => 'blog_posts',
                'label' => 'Blogberichten',
                'url' => '/admin/blog.php',
                'icon' => 'blog',
                'permission' => self::BLOG_VIEW,
                'order' => 410,
                'scripts' => ['blog.php', 'blog-post.php'],
            ],
            [
                'key' => 'blog_categories',
                'label' => 'Blogcategorieën',
                'url' => '/admin/blog-categories.php',
                'icon' => 'blog_categories',
                'permission' => self::BLOG_MANAGE,
                'order' => 420,
                'scripts' => ['blog-categories.php'],
            ],
            [
                'key' => 'blog_tags',
                'label' => 'Blogtags',
                'url' => '/admin/blog-tags.php',
                'icon' => 'blog_tags',
                'permission' => self::BLOG_MANAGE,
                'order' => 430,
                'scripts' => ['blog-tags.php'],
            ],
            [
                'key' => 'blog_settings',
                'label' => 'Bloginstellingen',
                'url' => '/admin/blog-settings.php',
                'icon' => 'blog_settings',
                'permission' => self::BLOG_MANAGE,
                'order' => 440,
                'scripts' => ['blog-settings.php'],
            ],
        ];
    }

    /**
     * Two permissions, the split this CMS already uses everywhere a section
     * has a read-only screen: look at the posts, or change them.
     *
     * There is deliberately no third "may publish" permission. This project
     * keeps as many permissions as the sidebar has kinds of section, and a
     * separate publish right is only worth its weight on a site with an
     * editorial approval workflow — which V1 explicitly does not have
     * (BLOG.md). Adding one later takes one constant and one group entry.
     */
    public function permissionGroups(): array
    {
        return [
            [
                'label' => 'Blog',
                'order' => 450,
                'permissions' => [
                    self::BLOG_VIEW => [
                        'label' => 'Blog bekijken',
                        'description' => 'Het berichtenoverzicht inzien, zonder iets te kunnen wijzigen.',
                    ],
                    self::BLOG_MANAGE => [
                        'label' => 'Blog beheren',
                        'description' => 'Berichten schrijven, publiceren en verwijderen, en categorieën, tags en bloginstellingen beheren. Bevat automatisch "Blog bekijken" en de mediabibliotheek.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Managing the Blog includes reading it, and it includes picking a
     * featured image — the same reason pages.manage and portfolio.manage
     * carry media.view (MEDIA.md).
     */
    public function permissionImplications(): array
    {
        return [
            self::BLOG_MANAGE => [self::BLOG_VIEW, AdminPermissions::MEDIA_VIEW],
        ];
    }

    /**
     * The Blog index as a menu/footer destination. Only the index: an
     * administrator links to "the blog", and a link to one post belongs in
     * that post's own content, not in the site's navigation.
     *
     * Nothing forces this into the menu. Whether /blog appears there is the
     * Navigation manager's decision like any other link, and switching the
     * module off makes the route disappear from the picker while an existing
     * link simply stops resolving (App\Service\LinkResolver) rather than
     * pointing at a 404.
     */
    public function routes(): array
    {
        return [
            'blog' => ['url' => BlogUrls::indexPath(), 'label_nl' => 'Blog', 'label_en' => 'Blog', 'order' => 50],
        ];
    }

    /**
     * The URL word this module owns, reserved whether or not it is enabled —
     * see this class's docblock and ModuleDefinition::reservedSlugs().
     *
     * The three root-level templates are reserved by name as well, so a CMS
     * page can never be created behind a file that would shadow it. Only
     * `blog` is a URL anyone visits; the other two are the templates its
     * rewrites point at.
     */
    public function reservedSlugs(): array
    {
        return [BlogUrls::ROOT, 'blog-post', 'blog-feed'];
    }

    /**
     * The five URL shapes this module serves — the same five that used to be
     * five `RewriteRule`s in `.htaccess`, in the same order and with the same
     * meaning (App\Service\Routing\RouteTable).
     *
     * Order is what keeps them apart: `feed.xml` and the two archive
     * namespaces are matched before the two-segment post route, so a post can
     * never be shadowed by one of them nor shadow one of them.
     */
    public function publicRoutes(): array
    {
        return [
            ['key' => 'blog.feed', 'pattern' => '{blog.root}/feed.xml', 'template' => 'blog-feed.php'],
            ['key' => 'blog.index', 'pattern' => '{blog.root}', 'template' => 'blog.php'],
            [
                'key' => 'blog.category',
                'pattern' => '{blog.root}/{blog.category}/{slug}',
                'template' => 'blog.php',
                'query' => ['category' => 'slug'],
            ],
            [
                'key' => 'blog.tag',
                'pattern' => '{blog.root}/{blog.tag}/{slug}',
                'template' => 'blog.php',
                'query' => ['tag' => 'slug'],
            ],
            [
                'key' => 'blog.post',
                'pattern' => '{blog.root}/{slug}',
                'template' => 'blog-post.php',
                'query' => ['slug' => 'slug'],
            ],
        ];
    }

    /**
     * `blog` and `tag` are the same word in Dutch and in English and have no
     * per-language entry; `categorie` is a Dutch word a visitor reads as
     * language, so English gets its own.
     */
    public function routeSegments(): array
    {
        return [
            'blog.root' => ['default' => BlogUrls::ROOT],
            'blog.category' => ['default' => BlogUrls::CATEGORY_SEGMENT, 'en' => 'category'],
            'blog.tag' => ['default' => BlogUrls::TAG_SEGMENT],
        ];
    }

    /**
     * The Blog's sitemap entries: the index, every indexable public post, and
     * every active category archive that actually holds one.
     *
     * The post rule is BlogSeo::isIndexable(), which is the same call the
     * post's own <meta name="robots"> is rendered from — so the sitemap and
     * the page cannot contradict each other, exactly as Core's page collector
     * uses App\Service\PageSeo::isIndexable().
     *
     * Tag archives are deliberately absent: they are noindex,follow (see
     * BlogSeo). An empty category is absent too — a sitemap entry for a
     * listing with nothing in it is an invitation to index a blank page.
     */
    public function sitemapCollectors(): array
    {
        return [
            'blog' => static function (): array {
                $now = BlogClock::nowForSql();
                $posts = new BlogPostRepository();

                /**
                 * ONE <url> PER LANGUAGE VERSION since Multilingual 2.0
                 * phase 6 (docs/multilingual/ROUTING.md), each carrying the
                 * others as hreflang alternates.
                 *
                 * The index exists in every active language: it is a listing,
                 * not a slug, so there is no address that could be missing. A
                 * POST is listed only in the languages it really has an
                 * address in — a sitemap entry for a URL that 404s is worse
                 * than no entry.
                 */
                $indexPaths = [];
                foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
                    $indexPaths[$code] = BlogUrls::indexPath(1, $code);
                }

                $entries = Sitemap::entriesForVersions($indexPaths, null);

                $sitemapPosts = $posts->findPublicForSitemap($now);

                // Every post's addresses in ONE query rather than one per
                // post (docs/multilingual/ROUTING.md, "Querygedrag").
                BlogLocalization::posts()->preload(
                    array_map(static fn (array $post): int => (int) ($post['id'] ?? 0), $sitemapPosts)
                );

                foreach ($sitemapPosts as $post) {
                    if (!BlogSeo::isIndexable($post)) {
                        continue;
                    }

                    foreach (
                        Sitemap::entriesForVersions(
                            BlogContent::postAlternates($post),
                            $post['updated_at'] ?? null
                        ) as $entry
                    ) {
                        $entries[] = $entry;
                    }
                }

                $counts = $posts->publicCountsByCategory($now);
                $sitemapCategories = (new BlogCategoryRepository())->allActive();

                BlogLocalization::categories()->preload(
                    array_map(static fn (array $category): int => (int) $category['id'], $sitemapCategories)
                );

                foreach ($sitemapCategories as $category) {
                    if (($counts[(int) $category['id']] ?? 0) < 1) {
                        continue;
                    }

                    $categoryPaths = [];
                    foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
                        $slug = BlogLocalization::categorySlug($category, $code);

                        if ($slug !== null) {
                            $categoryPaths[$code] = BlogUrls::categoryPath($slug, 1, $code);
                        }
                    }

                    foreach (
                        Sitemap::entriesForVersions($categoryPaths, $category['updated_at'] ?? null) as $entry
                    ) {
                        $entries[] = $entry;
                    }
                }

                return $entries;
            },
        ];
    }

    /**
     * How the Media Library learns that a post is using an image, without
     * Core ever naming a blog post (MEDIA.md).
     */
    public function mediaUsageProviders(): array
    {
        return [new BlogPostMediaUsage()];
    }

    /**
     * One dashboard card, next to the other content sections. The Blog's
     * frontend assets are NOT here and never will be: they belong to the two
     * Blog routes, which ask App\Service\PageAssets for them, so no page
     * outside /blog downloads a byte of blog CSS.
     */
    public function dashboardCards(): array
    {
        return [
            [
                'icon' => 'blog',
                'title' => AdminTranslator::trans('dashboard.card_blog_title'),
                'desc' => AdminTranslator::trans('dashboard.card_blog_desc'),
                'href' => '/admin/blog.php',
                'cta' => AdminTranslator::trans('dashboard.card_blog_cta'),
                'permission' => self::BLOG_VIEW,
                'order' => 250,
            ],
        ];
    }
}
