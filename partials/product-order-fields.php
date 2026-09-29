<?php

declare(strict_types=1);

/**
 * The order questions of a product on its page (Shop Product & Ordering 2.0,
 * MODULES.md "Bestelvelden"): what a customer fills in before the product
 * goes in the cart — "Naam op het bord". Drawn by product.php inside the add
 * row, right above the quantity and the button, from
 * App\Service\OrderFields\OrderFields::questions(); nothing for a product
 * that asks nothing, and never for one "op aanvraag" (it has no cart).
 *
 * assets/js/shop/shop.js reads the answers when "Toevoegen aan winkelwagen"
 * is pressed, says what is missing next to the question, and puts them on
 * the cart line: two different answers are two lines. That check is only
 * for the customer's convenience — api/checkout.php checks every answer
 * again (App\Service\OrderFields\OrderFields::validate()).
 *
 * Every word is the product's own, in the language of the page, escaped.
 *
 * AN IMAGE QUESTION ("Afbeelding uploaden", Shop Admin UX & Order Fields 2.0)
 * is a file control with the formats and the size it takes, a small local
 * preview once a picture is chosen, and buttons to replace or remove it.
 * shop.js uploads the picture as soon as it is chosen
 * (api/order-field-upload.php) and keeps only the token it gets back; the
 * picture never goes into the cart, localStorage or the page as data.
 */

/**
 * $scope prefixes every id and a radio group's name, so the same product's
 * questions twice on one page (two Uitgelicht product blocks) never share a
 * label target or a radio group. The product page passes '' and keeps the
 * ids it always had.
 *
 * @param list<array{id: int, type: string, required: bool, max_length: int, max_bytes?: int, label: string, help: string, options: list<array{id: int, label: string}>}> $questions
 */
