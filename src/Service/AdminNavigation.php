<?php

namespace App\Service;

use App\Module\ModuleRegistry;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageRegistry;

/**
 * The admin sidebar as data: one entry per CMS section, each with the
 * permission that opens it and the admin scripts that belong to it.
 *
 * It lives here rather than in admin/_header.php because three separate
 * things need the same answer and must never drift apart: which entries a
 * user sees, which entry is highlighted, and where someone lands right after
 * logging in (the first section they may actually open — a user without
 * dashboard.view must not be dropped on the dashboard).
 *
 * This is presentation only. Hiding a link is not access control: every page
 * listed here calls AdminAuth::requirePermission() itself, and every write
 * endpoint behind it calls AdminAuth::requirePermissionForApi(). The
 * 'permission' values below exist so the menu matches what the guards
 * already enforce, not so the guards can be skipped.
 *
 * ORDER AND GROUPING. Every entry carries an integer 'order'; the list is
 * sorted by it, and 'group' — the sidebar's visual grouping — is derived from
 * it as order/100. A divider is drawn wherever the group number changes
 * between two *visible* entries, so a user who cannot see a whole group never
 * gets a stray double divider. The numbers are spaced by 100 per group so a
 * module can slot an entry between two of Core's without either side knowing
 * about the other: App\Module\ModuleRegistry's enabled modules contribute
 * their own entries here, in exactly this shape, and Core no longer names a
 * single product, order or shipping screen.
 */
class AdminNavigation
{
    /** @var list<array<string, mixed>>|null built once per request */
    private static ?array $items = null;

    /**
     * Every sidebar entry that exists right now: Core's own, plus one per
     * entry each ENABLED module contributes, sorted by 'order'.
     *
     * @return list<array{key: string, label: string, url: string, icon: string, permission: string, order: int, group: int, scripts: list<string>}>
     */
    public static function items(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }

        $items = array_merge(self::coreItems(), ModuleRegistry::collect('adminNavigationItems'));

        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        foreach ($items as $index => $item) {
            $items[$index]['group'] = intdiv((int) $item['order'], 100);
            $items[$index]['label'] = self::label((string) $item['key'], (string) $item['label']);
        }

