<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_localized_fields.php';

/**
 * The markup of a list of child rows inside ONE block-editor form (a FAQ's
 * questions, a Cijferbalk's figures, a Detailsectie's points and images):
 * the screen half of App\Service\Blocks\EditorChildList, and what
 * admin/assets/row-list.js works on (PAGE-EDITOR.md, "Eén formulier per
 * blok-editor").
 *
 * A screen prints, per list:
 *
 *     <input type="hidden" name="items_present" value="1">
 *     <div class="admin-row-cards" data-row-list="faq-items">
 *       foreach row: editor_row_open(), its own fields, editor_row_close()
 *       <noscript> one empty row, key editor_rows_free_key($rows) </noscript>
 *     </div>
 *     editor_rows_status('faq-items');
 *     editor_rows_add('faq-items', 'Vraag toevoegen');
 *     <template data-row-list-template="faq-items"> the empty row, key "__KEY__" </template>
 *
 * A row's fields are named editor_row_name($list, $key, $field). No field of
 * a row carries `required`: a row marked for removal, or the empty row a
 * screen offers without JavaScript, must never keep the form from being
 * sent. The server checks what is required, and a refused save puts the
 * message next to the field (editor_field_invalid(), editor_field_error()).
 *
 * The CMS words here are the editor's own; nothing on this file prints words
 * of the website.
 */

/**
 * The rows a list shows: as a refused save handed them back (in their order,
 * with their marks and new rows), else as stored. A stored row the handback
 * does not name (added elsewhere meanwhile) follows at the end; a handed-back
 * row that no longer exists is left out.
 *
 * @param list<array<string, mixed>> $storedRows the block's rows, each with an `id`
 * @param list<array{key: string, fields: array<string, string>}>|null $old EditorChildList::old(), or null
 * @param callable(array<string, mixed>): array<string, string> $storedFields a stored row's fields as the form names them
 * @return list<array{key: string, id: int, fields: array<string, string>, stored: array<string, mixed>|null}>
 */
