<?php

declare(strict_types=1);


require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_block_visual.php';
require_once __DIR__ . '/_block_library.php';

use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\OffersPickerPresets;

/**
 * The block picker: ONE button under the page's block list — or, while the
 * page has nothing below its heading, the invitation that carries it — and
 * the panel it opens with a card per content block an editor may put on this
 * page.
 *
 * WHAT IT REPLACED. A `<select>` of type names next to a "+ Sectie toevoegen"
 * button. That asked someone to recognise a block from its name alone,
 * before seeing anything, and then to press a second button to confirm a
 * choice they had already made. The picker answers "wat krijg ik als ik dit
 * toevoeg?" on the card itself — a schematic drawing, the block's name and
 * one sentence — and adding is the single click on that card.
 *
 * ONE CLICK, AND IT IS A REAL FORM SUBMIT. Every card is a
 * `<button type="submit" name="section_type" value="...">` inside one
 * ordinary POST form to api/admin/add-page-section.php: the same endpoint,
 * the same CSRF token and the same server-side validation the dropdown used,
 * with the browser doing the submitting. The JavaScript in
 * admin/assets/block-picker.js opens and closes the panel and filters the
 * cards; it never adds a block itself, and it is not the security boundary.
 *
 * WHAT IS ON A CARD COMES FROM THE BLOCK. Label, description, category,
 * example uses, icon and preview are all read off the block's own definition
 * (App\Service\Blocks\BlockDefinition), which is also what the Contentblokken
 * catalogue reads — so a new block appears here, correctly described, with no
 * edit to this file, and a module's block appears and disappears with its
 * module. Registry keys stay internal: nothing here prints a `section_type`
 * to the editor.
 *
 * TWO VIEWS, ONE SET OF BUTTONS. Cards, the default, carry the drawing; the
 * list lays the very same buttons out as rows — name, category, one line of
 * description — for an editor who already knows what they want. The toggle
 * only changes a data attribute admin.css reads, so the search, the category
 * filter and the one-click add are the same code in both views, and the
 * markup holds exactly one submit button per block either way. The choice is
 * a display preference, remembered per browser by block-picker.js.
 *
 * EXAMPLE USES STAY OUT OF THE WAY. A card never lists them all. They are in
 * the search terms, and the one a search matched appears on its card while
 * that search is on — the reason the card is among the results. The
 * catalogue is where they are all written out.
 */

/**
 * The opener. Rendered where the old "+ Sectie toevoegen" form sat: directly
 * under the block list, because that is where the new block will land.
 */
function block_picker_button(): void
{
    ?>
    <div class="admin-add-block">
      <button type="button" class="admin-add-block__button" data-block-picker-open
              aria-haspopup="dialog" aria-expanded="false">
        <span aria-hidden="true"><?= admin_t('blocks.contentblok_toevoegen') ?>
      </button>
    </div>
    <?php
}

/**
 * What the Inhoud tab shows INSTEAD of the opener while a page has nothing
 * below its heading yet (App\Service\SectionRegistry::hasContentBlocks()):
 * one plain sentence saying so, and the way to the first block right under
 * it. Its button is one more opener of the SAME picker — block-picker.js
 * binds every [data-block-picker-open] — so there is no second way to add a
 * block, and no word for an editor to decode.
 *
 * @param bool $canAdd false when no block may be added to this page at all;
 *                     it then says so, instead of offering a button that
 *                     would open an empty picker
 */
