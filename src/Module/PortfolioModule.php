<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\AdminPermissions;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\ItemGalleryContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioMediaUsage;
use App\Service\Sitemap;

/**
 * The Portfolio as an optional first-party module: the catalogue of work items
 * with their categories, each item's optional link to an ordinary CMS page,
 * the Projecten block that shows the items on any page, the addresses of the
 * old project pages, the Portfolio page and the CMS section that manages them.
 *
 * WHY IT IS A MODULE. It used to be Core, on the argument that nobody would
 * ever switch it off. A new installation of this CMS is not a portfolio site
 * by default, though, and a Portfolio that is always present costs every site
 * that does not show one a sidebar entry, a permission, two reserved URL words
 * and a gallery source nobody picks. So it contributes everything through this
 * class, exactly like App\Module\BlogModule, and Core no longer names a
 * portfolio item, a category or a project page (Tests\Module\PortfolioModuleTest).
 *
 * A PROJECT PAGE IS NOT THE MODULE'S. An item links to an ordinary CMS page by
 * id (MODULES.md, "Portfolio"), and that page — its words, SEO, canonical and
 * sitemap entry — belongs to Pages. The module owns the link, and the old
 * /portfolio/<slug> addresses, which either redirect to the linked page or
 * still show the old project page (App\Service\PortfolioGalleryContent).
 *
 * WHAT SWITCHING IT OFF DOES. Nothing to the data (MODULES.md): the five
 * tables keep every row, every item keeps its link to a page, and every
 * uploaded image stays on disk. The module simply stops contributing: no
 * sidebar entry; no holdable permission, so both admin screens and every
 * Portfolio write endpoint refuse on the permission check they already make;
 * no sitemap entries; no Projecten block to pick, and one already placed shows
 * nothing and keeps its settings; no gallery source, so a gallery block set to
 * portfolio items keeps its settings and shows nothing; and, through
 * App\Module\ModuleGuard at the top of portfolio.php and portfolio-detail.php,
 * a 404 at /portfolio.php and at every /portfolio/<slug>, redirect or not. A
 * page an item links to is an ordinary page and keeps answering at its own
 * address.
 *
 * WHAT IT KEEPS WHILE OFF: its reserved slugs, for the Blog's reason — both
 * templates are still on disk.
 *
 * WHAT DID NOT MOVE. The Portfolio's own files stay where they are
 * (src/Service/PortfolioGalleryContent.php, src/Repository/Portfolio*.php,
 * admin/portfolio*.php, api/admin/*portfolio*.php): a module owns its
 * coupling points, not a directory (MODULES.md, "Nog niet geïmplementeerd").
 */
final class PortfolioModule extends ModuleDefinition
{
    /** The permission Portfolio already had as Core. Stored on user accounts, so never renamed. */
    public const PORTFOLIO_MANAGE = 'portfolio.manage';

    /** The gallery source key stored in `item_galleries.source_type`. Never renamed either. */
    public const GALLERY_SOURCE = 'portfolio';

    public function key(): string
    {
        return 'portfolio';
    }

    public function label(): string
    {
        return 'Portfolio';
    }

    public function description(): string
    {
        return 'Een overzicht van je werk met afbeeldingen en categorieën, te tonen met een galerijblok op elke pagina.';
    }

    /**
     * OFF until somebody asks for it, like the Blog: most sites built on this
     * CMS do not show a portfolio, and a new installation should not start
     * with a section it has to learn to ignore.
     *
     * An installation that ran the Portfolio while it was still Core is not
     * affected. It never asked because it never could, so
     * 20260914170000_pin_the_portfolio_module_where_it_is_in_use.php stored
     * "on" for it, and a stored preference is read before this default
     * (App\Module\ModuleConfig).
     */
    public function enabledByDefault(): bool
    {
        return false;
    }

    /**
     * One entry, at the place Portfolio always had (400, the group of content
     * that is not a page), covering the overview and the item editor it opens.
     */
    public function adminNavigationItems(): array
    {
        return [
            [
                'key' => 'portfolio',
                'label' => 'Portfolio',
                'url' => '/admin/portfolio.php',
                'icon' => 'portfolio',
                'permission' => self::PORTFOLIO_MANAGE,
                'order' => 400,
                'scripts' => ['portfolio.php', 'portfolio-item.php'],
            ],
        ];
    }

