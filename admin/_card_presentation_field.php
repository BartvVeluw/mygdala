<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\CardPresentation;

/**
 * The field "Kaartweergave" (Card Presentation 2.0,
 * App\Service\Blocks\CardPresentation, ADMIN-UI.md "Kaartweergave"): how the
 * cards inside this block look, one choice for the whole block.
 *
 * Plain radio buttons in a fieldset, each with a small sketch, its name and
 * one line on what it is for, so the choice is visible before it is saved
 * and needs no script. Only the presentations the block offers
 * (CardPresentation::offered()); a block that offers none gets no field at
 * all. The sketches are decorative (aria-hidden): the name and the line next
 * to each say everything they hint at.
 *
 * The posted `card_presentation` is checked by the endpoint with
 * CardPresentation::choiceFromRequest(), against the same offer.
 */

/** The sketch of each presentation: boxes in the admin's own colours. */
const ADMIN_CARD_PRESENTATION_SKETCHES = [
    CardPresentation::DEFAULT => '<rect x="3" y="4" width="17" height="22" rx="2" class="admin-cp__fill"/><rect x="23" y="4" width="17" height="22" rx="2" class="admin-cp__fill"/><rect x="43" y="4" width="17" height="22" rx="2" class="admin-cp__fill"/><path d="M6 22h8M26 22h8M46 22h8"/>',
    CardPresentation::COMPACT => '<rect x="3" y="4" width="12" height="10" rx="1.5" class="admin-cp__fill"/><rect x="18" y="4" width="12" height="10" rx="1.5" class="admin-cp__fill"/><rect x="33" y="4" width="12" height="10" rx="1.5" class="admin-cp__fill"/><rect x="48" y="4" width="12" height="10" rx="1.5" class="admin-cp__fill"/><path d="M3 18h8M18 18h8M33 18h8M48 18h8M3 22h11M18 22h11M33 22h11M48 22h11"/>',
    CardPresentation::WIDE => '<rect x="3" y="5" width="57" height="20" rx="2"/><rect x="3" y="5" width="24" height="20" rx="2" class="admin-cp__fill"/><path d="M32 11h20M32 16h24M32 20h14"/>',
];

/**
 * @param BlockDefinition $definition the block the editor belongs to
 * @param string          $current    the stored or handed-back presentation
 * @param string          $id         a prefix for the radio ids, unique on the screen
 */
function admin_card_presentation_field(BlockDefinition $definition, string $current, string $id): void
{
    $offered = CardPresentation::offered($definition);
    if ($offered === []) {
        return;
    }

    $current = in_array($current, $offered, true) ? $current : CardPresentation::DEFAULT;
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $legend = admin_t('card_presentation.legend');
    ?>
      <fieldset class="admin-cp" data-card-presentation-field>
        <legend class="admin-cp__legend"><?= $h($legend) ?><?= admin_help($legend, admin_t('help.card_presentation')) ?></legend>
        <div class="admin-cp__choices">
          <?php foreach ($offered as $presentation): ?>
            <label class="admin-cp__choice" for="<?= $h($id . '-' . $presentation) ?>">
              <svg class="admin-cp__sketch" viewBox="0 0 63 30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= ADMIN_CARD_PRESENTATION_SKETCHES[$presentation] ?? '' ?></svg>
              <span class="admin-cp__name">
                <input type="radio" class="admin-cp__radio" id="<?= $h($id . '-' . $presentation) ?>" name="card_presentation" value="<?= $h($presentation) ?>"<?= $presentation === $current ? ' checked' : '' ?>>
                <?= admin_te('card_presentation.' . $presentation) ?>
              </span>
              <span class="admin-cp__desc"><?= admin_te('card_presentation.' . $presentation . '_hint') ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php
}
