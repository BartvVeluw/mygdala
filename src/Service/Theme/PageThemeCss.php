<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Module\ModuleRegistry;

/**
 * The look of THE page this request renders, when it has one of its own
 * (App\Service\Theme\PageAppearance), and the three places it shows:
 *
 *   1. <style id="page-theme">, printed by App\Service\PageAssets right after
 *      the site theme's <style id="site-theme">: the complete token set,
 *      scoped to `main[data-page-theme="<slug>"]`;
 *   2. the attribute on <main id="main">, printed by every template that
 *      renders a CMS page (mainAttribute());
 *   3. the page theme's web font, next to the site's, and the @font-face
 *      rules of its Font Library families (PageAssets::renderFontStylesheet()).
 *
 * WHICH PAGE. declareForPage() is called once, by partials/page-head.php —
 * the head of every template that renders a `pages` row (the seven page
 * templates and admin/page-preview.php). The product and project pages do
 * not include that partial, and a content page (owner_type set) is refused
 * here as well, so a product or a project never gets a page theme. Only the
 * page's OWN choice counts: there is no inheritance from a parent page.
 *
 * WHO DECIDES. An enabled module, through ModuleDefinition::pageAppearance()
 * (ModuleRegistry::pageAppearance()). With no module that answers — the
 * Paginathema's module switched off — nothing is declared, and the page
 * renders byte for byte as it would without the feature: no attribute, no
 * <style>, no extra font. The stored choice stays where it is.
 *
 * WHAT IT CHANGES. Only what sits inside <main>: the page header and every
 * block. The header, the navigation, the footer and the cookie banner stay
 * on the site theme, because they are the site's, not the page's; core.css
 * forces the header's veil on a themed page so it never floats transparent
 * over a ground it was not designed for. See THEMING.md, "Paginathema's".
 *
 * One request renders one page, so the state here is per request, like
 * PageAssets' own; reset() is the test seam (PageAssets::reset() calls it).
 */
final class PageThemeCss
{
    private static ?PageAppearance $current = null;

    /**
     * Resolves and remembers the appearance of this `pages` row — or
     * nothing, for a missing row, a content page of a product or project, a
     * page without a theme, or a site where no module offers one.
     *
     * @param array<string, mixed>|null $page
     */
    public static function declareForPage(?array $page): void
    {
        self::$current = null;

        if ($page === null || ($page['owner_type'] ?? null) !== null) {
            return;
        }

        try {
            self::$current = ModuleRegistry::pageAppearance($page);
        } catch (\Throwable $e) {
            // A page must still render when its theme cannot be read: it
            // falls back to the site theme, like the site theme itself falls
            // back to core.css when the database is unreachable.
            error_log('[PageThemeCss] page appearance unavailable: ' . $e->getMessage());
        }
    }

    /**
     * Declares an appearance directly, for a screen that shows one without
     * a page — the theme editor's preview (admin/page-theme-preview.php).
     */
    public static function declare(?PageAppearance $appearance): void
    {
        self::$current = $appearance;
    }

    public static function current(): ?PageAppearance
    {
        return self::$current;
    }

    /**
     * ` data-page-theme="<slug>"` for the page's <main>, or an empty string —
     * so a page without a theme prints exactly the tag it always printed.
     */
    public static function mainAttribute(): string
    {
        if (self::$current === null) {
            return '';
        }

        return ' data-page-theme="' . htmlspecialchars(self::$current->slug, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * The inline block for one appearance (by default the declared one), or
     * an empty string. Values were validated when the appearance was made
     * and pass ThemeCss::isSafeValue() in declarations(); the slug is checked
     * again here because it becomes part of a selector.
     */
    public static function styleBlock(?PageAppearance $appearance = null): string
    {
        $appearance ??= self::$current;

        if ($appearance === null || !PageAppearance::isValidSlug($appearance->slug)) {
            return '';
        }

        $declarations = $appearance->declarations();
        if ($declarations === []) {
            return '';
        }

        $body = '';
        foreach ($declarations as $property => $value) {
            $body .= '  ' . $property . ': ' . $value . ";\n";
        }

        return '<style id="page-theme">' . "\n" . 'main[data-page-theme="' . $appearance->slug . '"]{' . "\n" . $body . "}\n</style>\n";
    }

    /** Prints styleBlock(); the shape App\Service\PageAssets calls. */
    public static function renderStyleBlock(): void
    {
        echo self::styleBlock();
    }

    /** The declared appearance's web font, or null. */
    public static function fontStylesheetUrl(): ?string
    {
        return self::$current?->fontStylesheetUrl();
    }

    /**
     * The Font Library families the declared appearance uses.
     *
     * @return list<int>
     */
    public static function fontFamilyIds(): array
    {
        return self::$current?->fontFamilyIds() ?? [];
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
