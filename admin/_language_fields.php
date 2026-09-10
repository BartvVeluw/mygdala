<?php

declare(strict_types=1);

/**
 * The one language-tabs pattern every content editor in this CMS uses.
 *
 * THE PROBLEM IT SOLVES. Every editor screen used to print a Dutch field and
 * an English field side by side, on every site, whether or not that site had
 * anything to say in English. On a Dutch-only website that is two fields to
 * read, two to tab past and one to wonder about for every single thing an
 * editor writes. Multilingual V1 exists because a real editor said so
 * (MULTILINGUAL.md).
 *
 * WHAT IT DOES, in the two cases that exist:
 *
 *   one content language    the primary language's fields, and nothing else.
 *                           No tabs, no "(NL)" suffixes, no second column —
 *                           editing feels single-language, because it is.
 *
 *   two content languages   one tab strip per form, switching every
 *                           localized field at once. Not a tab strip per
 *                           field: a screen with eight of them is worse than
 *                           the two columns it replaced.
 *
 * WHY A DISABLED LANGUAGE IS STILL RENDERED, hidden.
 * This is the part that keeps the promise in Part Q of the brief: turning a
 * language off must never erase what is stored in it. The endpoints of this
 * project write every column of their form on every save (that is what makes
 * a partial POST dangerous here, see PAGE-EDITOR.md), so a field that is
 * simply left out of the markup would be saved as empty and the translation
 * would be gone.
 *
 * So the field is still there, still carries its stored value, and still
 * submits it — it is just marked `hidden`, which takes it out of the
 * rendering, out of the tab order and out of the accessibility tree. Not one
 * of the ~77 write endpoints had to change, and no editor can lose a
 * translation by toggling a setting.
 *
 * WHY `required` ONLY EVER APPEARS ON THE PRIMARY LANGUAGE.
 * A hidden control that is `required` and empty makes a browser refuse to
 * submit the form while being unable to show the editor what is wrong ("An
 * invalid form control is not focusable"). Only the primary language's
 * controls are ever required, and admin-language-tabs.js lifts `required`
 * off a pane while that pane is the hidden one.
 */

use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\AdminLocale;

/** @var array<string, bool> guards against printing the tab script twice */
$GLOBALS['admin_lang_state'] ??= ['tabs_rendered' => false, 'script_rendered' => false, 'pane' => null];

/**
 * Does this screen need a language switcher at all? False on a
 * single-language site, which is the whole point.
 */
function admin_lang_has_tabs(): bool
{
    return ContentLanguages::isMultilingual();
}

/** @return string[] the languages an editor may type in, primary first */
function admin_lang_codes(): array
{
    return ContentLanguages::enabled();
}

function admin_lang_primary(): string
{
    return ContentLanguages::primary();
}

/**
 * The tab strip. Call it once per form, above the first localized field.
 *
 * Prints nothing on a single-language site — a switcher between one option
 * is furniture, not navigation.
 */
function admin_lang_tabs(string $formId = ''): void
{
    if (!admin_lang_has_tabs()) {
        return;
    }

    $locale = AdminLocale::current();
    $primary = admin_lang_primary();
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    echo '<div class="admin-lang-tabs" role="tablist" aria-label="' . $h(AdminTranslator::trans('language.tab_label')) . '"'
        . ($formId !== '' ? ' data-lang-scope="' . $h($formId) . '"' : '') . '>';

    foreach (admin_lang_codes() as $code) {
        $definition = LanguageRegistry::get($code);
        if ($definition === null) {
            continue;
        }

        $isActive = $code === $primary;

        echo '<button type="button" class="admin-lang-tab' . ($isActive ? ' is-active' : '') . '"'
            . ' role="tab" data-lang-tab="' . $h($code) . '"'
            . ' aria-selected="' . ($isActive ? 'true' : 'false') . '"'
            . ' tabindex="' . ($isActive ? '0' : '-1') . '">'
            . $h($definition->labelIn($locale))
            . ($code === $primary ? '' : '<span class="admin-lang-tab__badge" data-lang-untranslated hidden>•</span>')
            . '</button>';
    }

    echo '</div>';
}

/**
 * Open one language's pane.
 *
 * Everything printed until admin_lang_pane_end() belongs to $code and is
 * shown, hidden by the tab strip, or hidden outright when the site does not
 * publish that language.
 */
function admin_lang_pane_start(string $code): void
{
    $enabled = ContentLanguages::isEnabled($code);
    $isPrimary = $code === admin_lang_primary();

    // Hidden when the site does not publish this language at all, and when
    // the site does but another tab is open. The first is a setting, the
    // second is a UI state — but both mean "not on screen right now", and the
    // markup does not need to tell them apart.
    $hidden = !$enabled || !$isPrimary;

    $GLOBALS['admin_lang_state']['pane'] = $code;

    echo '<div class="admin-lang-pane" data-lang-pane="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
        . ($enabled ? '' : ' data-lang-disabled="1"')
        . ($hidden ? ' hidden' : '')
        . '>';
}

function admin_lang_pane_end(): void
{
    $GLOBALS['admin_lang_state']['pane'] = null;

    echo '</div>';
}

/**
 * ` required`, but only for the primary language.
 *
 * Use this instead of writing `required` in a localized field. A translation
 * is optional by definition — the site falls back to the primary language —
 * and a required control inside a hidden pane is a form that cannot be
 * submitted and cannot say why.
 */
function admin_lang_required(string $code): string
{
    return $code === admin_lang_primary() ? ' required' : '';
}

/**
 * The placeholder a translation field carries: "empty = same as <primary>".
 *
 * Built from the site's actual primary language rather than the word "NL",
 * because on an English-primary site the fallback runs the other way and a
 * hardcoded "Leeg = zelfde als NL" would be a lie.
 */
function admin_lang_fallback_placeholder(string $code): string
{
    if ($code === admin_lang_primary()) {
        return '';
    }

    $locale = AdminLocale::current();
    $primaryLabel = LanguageRegistry::label(admin_lang_primary(), $locale);

    return $locale === 'en'
        ? 'Empty = same as ' . $primaryLabel
        : 'Leeg = zelfde als ' . $primaryLabel;
}

/** The same thing, escaped and ready to drop into an attribute. */
function admin_lang_placeholder_attr(string $code): string
{
    $placeholder = admin_lang_fallback_placeholder($code);

    return $placeholder === '' ? '' : ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * The script that switches the tabs. Call once, before </body>, on any screen
 * that called admin_lang_tabs().
 */
function admin_lang_tabs_script(): void
{
    if (!admin_lang_has_tabs() || ($GLOBALS['admin_lang_state']['script_rendered'] ?? false)) {
        return;
    }

    $GLOBALS['admin_lang_state']['script_rendered'] = true;

    echo '<script src="/admin/assets/admin-language-tabs.js" defer></script>';
}
