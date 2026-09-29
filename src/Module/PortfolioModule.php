<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\AdminPermissions;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioMediaUsage;
use App\Service\PortfolioUrls;
use App\Service\Sitemap;

/**
 * The Portfolio as an optional first-party module: the catalogue of work items
 * with their categories, each item's own project page at /portfolio/<slug>,
 * the Projecten block that shows the items on any page, the Portfolio page
 * and the CMS section that manages them.
 *
 * WHY IT IS A MODULE. It used to be Core, on the argument that nobody would
 * ever switch it off. A new installation of this CMS is not a portfolio site
 * by default, though, and a Portfolio that is always present costs every site
 * that does not show one a sidebar entry, a permission, two reserved URL words
 * and a gallery source nobody picks. So it contributes everything through this
 * class, exactly like App\Module\BlogModule, and Core no longer names a
 * portfolio item, a category or a project page (Tests\Module\PortfolioModuleTest).
 *
 * A PROJECT PAGE IS THE ITEM'S OWN (Portfolio 2.0, MODULES.md "Portfolio"):
 * /portfolio/<slug> is rendered dynamically from the item by
 * portfolio-detail.php, with its own SEO, canonical and sitemap entry, and no
 * `pages` row behind it. An item that still carries a legacy link to an
 * ordinary CMS page (phase 4B) keeps redirecting there until an editor
 * unlinks it; that page itself belongs to Pages
 * (App\Service\PortfolioGalleryContent).
 *
 * WHAT SWITCHING IT OFF DOES. Nothing to the data (MODULES.md): the five
 * tables keep every row, every legacy link to a page stays stored, and every
 * uploaded image stays on disk. The module simply stops contributing: no
 * sidebar entry; no holdable permission, so both admin screens and every
 * Portfolio write endpoint refuse on the permission check they already make;
 * no sitemap entries; no Projecten block to pick, and one already placed shows
 * nothing and keeps its settings; no gallery source, so a gallery block set to
 * portfolio items keeps its settings and shows nothing; and, through
 * App\Module\ModuleGuard at the top of portfolio.php and portfolio-detail.php,
 * a 404 at /portfolio, /portfolio.php and every /portfolio/<slug>, redirect or not. A
 * legacy page an item links to is an ordinary page and keeps answering at its
 * own address.
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
     * The overview as a menu and footer destination, like the Blog's /blog —
     * but only while no CMS page is the overview: an installation that has
     * that page links it as a page, so the picker never offers the same
     * address twice (App\Service\PortfolioUrls::overviewPage()).
     */
    public function routes(): array
    {
        return PortfolioUrls::overviewPage() === null
            ? ['portfolio' => ['url' => PortfolioUrls::OVERVIEW_PATH, 'label' => PortfolioUrls::OVERVIEW_LABEL, 'order' => 60]]
            : [];
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
     * The overview page, content key "portfolio", at the module root: in
     * Pagina's on every installation (App\Service\ModuleSystemPages). While it
     * has no block of its own, /portfolio shows the module's own overview
     * (App\Service\PortfolioUrls::overviewPage()).
     *
     * Ordinary pages may sit under it at /portfolio/<slug> (Pages &
     * Destinations 3.0) — the very namespace of the project pages. One
     * namespace, so the check runs both ways: here, which slugs a project
     * already has, for a page about to be saved there; and in
     * App\Service\PortfolioSlug::problem(), which page already has a slug a
     * project is about to get. Every item's slug counts, visible or not and
     * with its project page on or off: switching one on later must not take
     * an address a page is using.
     */
    public function systemPages(): array
    {
        return [PortfolioUrls::OVERVIEW_CONTENT_KEY => [
            'route_path' => PortfolioUrls::OVERVIEW_PATH,
            'child_prefix' => PortfolioUrls::ROOT_SEGMENT,
            'child_conflicts' => static function (array $slugs): array {
                $conflicts = [];
                foreach ((new \App\Repository\PortfolioGalleryRepository())->itemsWithSlugs($slugs) as $item) {
                    $name = trim(\App\Service\PortfolioLocalization::itemName((int) $item['id']));
                    $conflicts[] = [
                        'slug' => (string) $item['slug'],
                        'label' => $name === ''
                            ? \App\Service\Language\AdminTranslator::trans('validation.page_slug_held_by_untitled_project')
                            : \App\Service\Language\AdminTranslator::trans('validation.page_slug_held_by_project', ['name' => $name]),
                    ];
                }

                return $conflicts;
            },
        ]];
    }

    /**
     * Both root-level templates, reserved whether or not the module runs.
     * `portfolio` is also the first segment of every project address
     * (/portfolio/<slug>, see publicRoutes()); `portfolio-detail` is the template
     * that route renders.
     */
    public function reservedSlugs(): array
    {
        return ['portfolio', 'portfolio-detail'];
    }

    /**
     * The module root and what lies below it (Portfolio 2.0):
     *
     *   /portfolio          the Portfolio overview, the CMS page with content
     *                       key "portfolio" rendered by portfolio.php
     *   /portfolio/<slug>   one project page, portfolio-detail.php
     *   /portfolio.php      the overview's old address, kept so every link to
     *                       it keeps working: portfolio.php answers it with a
     *                       permanent redirect to /portfolio
     *                       (PortfolioUrls::legacyOverviewRedirectUrl())
     *
     * All three sit before the page catch-all ({slug+}, App\Service\Routing\RouteTable),
     * and `portfolio` is reserved against page slugs (reservedSlugs()), so the
     * bare /portfolio can never be read as a page. "portfolio" is the same
     * word in Dutch and in English, so the namespace has no per-language
     * entry (App\Service\Routing\RouteSegments).
     */
    public function publicRoutes(): array
    {
        return [
            ['key' => 'portfolio.index', 'pattern' => '{portfolio.root}', 'template' => 'portfolio.php'],
            ['key' => 'portfolio.index.file', 'pattern' => 'portfolio.php', 'template' => 'portfolio.php'],
            [
                'key' => 'portfolio.project',
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
     * /portfolio serves the CMS page with content_key "portfolio" (its
     * `route_path` since 20260925170000; /portfolio.php before): an ordinary
     * content page that is linked as a page, and therefore NOT one of routes()
     * (App\Service\RouteRegistry). Naming the paths here is what makes that
     * page, a menu link to it and a redirect aimed at it stop resolving while
     * the module is off, instead of pointing at the 404 ModuleGuard answers
     * there. The old /portfolio.php stays named, for a page an upgrade has not
     * reached yet and for a redirect an editor aimed at it.
     */
    public function publicPaths(): array
    {
        return [PortfolioUrls::OVERVIEW_PATH, PortfolioUrls::LEGACY_OVERVIEW_PATH, '/portfolio-detail.php'];
    }

    /**
     * Every project page that shows itself. The Portfolio page
     * itself is a CMS page and comes from Core's pages collector, where
     * App\Service\PageSeo::isIndexable() leaves it out while this module is
     * off (publicPaths() above) — and so does every legacy page an item links
     * to, which is how such a project is listed once, under that page's own
     * canonical.
     *
     * The rule is PortfolioGalleryContent::projectPagesForSitemap()'s: an
     * address that redirects is not listed, and neither is one that answers
     * 404, so the sitemap never names an address that shows no page.
     */
    /** Public project pages in the site search (SEARCH.md). */
    public function searchProviders(): array
    {
        return ['project' => new \App\Service\PortfolioSearchProvider()];
    }

    public function sitemapCollectors(): array
    {
        return [
            'portfolio' => static function (): array {
                $entries = [];

                // The module's own overview, when no CMS page is the overview:
                // a page is listed by Core's pages collector instead, once.
                if (PortfolioUrls::overviewPage() === null) {
                    $entries = Sitemap::entriesForVersions(PortfolioUrls::overviewVersions(), null);
                }

                foreach (PortfolioGalleryContent::projectPagesForSitemap() as $project) {
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
            // A project's own head as a block, for the free project layout
            // (Product & Portfolio Content Pages 1.0); only on a project's page.
            'project_info' => \App\Service\Blocks\ProjectInfoBlock::class,
        ];
    }

    /**
     * Portfolio items as a source for the gallery block and the Projecten
     * block. First in `order`, so a new gallery block still starts as the
     * portfolio grid it always was while this module runs. It is the one
     * source whose items a block chooses (Projecten 2.0: all visible
     * projects, one category, or picked by hand, in an order of its own,
     * random included — PortfolioGalleryContent::galleryItems()), and the one
     * with a taxonomy for the filter bar. The four choice callables give the
     * block editors its categories and projects, and store a block's picked
     * projects in the module's own relation (item_gallery_portfolio_items).
     */
    public function itemGallerySources(): array
    {
        return [
            self::GALLERY_SOURCE => [
                'label' => 'Portfolio-items',
                'order' => 10,
                'needs_collection' => false,
                'needs_scope' => true,
                // Its card in the block picker: the gallery started on
                // portfolio items, filed under Portfolio
                // (App\Service\Blocks\ItemGalleryBlock).
                'picker' => [
                    'category' => BlockCategories::PORTFOLIO,
                    'label' => 'Portfoliogalerij',
                    'description' => 'Je portfolio-items als raster met beeld, met optioneel een filterbalk en een vergroting bij het aanklikken.',
                    'use_cases' => ['een portfolio-overzicht', 'uitgelicht werk op de homepage'],
                ],
                'items' => static fn (array $settings): array => PortfolioGalleryContent::galleryItems($settings),
                'filter_categories' => static fn (): array => PortfolioGalleryContent::filterCategories(),
                'category_choices' => static fn (): array => PortfolioGalleryContent::categoryChoices(),
                'item_choices' => static fn (): array => PortfolioGalleryContent::pickerChoices(),
                'selected_items' => static fn (int $galleryId): array => PortfolioGalleryContent::gallerySelection($galleryId),
                'save_selection' => static function (int $galleryId, array $itemIds): void {
                    PortfolioGalleryContent::saveGallerySelection($galleryId, $itemIds);
                },
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

    /**
     * A project carries content blocks on its project page (Product &
     * Portfolio Content Pages 1.0), through Core's one block engine
     * (App\Service\ContentOwners\ContentPages); its layout decides where
     * (App\Service\PortfolioProjectLayout).
     */
    public function contentOwners(): array
    {
        return [new \App\Service\PortfolioContentOwner()];
    }

    /**
     * A project's picture where a block shows it as a linked picture: its
     * main picture, the one its card and its project page show.
     */
    public function linkedImages(): array
    {
        return [
            'portfolio_project' => static function (int $id): ?array {
                $item = (new \App\Repository\PortfolioGalleryRepository())->findItemById($id);

                return $item === null ? null : \App\Service\Media\BlockImage::fromOwner($item, null);
            },
        ];
    }

    /**
     * A project as the destination of a link (App\Service\Routing\LinkTargets,
     * the Destination Picker): stored by id and linked at /portfolio/<slug>
     * in the language being read, only while its project page is public
     * (PortfolioSlug::isPublic()). Offered: every item with an address of its
     * own, a hidden one or one with its project page off marked so; an item
     * without a slug has no address to link. A CATEGORY is not a destination:
     * it has no public address of its own, only a filter on a gallery.
     */
    public function linkTargets(): array
    {
        return [
            'portfolio_project' => [
                'label' => ['nl' => 'Portfolioproject', 'en' => 'Portfolio project'],
                'order' => 40,
                'picker' => \App\Service\Routing\LinkTargets::PICKER_SEARCH,
                'choices' => static function (): array {
                    $repository = new \App\Repository\PortfolioGalleryRepository();
                    $catalogue = $repository->findCatalogue();
                    $items = array_values(array_filter(
                        $catalogue === null ? [] : $repository->findItemsByGalleryId((int) $catalogue['id']),
                        static fn (array $item): bool => trim((string) ($item['slug'] ?? '')) !== ''
                    ));
                    \App\Service\PortfolioLocalization::preloadItems(array_map(static fn (array $item): int => (int) $item['id'], $items));

                    $choices = [];
                    foreach ($items as $item) {
                        $name = trim(\App\Service\PortfolioLocalization::itemName((int) $item['id']));
                        $choice = ['id' => (int) $item['id'], 'label' => $name !== '' ? $name : '/portfolio/' . $item['slug']];
                        if (!\App\Service\PortfolioSlug::isPublic((bool) $item['is_active'], (bool) $item['has_detail_page'], (string) $item['slug'])) {
                            $choice['note'] = 'hidden';
                        }
                        $picture = (string) ($item['thumbnail_path'] ?? '') !== '' ? (string) $item['thumbnail_path'] : (string) ($item['image_path'] ?? '');
                        if ($picture !== '') {
                            $choice['thumbnail'] = '/' . ltrim($picture, '/');
                        }
                        $choices[] = $choice;
                    }

                    usort($choices, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

                    return $choices;
                },
                'href' => static function (int $id): ?string {
                    $item = (new \App\Repository\PortfolioGalleryRepository())->findItemById($id);
                    if ($item === null || !\App\Service\PortfolioSlug::isPublic((bool) $item['is_active'], (bool) $item['has_detail_page'], $item['slug'] ?? null)) {
                        return null;
                    }

                    return \App\Service\Routing\LocalizedUrl::path(PortfolioGalleryContent::publicPath((string) $item['slug']));
                },
                'title' => static fn (int $id, string $language): ?string => (new \App\Repository\PortfolioGalleryRepository())->findItemById($id) === null
                    ? null
                    : \App\Service\PortfolioLocalization::item($id, \App\Service\PortfolioLocalization::TITLE, $language),
            ],
        ];
    }
}
