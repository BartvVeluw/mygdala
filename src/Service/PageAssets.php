<?php

namespace App\Service;

use App\Module\ModuleRegistry;
use App\Service\Theme\ThemeCss;

/**
 * The frontend assets ONE request needs: collected while the page is put
 * together, printed twice — the stylesheets in <head>, the scripts just
 * before </body>.
 *
 * Why this exists. Until step 4 every public template hand-wrote the same
 * three tags (style.css, gsap, main.js), so every page downloaded the whole
 * site: the shop's cart, product and checkout code on a contact page, the
 * hero's GSAP on the cart page. Assets now have owners — Core, one content
 * block, a module — and an owner asks for its own files here instead of
 * being added to a global list somebody else maintains. A module that is
 * switched off asks for nothing, so a CMS-only deployment loads no shop CSS
 * or JS at all.
 *
 * How a caller uses it:
 *
 *   PageAssets::requireStyle('assets/css/blocks/item-gallery.css');
 *   PageAssets::requireScript('assets/js/blocks/item-gallery.js');
 *   PageAssets::requireVendorScript('gsap');
 *
 * Everything is deduplicated, so ten instances of the same block on one page
 * still load one file, and printed in the order it was asked for — after the
 * site shell, which always goes first (see SHELL_STYLES / SHELL_SCRIPTS).
 *
 * What it deliberately is NOT: a bundler, a dependency graph or a plugin
 * system. There is no concatenation, no minification and no build step —
 * this project deploys plain files to shared hosting. AssetVersion still
 * supplies the ?v=<mtime> cache buster, exactly as the hand-written tags did.
 *
 * Security: a path handed to requireStyle()/requireScript() must be a local
 * file under assets/ that really exists, and a vendor script must be a key of
 * the closed VENDOR_SCRIPTS map. Nothing here can turn request input into a
 * URL the browser loads — the same closed-list rule the content blocks use
 * for their type keys and data sources.
 */
final class PageAssets
{
    /**
     * Third-party frontend libraries, by name. A CLOSED map on purpose: a
     * caller names a library, it never supplies a URL. Versions are pinned
     * here so the site cannot drift apart per page — before step 4 the same
     * GSAP tag was copy-pasted into twelve templates.
     */
    private const VENDOR_SCRIPTS = [
        'gsap' => 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js',
    ];

    /**
     * The site shell: what EVERY public page needs, always printed first so
     * Core's design tokens and behaviour are in place before anything that
     * builds on them.
     *
     * Core's own half is these two files and nothing else. An ENABLED module
     * that really does render something into every page adds its files after
     * them, through App\Module\ModuleDefinition::shellStyles()/shellScripts()
     * — today only the Shop, for the mini-cart in the shared header. The
     * REQUEST has to live in the shell rather than in the header partial
     * because <head> is written before that partial ever runs and a
     * stylesheet cannot be asked for afterwards; the OWNERSHIP is the
     * module's, so Core no longer names a single Shop file.
     */
    private const SHELL_STYLES = [
        'assets/css/core.css',
    ];

    private const SHELL_SCRIPTS = [
        'assets/js/core.js',
    ];

    /** @var list<string> */
    private static array $styles = [];

    /** @var list<string> */
    private static array $scripts = [];

    /** @var list<string> */
    private static array $vendorScripts = [];

    private static bool $seeded = false;

    /**
     * Asks for a stylesheet. The path is project-relative ('assets/css/...');
     * one that is not loadable is ignored rather than printed, so a typo can
     * never emit a 404-ing <link> into every page.
     */
    public static function requireStyle(string $path): void
    {
        self::seed();
        self::add(self::$styles, $path);
    }

    /** Asks for a script. Same rules as requireStyle(). */
    public static function requireScript(string $path): void
    {
        self::seed();
        self::add(self::$scripts, $path);
    }

    /** Asks for a third-party library by its key in VENDOR_SCRIPTS. */
    public static function requireVendorScript(string $name): void
    {
        self::seed();

        if (!array_key_exists($name, self::VENDOR_SCRIPTS)) {
            return;
        }

        if (!in_array($name, self::$vendorScripts, true)) {
            self::$vendorScripts[] = $name;
        }
    }

