<?php

namespace App\Service\Theme;

/**
 * Turns the stored theme into the two things a page's <head> needs: the font
 * stylesheet for the selected pairing, and a small override block that
 * redefines only the design tokens that actually differ from the default.
 *
 * The model is deliberately subtractive:
 *
 *   assets/css/core.css declares the complete DEFAULT theme.
 *   This class emits the DIFFERENCE, after it, and nothing else.
 *
 * So a site that has changed nothing emits no <style> block at all — the
 * page is byte-for-byte what it always was — and a site that changed one
 * colour ships one short block instead of a second copy of the palette. It
 * also means no theme setting can ever require rebuilding a stylesheet;
 * there is nothing to rebuild.
 *
 * App\Service\PageAssets is the only caller: it prints the font link before
 * the stylesheets and this block after them, so the override always wins
 * over core.css and over any block or Shop stylesheet that came in between.
 * No template renders theme CSS of its own.
 *
 * Injection is closed off upstream rather than escaped here: every value
 * comes from ThemeSettings, which only ever returns a validated #RRGGBB, a
 * font stack from the closed ThemeFonts list, or a radius from the closed
 * shape list. The guard in declarations() is a second lock on that door, not
 * the first.
 */
final class ThemeCss
{
    /** Which chosen setting redefines which token, one to one. */
    private const DIRECT = [
        'primary_color' => '--color-primary',
        'on_primary_color' => '--color-on-primary',
        'background_color' => '--color-bg',
        'surface_color' => '--color-surface',
        'text_color' => '--color-text',
    ];

    /** The ThemePalette role each colour setting plays. */
    private const ROLES = [
        'primary_color' => 'primary',
        'background_color' => 'background',
        'surface_color' => 'surface',
        'text_color' => 'text',
    ];

    /**
     * Every custom property whose value differs from the shipped default,
     * in a stable order. Empty for the default theme.
     *
     * @return array<string, string>
     */
    public static function declarations(): array
    {
        $changed = ThemeSettings::changedKeys();

        if ($changed === []) {
            return [];
        }

        $effective = ThemeSettings::all();
        $out = [];

        foreach (self::DIRECT as $key => $property) {
            if (in_array($key, $changed, true)) {
                $out[$property] = $effective[$key];
            }
        }

        $derived = ThemePalette::derive([
            'primary' => $effective['primary_color'],
            'background' => $effective['background_color'],
            'surface' => $effective['surface_color'],
            'text' => $effective['text_color'],
        ]);
        $dependencies = ThemePalette::dependencies();

        foreach (self::ROLES as $key => $role) {
            if (!in_array($key, $changed, true)) {
                continue;
            }

            foreach ($dependencies[$role] as $property) {
                $out[$property] = $derived[$property];
            }
        }

        if (in_array('font_pairing', $changed, true)) {
            $pairing = ThemeFonts::pairing($effective['font_pairing']);
            $out['--font-display'] = $pairing['heading'];
            $out['--font-body'] = $pairing['body'];
        }

        if (in_array('button_shape', $changed, true)) {
            $out['--button-radius'] = ThemeSettings::buttonRadius();
        }

        return array_filter($out, static fn (string $value): bool => self::isSafeValue($value));
    }

    /**
     * The override block, or an empty string when there is nothing to
     * override. Includes the trailing newline so the <head> stays readable.
     */
    public static function styleBlock(): string
    {
        $declarations = self::declarations();

        if ($declarations === []) {
            return '';
        }

        $body = '';
        foreach ($declarations as $property => $value) {
            $body .= '  ' . $property . ': ' . $value . ";\n";
        }

        return '<style id="site-theme">' . "\n:root{\n" . $body . "}\n</style>\n";
    }

    /** Prints styleBlock(); the shape App\Service\PageAssets calls. */
    public static function renderStyleBlock(): void
    {
        echo self::styleBlock();
    }

    /**
     * The one font stylesheet this theme needs, or null for a pairing built
     * from fonts every device already has. Only the SELECTED pairing is ever
     * returned, so switching pairing switches the download rather than
     * adding one.
     */
    public static function fontStylesheetUrl(): ?string
    {
        return ThemeFonts::pairing(ThemeSettings::get('font_pairing'))['url'];
    }

    /**
     * The effective page-ground colour, for <meta name="theme-color">. Read
     * from the settings rather than parsed back out of any stylesheet — the
     * meta tag and the CSS have one source, so they cannot drift.
     */
    public static function backgroundColor(): string
    {
        return ThemeSettings::get('background_color');
    }

    /**
     * A value may not be able to close the declaration, the rule or the
     * element it is printed inside. Nothing that reaches here should ever
     * fail this; a value that does is dropped rather than printed.
     */
    private static function isSafeValue(string $value): bool
    {
        return $value !== '' && preg_match('/[<>{};]/', $value) !== 1;
    }
}
