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

// Every screen with language panes also prints translated labels around
// them, so the two helpers travel together.
require_once __DIR__ . '/_translate.php';

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

    admin_lang_translate_bar();
}

/**
 * The "translate into <language>" control that sits under the tab strip.
 *
 * Rendered only when this installation actually has a translation provider
 * with credentials. Automatic translation is an optional extra
 * (App\Service\Translation\NullTranslationProvider), and a button that cannot
 * work is worse than no button — so a fresh Mygdala with no DeepL key simply
 * has a plain pair of language tabs and nothing else.
 */
function admin_lang_translate_bar(): void
{
    if (!admin_lang_has_tabs()) {
        return;
    }

    $service = new \App\Service\Translation\TranslationService();
    $locale = AdminLocale::current();
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $targets = [];
    foreach (ContentLanguages::secondaries() as $code) {
        if ($service->canTranslateInto($code)) {
            $targets[$code] = LanguageRegistry::label($code, $locale);
        }
    }

    if ($targets === []) {
        return;
    }

    [$entityType, $entityKey] = admin_lang_entity();

    echo '<div class="admin-lang-translate" data-lang-translate'
        . ' data-endpoint="/api/admin/translate-fields.php"'
        . ' data-entity-type="' . $h($entityType) . '"'
        . ' data-entity-key="' . $h($entityKey) . '"'
        . ' data-manual-label="' . $h(AdminTranslator::trans('translate.manual')) . '"'
        . ' data-nothing-label="' . $h(AdminTranslator::trans('translate.done')) . '"'
        . ' data-confirm-overwrite="' . $h(AdminTranslator::trans('translate.confirm_overwrite')) . '"'
        . ' data-busy-label="' . $h(AdminTranslator::trans('translate.busy')) . '"'
        . ' data-done-label="' . $h(AdminTranslator::trans('translate.done')) . '">';

    foreach ($targets as $code => $label) {
        echo '<button type="button" class="admin-lang-translate__button"'
            . ' data-translate-target="' . $h($code) . '">'
            . $h(AdminTranslator::trans('translate.action', ['language' => $label]))
            . '</button>';
    }

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

    echo '<script src="' . \App\Service\AssetVersion::url('/admin/assets/admin-language-tabs.js') . '" defer></script>';
}