function render_product_order_fields(array $questions, string $scope = ''): void
{
    if ($questions === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $language = \App\Service\Language\SiteText::documentLanguage();
    $requiredMark = ' <span class="req product-order-field__required" aria-hidden="true">*</span>';
    ?>
    <div class="product-order-fields" data-product-order-fields>
      <p class="product-order-fields__heading"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Jouw bestelgegevens', 'en' => 'Your order details']) ?></p>
      <?php foreach ($questions as $question): ?>
        <?php
          $id = $scope . 'order-field-' . $question['id'];
          $name = $scope . 'order_field_' . (int) $question['id'];
          $helpId = $id . '-help';
          $errorId = $id . '-error';
          $rulesId = $id . '-rules';
          $described = ($question['help'] !== '' ? $helpId . ' ' : '') . ($question['type'] === 'image' ? $rulesId . ' ' : '') . $errorId;
          $required = $question['required'] ? ' required aria-required="true"' : '';
          $isChoice = $question['type'] === 'radio' || $question['type'] === 'checkbox';
        ?>
        <?php /* The site's own form field (core.css .form-field): the same
                 label, control, focus ring, hint and error as every other
                 form. A tick box or a group of radios is a row of
                 .checkbox-field choices instead. */ ?>
        <div class="product-order-field form-field<?= $isChoice ? ' product-order-field--choice' : '' ?><?= $question['type'] === 'image' ? ' product-order-field--image' : '' ?>" data-order-field="<?= (int) $question['id'] ?>" data-order-field-type="<?= $h($question['type']) ?>"<?= $question['required'] ? ' data-order-field-required' : '' ?> data-order-field-label="<?= $h($question['label']) ?>"<?= $question['type'] === 'image' ? ' data-order-field-max-bytes="' . (int) ($question['max_bytes'] ?? 0) . '"' : '' ?>>
          <?php if ($question['type'] === 'image'): ?>
            <?php $maxBytes = (int) ($question['max_bytes'] ?? 0); ?>
            <label for="<?= $h($id) ?>"><?= $h($question['label']) ?><?= $question['required'] ? $requiredMark : '' ?></label>
            <?php /* The native control stays the one that is focused and
                     announced; it is only visually replaced by the button
                     that is its second label. */ ?>
            <input type="file" class="product-order-field__file" id="<?= $h($id) ?>" name="<?= $h($name) ?>" accept="<?= $h(\App\Service\OrderFields\OrderFieldUploadPolicy::acceptAttribute()) ?>" aria-describedby="<?= $h($described) ?>" data-order-field-file<?= $required ?>>
            <div class="product-order-field__upload">
              <label for="<?= $h($id) ?>" class="btn btn--ghost btn--sm product-order-field__pick" data-order-field-pick aria-hidden="true"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Afbeelding kiezen', 'en' => 'Choose a picture']) ?></label>
              <div class="product-order-field__preview" data-order-field-preview hidden>
                <img class="product-order-field__thumb" alt="" width="64" height="64" data-order-field-thumb>
                <span class="product-order-field__file-name" data-order-field-filename></span>
                <span class="product-order-field__actions">
                  <button type="button" class="btn btn--ghost btn--sm" data-order-field-replace><?= \App\Service\Language\SiteText::escaped(['nl' => 'Vervangen', 'en' => 'Replace']) ?><span class="visually-hidden"> — <?= $h($question['label']) ?></span></button>
                  <button type="button" class="btn btn--ghost btn--sm" data-order-field-remove><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verwijderen', 'en' => 'Remove']) ?><span class="visually-hidden"> — <?= $h($question['label']) ?></span></button>
                </span>
              </div>
            </div>
            <p class="hint product-order-field__rules" id="<?= $h($rulesId) ?>"><?= $h(\App\Service\OrderFields\OrderFieldUploadPolicy::formatsText($language)) ?> · <?= $h(\App\Service\Language\SiteText::pick(['nl' => 'max.', 'en' => 'max.'], $language)) ?> <?= $h(\App\Service\OrderFields\OrderFieldUploadPolicy::formatBytes($maxBytes, $language)) ?></p>
            <p class="product-order-field__status" role="status" aria-live="polite" data-order-field-status></p>
          <?php elseif ($question['type'] === 'radio'): ?>
            <fieldset class="product-order-field__group" aria-describedby="<?= $h($described) ?>">
              <legend><?= $h($question['label']) ?><?= $question['required'] ? $requiredMark : '' ?></legend>
              <?php foreach ($question['options'] as $option): ?>
                <label class="checkbox-field product-order-field__choice">
                  <input type="radio" name="<?= $h($name) ?>" value="<?= (int) $option['id'] ?>"<?= $required ?>>
                  <span><?= $h($option['label']) ?></span>
                </label>
              <?php endforeach; ?>
            </fieldset>
          <?php elseif ($question['type'] === 'checkbox'): ?>
            <label class="checkbox-field product-order-field__choice">
              <input type="checkbox" id="<?= $h($id) ?>" name="<?= $h($name) ?>" value="1" aria-describedby="<?= $h($described) ?>"<?= $required ?>>
              <span><?= $h($question['label']) ?><?= $question['required'] ? $requiredMark : '' ?></span>
            </label>
          <?php else: ?>
            <label for="<?= $h($id) ?>"><?= $h($question['label']) ?><?= $question['required'] ? $requiredMark : '' ?></label>
            <?php if ($question['type'] === 'select'): ?>
              <select id="<?= $h($id) ?>" name="<?= $h($name) ?>" aria-describedby="<?= $h($described) ?>"<?= $required ?>>
                <option value=""><?= \App\Service\Language\SiteText::escaped(['nl' => 'Maak een keuze', 'en' => 'Choose one']) ?></option>
                <?php foreach ($question['options'] as $option): ?>
                  <option value="<?= (int) $option['id'] ?>"><?= $h($option['label']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($question['type'] === 'textarea'): ?>
              <textarea id="<?= $h($id) ?>" name="<?= $h($name) ?>" rows="3" maxlength="<?= (int) $question['max_length'] ?>" aria-describedby="<?= $h($described) ?>"<?= $required ?>></textarea>
            <?php else: ?>
              <input type="text" id="<?= $h($id) ?>" name="<?= $h($name) ?>" maxlength="<?= (int) $question['max_length'] ?>" autocomplete="off" aria-describedby="<?= $h($described) ?>"<?= $required ?>>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($question['help'] !== ''): ?>
            <p class="hint product-order-field__help" id="<?= $h($helpId) ?>"><?= $h($question['help']) ?></p>
          <?php endif; ?>
          <p class="product-order-field__error" id="<?= $h($errorId) ?>" data-order-field-error hidden></p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}
