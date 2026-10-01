<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';

/**
 * The three fields of a block's optional head (App\Service\Blocks\BlockHead):
 * Bovenkop, Titel and Tekst, in the language being edited, under the
 * language bar of admin/_localized_fields.php. Plain text, like every block
 * heading; the lengths are BlockHead::fields()'s.
 *
 * $word answers the words of one field on screen (handed back after a refused
 * save, else stored in this language), $placeholder is
 * admin_localized_placeholder_attr()'s attribute.
 *
 * @param callable(string): string $word
 */
function admin_block_head_fields(callable $word, string $placeholder, string $language): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
      <?php admin_localized_bar($language); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_head.eyebrow') ?>
          <input type="text" name="eyebrow" maxlength="255" value="<?= $h($word('eyebrow')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title" maxlength="255" value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_head.lead') ?>
          <textarea name="lead" maxlength="600" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
        </label>
      </div>
    <?php
}
