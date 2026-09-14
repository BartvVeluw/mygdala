<?php

declare(strict_types=1);

use App\Service\AssetVersion;

/**
 * The shared building blocks of an admin form: field help with its global
 * on/off switch, the info panel, the one native control that needs more
 * markup than a class name — the file input — and the dialog that asks
 * before a form does something that cannot be undone. ADMIN-UI.md is the
 * manual.
 *
 * WHY OUTPUT FUNCTIONS. Same shape as admin/_admin_tabs.php and
 * admin/_admin_collapse.php: the markup of a component lives in one function
 * here, its look in one section of admin.css ("ADMIN UI PRIMITIVES") and its
 * behaviour in one script (admin/assets/admin-ui.js). A screen asks for a
 * component and never writes its markup by hand, so there is one accessible
 * version of it instead of twenty almost-identical ones.
 *
 * Required by admin/_header.php, so every screen that renders the CMS shell
 * has these functions and loads the script. The controls that need nothing
 * but a class on the native element — select, checkbox, switch, search — have
 * no function here on purpose (ADMIN-UI.md lists the classes).
 *
 * HOW A SCREEN USES IT
 *
 *     <div class="admin-field">
 *       <?= admin_field_label('settings-email', admin_t('common.email_address'), admin_t('help.settings.email'), true) ?>
 *       <input type="email" id="settings-email" name="email" ...>
 *     </div>
 *
 *     <?= admin_info_panel(admin_t('help.pages.overview')) ?>
 *
 * THE HELP ICON SITS NEXT TO THE <label>, NEVER INSIDE IT. Inside a label the
 * button's name becomes part of the field's accessible name ("E-mailadres
 * Uitleg over E-mailadres"), and a click anywhere in the open explanation is
 * forwarded to the field. admin_field_label() writes the pair in the right
 * order; a checkbox or switch puts admin_help() after its own label.
 *
 * WHAT IS NOT HERE. The help TEXTS are CMS text and live in the catalog
 * (src/Service/Language/messages/), next to every other sentence an editor
 * reads; a screen passes them in. Nothing here reads a setting, a request or
 * the database, and nothing here decides what a form submits.
 */

/** Where the shell prints the help switch: in the sidebar, and beside the menu button on a narrow screen. */
const ADMIN_HELP_TOGGLE_PLACEMENTS = ['sidebar', 'topbar'];

/**
 * A fresh id for one component on this page. A per-request counter, the same
 * static-slot idea as admin_tabs_stack(): deterministic, and unique however
 * often a screen repeats a field (the Dutch and English panes of one setting
 * both carry its help).
 */
function admin_ui_id(string $prefix): string
{
    static $sequence = 0;
    $sequence++;

    return $prefix . '-' . $sequence;
}

/**
 * CMS text escaped for an element or an attribute. An entity the catalog
 * already wrote (&rsquo;, &mdash;) stays that character instead of being
 * printed literally; a `<` never survives either way.
 */
function admin_ui_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
}

/**
 * Help text as markup: escaped first, always, and then a deliberately tiny
 * allowance given back — <strong> and <em> in matched pairs, a blank line for
 * a new paragraph, a single line break for <br>. The escaped text can never
 * turn back into a tag that carries an attribute, so there is nothing to
 * sanitise and no rich-text renderer to trust.
 *
 * Paragraphs are <span>s rather than <p>s, so an explanation stays phrasing
 * content wherever a screen puts its icon.
 */
function admin_help_text(string $text): string
{
    $escaped = admin_ui_escape(trim($text));

    foreach (['strong', 'em'] as $tag) {
        $escaped = (string) preg_replace(
            '#&lt;' . $tag . '&gt;(.*?)&lt;/' . $tag . '&gt;#s',
            '<' . $tag . '>$1</' . $tag . '>',
            $escaped
        );
    }

    $html = '';

    foreach (preg_split('/\R[ \t]*\R/', $escaped) ?: [] as $paragraph) {
        $paragraph = trim($paragraph);

        if ($paragraph !== '') {
            $html .= '<span class="admin-help__p">' . (string) preg_replace('/\R/', '<br>', $paragraph) . '</span>';
        }
    }

    return $html;
}

