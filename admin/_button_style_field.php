<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ThemeColor;

/**
 * The two controls of Button Styles 2.0 (THEMING.md, "Knopstijlen"), one
 * version each (ADMIN-UI.md, "Vier afspraken"):
 *
 *   admin_button_style_field()  "Knopstijl" next to a content button: the
 *       default, or a style from the library. Every block editor with a
 *       button uses this one field; the options always come from the
 *       library, never from the editor. The endpoint reads it back with
 *       ButtonStyles::choiceFromRequest().
 *   admin_button_color_field()  a colour in the button style editor: a
 *       theme colour (it follows the palette and a page theme) or a fixed
 *       colour, with the native colour picker for the latter.
 *
 * Markup only; the caller owns the form, the permission check and the value.
 */

if (!function_exists('admin_button_style_field')) {
    /**
     * @param string $role 'primary' or 'secondary': which default "Standaard" is
     * @param string $label '' = "Knopstijl"
     */
    function admin_button_style_field(string $id, string $name, ?int $value, string $role = 'primary', string $label = '', ?string $error = null): string
    {
        $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        try {
            $styles = ButtonStyles::all();
            $default = ButtonStyles::defaultFor($role === 'secondary' ? 'secondary' : 'primary');
        } catch (\Throwable $e) {
            error_log('[admin_button_style_field] ' . $e->getMessage());
            $styles = [];
            $default = null;
        }

        $html = '<div class="admin-field" data-button-style-field>'
            . admin_field_label($id, $label !== '' ? $label : admin_t('buttons.field_label'), admin_t('help.buttons.field'))
            . '<select id="' . $h($id) . '" name="' . $h($name) . '" class="admin-select"' . ($error !== null ? ' aria-invalid="true"' : '') . '>'
            . '<option value=""' . ($value === null ? ' selected' : '') . '>'
            . $h(admin_t('buttons.field_default', ['name' => (string) ($default['name'] ?? '')])) . '</option>';

        foreach ($styles as $style) {
            $styleId = (int) $style['id'];
            $html .= '<option value="' . $styleId . '"' . ($value === $styleId ? ' selected' : '') . '>' . $h((string) $style['name']) . '</option>';
        }

        $html .= '</select>';
        if ($error !== null) {
            $html .= '<p class="admin-field-error">' . $h($error) . '</p>';
        }

        return $html . '</div>';
    }
}

if (!function_exists('admin_button_color_field')) {
    /**
     * @param ?string $value a colour word, a #RRGGBB, or null ("unchanged")
     * @param bool $optional whether "Niet veranderen" is a choice (the hover colours)
     */
    function admin_button_color_field(string $field, string $label, ?string $value, bool $optional = false, ?string $error = null): string
    {
        $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $id = 'button-' . str_replace('_', '-', $field);
        $fixed = $value !== null && !isset(ButtonStyleCss::COLORS[$value]) ? (ThemeColor::normalise($value) ?? '') : '';
        $selected = $fixed !== '' ? 'custom' : ($value ?? '');

        $html = '<div class="admin-field admin-button-color" data-button-color="' . $h($field) . '">'
            . admin_field_label($id, $label)
            . '<select id="' . $h($id) . '" name="' . $h($field) . '" class="admin-select"' . ($error !== null ? ' aria-invalid="true"' : '') . '>';

        if ($optional) {
            $html .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>' . admin_te('buttons.color_unchanged') . '</option>';
        }

        $html .= '<optgroup label="' . admin_te('buttons.color_theme_group') . '">';
        foreach (array_keys(ButtonStyleCss::COLORS) as $word) {
            $html .= '<option value="' . $h($word) . '"' . ($selected === $word ? ' selected' : '') . '>' . admin_te('buttons.color.' . $word) . '</option>';
        }
        $html .= '</optgroup><optgroup label="' . admin_te('buttons.color_fixed_group') . '">'
            . '<option value="custom"' . ($selected === 'custom' ? ' selected' : '') . '>' . admin_te('buttons.color_custom') . '</option>'
            . '</optgroup></select>';

        // The fixed colour: the picker is a convenience, the hex field is
        // what is posted (the pattern of admin/_theme_color_field.php).
        $customId = $id . '-custom';
        $customLabel = admin_t('buttons.color_custom_label', ['field' => $label]);
        $html .= '<div class="admin-theme-color__inputs admin-button-color__custom" data-button-color-custom>'
            . '<input type="color" class="admin-theme-color__swatch" value="' . $h($fixed !== '' ? $fixed : '#C9A063') . '"'
            . ' data-theme-color-for="' . $h($customId) . '" aria-label="' . $h($customLabel) . '" tabindex="-1">'
            . '<input type="text" id="' . $h($customId) . '" name="' . $h($field . '_custom') . '" value="' . $h($fixed) . '"'
            . ' maxlength="7" pattern="#?[0-9A-Fa-f]{6}" spellcheck="false" class="admin-theme-color__hex" aria-label="' . $h($customLabel) . '" placeholder="#RRGGBB">'
            . '</div>';

        if ($error !== null) {
            $html .= '<p class="admin-field-error">' . $h($error) . '</p>';
        }

        return $html . '</div>';
    }
}
