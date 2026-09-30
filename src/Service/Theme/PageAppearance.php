<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * How ONE page looks when it does not look like the rest of the site: a
 * validated set of the five theme colours plus a font pairing (and, per role,
 * optionally a Font Library family), under a slug
 * that names it in the markup (`<main data-page-theme="halloween">`).
 *
 * Core, and deliberately ignorant of where it came from. A module that lets
 * an editor choose a look per page (Paginathema's) answers
 * ModuleDefinition::pageAppearance()
 * with one of these; Core prints it (App\Service\Theme\PageThemeCss) and
 * never names the module. See THEMING.md, "Paginathema's".
 *
 * THE SAME RULES AS THE SITE THEME, not a copy of them: a colour passes
 * App\Service\Theme\ThemeColor::normalise(), the tints come from
 * App\Service\Theme\ThemePalette::derive(), the fonts from the closed
 * App\Service\Theme\ThemeFonts list, and every value takes
 * App\Service\Theme\ThemeCss::isSafeValue() on its way out. So a page theme
 * cannot express anything the site theme cannot, and a tampered database
 * row (`red;}body{…`) never becomes CSS: fromTheme() refuses it outright.
 *
 * What it does NOT carry: the button shape (a site-wide decision, kept on
 * :root), a logo, or any free CSS.
 */
final class PageAppearance
{
    /** What a slug may look like: the attribute value, and the selector. */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const SLUG_MAX_LENGTH = 80;

    /**
     * @param array<string, string> $colors ThemeSettings::COLOR_KEYS => #RRGGBB
     */
    private function __construct(
        public readonly string $slug,
        public readonly array $colors,
        public readonly string $fontPairing,
        public readonly ?int $headingFamilyId = null,
        public readonly ?int $bodyFamilyId = null
    ) {
    }

    /**
     * An appearance from stored or submitted values, or null when ANY of
     * them is not exactly what a theme allows. All or nothing on purpose: a
     * half-valid theme would silently mix page and site colours, which is a
     * look nobody chose.
     *
     * A Font Library family per role is optional (null = the pairing's
     * font); one that is given must be usable — a family without a file is
     * refused like any other broken value, never silently swapped.
     *
     * @param array<string, mixed> $colors keyed by ThemeSettings::COLOR_KEYS
     */
    public static function fromTheme(string $slug, array $colors, string $fontPairing, ?int $headingFamilyId = null, ?int $bodyFamilyId = null): ?self
    {
        if (!self::isValidSlug($slug) || !ThemeFonts::isValidKey($fontPairing)) {
            return null;
        }

        foreach ([$headingFamilyId, $bodyFamilyId] as $familyId) {
            if ($familyId !== null && !FontLibrary::isUsable($familyId)) {
                return null;
            }
        }

        $clean = [];
        foreach (ThemeSettings::COLOR_KEYS as $key) {
            $value = $colors[$key] ?? null;
            $normalised = is_string($value) ? ThemeColor::normalise($value) : null;

            if ($normalised === null) {
                return null;
            }

            $clean[$key] = $normalised;
        }

        return new self($slug, $clean, $fontPairing, $headingFamilyId, $bodyFamilyId);
    }

    public static function isValidSlug(string $slug): bool
    {
        return strlen($slug) <= self::SLUG_MAX_LENGTH && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /**
     * The COMPLETE token set of this appearance, in a stable order: the five
     * chosen colours, every tint ThemePalette derives from them, and the two
     * font stacks. Complete rather than a difference (which is what the site
     * theme emits, App\Service\Theme\ThemeCss): a page theme replaces the
     * palette inside its scope, so every token must be restated there or a
     * site-theme tint would leak into it.
     *
     * @return array<string, string>
     */
    public function declarations(): array
    {
        $out = ThemeCss::paletteDeclarations($this->colors);

        $stacks = ThemeTypography::stacks($this->fontPairing, $this->headingFamilyId, $this->bodyFamilyId);
        $out['--font-display'] = $stacks['heading'];
        $out['--font-body'] = $stacks['body'];

        return array_filter($out, static fn (string $value): bool => ThemeCss::isSafeValue($value));
    }

    /**
     * The pairing's web font stylesheet this appearance needs, or null for a
     * system pairing or when both roles use a Font Library family.
     */
    public function fontStylesheetUrl(): ?string
    {
        return ThemeTypography::pairingStylesheetUrl($this->fontPairing, $this->headingFamilyId, $this->bodyFamilyId);
    }

    /**
     * The Font Library families this appearance uses.
     *
     * @return list<int>
     */
    public function fontFamilyIds(): array
    {
        return ThemeTypography::familyIds($this->headingFamilyId, $this->bodyFamilyId);
    }
}
