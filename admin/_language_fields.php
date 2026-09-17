<?php

declare(strict_types=1);

/**
 * The one localized-fields pattern every content editor in this CMS uses.
 *
 * WHAT AN EDITOR SEES. One language at a time - whichever one the
 * administrator picked in the CMS shell's "Editing content" switch
 * (App\Service\Language\ContentEditingLanguage). Never Dutch and English
 * side by side, and never a second language control of its own:
 *
 *     Editing: English          <- passive, says where you are
 *
 *     Title      [ ................ ]
 *     Intro      [ ................ ]
 *     Button     [ ................ ]
 *
 * WHY THERE IS NO TAB STRIP HERE ANY MORE. There used to be one per form,
 * and it was the only way to reach the English version of anything. That put
 * the language choice in the wrong place twice over: it was per screen, so an
 * editor re-picked English on every page they opened, and it was invisible
 * from everywhere else in the CMS. The choice is one global piece of editor
 * state now, so this file prints a passive indicator instead of a second
 * control that could disagree with the first one.
 *
 * WHY THE LANGUAGE YOU ARE NOT EDITING IS STILL RENDERED, hidden.
 * The endpoints of this project write every column of their form on every
 * save (that is what makes a partial POST dangerous here, see
 * PAGE-EDITOR.md), so a field simply left out of the markup would be saved as
 * empty and the translation would be gone.
 *
 * So the field is still there, still carries its stored value, and still
 * submits it - it is just marked `hidden`, which takes it out of the
 * rendering, out of the tab order and out of the accessibility tree. Not one
 * of the ~77 write endpoints had to change, and no editor can lose a
 * translation by switching language.
 *
 * WHAT A TRANSLATION FIELD SHOWS IS THE STORED VALUE, NEVER THE FALLBACK.
 * The public site falls back to the primary language when a translation is
 * missing (App\Service\Language\LocalizedValue::in()), but an editor must
 * see the field as EMPTY, or they cannot tell "translated" from "not
 * translated yet" - and the next Save would write the fallback in as if it
 * were a real translation. Editors read ::raw(); visitors read ::in().
 *
 * WHY `required` FOLLOWS THE VISIBLE LANGUAGE.
 * A control that is `required`, empty and inside a `hidden` element makes a
 * browser refuse to submit while being unable to show the editor what is
 * wrong ("An invalid form control is not focusable"). `required` is printed
 * only on the primary language's controls AND only while the primary language
 * is the one on screen, so the situation cannot arise. Server-side validation
 * is unchanged and is still the real boundary.
 */

use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\AdminLocale;

// Every screen with language panes also prints translated labels around
// them, so the two helpers travel together.
require_once __DIR__ . '/_translate.php';

/** @var array<string, bool> guards against printing the indicator or the script twice */
$GLOBALS['admin_lang_state'] ??= ['indicator_rendered' => false, 'script_rendered' => false, 'pane' => null];

/**
 * Does this site publish more than one language, and therefore does an editor
 * screen have anything to say about languages at all?
 */
function admin_lang_has_tabs(): bool
{
    return count(ContentLanguages::enabled()) > 1;
}

/**
 * The language this screen's fields are showing: the administrator's own
 * choice from the CMS shell, not a per-screen state.
 *
 * A screen built on these panes can only store the V1 pair. Since the shell
 * also offers the website's other languages (Multilingual 2.0 phase 2), a
 * choice this screen cannot store shows the default language instead, and
 * the bar says so (admin_lang_bar()). Only screens on per-language storage
 * (admin/_localized_fields.php) follow that choice exactly.
 */