    /**
     * The one permission Portfolio had as Core, with the same name and the
     * same words, in a group of its own now that it is not always there. It
     * follows Core's "Website" group, where it used to be listed.
     */
    public function permissionGroups(): array
    {
        return [
            [
                'label' => 'Portfolio',
                'order' => 420,
                'permissions' => [
                    self::PORTFOLIO_MANAGE => [
                        'label' => 'Portfolio beheren',
                        'description' => 'Portfolio-items, projectpagina\'s, foto\'s en categorieën.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Managing the Portfolio includes picking an image, the same rule
     * pages.manage and blog.manage follow (MEDIA.md).
     */
    public function permissionImplications(): array
    {
        return [
            self::PORTFOLIO_MANAGE => [AdminPermissions::MEDIA_VIEW],
        ];
    }

    /**
     * Both root-level templates, reserved whether or not the module runs.
     * `portfolio` is also the first segment of every old project address
     * (/portfolio/<slug>, see .htaccess); `portfolio-detail` is the template
     * that rewrite points at.
     */
    public function reservedSlugs(): array
    {
        return ['portfolio', 'portfolio-detail'];
    }

    /**
     * The Portfolio page's own template, and the old project addresses.
     * "portfolio" is the same word in Dutch and in English, so the namespace
     * has no per-language entry (App\Service\Routing\RouteSegments).
     */
    public function publicRoutes(): array
    {
        return [
            ['key' => 'portfolio.index', 'pattern' => 'portfolio.php', 'template' => 'portfolio.php'],
            [
                'key' => 'portfolio.legacy-project',
                'pattern' => '{portfolio.root}/{slug}',
                'template' => 'portfolio-detail.php',
                'query' => ['slug' => 'slug'],
            ],
        ];
    }

    public function routeSegments(): array
    {
        return [
            'portfolio.root' => ['default' => 'portfolio'],
        ];
    }

    /**
     * /portfolio.php serves the CMS page with content_key "portfolio": an
     * ordinary content page that is linked as a page, and therefore NOT one of
     * routes() (App\Service\RouteRegistry). Naming the path here is what makes
     * that page, a menu link to it and a redirect aimed at it stop resolving
     * while the module is off, instead of pointing at the 404 ModuleGuard
     * answers there.
     */
    public function publicPaths(): array
    {
        return ['/portfolio.php', '/portfolio-detail.php'];
    }

    /**
     * Every old project page that still shows itself. The Portfolio page
     * itself is a CMS page and comes from Core's pages collector, where
     * App\Service\PageSeo::isIndexable() leaves it out while this module is
     * off (publicPaths() above) — and so does every page an item links to,
     * which is how a linked project is listed once, under that page's own
     * canonical.
     *
     * The rule is PortfolioGalleryContent::legacyProjectPagesForSitemap()'s: an
     * old address that redirects is not listed, and neither is one that
     * answers 404, so the sitemap never names an address that shows no page.
     */
    public function sitemapCollectors(): array
    {
        return [
            'portfolio' => static function (): array {
                $entries = [];

                foreach (PortfolioGalleryContent::legacyProjectPagesForSitemap() as $project) {
                    // Every published language's version of the page, each
                    // naming the others, exactly as portfolio-detail.php
                    // declares them.
                    $paths = [];
                    foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
                        $paths[$code] = \App\Service\Routing\LocalizedUrl::path(
                            PortfolioGalleryContent::publicPath($project['slug']),
                            $code
                        );
                    }

                    $entries = array_merge($entries, Sitemap::entriesForVersions($paths, $project['updated_at']));
                }

                return $entries;
            },
        ];
    }

    /**
     * "Projecten": the block that shows this module's projects on an ordinary
     * page, offered only while the module runs. Switched off, it is gone from
     * the picker, a block already placed renders nothing and the page builder
     * calls it a block of a switched-off part, with every setting kept for
     * when the module is back (CONTENT-BLOCKS.md). It stores, reads and draws
     * through the gallery block, so it brings no query, card or link of its
     * own (App\Service\Blocks\ProjectCardsBlock).
     */
    public function blockDefinitions(): array
    {
        return [
            'project_cards' => ProjectCardsBlock::class,
        ];
    }

    /**
     * Portfolio items as a source for the gallery block. First in `order`, so a
     * new gallery block still starts as the portfolio grid it always was while
     * this module runs. It is the one source the block's scope setting ("all
     * visible items" or "only the ones marked for the homepage") applies to,
     * and the one with a taxonomy for the filter bar.
     */
    public function itemGallerySources(): array
    {
        return [
            self::GALLERY_SOURCE => [
                'label' => 'Portfolio-items',
                'order' => 10,
                'needs_collection' => false,
                'needs_scope' => true,
                'items' => static fn (array $settings): array => PortfolioGalleryContent::catalogueItems(
                    ($settings['portfolio_scope'] ?? '') === ItemGalleryContent::SCOPE_FEATURED
                ),
                'filter_categories' => static fn (): array => PortfolioGalleryContent::filterCategories(),
            ],
        ];
    }

    /**
     * An item's picture is a Media Library item since Media Library 2.0:
     * the library asks this module where it is used (MEDIA.md).
     */
    public function mediaUsageProviders(): array
    {
        return [new PortfolioMediaUsage()];
    }
}