function editor_rows_on_screen(array $storedRows, ?array $old, callable $storedFields): array
{
    $byId = [];
    foreach ($storedRows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    $rows = [];
    $shown = [];

    foreach ($old ?? [] as $handed) {
        $key = (string) ($handed['key'] ?? '');
        $id = ctype_digit($key) ? (int) $key : 0;
        if ($id > 0 && !isset($byId[$id])) {
            continue;
        }
        if ($id === 0 && !preg_match('/^new[0-9]{1,4}$/', $key)) {
            continue;
        }

        $rows[] = ['key' => $key, 'id' => $id, 'fields' => (array) ($handed['fields'] ?? []), 'stored' => $byId[$id] ?? null];
        if ($id > 0) {
            $shown[$id] = true;
        }
    }

    foreach ($byId as $id => $row) {
        if (!isset($shown[$id])) {
            $rows[] = ['key' => (string) $id, 'id' => $id, 'fields' => $storedFields($row), 'stored' => $row];
        }
    }

    return $rows;
}

/**
 * A key for the empty row a list offers without JavaScript: "new<n>" that no
 * handed-back row uses yet.
 *
 * @param list<array{key: string}> $rows
 */
function editor_rows_free_key(array $rows): string
{
    $taken = array_column($rows, 'key');
    $n = 0;
    while (in_array('new' . $n, $taken, true)) {
        $n++;
    }

    return 'new' . $n;
}

/** `<list>[<key>][<field>]`: the name of one field of one row. */
function editor_row_name(string $list, string $key, string $field): string
{
    return $list . '[' . $key . '][' . $field . ']';
}

/** A stable DOM id for one field of one row. */
function editor_row_id(string $list, string $key, string $field): string
{
    return 'row-' . preg_replace('/[^a-z0-9_-]/i', '-', $list . '-' . $key . '-' . $field);
}

/** ` aria-invalid="true" aria-describedby="…"` on a field with a message of its own, else nothing. */
function editor_field_invalid(array $errors, string $errorKey): string
{
    if (!isset($errors[$errorKey])) {
        return '';
    }

    return ' aria-invalid="true" aria-describedby="' . htmlspecialchars(editor_error_id($errorKey), ENT_QUOTES, 'UTF-8') . '"';
}

/** The message under a field, when it has one. */
function editor_field_error(array $errors, string $errorKey): void
{
    if (isset($errors[$errorKey])) {
        echo '<p class="admin-field-error" id="' . htmlspecialchars(editor_error_id($errorKey), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) $errors[$errorKey], ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

function editor_error_id(string $errorKey): string
{
    return 'error-' . preg_replace('/[^a-z0-9_-]/i', '-', $errorKey);
}

/**
 * The start of one row: a fieldset named "<noun> <place>" (row-list.js keeps
 * the place right after a move), the marker that says the row was on the
 * form, and the row's tools: ↑, ↓ and the removal mark. $class is an extra
 * class for a screen's own styling of its rows.
 */
function editor_row_open(string $list, string $key, string $noun, int $position, int $count, bool $removed, string $class = ''): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $isNew = !ctype_digit($key);
    ?>
    <fieldset class="admin-row-card<?= $class !== '' ? ' ' . $h($class) : '' ?>" data-row-list-row>
      <legend class="admin-row-card__legend"><?= $h($noun) ?> <span data-row-list-number><?= $key === '__KEY__' ? '' : $position + 1 ?></span><?php if ($isNew): ?> <span class="admin-badge admin-badge--info"><?= admin_te('editor_rows.nieuw') ?></span><?php endif; ?></legend>
      <input type="hidden" name="<?= $h(editor_row_name($list, $key, 'present')) ?>" value="1">
      <div class="admin-row-card__head">
        <span class="admin-row-card__tools">
          <span class="admin-option-row__move">
            <button type="submit" class="admin-btn-ghost admin-option-row__move-button" name="editor_action" value="<?= $h($list . ':up:' . $key) ?>" data-row-list-move="up" aria-label="<?= admin_te('editor_rows.omhoog') ?>"<?= $position === 0 ? ' disabled' : '' ?>><span aria-hidden="true">&uarr;</span></button>
            <button type="submit" class="admin-btn-ghost admin-option-row__move-button" name="editor_action" value="<?= $h($list . ':down:' . $key) ?>" data-row-list-move="down" aria-label="<?= admin_te('editor_rows.omlaag') ?>"<?= $position >= $count - 1 ? ' disabled' : '' ?>><span aria-hidden="true">&darr;</span></button>
          </span>
          <label class="admin-btn-text admin-btn-text--danger admin-row-card__remove">
            <input type="checkbox" class="admin-visually-hidden admin-row-card__remove-input" name="<?= $h(editor_row_name($list, $key, 'remove')) ?>" value="1" data-row-list-removing<?= $removed ? ' checked' : '' ?>>
            <span class="admin-row-card__remove-on"><?= admin_te('common.delete') ?></span>
            <span class="admin-row-card__remove-off"><?= admin_te('editor_rows.niet_verwijderen') ?></span>
          </label>
        </span>
      </div>
      <p class="admin-row-card__removing"><?= admin_te('editor_rows.wordt_verwijderd') ?></p>
      <div class="admin-row-card__body">
    <?php
}

function editor_row_close(): void
{
    echo "      </div>\n    </fieldset>\n";
}

/**
 * One text field of a row, with its label, its value and its own message.
 * $rows > 0 makes it a textarea of that many lines; $attributes is extra
 * markup for the control (a placeholder), already escaped.
 *
 * @param array<string, string> $fields the row's values
 * @param array<string, string> $errors field errors keyed `<list>.<key>.<field>`
 */
function editor_row_text(string $list, string $key, string $field, string $label, int $maxLength, array $fields, array $errors, string $attributes = '', int $rows = 0): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = editor_row_id($list, $key, $field);
    $name = editor_row_name($list, $key, $field);
    $errorKey = $list . '.' . $key . '.' . $field;
    $value = (string) ($fields[$field] ?? '');

    echo '<div class="admin-field">';
    echo admin_field_label($id, $label);
    if ($rows > 0) {
        echo '<textarea id="' . $h($id) . '" name="' . $h($name) . '" maxlength="' . $maxLength . '" rows="' . $rows . '"'
            . $attributes . editor_field_invalid($errors, $errorKey) . '>' . $h($value) . '</textarea>';
    } else {
        echo '<input type="text" id="' . $h($id) . '" name="' . $h($name) . '" maxlength="' . $maxLength . '" value="' . $h($value) . '"'
            . $attributes . editor_field_invalid($errors, $errorKey) . '>';
    }
    editor_field_error($errors, $errorKey);
    echo '</div>';
}

/**
 * One rich-text field of a row: the shared editor of admin/_richtext_field.php
 * (a textarea that admin/assets/admin.js turns into the Quill editor, also in
 * a row row-list.js adds later), with the row's own message. The server
 * sanitizes what it gets (TranslatableField::rich()); the editor is not the
 * boundary.
 *
 * @param array<string, string> $fields the row's values
 * @param array<string, string> $errors field errors keyed `<list>.<key>.<field>`
 */
function editor_row_rich(string $list, string $key, string $field, string $label, array $fields, array $errors, string $toolbar = 'full'): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = editor_row_id($list, $key, $field);
    $errorKey = $list . '.' . $key . '.' . $field;

    echo '<div class="admin-field admin-richtext-field" data-richtext-field data-richtext-toolbar="' . $h($toolbar) . '" data-richtext-size="">';
    echo '<label for="' . $h($id) . '">' . $h($label) . '</label>';
    echo '<textarea id="' . $h($id) . '" name="' . $h(editor_row_name($list, $key, $field)) . '" class="admin-richtext-fallback" data-richtext-source rows="6"'
        . editor_field_invalid($errors, $errorKey) . '>' . $h((string) ($fields[$field] ?? '')) . '</textarea>';
    editor_field_error($errors, $errorKey);
    echo '</div>';
}

