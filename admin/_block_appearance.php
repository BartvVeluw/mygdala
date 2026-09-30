<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;

/**
 * The "Extra vormgeving" panel of ONE block instance (CONTENT-BLOCKS.md,
 * "Extra vormgeving"), drawn inside that block's row in the shared block list
 * (admin/_content_blocks.php) — so a page, a product and a project get the
 * same panel, and no block editor has a styling card of its own. There is
 * one place to choose a block's look, never two that could disagree.
 *
 * Closed by default: the list stays one line per block. It shows only what
 * this block type supports (BlockDefinition::appearanceSupport()); a block
 * that supports nothing gets no panel at all. The summary line says what was
 * chosen, so a closed panel still tells the editor the block has a look.
 *
 * The form is its own, posting to api/admin/update-block-appearance.php with
 * the row's page_sections id. It is kept out of the screen's save bar
 * (data-no-dirty-track): the bar saves the page form, and a change here is
 * saved with the panel's own button.
 *
 * @param array<string, mixed> $pageSection one page_sections row of the list (never a draft: a draft is not in the list)
 */
function block_appearance_panel(array $pageSection, string $csrfToken): void
{
    $type = (string) ($pageSection['section_type'] ?? '');

    if (!BlockDefinitions::has($type)) {
        return;
    }

    $support = BlockDefinitions::get($type)->appearanceSupport();

    if ($support->isEmpty()) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = (int) $pageSection['id'];
    $values = BlockAppearance::effective($pageSection, $support);
    $prefix = 'block-appearance-' . $id . '-';

    $select = static function (string $field, array $options) use ($h, $values, $prefix): void {
        ?>
        <div class="admin-field">
          <?= admin_field_label($prefix . $field, BlockAppearance::fieldLabel($field), $field === 'border_tone' ? '' : admin_t('help.appearance.' . $field)) ?>
          <select class="admin-select" id="<?= $h($prefix . $field) ?>" name="<?= $h($field) ?>">
            <?php foreach ($options as $word): ?>
              <option value="<?= $h($word) ?>"<?= $values[$field] === $word ? ' selected' : '' ?>><?= $h(BlockAppearance::label($field, $word)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php
    };
    ?>
  <details class="admin-collapse block-appearance-panel" data-block-appearance-panel="<?= $id ?>">
    <summary class="admin-collapse__summary block-appearance-panel__summary">
      <span class="admin-collapse__caret" aria-hidden="true"></span>
      <span class="block-appearance-panel__title"><?= admin_te('appearance.panel_title') ?></span>
      <span class="block-appearance-panel__current"><?= $h(BlockAppearance::summary($values)) ?></span>
    </summary>
    <form method="post" action="/api/admin/update-block-appearance.php" class="block-appearance-panel__form" data-no-dirty-track>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <p class="block-appearance-panel__intro admin-text-muted"><?= admin_help_text(admin_t('appearance.panel_intro')) ?></p>
      <div class="block-appearance-panel__fields">
        <?php if ($support->background) { $select('background', BlockAppearance::BACKGROUNDS); } ?>
        <?php if ($support->borders) { $select('border', BlockAppearance::BORDERS); $select('border_tone', BlockAppearance::BORDER_TONES); } ?>
        <?php if ($support->spacing) { $select('spacing', BlockAppearance::SPACINGS); } ?>
        <?php if ($support->decorations !== []) { $select('decoration', ['none', ...$support->decorations]); } ?>
      </div>
      <div class="block-appearance-panel__actions">
        <button type="submit" class="admin-btn-secondary"><?= admin_te('appearance.save') ?></button>
      </div>
    </form>
  </details>
    <?php
}