        return self::$items = $items;
    }

    /**
     * The sidebar entry's label in the CMS interface language of whoever is
     * signed in, falling back to the Dutch label written beside the entry.
     *
     * Translated HERE, once, rather than at each entry's definition, so a
     * module's entries change language without the module knowing the CMS
     * has more than one: App\Module\ShopModule and App\Module\BlogModule keep
     * declaring a plain Dutch 'label' and get English the moment a
     * `nav.<key>` key exists.
     *
     * An entry with no key in the catalog keeps its own label rather than
     * rendering a raw dotted key — the same direction
     * App\Service\Language\AdminTranslator takes everywhere: an untranslated
     * menu item is a blemish, an empty one is a broken CMS.
     */
    private static function label(string $key, string $fallback): string
    {
        $catalogKey = 'nav.' . $key;

        // Asked of the reference catalog rather than of the wanted locale:
        // Dutch is complete by construction, so "does this entry have a
        // translation at all" is a question about Dutch, and a key missing
        // only from English already falls back to Dutch inside trans().
        if (!AdminTranslator::has($catalogKey, LanguageRegistry::DEFAULT_LANGUAGE)) {
            return $fallback;
        }

        return AdminTranslator::trans($catalogKey);
    }

    /** Forgets the merged list; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$items = null;
    }

    /**
     * The entries the CMS has without any module: the dashboard, the pages
     * and their editors, the portfolio, customer contact, and the site-wide
     * settings screens.
     *
     * @return list<array{key: string, label: string, url: string, icon: string, permission: string, order: int, scripts: list<string>}>
     */
    private static function coreItems(): array
    {
        return [
            [
                'key' => 'dashboard',
                'label' => 'Dashboard',
                'url' => '/admin/index.php',
                'icon' => 'dashboard',
                'permission' => AdminPermissions::DASHBOARD_VIEW,
                'order' => 100,
                'scripts' => ['index.php'],
            ],
            [
                'key' => 'pages',
                'label' => "Pagina's",
                'url' => '/admin/pages.php',
                'icon' => 'pages',
                'permission' => AdminPermissions::PAGES_MANAGE,
                'order' => 200,
                // Every screen that is "about a CMS page": the overview, the
                // per-page settings + page-builder screen, the create form,
                // and each section editor a page-builder row links into
                // (including the carousel's per-card screen, which is reached
                // from the carousel editor rather than from a page directly).
                'scripts' => [
                    'pages.php',
                    'page.php',
                    'page-new.php',
                    // A page as its template renders it, drafts included, for
                    // a signed-in editor (Voorbeeld bekijken).
                    'page-preview.php',
                    'page-hero.php',
                    'homepage-hero.php',
                    'cta-band.php',
                    'feature-grid.php',
                    'faq.php',
                    'step-list.php',
                    'text-image-split.php',
                    'stat-strip.php',
                    'marquee.php',
                    'rich-text.php',
                    'contact-form.php',
                    'form-block.php',
                    'contact-card.php',
                    'detail-section.php',
                    'card-carousel.php',
                    'carousel-card.php',
                    'item-gallery.php',
                ],
            ],
            [
                // The Media Library sits directly under Pagina's: it is the
                // shared pool every image picker in the page builder draws
                // from, and an editor hunting for "that photo from last week"
                // looks for it next to the pages, not next to the settings.
                'key' => 'media',
                'label' => 'Media',
                'url' => '/admin/media.php',
                'icon' => 'media',
                'permission' => AdminPermissions::MEDIA_VIEW,
                'order' => 210,
                'scripts' => ['media.php'],
            ],
            [
                // Formulieren sits with Pagina's and Media rather than with
                // the settings: building a form is content work, and an
                // editor reaches for it while thinking about the page it
                // will go on. What people SENT is a different screen with a
                // different permission — see 'form_submissions' below.
                'key' => 'forms',
                'label' => 'Formulieren',
                'url' => '/admin/forms.php',
                'icon' => 'forms',
                'permission' => AdminPermissions::FORMS_MANAGE,
                'order' => 220,
                'scripts' => ['forms.php', 'form.php', 'form-field.php'],
            ],
            [
                // The catalogue of content blocks: what exists, what each one
                // puts on a page, what it is good for. It sits with Pagina's,
                // Media and Formulieren because it answers a question asked
                // while building a page, and it needs no permission of its own
                // — it describes the blocks the page builder already offers,
                // so whoever may build a page may read about the pieces.
                'key' => 'content_blocks',
                'label' => 'Contentblokken',
                'url' => '/admin/content-blocks.php',
                'icon' => 'content_blocks',
                'permission' => AdminPermissions::PAGES_MANAGE,
                'order' => 230,
                'scripts' => ['content-blocks.php'],
            ],
            [
                'key' => 'portfolio',
                'label' => 'Portfolio',
                'url' => '/admin/portfolio.php',
                'icon' => 'portfolio',
                'permission' => AdminPermissions::PORTFOLIO_MANAGE,
                'order' => 400,
                'scripts' => ['portfolio.php', 'portfolio-item.php'],
            ],
            [
                'key' => 'contact_requests',
                'label' => 'Contactaanvragen',
                'url' => '/admin/contact-requests.php',
                'icon' => 'contact_requests',
                'permission' => AdminPermissions::CONTACT_MANAGE,
                'order' => 600,
                'scripts' => ['contact-requests.php', 'contact-request.php'],
            ],
            [
                // Next to Contactaanvragen, because it answers the same
                // question for the owner ("wie heeft mij iets gestuurd"),
                // and behind its own permission, because these are personal
                // details a page editor has no reason to read.
                'key' => 'form_submissions',
                'label' => 'Inzendingen',
                'url' => '/admin/form-submissions.php',
                'icon' => 'form_submissions',
                'permission' => AdminPermissions::FORMS_SUBMISSIONS,
                'order' => 610,
                'scripts' => ['form-submissions.php', 'form-submission.php'],
            ],
            [
                'key' => 'navigation',
                'label' => 'Navigatie',
                'url' => '/admin/navigation.php',
                'icon' => 'navigation',
                'permission' => AdminPermissions::PAGES_MANAGE,
                'order' => 700,
                'scripts' => ['navigation.php', 'navigation-item.php'],
            ],
            [
                'key' => 'footer',
                'label' => 'Footer',
                'url' => '/admin/footer.php',
                'icon' => 'footer',
                'permission' => AdminPermissions::PAGES_MANAGE,
                'order' => 710,
                'scripts' => ['footer.php', 'footer-column.php', 'footer-link.php'],
            ],
            [
                // The shared shell's remaining CONTENT: the header's one
                // call-to-action button, the footer's closing line and the
                // social profiles. Next to Navigatie and Footer rather than
                // next to Site-instellingen, because it is edited while
                // thinking about the header and the footer, not while
                // thinking about the company's details.
                'key' => 'header_footer',
                'label' => 'Header & footer',
                'url' => '/admin/header-footer.php',
                'icon' => 'header_footer',
                'permission' => AdminPermissions::PAGES_MANAGE,
                'order' => 720,
                'scripts' => ['header-footer.php'],
            ],
            [
                'key' => 'settings',
                'label' => 'Site-instellingen',
                'url' => '/admin/settings.php',
                'icon' => 'settings',
                'permission' => AdminPermissions::SETTINGS_MANAGE,
                'order' => 800,
                'scripts' => ['settings.php'],
            ],
            [
                'key' => 'theme',
                'label' => 'Vormgeving',
                'url' => '/admin/theme.php',
                'icon' => 'theme',
                'permission' => AdminPermissions::SETTINGS_MANAGE,
                'order' => 810,
                'scripts' => ['theme.php'],
            ],
            [
                // Next to Site-instellingen and Vormgeving, because a redirect
                // is site-wide plumbing rather than the content of one page,
                // and because the SEO settings it belongs with (Instellingen →
                // SEO, see SEO.md) already live in this group. It shares
                // settings.manage for the same reason: this is one more
                // site-wide setting, not a new kind of responsibility, and
                // this project keeps exactly as many permissions as the
                // sidebar has kinds of section.
                'key' => 'redirects',
                'label' => 'Redirects',
                'url' => '/admin/redirects.php',
                'icon' => 'redirects',
                'permission' => AdminPermissions::SETTINGS_MANAGE,
                'order' => 820,
                'scripts' => ['redirects.php', 'redirect.php'],
            ],
            [
                'key' => 'users',
                'label' => 'Gebruikers',
                'url' => '/admin/users.php',
                'icon' => 'users',
                'permission' => AdminPermissions::USERS_MANAGE,
                'order' => 900,
                'scripts' => ['users.php', 'user-form.php'],
            ],
        ];
    }

    /**
     * The entries the signed-in user may open, in sidebar order.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleItems(): array
    {
        return array_values(array_filter(
            self::items(),
            static fn (array $item): bool => AdminAuth::can($item['permission'])
        ));
    }

    /**
     * Which sidebar entry the given admin script belongs to, so the sidebar
     * can highlight it — e.g. 'product-form.php' highlights "Producten".
     */
    public static function activeKeyForScript(string $script): ?string
    {
        foreach (self::items() as $item) {
            if (in_array($script, $item['scripts'], true)) {
                return $item['key'];
            }
        }

        return null;
    }

    /**
     * First section the signed-in user may open; null when their permission
     * set opens nothing at all.
     */
    public static function firstAccessibleUrl(): ?string
    {
        $visible = self::visibleItems();

        return $visible === [] ? null : (string) $visible[0]['url'];
    }
}
