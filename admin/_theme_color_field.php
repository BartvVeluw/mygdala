<?php

declare(strict_types=1);

/**
 * ONE website-theme colour field: a label, the native colour picker as a
 * convenience, and the hex text field that is what the form submits. The
 * same control wherever one of the five theme colours is chosen — Vormgeving
 * (admin/theme.php), the Setup Wizard's appearance step (admin/setup.php)
 * and a page theme (admin/page-theme.php) — so there is one version of it
 * (ADMIN-UI.md, "Vier afspraken").
 *
 * The hex field's id is `theme-<key>` and the picker names it in
 * data-theme-color-for: that is what admin/assets/theme-admin.js keeps in
 * step, and what the editors' own scripts read. Markup only; the caller owns
 * the form, the permission check and the value.
 */

if (!function_exists('admin_theme_color_field')) {
    /**
     * @param list<string> $notes extra lines under the help text, already
     *                            escaped by the caller (a translated line with
     *                            a value in it, like "Standaard: #C9A063")
     */
    function admin_theme_color_field(string $key, string $label, string $help, string $value, array $notes = []): string
    {
        $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $id = 'theme-' . $key;

        $html = '<div class="admin-theme-color">' . "\n"
            . '  <label for="' . $h($id) . '">' . $h($label) . '</label>' . "\n"
            . '  <div class="admin-theme-color__inputs">' . "\n"
            . '    <input type="color" class="admin-theme-color__swatch" value="' . $h($value) . '"'
            . ' data-theme-color-for="' . $h($id) . '" aria-label="Kleurkiezer voor ' . $h($label) . '" tabindex="-1">' . "\n"
            . '    <input type="text" id="' . $h($id) . '" name="' . $h($key) . '" value="' . $h($value) . '"'
            . ' maxlength="7" pattern="#?[0-9A-Fa-f]{6}" spellcheck="false" class="admin-theme-color__hex">' . "\n"
            . '  </div>' . "\n"
            . '  <p class="admin-text-muted">' . $h($help) . '</p>' . "\n";

        foreach ($notes as $note) {
            $html .= '  <p class="admin-text-muted">' . $note . '</p>' . "\n";
        }

        return $html . '</div>' . "\n";
    }
}
