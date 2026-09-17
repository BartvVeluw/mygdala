<?php

declare(strict_types=1);

/**
 * THE localized-fields primitive of Multilingual 2.0 (phase 2,
 * docs/multilingual/ARCHITECTURE.md): an editor screen whose text is stored
 * per website language, one language at a time.
 *
 * The page editor is the first screen on it. It replaces, for such a screen,
 * the V1 panes of admin/_language_fields.php, which render a hidden Dutch and
 * a hidden English copy of every field and cannot hold a third language.
 *
 * WHAT AN EDITOR SEES. The fields of ONE language, under a bar that says
 * which one and whether it is the default:
 *
 *     You are editing: English
 *     Empty means not translated yet. Visitors then see the text in Dutch.
 *
 *     Title   [ ................ ]
 *
 * Language-neutral fields (address, status, switches, images) are not this
 * file's business; they stay on screen in every language.
 *
 * WHICH LANGUAGE. The one the administrator chose in the CMS shell's switch
 * (App\Service\Language\ContentEditingLanguage), when the website has it;
 * otherwise the default language. There is no second language control here:
 * the shell's switch is the only one, so two controls can never disagree.
 * The languages come from the website language registry, never from a list
 * in this file — German is a row in site_languages, not a line of PHP.
 *
 * NOTHING HIDDEN IS SUBMITTED. The form carries the fields of the language on
 * screen plus admin_localized_input(), and its endpoint writes exactly that
 * language. Every other language's text stays where it is in storage, so
 * switching language cannot overwrite a translation with a stale copy, and
 * a site with five languages sends one language's fields, not five.
 *
 * SWITCHING NEVER LOSES TYPING SILENTLY. The switch is a navigation: while a
 * form holds unsaved input, the save bar's leave warning
 * (admin/assets/save-bar.js) asks first.
 *
 * `required` is only ever on the default language: a translation is optional
 * by definition, because every field falls back to the default language.
 */

use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\ContentLanguages;
use App\Service\Language\SiteLanguage;
use App\Service\Language\SiteLanguages;

require_once __DIR__ . '/_translate.php';

/**
 * The website languages an editor can write in, the default first and the
 * rest in the registry's order.
 *
 * @return list<SiteLanguage>
 */
function admin_localized_languages(): array
{
    $default = admin_localized_default();
    $first = [];
    $rest = [];

    foreach (SiteLanguages::active() as $language) {
        if ($language->code === $default) {
            $first[] = $language;
        } else {
            $rest[] = $language;
        }
    }

    return array_merge($first, $rest);
}

/** The website's default language; the V1 adapter's answer if the registry cannot give one. */
function admin_localized_default(): string
{
    try {
        return SiteLanguages::defaultCode();
    } catch (\RuntimeException) {
        return ContentLanguages::primary();
    }
}

/**
 * The language this screen's localized fields are in: the administrator's
 * choice when the website has that language, else the default language.
 */
function admin_localized_language(): string
{
    $chosen = ContentEditingLanguage::current();

    foreach (admin_localized_languages() as $language) {
        if ($language->code === $chosen) {
            return $chosen;
        }
    }

    return admin_localized_default();
}

/**
 * The bar above a group of localized fields: which language they are in,
 * that it is the default language when it is, and what an empty field means
 * when it is not. Prints nothing on a website with a single language, where
 * there is nothing to say.
 *
 * May be printed more than once on a screen (one per card or tab); it holds
 * no state.
 */
function admin_localized_bar(string $language): void
{
    if (count(admin_localized_languages()) < 2) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $default = admin_localized_default();
    $isDefault = $language === $default;

    echo '<div class="admin-lang-bar admin-localized-bar" data-localized-language="' . $h($language) . '">';
    echo '<p class="admin-lang-bar__state">'
        . '<span class="admin-lang-bar__label">' . $h(AdminTranslator::trans('language.editing_indicator')) . '</span> '
        . '<strong class="admin-lang-bar__value">' . $h(admin_website_language_label($language)) . '</strong>'
        . ($isDefault
            ? ' <span class="admin-badge admin-badge--info">' . $h(AdminTranslator::trans('language.default_marker')) . '</span>'
            : '')
        . '</p>';
    echo '<p class="admin-lang-bar__hint">'
        . $h($isDefault
            ? AdminTranslator::trans('language.editing_default_hint')
            : AdminTranslator::trans('language.editing_fallback_hint', ['language' => admin_website_language_label($default)]))
        . '</p>';
    echo '</div>';
}

/**
 * The hidden field that tells the endpoint which language the localized
 * fields of this form hold. Once per form. The endpoint checks it against the
 * registry; it never becomes a column name.
 */
function admin_localized_input(string $language): string
{
    return '<input type="hidden" name="language_code" value="' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '">';
}

/** ` required` on the default language's fields, nothing on a translation's. */
function admin_localized_required(string $language): string
{
    return $language === admin_localized_default() ? ' required' : '';
}

/**
 * The placeholder of a translation field: what a visitor gets while it is
 * empty. Nothing on the default language, whose words are the fallback.
 */
function admin_localized_placeholder_attr(string $language): string
{
    $default = admin_localized_default();

    if ($language === $default) {
        return '';
    }

    return ' placeholder="' . htmlspecialchars(
        AdminTranslator::trans('language.fallback_placeholder', ['language' => admin_website_language_label($default)]),
        ENT_QUOTES,
        'UTF-8'
    ) . '"';
}