/**
 * A "?" beside a field that opens a longer explanation.
 *
 * Hover shows it, a click (or Enter, or a tap) pins it, and the cross, Escape
 * or a click elsewhere closes it again — all of that is admin-ui.js. The
 * markup is what keeps it usable without the script: `popovertarget` is the
 * browser's own open-and-close, so a click still shows the explanation,
 * centred and with its cross, when JavaScript never ran.
 *
 * `popover="manual"` rather than "auto": the script decides when an
 * explanation closes, and an auto popover would drop a pinned one the moment
 * another icon is hovered. The top layer a popover lives in is why an
 * explanation can never end up underneath the sidebar, a modal or the save bar.
 *
 * @param string $subject what the explanation is about — normally the field's
 *                        own label. It heads the explanation and names the button.
 * @param string $body    the explanation, as CMS text (see admin_help_text())
 */
function admin_help(string $subject, string $body): string
{
    $id = admin_ui_id('admin-help');
    $subject = trim($subject);

    return '<span class="admin-help" data-admin-help>'
        . '<button type="button" class="admin-help__trigger" data-admin-help-trigger'
        . ' popovertarget="' . $id . '" aria-controls="' . $id . '"'
        . ' aria-expanded="false" aria-haspopup="dialog"'
        . ' aria-label="' . admin_ui_escape(admin_t('ui.help.open', ['subject' => $subject])) . '">'
        . '<span class="admin-help__mark" aria-hidden="true">?</span>'
        . '</button>'
        . '<span class="admin-help__popover" id="' . $id . '" popover="manual" role="dialog"'
        . ' aria-labelledby="' . $id . '-title" data-admin-help-popover>'
        . '<span class="admin-help__head">'
        . '<strong class="admin-help__title" id="' . $id . '-title">' . admin_ui_escape($subject) . '</strong>'
        . '<button type="button" class="admin-help__close" data-admin-help-close'
        . ' popovertarget="' . $id . '" popovertargetaction="hide"'
        . ' aria-label="' . admin_ui_escape(admin_t('ui.help.close')) . '">'
        . '<span aria-hidden="true">&times;</span>'
        . '</button>'
        . '</span>'
        . '<span class="admin-help__body">' . admin_help_text($body) . '</span>'
        . '</span>'
        . '</span>';
}

/**
 * The label row of one field: the <label> and, when there is an explanation,
 * its help icon beside it — in that order and never nested (see the file
 * docblock). The caller writes the field itself, with the matching id.
 *
 * @param string $for      the id of the field this labels
 * @param string $label    CMS text; also what the explanation is about
 * @param string $help     the explanation, or '' for a label without one
 * @param bool   $required appends the "*" every admin form marks a required field with
 */
function admin_field_label(string $for, string $label, string $help = '', bool $required = false): string
{
    return '<div class="admin-field__label">'
        . '<label for="' . admin_ui_escape($for) . '">' . admin_ui_escape($label) . ($required ? '*' : '') . '</label>'
        . ($help !== '' ? admin_help($label, $help) : '')
        . '</div>';
}

/**
 * A short explanation at the top of a screen: what this screen is for, in
 * words for somebody who has never run a website.
 *
 * Optional, and part of the help. Switching help off in the shell hides it
 * (admin.css), so a screen never needs a switch of its own for it.
 */
function admin_info_panel(string $body): string
{
    return '<div class="admin-info-panel" role="note" data-admin-help-panel>'
        . '<span class="admin-info-panel__icon" aria-hidden="true">i</span>'
        . '<span class="admin-info-panel__text">' . admin_help_text($body) . '</span>'
        . '</div>';
}