function admin_lang_current(): string
{
    $current = ContentEditingLanguage::current();

    return ContentLanguages::isEnabled($current) ? $current : ContentLanguages::primary();
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
 * The localized-fields bar. Call it once per form, above the first localized
 * field.
 *
 * It prints which language the fields below are in, and - when this
 * installation has a translation provider - the button that fills them from
 * the other language. It is deliberately NOT a control: the administrator
 * changes editing language once, in the CMS shell, and every screen follows.
 * A second switcher here could disagree with the first one, and an editor
 * would have no way to tell which of the two the Save button believed.
 *
 * THE INDICATOR IS PRINTED ONCE PER SCREEN, the translate button once per
 * FORM. Several editors here are a column of small forms - six on a detail
 * section, five in the personalization builder - and each of them needs its
 * own translate button, because translation reads the fields of the form it
 * sits in. Saying "Editing: English" six times down the same page is just
 * noise, and the CMS shell says it too.
 *
 * Prints nothing on a site with a single content language.
 */
function admin_lang_bar(string $formId = ''): void
{
    if (!admin_lang_has_tabs()) {
        return;
    }

    if ($GLOBALS['admin_lang_state']['indicator_rendered'] ?? false) {
        admin_lang_translate_bar();

        return;
    }

    $GLOBALS['admin_lang_state']['indicator_rendered'] = true;

    $locale = AdminLocale::current();
    $current = admin_lang_current();
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    echo '<div class="admin-lang-bar"'
        . ($formId !== '' ? ' data-lang-scope="' . $h($formId) . '"' : '')
        . ' data-lang-current="' . $h($current) . '"'
        . ' data-lang-primary="' . $h(admin_lang_primary()) . '">';

    echo '<p class="admin-lang-bar__state">'
        . '<span class="admin-lang-bar__label">' . $h(AdminTranslator::trans('language.editing_indicator')) . '</span> '
        . '<strong class="admin-lang-bar__value">' . $h(LanguageRegistry::label($current, $locale)) . '</strong>'
        . '</p>';

    // The shell asked for a language this screen cannot store yet: say which
    // one it is showing instead, rather than letting the editor type German
    // into a Dutch field.
    $chosen = ContentEditingLanguage::current();
    if ($chosen !== $current) {
        echo '<p class="admin-lang-bar__hint">'
            . $h(AdminTranslator::trans('language.editing_not_on_this_screen', [
                'chosen' => admin_website_language_label($chosen),
                'language' => LanguageRegistry::label($current, $locale),
            ]))
            . '</p>';
    }

    // Says out loud what an empty field means here, so nobody reads a blank
    // English input as "the CMS lost my text". The public site falls back;
    // this form does not.
    if ($current !== admin_lang_primary()) {
        echo '<p class="admin-lang-bar__hint">'
            . $h(AdminTranslator::trans('language.editing_fallback_hint', [
                'language' => LanguageRegistry::label(admin_lang_primary(), $locale),
            ]))
            . '</p>';
    }

    echo '</div>';

    admin_lang_translate_bar();
}

/**
 * The "translate from <language>" control that sits under the indicator.
 *
 * It only ever offers to fill the language the editor is LOOKING AT, from the
 * other one. That is the whole interaction: you switch the CMS to English,
 * you see empty English fields, and there is one button that offers to fill
 * them from the Dutch you already wrote.
 *
 * It is never automatic. Switching editing language translates nothing - an
 * editor who wanted machine output asks for it, and what a person wrote by
 * hand is never silently replaced (App\Service\Translation\TranslationState
 * ::mayOverwrite()).
 *
 * Rendered only when this installation actually has a translation provider
 * with credentials. Automatic translation is an optional extra
 * (App\Service\Translation\NullTranslationProvider), and a button that
 * cannot work is worse than no button - so a fresh Mygdala with no DeepL key
 * simply shows the indicator and nothing else.
 */
function admin_lang_translate_bar(): void
{
    if (!admin_lang_has_tabs()) {
        return;
    }

    $service = new \App\Service\Translation\TranslationService();
    $locale = AdminLocale::current();
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $target = admin_lang_current();
    $source = ContentEditingLanguage::source();

    if ($source === null || $source === $target || !$service->canTranslateInto($target, $source)) {
        return;
    }

    [$entityType, $entityKey] = admin_lang_entity();

    echo '<div class="admin-lang-translate" data-lang-translate'
        . ' data-endpoint="/api/admin/translate-fields.php"'
        . ' data-entity-type="' . $h($entityType) . '"'
        . ' data-entity-key="' . $h($entityKey) . '"'
        . ' data-source-language="' . $h($source) . '"'
        . ' data-target-language="' . $h($target) . '"'
        . ' data-manual-label="' . $h(AdminTranslator::trans('translate.manual')) . '"'
        . ' data-nothing-label="' . $h(AdminTranslator::trans('translate.done')) . '"'
        . ' data-confirm-overwrite="' . $h(AdminTranslator::trans('translate.confirm_overwrite')) . '"'
        . ' data-busy-label="' . $h(AdminTranslator::trans('translate.busy')) . '"'
        . ' data-done-label="' . $h(AdminTranslator::trans('translate.done')) . '">';

    echo '<button type="button" class="admin-lang-translate__button"'
        . ' data-translate-target="' . $h($target) . '">'
        . $h(AdminTranslator::trans('translate.action_from', [
            'language' => LanguageRegistry::label($source, $locale),
        ]))
        . '</button>';

    echo '<p class="admin-lang-translate__status" role="status" aria-live="polite"></p>';
    echo '</div>';
}

/**
 * WHICH content row this editor screen is editing, as a (type, key) pair.
 *
 * Derived here rather than passed in by every editor, and that is the whole
 * reason translation state works across ~30 screens without a line of wiring
 * in any of them. Both halves come from the SERVER, never from the form:
 *
 *   type  the editor script's own filename — 'cta-band', 'blog-post'. Fixed
 *         at deploy time, so it cannot be influenced by a request at all.
 *   key   whatever this CMS already addresses the row by, which for a content
 *         block is the `<page_slug>:<section_key>` pair in ?section=
 *         (CONTENT-BLOCKS.md), and otherwise ?id=.
 *
 * The editor has already refused to render unless that parameter names a page
 * and a content row that really exist — every block editor in this project
 * does that before it prints anything. By the time this runs, the value has
 * been validated by the screen that owns it.
 *
 * It is stored as DATA in one column of one table and is never concatenated
 * into a query, a class name or a path. An empty key simply means "no state
 * tracking on this screen", which degrades to treating every existing
 * translation as hand-written — the protective direction.
 *
 * @return array{0: string, 1: string}
 */
function admin_lang_entity(): array
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $type = $script === '' ? '' : basename($script, '.php');

    foreach (['section', 'id'] as $parameter) {
        $value = trim((string) ($_GET[$parameter] ?? ''));
        if ($value !== '' && strlen($value) <= 191) {
            return [$type, $value];
        }
    }

    return [$type, ''];
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

    // Exactly one pane is on screen: the language this administrator chose to
    // edit in. Everything else is `hidden` and still submits its stored
    // value, which is what makes switching language lossless.
    $hidden = !$enabled || $code !== admin_lang_current();

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
 * What an OVERVIEW row calls one of its items, in the site's primary
 * language.
 *
 * The list screens used to print both languages next to each other
 * ("Contact / Contact"), which is the same complaint as the double fields one
 * level down: on a single-language site it is one name written twice, and on
 * a two-language site it is a name plus a translation nobody asked to see in
 * a list. The row now says what the site says.
 *
 * Falls back the way everything else does (App\Service\Language\LocalizedValue):
 * an empty primary value shows whatever IS filled in, because a nameless row
 * in a list cannot be clicked with any confidence.
 *
 * @param array<string, mixed> $row  a repository row carrying `<base>_nl` etc.
 * @param string $base               the column name without its language suffix
 */
function admin_lang_summary(array $row, string $base): string
{
    $primary = trim((string) ($row[$base . '_' . admin_lang_primary()] ?? ''));
    if ($primary !== '') {
        return $primary;
    }

    foreach (LanguageRegistry::codes() as $code) {
        $value = trim((string) ($row[$base . '_' . $code] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * ` required`, for the primary language AND only while it is the language on
 * screen.
 *
 * Use this instead of writing `required` in a localized field. Two reasons,
 * and they are different:
 *
 *   a translation is optional by definition - the site falls back to the
 *   primary language - so a translation field is never required;
 *
 *   a required, empty control inside a `hidden` element makes the browser
 *   refuse to submit the form while being unable to focus the offending
 *   field, so the primary language drops `required` too while somebody is
 *   editing the other one.
 *
 * The second half used to be done by JavaScript after the fact. The server
 * knows which pane is visible at render time, so it decides here.
 */
function admin_lang_required(string $code): string
{
    return ($code === admin_lang_primary() && $code === admin_lang_current()) ? ' required' : '';
}

/**
 * The placeholder a translation field carries: what a VISITOR will get while
 * this field is still empty.
 *
 * Built from the site's actual primary language rather than the word "NL",
 * because on an English-primary site the fallback runs the other way and a
 * hardcoded "Leeg = zelfde als NL" would be a lie.
 *
 * It deliberately does not say "empty = same as Dutch", which reads as if the
 * two are equivalent. They are not: the visitor gets the Dutch words, and
 * this field is still untranslated.
 */
function admin_lang_fallback_placeholder(string $code): string
{
    if ($code === admin_lang_primary()) {
        return '';
    }

    $locale = AdminLocale::current();
    $primaryLabel = LanguageRegistry::label(admin_lang_primary(), $locale);

    // It describes what a VISITOR gets, not what this field holds. The field
    // itself stays visibly empty until somebody translates it - that is the
    // distinction the editor needs and the placeholder must not blur.
    return $locale === 'en'
        ? 'Not translated - visitors see the text in ' . $primaryLabel
        : 'Niet vertaald - bezoekers zien de tekst in het ' . $primaryLabel;
}

/** The same thing, escaped and ready to drop into an attribute. */
function admin_lang_placeholder_attr(string $code): string
{
    $placeholder = admin_lang_fallback_placeholder($code);

    return $placeholder === '' ? '' : ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * The script that drives automatic translation. Call once, before </body>,
 * on any screen that called admin_lang_bar().
 */
function admin_lang_script(): void
{
    if (!admin_lang_has_tabs() || ($GLOBALS['admin_lang_state']['script_rendered'] ?? false)) {
        return;
    }

    $GLOBALS['admin_lang_state']['script_rendered'] = true;

    echo '<script src="' . \App\Service\AssetVersion::url('/admin/assets/admin-language-translate.js') . '" defer></script>';
}