    /**
     * Prints every collected <link rel="stylesheet">, for <head>. A block
     * that renders lower down the page has already been accounted for by
     * SectionRegistry::collectPageAssets(), which runs before the head is
     * written — see that method for the ordering rationale.
     */
    public static function renderStyles(): void
    {
        self::seed();

        self::renderFontStylesheet();

        foreach (self::$styles as $path) {
            echo '<link rel="stylesheet" href="/' . self::href($path) . '">' . "\n";
        }

        // The theme override goes LAST so it wins over core.css and over
        // any block or Shop stylesheet in between. For a site still on
        // the default theme it prints nothing at all, not even an empty
        // <style> tag. See App\Service\Theme\ThemeCss.
        ThemeCss::renderStyleBlock();
    }

    /**
     * The web font for the selected pairing, printed before the
     * stylesheets so its @font-face rules are known as early as possible.
     *
     * This used to be an @import at the top of core.css, which meant
     * every site downloaded Trirong whatever it had chosen, and that the
     * browser could not even start the font request until core.css had
     * arrived. A pairing built from fonts every device already has asks
     * for nothing at all. See App\Service\Theme\ThemeFonts.
     */
    private static function renderFontStylesheet(): void
    {
        $url = ThemeCss::fontStylesheetUrl();

        if ($url === null) {
            return;
        }

        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    /** Prints every collected <script>, for just before </body>. */
    public static function renderScripts(): void
    {
        self::seed();

        foreach (self::$vendorScripts as $name) {
            echo '<script src="' . htmlspecialchars(self::VENDOR_SCRIPTS[$name], ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
        }

        foreach (self::$scripts as $path) {
            echo '<script src="/' . self::href($path) . '"></script>' . "\n";
        }
    }

    /**
     * The collected paths, for tests and for reasoning about a page without
     * rendering it.
     *
     * @return array{styles: list<string>, scripts: list<string>, vendor: list<string>}
     */
    public static function collected(): array
    {
        self::seed();

        return ['styles' => self::$styles, 'scripts' => self::$scripts, 'vendor' => self::$vendorScripts];
    }

    /** @return list<string> */
    public static function vendorScriptNames(): array
    {
        return array_keys(self::VENDOR_SCRIPTS);
    }

    /** Test seam: forget everything, including the shell seed. */
    public static function reset(): void
    {
        self::$styles = [];
        self::$scripts = [];
        self::$vendorScripts = [];
        self::$seeded = false;
    }

    /**
     * Whether a path is one this class would actually print: local, under
     * assets/, no traversal, and present on disk.
     */
    public static function isLoadable(string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }

        if (preg_match('#^assets/[A-Za-z0-9_/-]+\.(css|js)$#', $path) !== 1) {
            return false;
        }

        return is_file(self::projectRoot() . '/' . $path);
    }

    /**
     * The shell is seeded lazily on first use rather than by every entry
     * point calling a boot method: whoever asks first — a route template, a
     * block definition, the header — the shell still ends up in front of
     * them, and no template can forget it.
     */
    private static function seed(): void
    {
        if (self::$seeded) {
            return;
        }

        self::$seeded = true;

        foreach (self::SHELL_STYLES as $path) {
            self::add(self::$styles, $path);
        }

        foreach (ModuleRegistry::collect('shellStyles') as $path) {
            self::add(self::$styles, (string) $path);
        }

        foreach (self::SHELL_SCRIPTS as $path) {
            self::add(self::$scripts, $path);
        }

        foreach (ModuleRegistry::collect('shellScripts') as $path) {
            self::add(self::$scripts, (string) $path);
        }
    }

    /**
     * @param list<string> $bucket
     */
    private static function add(array &$bucket, string $path): void
    {
        $path = ltrim($path, '/');

        if (!self::isLoadable($path) || in_array($path, $bucket, true)) {
            return;
        }

        $bucket[] = $path;
    }

    private static function href(string $path): string
    {
        return htmlspecialchars(ltrim(AssetVersion::url($path), '/'), ENT_QUOTES, 'UTF-8');
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
