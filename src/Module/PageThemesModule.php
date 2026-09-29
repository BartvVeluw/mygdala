<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\PageThemes\PageThemeService;
use App\Service\PageThemes\PageThemeSettingsSection;
use App\Service\Theme\PageAppearance;

/**
 * Paginathema's (Page Themes 1.0): named looks — five colours and a font
 * pairing — that an ordinary CMS page may use instead of the site theme. A
 * campaign page, a seasonal page, a landing page in another brand's colours;
 * never a hardcoded "if halloween": a theme is data, made on its own screen.
 *
 * WHAT IT CONTRIBUTES, all through ModuleDefinition so Core names nothing:
 *
 *   - adminNavigationItems() / permissionGroups(): the Paginathema's screens
 *     behind page_themes.manage (list, create, edit, duplicate, delete, a
 *     live preview);
 *   - pageSettingsSections(): the "Paginathema" choice on the Pagina tab of
 *     the page editor (App\Service\PageThemes\PageThemeSettingsSection);
 *   - pageAppearance(): the look of a page that chose a theme, which Core
 *     prints scoped to that page's <main> (App\Service\Theme\PageThemeCss);
 *   - switchableFromAppearance(): its on/off switch on the Vormgeving screen.
 *
 * SWITCHING IT OFF DELETES NOTHING, like every module (MODULES.md). The
 * themes stay in `page_themes`, every page keeps its `page_theme_id`, and
 * the site simply renders every page in the site theme: no attribute, no
 * extra <style>, no extra font. The screens refuse on their permission
 * check (a disabled module's permission is held by nobody). Switching it on
 * again brings every page's theme back.
 *
 * OFF ON A NEW INSTALLATION, like the Blog: most sites have one look.
 *
 * See THEMING.md, "Paginathema's".
 */
final class PageThemesModule extends ModuleDefinition
{
    public const PAGE_THEMES_MANAGE = 'page_themes.manage';

    public function key(): string
    {
        return 'page_themes';
    }

    public function label(): string
    {
        return 'Paginathema\'s';
    }

    public function description(): string
    {
        return 'Eigen kleuren en lettertypes voor een losse pagina, bijvoorbeeld een actie- of seizoenspagina. Header en footer blijven in de vormgeving van de website. Uitzetten verwijdert geen thema.';
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    /**
     * One entry, right below Vormgeving (810) and above Redirects (820): it
     * is part of how the site looks. The editor and its preview frame belong
     * to it too.
     */
    public function adminNavigationItems(): array
    {
        return [
            [
                'key' => 'page_themes',
                'label' => 'Paginathema\'s',
                'url' => '/admin/page-themes.php',
                'icon' => 'page_themes',
                'permission' => self::PAGE_THEMES_MANAGE,
                'order' => 815,
                'scripts' => ['page-themes.php', 'page-theme.php', 'page-theme-preview.php'],
            ],
        ];
    }

    /**
     * One permission: making and changing themes. Choosing a theme for a
     * page is part of editing that page (pages.manage), the way choosing a
     * picture from the Media Library is.
     */
    public function permissionGroups(): array
    {
        return [
            [
                'label' => 'Paginathema\'s',
                'order' => 460,
                'permissions' => [
                    self::PAGE_THEMES_MANAGE => [
                        'label' => 'Paginathema\'s beheren',
                        'description' => 'Paginathema\'s maken, wijzigen, dupliceren en verwijderen. Een thema kiezen voor een pagina valt onder "Pagina\'s beheren".',
                    ],
                ],
            ],
        ];
    }

    public function pageAppearance(array $page): ?PageAppearance
    {
        return PageThemeService::appearanceFor($page);
    }

    public function pageSettingsSections(): array
    {
        return [new PageThemeSettingsSection()];
    }

    public function switchableFromAppearance(): bool
    {
        return true;
    }
}
