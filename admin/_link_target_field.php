<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Routing\LinkChoice;
use App\Service\Routing\LinkTargets;

/**
 * THE DESTINATION PICKER (2.0, Pages & Destinations 3.0; CONTENT-BLOCKS.md
 * "Waar een knop heen gaat"): where a block's button or card goes. First the
 * KIND — no button, a page, a blog post, a product, a collection, a Portfolio
 * project, an own address; only kinds whose module is on
 * (App\Service\Routing\LinkTargets) — then only the picker of that kind:
 *
 *   - a page from a list in the Pages overview's tree order, indented, a
 *     concept marked (App\Service\PageOptions);
 *   - a product, collection, project or blog post from a searchable list
 *     with its picture and its status: admin/assets/destination-picker.js
 *     builds it from this field's own <select>, which stays the value that
 *     is posted and, without the script, the whole picker;
 *   - an own address, checked by App\Service\Routing\SafeUrl.
 *
 * What it posts is what App\Service\Routing\LinkChoice::fromRequest() reads;
 * how it is stored and resolved is that class's docblock.
 *
 * WHAT THE EDITOR IS TOLD, next to the choice: a destination a visitor cannot
 * open yet (a concept, an inactive product, a hidden project) — the button
 * appears once it can; one that can no longer be chosen (deleted, or under a
 * module that is off) — kept, not shown on the website, choose another; and
 * a kind whose module is off, by the module's name. Nothing stored is thrown
 * away by opening and saving the form (LinkChoice keeps it).
 *
 * ONE FIELD FOR EVERY BLOCK BUTTON (the Kaarten-carrousel's card, the
 * Homepage Hero's two buttons, the Oproep met knop, the Tekstblok, Tekst met
 * afbeelding and the Hover-kaarten grid), so they cannot drift apart. Showing
 * only the part that belongs to the chosen kind is admin/assets/navigation-
 * item.js, through the data-nav-link-* attributes; with several buttons on
 * one form the caller wraps each one (this field plus whatever only matters
 * while it is a button, such as its label) in its own [data-nav-link-group].
 * Without the scripts every part is on screen and the server stores only the
 * one that belongs to the kind. A screen that prints this field loads
 * destination-picker.js next to navigation-item.js (link_target_scripts()).
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
    $disabledModule = $keepsUnavailable ? LinkTargets::disabledModuleOf($storedType) : null;
    $label = $field['label'] ?? admin_t('link_choice.type');
    ?>
      <div class="admin-destination" data-destination>
      <div class="admin-field">
        <?= admin_field_label($id . '-type', $label, admin_t('help.link_choice.type')) ?>
        <select class="admin-select" id="<?= $h($id) ?>-type" name="<?= $h($field['type_name']) ?>" data-nav-link-type<?= $field['invalid'] ?? '' ?>>
          <?php if ($allowNone): ?>
            <option value="<?= LinkChoice::NONE ?>"<?= $type === LinkChoice::NONE ? ' selected' : '' ?>><?= admin_te('link_choice.none') ?></option>
          <?php endif; ?>
          <?php foreach (array_keys($types) as $kind): ?>
            <option value="<?= $h($kind) ?>"<?= $type === $kind ? ' selected' : '' ?>><?= $h(LinkTargets::label($kind)) ?></option>
          <?php endforeach; ?>
          <option value="<?= LinkChoice::URL ?>"<?= $type === LinkChoice::URL ? ' selected' : '' ?>><?= admin_te('link_choice.url') ?></option>
          <?php if ($keepsUnavailable): ?>
            <option value="<?= $h($storedType) ?>"<?= $type === $storedType ? ' selected' : '' ?>><?= $disabledModule !== null ? admin_te('link_choice.unavailable_module', ['module' => $disabledModule]) : admin_te('link_choice.unavailable') ?></option>
          <?php endif; ?>
        </select>
        <?php if (isset($field['error'])) { ($field['error'])(); } ?>
      </div>

      <?php foreach (array_keys($types) as $kind): ?>
        <?php
          $selected = (int) ($field['targets'][$kind] ?? 0);
          $choices = link_target_choices($kind);
          $chosen = null;
          foreach ($choices as $choice) {
              if ((int) $choice['id'] === $selected && empty($choice['context'])) {
                  $chosen = $choice;
              }
          }
          $search = LinkTargets::picker($kind) === LinkTargets::PICKER_SEARCH;
          $kindLabel = LinkTargets::label($kind);
        ?>
        <div class="admin-field admin-destination__panel" data-nav-link-field="<?= $h($kind) ?>"<?= $search ? link_target_search_attributes($kindLabel) : '' ?>>
          <?= admin_field_label($id . '-' . $kind, $kindLabel) ?>
          <select class="admin-select" id="<?= $h($id . '-' . $kind) ?>" name="<?= $h($field['target_name']) ?>[<?= $h($kind) ?>]" data-destination-select="<?= $h($kind) ?>">
            <option value=""><?= admin_te('link_choice.choose') ?></option>
            <?php if ($selected > 0 && $chosen === null): ?>
              <?php /* The stored destination can no longer be chosen: it
                       stays selected, so saving keeps it (LinkChoice). */ ?>
              <option value="<?= $selected ?>" selected data-note="gone" data-name="#<?= $selected ?>"><?= admin_te('link_choice.gone_option', ['id' => (string) $selected]) ?></option>
            <?php endif; ?>
            <?php foreach ($choices as $choice): ?>
              <?php $note = (string) ($choice['note'] ?? ''); ?>
              <option value="<?= (int) $choice['id'] ?>"<?= !empty($choice['context']) ? ' disabled' : ($selected === (int) $choice['id'] ? ' selected' : '') ?><?= $note !== '' ? ' data-note="' . $h($note) . '"' : '' ?><?= isset($choice['thumbnail']) ? ' data-thumbnail="' . $h((string) $choice['thumbnail']) . '"' : '' ?> data-name="<?= $h((string) preg_replace('/^[\x{00A0}\x{2013}\s]+/u', '', (string) $choice['label'])) ?>"><?= $h($choice['label']) ?><?= $note !== '' ? ' ' . admin_te('link_choice.note_' . $note) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($selected > 0 && $chosen === null): ?>
            <p class="admin-alert admin-alert--warning admin-destination__warning" role="status" data-destination-warning="<?= $selected ?>"><?= admin_te('link_choice.gone_warning') ?></p>
          <?php elseif ($chosen !== null && isset($chosen['note'])): ?>
            <p class="admin-destination__note" data-destination-warning="<?= $selected ?>"><?= admin_te('link_choice.not_public_' . $chosen['note']) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="admin-field" data-nav-link-field="<?= LinkChoice::URL ?>">
        <?= admin_field_label($id . '-url', admin_t('link_choice.url_label'), admin_t('help.link_choice.url_label')) ?>
        <input type="text" id="<?= $h($id) ?>-url" name="<?= $h($field['url_name']) ?>" maxlength="255" value="<?= $h($field['url']) ?>" placeholder="/contact of https://…">
      </div>

      <?php if ($keepsUnavailable): ?>
        <p class="admin-alert admin-alert--warning" data-nav-link-field="<?= $h($storedType) ?>"><?= $disabledModule !== null ? admin_te('link_choice.unavailable_module_uitleg', ['module' => $disabledModule]) : admin_te('link_choice.unavailable_uitleg') ?></p>
      <?php endif; ?>
      </div>
    <?php
}

