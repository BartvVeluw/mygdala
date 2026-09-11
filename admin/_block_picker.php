<?php

declare(strict_types=1);


require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_block_visual.php';

use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinition;

/**
 * The block picker: ONE button under the page's block list, and the panel it
 * opens with a card per content block an editor may put on this page.
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
 * WHAT IS ON A CARD COMES FROM THE BLOCK. Label, description, category, icon
 * and preview are all read off the block's own definition
 * (App\Service\Blocks\BlockDefinition), which is also what the Contentblokken
 * catalogue reads — so a new block appears here, correctly described, with no
 * edit to this file, and a module's block appears and disappears with its
 * module. Registry keys stay internal: nothing here prints a `section_type`
 * to the editor.
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
 * The panel itself. Call once per screen, near the end of the document —
 * same convention as media_picker_modal().
 *
 * @param array<string, BlockDefinition> $available type => definition, from
 *                                                  App\Service\SectionRegistry::availableDefinitionsForPage()
 */
function block_picker_modal(array $available, int $pageId, string $csrfToken): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $groups = BlockCategories::group($available);
    ?>
    <div class="admin-block-picker" data-block-picker hidden aria-hidden="true"
         role="dialog" aria-modal="true" aria-labelledby="admin-block-picker-title">
      <div class="admin-block-picker__backdrop" data-block-picker-close></div>
      <div class="admin-block-picker__panel">
        <header class="admin-block-picker__head">
          <h2 id="admin-block-picker-title"><?= admin_te('blocks.contentblok_kiezen') ?></h2>
          <button type="button" class="admin-block-picker__close" data-block-picker-close aria-label="Sluiten">&times;</button>
        </header>

        <p class="admin-block-picker__intro"><?= admin_te('blocks.klik_blok_onderaan_pagina') ?></p>

        <div class="admin-block-picker__tools">
          <label class="admin-block-picker__search">
            <span class="admin-visually-hidden"><?= admin_te('blocks.zoek_contentblok') ?></span>
            <input type="search" placeholder="Zoeken op naam of omschrijving" data-block-picker-search autocomplete="off">
          </label>
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

            <?php foreach ($groups as $categoryKey => $blocks): ?>
              <section class="admin-block-picker__group" data-block-picker-group="<?= $h($categoryKey) ?>">
                <h3 class="admin-block-picker__group-title"><?= $h(BlockCategories::label($categoryKey)) ?></h3>
                <div class="admin-block-grid">
                  <?php foreach ($blocks as $type => $definition): ?>
                    <?php
                      // Everything the search box matches on, lowercased once
                      // here so the script does no work per keystroke — and
                      // deliberately NOT including the registry key: an
                      // editor never sees `text_image_split`, so they can
                      // never usefully search for it either.
                      $terms = mb_strtolower(
                          $definition->label() . ' ' . $definition->describedFor()
                          . ' ' . BlockCategories::label($definition->category())
                          . ' ' . implode(' ', $definition->useCasesFor())
                      );
                    ?>
                    <button type="submit" name="section_type" value="<?= $h($type) ?>"
                            class="admin-block-card" data-block-card
                            data-block-category="<?= $h($definition->category()) ?>"
                            data-block-terms="<?= $h($terms) ?>">
                      <?php block_visual($definition); ?>
                      <span class="admin-block-card__body">
                        <span class="admin-block-card__name">
                          <?php block_icon_svg($definition, 'admin-block-card__icon'); ?>
                          <span><?= $h($definition->label()) ?></span>
                        </span>
                        <span class="admin-block-card__desc"><?= $h($definition->describedFor()) ?></span>
                      </span>
                      <span class="admin-block-card__add" aria-hidden="true"><?= admin_te('common.add') ?></span>
                    </button>
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
}