/**
 * A choice between a few named options of a row, as a segmented control
 * (.admin-segmented) in a fieldset named $legend. $options is value => Dutch
 * label; $data names a data attribute each radio carries with its value, for
 * a screen's CSS to read (admin.css, `.admin-tis-item`).
 *
 * @param array<string, string> $options
 */
function editor_row_choice(string $list, string $key, string $field, string $legend, array $options, string $chosen, string $data = ''): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    echo '<fieldset class="admin-segmented-field"><legend>' . $h($legend) . '</legend><div class="admin-segmented">';
    foreach ($options as $value => $label) {
        echo '<label class="admin-segmented__option"><input type="radio" name="' . $h(editor_row_name($list, $key, $field)) . '" value="' . $h((string) $value) . '"'
            . ($data !== '' ? ' data-' . $h($data) . '="' . $h((string) $value) . '"' : '')
            . ((string) $value === $chosen ? ' checked' : '') . '> <span>' . $h($label) . '</span></label>';
    }
    echo '</div></fieldset>';
}

/**
 * A row's image: the Media picker (admin/_media_picker.php) under the row's
 * own `media_id`, with its message. The picker hands back an id and nothing
 * else; the endpoint resolves it. The screen prints media_picker_modal() and
 * media_picker_script() once.
 *
 * @param array<string, string> $fields the row's values
 * @param array<string, string> $errors field errors keyed `<list>.<key>.<field>`
 */
function editor_row_media(string $list, string $key, array $fields, array $errors, string $label, string $help = '', bool $clearable = false): void
{
    require_once __DIR__ . '/_media_picker.php';

    $errorKey = $list . '.' . $key . '.media_id';
    $mediaId = (int) ($fields['media_id'] ?? 0);

    echo '<div class="admin-field">';
    media_picker_field(editor_row_name($list, $key, 'media_id'), $mediaId > 0 ? \App\Service\Media\MediaService::find($mediaId) : null, $label, $help, $clearable);
    editor_field_error($errors, $errorKey);
    echo '</div>';
}

/**
 * A row's own alt text, linked to the row's image (media_alt_field() in
 * admin/_media_picker.php): it shows the alt text the image really gets, and
 * choosing another image fills in that one's. $translationPlaceholder is the
 * screen's admin_localized_placeholder_attr(); it applies to a stored row
 * only, because a new row is written in the default language.
 */
function editor_row_media_alt(string $list, string $key, array $fields, array $errors, string $translationPlaceholder = ''): void
{
    require_once __DIR__ . '/_media_picker.php';

    $mediaId = (int) ($fields['media_id'] ?? 0);
    $alt = media_alt_field(
        editor_row_name($list, $key, 'media_id'),
        (string) ($fields['alt'] ?? ''),
        $mediaId > 0 ? \App\Service\Media\MediaService::find($mediaId) : null,
        ctype_digit($key) ? $translationPlaceholder : ''
    );

    editor_row_text($list, $key, 'alt', admin_t('common.alt_text'), 255, ['alt' => $alt['value']] + $fields, $errors, $alt['attributes']);
}

/** A row's own switch (`active`): whether it is shown on the website. */
function editor_row_switch(string $list, string $key, array $fields, string $label): void
{
    echo '<label class="admin-checkbox-label">'
        . '<input type="checkbox" class="admin-switch" role="switch" name="' . htmlspecialchars(editor_row_name($list, $key, 'active'), ENT_QUOTES, 'UTF-8') . '" value="1"'
        . (($fields['active'] ?? '') !== '' ? ' checked' : '') . '> '
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
}

/**
 * What a row's words need on this screen: a new row is written in the
 * default language, so its required fields are marked and it carries no
 * "empty = the default language" placeholder; a stored row follows the
 * language on screen.
 *
 * @return array{string, string} [the marker after a required label, the placeholder attribute]
 */
function editor_row_word_hints(string $key, string $marker, string $placeholder): array
{
    return ctype_digit($key) ? [$marker, $placeholder] : ['*', ''];
}

/** The spoken line that says where a moved row went. Once per list. */
function editor_rows_status(string $listId): void
{
    echo '<p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="'
        . htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') . '" data-row-list-moved="' . admin_te('editor_rows.verplaatst') . '"></p>';
}

/**
 * The button that adds an empty row on screen (row-list.js), hidden until the
 * script shows it: without JavaScript the list carries one empty row instead.
 * The note under it says a new row is written in the default language when
 * the screen shows another one.
 */
function editor_rows_add(string $listId, string $label, string $editLanguage): void
{
    echo '<div class="admin-option-rows__tools">'
        . '<button type="button" class="admin-btn-secondary" data-row-list-add="' . htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') . '" hidden>+ '
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></div>';
    admin_localized_new_item_note($editLanguage);
}
