<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';

use App\Service\Theme\FontFileInspector;
use App\Service\Theme\FontLibrary;
use App\Service\Theme\FontVariant;

/**
 * The Font Library's small pieces of CMS markup, shared by Vormgeving
 * (admin/theme.php, tab Lettertypen and the choice per role), the family
 * editor (admin/font-family.php) and the page theme editor
 * (admin/page-theme.php). See THEMING.md, "Font Library".
 *
 * Every text is a CMS text (`fonts.*`, `help.fonts.*`), every value is
 * escaped, and nothing an administrator typed ever reaches CSS: a sample is
 * styled with the family's generated CSS name (FontLibrary::cssFamilyName()),
 * never with its name.
 *
 * admin_font_help() IS the administrator's manual "Eigen lettertypen
 * toevoegen": the texts live in the CMS catalogue, so the help on the screen
 * and the manual cannot drift apart.
 */

/** The @font-face rules for these families, for samples on an admin screen. */
function admin_font_faces(array $familyIds): string
{
    $css = FontLibrary::fontFaceCss(array_map('intval', $familyIds));

    return $css === '' ? '' : "<style data-font-faces>\n" . $css . "</style>\n";
}

/** ` style="font-family: …"` for text shown in one library family. */
function admin_font_sample_style(int $familyId, string $category, int $weight = 400, string $style = 'normal'): string
{
    $fallback = FontLibrary::CATEGORIES[$category] ?? FontLibrary::CATEGORIES['sans'];
    $css = "font-family: '" . FontLibrary::cssFamilyName($familyId) . "', " . $fallback . ';';
    if (FontVariant::isValid($weight, $style)) {
        $css .= ' font-weight: ' . $weight . '; font-style: ' . $style . ';';
    }

    return ' style="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '"';
}

/** "Vet", "Cursief", "Halfvet cursief": what a variant is called in the CMS. */
function admin_font_variant_label(int $weight, string $style): string
{
    return FontVariant::label($weight, $style, static fn (string $key, array $replace): string => admin_t($key, $replace));
}

/**
 * The <option>s of a variant choice, every one of the eighteen, with the
 * technical weight in brackets for who looks for it.
 */
function admin_font_variant_options(string $selected = ''): string
{
    $html = '<option value="">' . admin_te('fonts.variant_choose') . '</option>';
    foreach (FontVariant::keys() as $key) {
        $variant = FontVariant::parse($key);
        $label = admin_font_variant_label($variant['weight'], $variant['style']) . ' (' . $variant['weight'] . ')';
        $html .= '<option value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"'
            . ($key === $selected ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html;
}

/**
 * The choice for one role (headings or body text): the pairing's own font,
 * or a library family that has a file.
 *
 * @param array<int, array<string, mixed>> $usable FontLibrary::usableFamilies()
 */
function admin_font_role_select(string $id, string $name, string $value, array $usable, bool $invalid = false): string
{
    $html = '<select id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="admin-select"'
        . ($invalid ? ' aria-invalid="true"' : '') . ' data-font-role>';
    $html .= '<option value=""' . ($value === '' ? ' selected' : '') . '>' . admin_te('fonts.role_from_pairing') . '</option>';

    foreach ($usable as $familyId => $family) {
        $html .= '<option value="' . (int) $familyId . '"' . ($value === (string) $familyId ? ' selected' : '') . '>'
            . htmlspecialchars((string) $family['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html . '</select>';
}

/** @param list<array<string, mixed>> $variants */
function admin_font_variant_summary(array $variants): string
{
    if ($variants === []) {
        return admin_t('fonts.no_variants');
    }

    $labels = array_map(
        static fn (array $variant): string => admin_font_variant_label((int) $variant['weight'], (string) $variant['style']),
        $variants
    );

    return implode(', ', $labels);
}

/** @param array{site: list<string>, others: list<array{label: string, url: string}>} $usage */
function admin_font_usage_line(array $usage): string
{
    $parts = [];
    if ($usage['site'] !== []) {
        $roles = array_map(static fn (string $role): string => admin_t('fonts.role_' . $role), $usage['site']);
        $parts[] = admin_t('fonts.usage_site', ['roles' => implode(', ', $roles)]);
    }

    foreach ($usage['others'] as $use) {
        $parts[] = $use['label'];
    }

    return admin_t('fonts.used_by', ['users' => implode('; ', $parts)]);
}

/** Bytes as "1,4" megabytes, one decimal. */
function admin_font_megabytes(int $bytes): string
{
    return number_format($bytes / (1024 * 1024), 1, ',', '.');
}

/** Kilobytes for one file: "86 kB". */
function admin_font_kilobytes(int $bytes): string
{
    return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' kB';
}

/**
 * The licence warning. Always visible where fonts are added: plain words,
 * no legal claim the software cannot check.
 */
function admin_font_licence_warning(): string
{
    return '<div class="admin-alert admin-alert--warning" data-font-licence role="note">'
        . '<p><strong>' . admin_te('fonts.licence_title') . '</strong> ' . admin_te('fonts.licence_text') . '</p>'
        . '</div>';
}

/**
 * The manual "Eigen lettertypen toevoegen", as three folded cards: how to
 * add a font (Google Fonts, step by step), what the files mean, and why
 * an icon font such as Font Awesome is something else. Folded, so the help
 * is one click away instead of a wall of text under every field.
 */
function admin_font_help(bool $openFirst = false): string
{
    $card = static function (string $key, string $body, bool $open): string {
        return '<details class="admin-collapse admin-collapse--card admin-font-help" data-font-help="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' . ($open ? ' open' : '') . '>'
            . '<summary class="admin-collapse__summary"><span class="admin-collapse__caret" aria-hidden="true"></span>'
            . '<h2 class="admin-collapse__title">' . admin_te('help.fonts.' . $key . '_title') . '</h2></summary>'
            . '<div class="admin-collapse__body">' . $body . '</div></details>';
    };

    $steps = '';
    for ($step = 1; $step <= 10; $step++) {
        $steps .= '<li>' . admin_t('help.fonts.howto_step_' . $step) . '</li>';
    }

    $howto = '<p>' . admin_t('help.fonts.howto_intro') . '</p><ol class="admin-font-help__steps">' . $steps . '</ol>'
        . '<p>' . admin_t('help.fonts.howto_other_sources') . '</p>'
        . '<p class="admin-text-muted">' . admin_te('help.fonts.howto_limits', [
            'max' => FontFileInspector::maxMegabytes(),
            'total' => (int) round(FontLibrary::MAX_LIBRARY_BYTES / (1024 * 1024)),
        ]) . '</p>';

    $files = '<dl class="admin-font-help__terms">';
    foreach (['regular', 'bold', 'italic', 'variable', 'woff2', 'ttf', 'missing'] as $term) {
        $files .= '<dt>' . admin_te('help.fonts.term_' . $term) . '</dt><dd>' . admin_t('help.fonts.term_' . $term . '_text') . '</dd>';
    }
    $files .= '</dl>';

    $use = '<p>' . admin_t('help.fonts.use_select') . '</p><p>' . admin_t('help.fonts.use_delete') . '</p>';

    $icons = '<p>' . admin_t('help.fonts.icons_text') . '</p><p>' . admin_t('help.fonts.icons_later') . '</p>';

    return $card('howto', $howto, $openFirst)
        . $card('files', $files, false)
        . $card('use', $use, false)
        . $card('icons', $icons, false);
}