/**
 * The global help switch in the CMS shell.
 *
 * One preference for the whole CMS, remembered per browser by admin-ui.js.
 * The server always renders it as on, the default for somebody who has never
 * chosen; the script puts the stored choice on <html> before the first paint,
 * and admin.css reads the visible state from there. Without the script the
 * switch could not do anything, so it is not shown and help simply stays on.
 *
 * aria-pressed carries the state for a screen reader, the "aan"/"uit" word
 * carries it for everybody else: a state shown only in colour is not a state.
 */
function admin_help_toggle(string $placement): string
{
    if (!in_array($placement, ADMIN_HELP_TOGGLE_PLACEMENTS, true)) {
        $placement = 'sidebar';
    }

    return '<button type="button" class="admin-help-toggle admin-help-toggle--' . $placement . '"'
        . ' data-admin-help-toggle aria-pressed="true">'
        . '<span class="admin-help-toggle__mark" aria-hidden="true">?</span>'
        . '<span class="admin-help-toggle__label">' . admin_ui_escape(admin_t('ui.help.toggle')) . '</span>'
        . '<span class="admin-help-toggle__state admin-help-toggle__state--on" aria-hidden="true">' . admin_ui_escape(admin_t('ui.help.state_on')) . '</span>'
        . '<span class="admin-help-toggle__state admin-help-toggle__state--off" aria-hidden="true">' . admin_ui_escape(admin_t('ui.help.state_off')) . '</span>'
        . '</button>';
}

/**
 * A file input in the admin's own style, with the native <input type="file">
 * still underneath doing all of the work: the file dialog, `required`,
 * `accept`, `multiple`, keyboard focus, the submission, and whatever upload
 * script a screen already binds to it (admin.js looks for input[type="file"]
 * and still finds it).
 *
 * With admin-ui.js the native control is laid invisibly over its own trigger
 * and a line naming the chosen file; without it, admin.css styles the
 * browser's own button instead, so uploading never depends on the script.
 * The two visual parts are aria-hidden: a screen reader keeps hearing the
 * real control, which already announces what was chosen.
 *
 * It sits inside the field's <label> like a plain file input does — it IS
 * the labelled control. Drag and drop and upload progress belong to the
 * Media Library (MEDIA.md): this changes how the control looks, not how
 * uploading works.
 *
 * @param array<string, string|bool> $attributes the native input's attributes, as the
 *        template would have written them — name, accept, required, multiple, id.
 *        true writes a bare attribute and false leaves it out. Written in a
 *        template, never taken from a request: `type` is always "file", and an
 *        inline event handler is refused.
 */
function admin_file_input(array $attributes): string
{
    $multiple = ($attributes['multiple'] ?? false) === true;
    $html = '<span class="admin-file" data-admin-file><input type="file" class="admin-file__input"';

    foreach ($attributes as $name => $value) {
        $name = strtolower((string) $name);

        if (
            $value === false
            || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1
            || in_array($name, ['type', 'class'], true)
            || str_starts_with($name, 'on')
        ) {
            continue;
        }

        $html .= ' ' . $name . ($value === true ? '' : '="' . admin_ui_escape((string) $value) . '"');
    }

    $none = admin_t('ui.file.none');

    return $html . '>'
        . '<span class="admin-file__button" aria-hidden="true">' . admin_ui_escape(admin_t($multiple ? 'ui.file.choose_many' : 'ui.file.choose')) . '</span>'
        . '<span class="admin-file__name" aria-hidden="true" data-admin-file-name'
        . ' data-admin-file-none="' . admin_ui_escape($none) . '"'
        . ' data-admin-file-many="' . admin_ui_escape(admin_t('ui.file.many')) . '">'
        . admin_ui_escape($none)
        . '</span>'
        . '</span>';
}