/**
 * The choices of one kind, asked once per screen: a form with ten cards asks
 * for the list of products once, not ten times. Only for the screen being
 * rendered — an endpoint asks LinkTargets itself, fresh.
 *
 * @return list<array<string, mixed>>
 */
function link_target_choices(string $kind): array
{
    static $cache = [];

    return $cache[$kind] ??= LinkTargets::choices($kind);
}

/**
 * The words the search list of a kind shows, handed to the script as data
 * (ADMIN-UI.md: an admin script holds no text of its own).
 */
function link_target_search_attributes(string $kindLabel): string
{
    // "Zoek een product op naam": the kind in running text.
    $kindLabel = mb_strtolower($kindLabel);
    $texts = [
        'search_label' => admin_t('link_choice.search_label', ['kind' => $kindLabel]),
        'search_placeholder' => admin_t('link_choice.search_placeholder'),
        'results_label' => admin_t('link_choice.results_label', ['kind' => $kindLabel]),
        'chosen' => admin_t('link_choice.chosen'),
        'nothing_chosen' => admin_t('link_choice.nothing_chosen'),
        'count' => admin_t('link_choice.result_count'),
        'count_more' => admin_t('link_choice.result_count_more'),
        'no_results' => admin_t('link_choice.no_results'),
        'note_draft' => admin_t('link_choice.badge_draft'),
        'note_inactive' => admin_t('link_choice.badge_inactive'),
        'note_hidden' => admin_t('link_choice.badge_hidden'),
        'note_gone' => admin_t('link_choice.badge_gone'),
    ];

    return ' data-destination-search data-destination-texts="'
        . htmlspecialchars((string) json_encode($texts, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * The two script tags a screen with this field needs: the part that shows
 * only the chosen kind (shared with the header and footer editors) and the
 * search list. Deferred, like every admin script but admin-ui.js.
 */
function link_target_scripts(): void
{
    foreach (['/admin/assets/navigation-item.js', '/admin/assets/destination-picker.js'] as $path) {
        echo '<script src="' . htmlspecialchars(\App\Service\AssetVersion::url($path), ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }
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