function block_picker_empty_state(bool $canAdd): void
{
    ?>
    <div class="admin-blocks-empty" data-block-picker-empty>
      <p class="admin-blocks-empty__title"><?= admin_te('blocks.empty_title') ?></p>
      <?php if ($canAdd): ?>
        <p class="admin-blocks-empty__text"><?= admin_te('blocks.empty_text') ?></p>
        <button type="button" class="admin-btn-primary" data-block-picker-open
                aria-haspopup="dialog" aria-expanded="false">
          <span aria-hidden="true"><?= admin_t('blocks.contentblok_toevoegen') ?>
        </button>
      <?php else: ?>
        <p class="admin-blocks-empty__text"><?= admin_te('blocks.er_pagina_moment_contentblok') ?></p>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * The picker's cards, grouped in BlockCategories order with empty groups left
 * out: one card per block, or one per PRESET for a block that offers presets
 * (App\Service\Blocks\OffersPickerPresets) — the gallery shows as
 * "Collectiegalerij" under Shop and "Portfoliogalerij" under Portfolio, each
 * card its own category, words and starting setting, and all of them the one
 * type. A block whose presets are empty keeps its ordinary card.
 *
 * Only the picker expands presets. The Contentblokken catalogue describes
 * block types and groups them with BlockCategories::group(), so there the
 * gallery is one block under its own category.
 *
 * @param array<string, BlockDefinition> $available type => definition
 *
 * @return array<string, list<array{type: string, preset: ?string, definition: BlockDefinition, label: string, description: string, category: string, use_cases: list<string>}>>
 */
function block_picker_cards(array $available): array
{
    $grouped = array_fill_keys(BlockCategories::keys(), []);

    foreach ($available as $type => $definition) {
        $presets = $definition instanceof OffersPickerPresets ? $definition->pickerPresets() : [];

        if ($presets === []) {
            $grouped[$definition->category()][] = [
                'type' => (string) $type,
                'preset' => null,
                'definition' => $definition,
                'label' => $definition->label(),
                'description' => $definition->describedFor(),
                'category' => $definition->category(),
                'use_cases' => $definition->useCasesFor(),
            ];
            continue;
        }

        foreach ($presets as $preset => $card) {
            $grouped[$card['category']][] = [
                'type' => (string) $type,
                'preset' => (string) $preset,
                'definition' => $definition,
                'label' => $card['label'],
                'description' => $card['description'],
                'category' => $card['category'],
                'use_cases' => $card['use_cases'],
            ];
        }
    }

    return array_filter($grouped, static fn (array $cards): bool => $cards !== []);
}

/**
 * The panel itself. Call once per screen, near the end of the document —
 * same convention as media_picker_modal().
 *
 * @param array<string, BlockDefinition> $available type => definition, from
 *                                                  App\Service\SectionRegistry::availableDefinitionsForPage()
 * @param array<string, string>           $hidden    extra fields the add request carries
 */
function block_picker_modal(array $available, int $pageId, string $csrfToken, array $hidden = []): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $groups = block_picker_cards($available);
    ?>
    <div class="admin-block-picker" data-block-picker data-block-picker-layout="cards" hidden aria-hidden="true"
         role="dialog" aria-modal="true" aria-labelledby="admin-block-picker-title">
      <div class="admin-block-picker__backdrop" data-block-picker-close></div>
      <div class="admin-block-picker__panel">
        <header class="admin-block-picker__head">
          <h2 id="admin-block-picker-title"><?= admin_te('blocks.contentblok_kiezen') ?></h2>
          <button type="button" class="admin-block-picker__close" data-block-picker-close aria-label="Sluiten">&times;</button>
        </header>

        <p class="admin-block-picker__intro"><?= admin_te('blocks.klik_blok_onderaan_pagina') ?></p>

        <div class="admin-block-picker__tools">
          <?php /* The CMS's one search field (.admin-search, ADMIN-UI.md): the
                   magnifier, the height and the focus ring every other admin
                   search box has, rather than a look of its own. */ ?>
          <label class="admin-search admin-block-picker__search">
            <span class="admin-visually-hidden"><?= admin_te('blocks.zoek_contentblok') ?></span>
            <input type="search" placeholder="Zoeken op naam of omschrijving" data-block-picker-search autocomplete="off">
          </label>

          <?php /* Real buttons with aria-pressed, like the filters below, drawn
                   as one segmented control: this decides how the blocks are
                   shown, not which ones. Cards is what the server renders; the
                   script puts a remembered choice in place before the panel
                   ever opens. */ ?>
          <div class="admin-block-picker__views" role="group" aria-label="<?= admin_te('blocks.view_label') ?>">
            <button type="button" class="admin-block-picker__view" data-block-picker-view="cards" aria-pressed="true">
              <svg class="admin-block-picker__view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="4" width="7" height="7" rx="1.5"></rect><rect x="13" y="4" width="7" height="7" rx="1.5"></rect><rect x="4" y="13" width="7" height="7" rx="1.5"></rect><rect x="13" y="13" width="7" height="7" rx="1.5"></rect></svg>
              <span><?= admin_te('blocks.view_cards') ?></span>
            </button>
            <button type="button" class="admin-block-picker__view" data-block-picker-view="list" aria-pressed="false">
              <svg class="admin-block-picker__view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6h11"></path><path d="M9 12h11"></path><path d="M9 18h11"></path><path d="M4.5 6h.01"></path><path d="M4.5 12h.01"></path><path d="M4.5 18h.01"></path></svg>
              <span><?= admin_te('blocks.view_list') ?></span>
            </button>
          </div>

          <?php if (count($groups) > 1): ?>
            <div class="admin-block-picker__filters" role="group" aria-label="Filteren op categorie">
              <button type="button" class="admin-chip is-active" data-block-picker-filter="" aria-pressed="true"><?= admin_te('common.all') ?></button>
              <?php foreach (array_keys($groups) as $categoryKey): ?>
                <button type="button" class="admin-chip" data-block-picker-filter="<?= $h($categoryKey) ?>" aria-pressed="false"><?= $h(BlockCategories::label($categoryKey)) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php /* aria-live so a filtered-away result is announced, not only
                 shown — the grid below it changes silently otherwise. */ ?>
        <p class="admin-block-picker__status" data-block-picker-status role="status" aria-live="polite"></p>

        <?php if ($available === []): ?>
          <p class="admin-text-muted"><?= admin_te('blocks.er_pagina_moment_contentblok') ?></p>
        <?php else: ?>
          <form method="post" action="/api/admin/add-page-section.php" class="admin-block-picker__body" data-no-dirty-track>
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="page_id" value="<?= $pageId ?>">
<?php /* A product or project without a content page yet posts its owner
         instead (admin/_content_blocks.php); the endpoint makes the page with
         the first block. At the left margin, so a page prints nothing extra. */ ?>
<?php foreach ($hidden as $hiddenName => $hiddenValue): ?>
            <input type="hidden" name="<?= $h((string) $hiddenName) ?>" value="<?= $h((string) $hiddenValue) ?>">
<?php endforeach; ?>

            <?php foreach ($groups as $categoryKey => $cards): ?>
              <section class="admin-block-picker__group" data-block-picker-group="<?= $h($categoryKey) ?>">
                <h3 class="admin-block-picker__group-title"><?= $h(BlockCategories::label($categoryKey)) ?></h3>
                <div class="admin-block-grid">
                  <?php foreach ($cards as $card): ?>
                    <?php
                      $type = $card['type'];
                      $definition = $card['definition'];
                      $categoryLabel = BlockCategories::label($card['category']);
                      $useCases = $card['use_cases'];
                      // Everything the search box matches on, lowercased once
                      // here so the script does no work per keystroke — and
                      // deliberately NOT including the registry key: an
                      // editor never sees `text_image_split`, so they can
                      // never usefully search for it either.
                      $terms = mb_strtolower(
                          $card['label'] . ' ' . $card['description']
                          . ' ' . $categoryLabel
                          . ' ' . implode(' ', $useCases)
                      );
                      // A preset card posts its type and preset together
                      // (api/admin/add-page-section.php); an ordinary card
                      // posts its type, as it always did.
                      [$choiceName, $choiceValue] = $card['preset'] === null
                          ? ['section_type', $type]
                          : ['section_preset', $type . ':' . $card['preset']];
                    ?>
                    <?php /* The card is the submit button that adds the block, and a
                             button cannot hold another one: the preview is its
                             neighbour in the same slot. It opens the one preview
                             dialog of admin/_block_library.php with the same
                             sample and the same frame as the Contentblokken
                             library, and adds nothing. */ ?>
                    <div class="admin-block-card-slot" data-block-slot>
                    <button type="submit" name="<?= $h($choiceName) ?>" value="<?= $h($choiceValue) ?>"
                            class="admin-block-card" data-block-card
                            data-block-category="<?= $h($card['category']) ?>"
                            data-block-terms="<?= $h($terms) ?>">
                      <?php block_visual($definition); ?>
                      <span class="admin-block-card__body">
                        <span class="admin-block-card__name">
                          <?php block_icon_svg($definition, 'admin-block-card__icon'); ?>
                          <span data-block-slot-name><?= $h($card['label']) ?></span>
                        </span>
                        <span class="admin-block-card__desc" data-block-slot-description><?= $h($card['description']) ?></span>
                        <?php if ($useCases !== []): ?>
                          <?php /* Hidden until a search matches one of them, and
                                   then only the ones it matched. Hidden text is
                                   no part of the button's name either, so a
                                   screen reader hears it exactly when it shows. */ ?>
                          <span class="admin-block-card__uses" data-block-uses hidden>
                            <span class="admin-block-card__uses-label"><?= admin_te('blocks.geschikt') ?>:</span>
                            <?php foreach ($useCases as $useCase): ?>
                              <span class="admin-block-card__use" data-block-use="<?= $h(mb_strtolower($useCase)) ?>" hidden><?= $h($useCase) ?></span>
                            <?php endforeach; ?>
                          </span>
                        <?php endif; ?>
                      </span>
                      <span class="admin-block-card__meta">
                        <span class="admin-block-card__category" data-block-slot-category><?= $h($categoryLabel) ?></span>
                        <span class="admin-block-card__add" aria-hidden="true"><?= admin_te('common.add') ?></span>
                      </span>
                    </button>
                    <?php block_library_preview_button($type, $definition, 'admin-block-card__preview', null, $card['label']); ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </form>
        <?php endif; ?>

        <footer class="admin-block-picker__foot">
          <p class="admin-text-muted"><?= admin_t('blocks.weten_wat_elk_blok') ?></p>
        </footer>
      </div>
    </div>
    <?php
    if ($available !== []) {
        // The preview the Voorbeeld buttons open: the library's own dialog,
        // driven by admin/assets/block-library.js, which the screen loads.
        block_library_preview_dialog();
    }
}
