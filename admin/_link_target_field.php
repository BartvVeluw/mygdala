<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Routing\LinkChoice;
use App\Service\Routing\LinkTargets;

/**
 * The "where does this button go" field of a content block: a choice of kind
 * (no button, a page, a blog post, a product, an own address), one list of
 * items per internal kind, and the address field. What it posts is what
 * App\Service\Routing\LinkChoice::fromRequest() reads; how it is stored and
 * resolved is that class's docblock.
 *
 * ONE FIELD FOR EVERY BLOCK BUTTON. The Kaarten-carrousel's card had this
 * markup inline; the Homepage Hero's two buttons now use it as well, so the
 * two can never drift apart. Showing only the part that belongs to the chosen
 * kind is admin/assets/navigation-item.js, through the data-nav-link-*
 * attributes; with several buttons on one form the caller wraps each one
 * (this field plus whatever only matters while it is a button, such as its
 * label) in its own [data-nav-link-group]. Without the script every part is on screen and the
 * server stores only the one that belongs to the kind.
 *
 * @param array{
 *     id: string,             DOM id prefix, unique on the screen
 *     type_name: string,      name of the kind select
 *     target_name: string,    name prefix of the per-kind lists: <target_name>[<kind>]
 *     url_name: string,       name of the address field
 *     type: string,           the kind on screen (LinkChoice::NONE, ::URL or a LinkTargets type)
 *     targets: array<string, int>, the chosen id per kind on screen
 *     url: string,            the address on screen
 *     stored_type?: string,   the kind as stored, to keep one whose module is off
 *     allow_none?: bool,      whether "Geen knop" is offered (default true)
 *     invalid?: string,       aria attributes for the kind select when it has a message
 *     error?: callable(): void, prints the message under the kind (editor_field_error())
 *     label?: string,         the kind's label (default "Waar gaat de knop heen?")
 * } $field
 */
function link_target_field(array $field): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = $field['id'];
    $type = $field['type'] === '' ? LinkChoice::NONE : $field['type'];
    $storedType = (string) ($field['stored_type'] ?? '');
    $allowNone = $field['allow_none'] ?? true;
    $types = LinkTargets::types();
    // A button that points at a kind whose module is switched off keeps it.
    $keepsUnavailable = !in_array($storedType, ['', LinkChoice::NONE, LinkChoice::URL], true) && !isset($types[$storedType]);
    $label = $field['label'] ?? admin_t('link_choice.type');
    ?>
      <div class="admin-field">
        <?= admin_field_label($id . '-type', $label, admin_t('help.link_choice.type')) ?>
        <select class="admin-select" id="<?= $h($id) ?>-type" name="<?= $h($field['type_name']) ?>" data-nav-link-type<?= $field['invalid'] ?? '' ?>>
          <?php if ($allowNone): ?>
            <option value="<?= LinkChoice::NONE ?>"<?= $type === LinkChoice::NONE ? ' selected' : '' ?>><?= admin_te('link_choice.none') ?></option>
          <?php endif; ?>
          <?php foreach ($types as $kind => $definition): ?>
            <option value="<?= $h($kind) ?>"<?= $type === $kind ? ' selected' : '' ?>><?= $h((string) $definition['label']) ?></option>
          <?php endforeach; ?>
          <option value="<?= LinkChoice::URL ?>"<?= $type === LinkChoice::URL ? ' selected' : '' ?>><?= admin_te('link_choice.url') ?></option>
          <?php if ($keepsUnavailable): ?>
            <option value="<?= $h($storedType) ?>"<?= $type === $storedType ? ' selected' : '' ?>><?= admin_te('link_choice.unavailable') ?></option>
          <?php endif; ?>
        </select>
        <?php if (isset($field['error'])) { ($field['error'])(); } ?>
      </div>

      <?php foreach ($types as $kind => $definition): ?>
        <?php $selected = (int) ($field['targets'][$kind] ?? 0); ?>
        <div class="admin-field" data-nav-link-field="<?= $h($kind) ?>">
          <?= admin_field_label($id . '-' . $kind, (string) $definition['label']) ?>
          <select class="admin-select" id="<?= $h($id . '-' . $kind) ?>" name="<?= $h($field['target_name']) ?>[<?= $h($kind) ?>]">
            <option value=""><?= admin_te('link_choice.choose') ?></option>
            <?php foreach (LinkTargets::choices($kind) as $choice): ?>
              <option value="<?= (int) $choice['id'] ?>"<?= $selected === (int) $choice['id'] ? ' selected' : '' ?>><?= $h($choice['label']) ?><?= isset($choice['note']) ? ' ' . admin_te('link_choice.note_' . $choice['note']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>

      <div class="admin-field" data-nav-link-field="<?= LinkChoice::URL ?>">
        <?= admin_field_label($id . '-url', admin_t('link_choice.url_label'), admin_t('help.link_choice.url_label')) ?>
        <input type="text" id="<?= $h($id) ?>-url" name="<?= $h($field['url_name']) ?>" maxlength="255" value="<?= $h($field['url']) ?>" placeholder="/contact of https://…">
      </div>

      <?php if ($keepsUnavailable): ?>
        <p class="admin-text-muted" data-nav-link-field="<?= $h($storedType) ?>"><?= admin_te('link_choice.unavailable_uitleg') ?></p>
      <?php endif; ?>
    <?php
}

/**
 * The kinds on which a button is shown — every one except "Geen knop" — for
 * a field that only matters while there is a button (the button's label):
 * its data-nav-link-field value.
 */
function link_target_shown_kinds(string $storedType = ''): string
{
    $kinds = array_merge(array_keys(LinkTargets::types()), [LinkChoice::URL]);

    if (!in_array($storedType, ['', LinkChoice::NONE, LinkChoice::URL], true) && !in_array($storedType, $kinds, true)) {
        $kinds[] = $storedType;
    }

    return implode(' ', $kinds);
}