/**
 * The CMS's own "are you sure?", for a form that does something that cannot
 * be undone. A screen prints it ONCE, near the end of the document — the same
 * convention as media_picker_modal() — and marks every form that should ask
 * with admin_confirm_attributes(). The words of each question travel on its
 * form; this is only the dialog they are shown in.
 *
 * WHY NOT confirm(). The browser's own question looks different in every
 * browser, cannot name its buttons in the CMS's words and offers "OK" where
 * an editor should read what is about to happen. A native <dialog> opened
 * with showModal() is modal by itself: the page behind it is inert, Tab stays
 * inside and Escape answers "no", with no focus trap of our own to trust.
 *
 * THE FORM STILL DOES THE WORK. admin-ui.js holds the submit back, asks, and
 * on "yes" hands the very same form back to the browser: the same request,
 * the same CSRF token, the same endpoint and the same server-side guards.
 * The dialog decides nothing and is never the security boundary.
 *
 * "No" comes first in the source, so it is where focus lands and what a
 * stray Enter presses. The <form method="dialog"> around both answers only
 * closes the dialog with the pressed button's value; it sends nothing.
 */
function admin_confirm_dialog(): string
{
    $headingId = admin_ui_id('admin-confirm-title');
    $textId = admin_ui_id('admin-confirm-text');
    $title = admin_ui_escape(admin_t('ui.confirm.title'));
    $accept = admin_ui_escape(admin_t('ui.confirm.accept'));

    return '<dialog class="admin-confirm" data-admin-confirm-dialog'
        . ' aria-labelledby="' . $headingId . '" aria-describedby="' . $textId . '">'
        . '<form method="dialog" class="admin-confirm__panel">'
        . '<h2 class="admin-confirm__title" id="' . $headingId . '" data-admin-confirm-heading'
        . ' data-admin-confirm-default="' . $title . '">' . $title . '</h2>'
        . '<p class="admin-confirm__message" id="' . $textId . '" data-admin-confirm-text></p>'
        . '<div class="admin-confirm__actions">'
        . '<button type="submit" value="cancel" class="admin-btn-secondary" data-admin-confirm-no>'
        . admin_ui_escape(admin_t('ui.confirm.cancel'))
        . '</button>'
        . '<button type="submit" value="confirm" class="admin-btn-danger" data-admin-confirm-yes'
        . ' data-admin-confirm-default="' . $accept . '">' . $accept . '</button>'
        . '</div>'
        . '</form>'
        . '</dialog>';
}

/**
 * What makes one form ask before it is sent, for its opening tag:
 *
 *     <form method="post" action="/api/admin/delete-…" class="admin-inline-form"<?= admin_confirm_attributes(
 *         admin_t('…title'), admin_t('…message', ['name' => $name]), admin_t('common.delete')
 *     ) ?>>
 *
 * CMS text, escaped here and nowhere else, so an editor's own title can be
 * part of the question. An empty title or button name leaves the dialog's
 * own words in place.
 *
 * Without the script the form is sent straight away, exactly as it was with
 * the inline confirm() this replaces. A screen that marks a form but never
 * prints admin_confirm_dialog() still asks: the script falls back to the
 * browser's own question rather than send without one.
 *
 * @param string $title   the question itself, short: "Contentblok verwijderen?"
 * @param string $message what will happen, in a sentence or two
 * @param string $action  the name of the button that goes ahead: "Verwijderen"
 */
function admin_confirm_attributes(string $title, string $message, string $action = ''): string
{
    $attributes = ' data-admin-confirm="' . admin_ui_escape($message) . '"';

    if ($title !== '') {
        $attributes .= ' data-admin-confirm-title="' . admin_ui_escape($title) . '"';
    }

    if ($action !== '') {
        $attributes .= ' data-admin-confirm-action="' . admin_ui_escape($action) . '"';
    }

    return $attributes;
}

/**
 * The behaviour, printed by admin/_header.php as the first thing in <body>.
 *
 * Deliberately NOT deferred, unlike every other admin script: it has to put a
 * stored "help off" on <html> before the first paint, or a screen would flash
 * every help icon and info panel it is about to hide. It is small, cached, and
 * does nothing else until something is hovered or clicked.
 */
function admin_ui_script(): void
{
    echo '<script src="' . htmlspecialchars(AssetVersion::url('/admin/assets/admin-ui.js'), ENT_QUOTES, 'UTF-8') . '"></script>';
}
