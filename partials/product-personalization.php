<?php

declare(strict_types=1);

use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationColors;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationRules;

/**
 * The customer-facing personalization section on /product.php.
 *
 * ## Where it sits, and why
 *
 * It is its own FULL-WIDTH section below the normal product area, at the
 * site's ordinary content width — not a panel inside the product-information
 * column. Personalizing is a task, not a product attribute: it needs a large
 * preview, room for its controls and a heading of its own, and squeezing that
 * into half a column beside the gallery is what made the first version look
 * like an accident. The normal product presentation above (gallery, title,
 * price, variants, description) is completely untouched by this block.
 *
 * ## The preview image is dedicated, always
 *
 * Every canvas below is a preview image uploaded FOR personalization
 * (assets/images/personalization/). The product gallery is never used as a
 * canvas, and there is no fallback to it anywhere: a view without its own
 * image is dropped by
 * App\Service\Personalization\ProductPersonalizationContent long before this
 * file runs, and the CMS says so in plain language instead.
 *
 * ## Rendered server-side
 *
 * Like "Gerelateerde producten", and unlike the rest of the product page
 * (which assets/js/shop/shop.js fetches from /api/product.php): which views and
 * zones a product offers, what is allowed in each, which fonts exist and what
 * they cost extra are all CMS configuration, so the decision — and the
 * resulting markup — belong on the server. A product without personalization
 * renders nothing at all.
 *
 * ## Fonts
 *
 * The customer picks from the GLOBAL library
 * (App\Service\Personalization\PersonalizationFonts); there is no per-zone
 * font list any more. The `@font-face` rules for the uploaded faces are
 * printed once here, so the selector and the live preview show the real
 * shapes rather than a fallback.
 *
 * The whole configuration is handed to assets/js/personalization.js as one
 * JSON block rather than as a scattering of data-attributes, so the editor
 * and the server can never disagree about which rules are in force. It is
 * still only a convenience: every value the customer produces is re-validated
 * against the live product configuration at checkout by
 * App\Service\Personalization\PersonalizationValidator, every surcharge is
 * re-read there from the product's own zones, and a required product cannot
 * be bought unpersonalized whatever the browser sends.
 *
 * @param array<string, mixed> $personalization a resolved configuration from
 *        App\Service\Personalization\ProductPersonalizationContent::forProduct()
 * @param callable|string|null $renderAddToCart the page's ONE purchase action,
 *        passed in only for a personalization-REQUIRED product — that is the
 *        product whose add-to-cart belongs at the end of this section instead
 *        of up beside the price. Null means the page rendered it up there and
 *        this section must not render a second one.
 */
function render_product_personalization(array $personalization, callable|string|null $renderAddToCart = null): void
{
    $views = $personalization['views'] ?? [];

    if ($views === []) {
        return;
    }

    $maxUploadMb = (int) round(PersonalizationRules::MAX_UPLOAD_BYTES / (1024 * 1024));
    $isMultiView = count($views) > 1;
    $isRequired = ($personalization['is_required'] ?? false) === true;

    /** The shop-wide font library, resolved once for the whole section. */
    $fontKeys = $personalization['fonts'] ?? [];
    $fonts = PersonalizationFonts::payload($fontKeys);
    $defaultFont = (string) ($personalization['default_font'] ?? PersonalizationFonts::FALLBACK);

    $colors = $personalization['colors'] ?? PersonalizationColors::payload();
    $defaultColor = (string) ($personalization['default_color'] ?? PersonalizationColors::FALLBACK);

    $config = [
        'product_id' => (int) $personalization['product_id'],
        'upload_endpoint' => '/api/personalization-upload.php',
        // Where the browser posts the COMPOSED preview of each used view when
        // the customer adds the product to the cart. Supplementary: if this
        // fails, the line is still added and the order is still complete.
        'preview_endpoint' => '/api/personalization-preview.php',
        'preview_width' => \App\Service\Personalization\PersonalizationPreviewComposer::TARGET_WIDTH,
        // The fixed palette a customer may preview their text in. Sent whole
        // so the editor never invents a colour of its own.
        'colors' => $colors,
        'default_color' => $defaultColor,
        'max_upload_mb' => $maxUploadMb,
        'mode' => (string) ($personalization['mode'] ?? PersonalizationRules::PURCHASE_OPTIONAL),
        'is_required' => $isRequired,
        'fonts' => $fonts,
        'default_font' => $defaultFont,
        'limits' => [
            'min_scale' => PersonalizationRules::MIN_SCALE,
            'max_scale' => PersonalizationRules::MAX_SCALE,
            'max_rotation' => PersonalizationRules::MAX_ROTATION,
        ],
        'render' => [
            'text_base_height_ratio' => PersonalizationRules::TEXT_BASE_HEIGHT_RATIO,
            'image_base_width_ratio' => PersonalizationRules::IMAGE_BASE_WIDTH_RATIO,
        ],
        'views' => array_map(
            static fn (array $view): array => [
                'view_key' => $view['view_key'],
                'label' => $view['label'],
                'label_en' => $view['label_en'],
                'preview_image' => '/' . ltrim((string) $view['preview_image_path'], '/'),
                'zones' => array_map(
                    static fn (array $zone): array => [
                        'zone_key' => $zone['zone_key'],
                        'label' => $zone['label'],
                        'label_en' => $zone['label_en'],
                        'mode' => $zone['mode'],
                        'allow_text' => $zone['allow_text'],
                        'allow_image' => $zone['allow_image'],
                        'is_required' => $zone['is_required'],
                        'allow_rotation' => $zone['allow_rotation'],
                        'max_text_length' => $zone['max_text_length'],
                        // Display only. The authoritative amount is read from
                        // the product's own configuration at checkout; a
                        // tampered value here changes a label, never a price.
                        'surcharge_cents' => $zone['surcharge_cents'],
                        'area' => $zone['area'],
                    ],
                    $view['zones']
                ),
            ],
            $views
        ),
    ];

    $instructions = (string) ($personalization['instructions'] ?? '');
    $instructionsEn = (string) ($personalization['instructions_en'] ?? '');
    if ($instructionsEn === '') {
        $instructionsEn = $instructions;
    }

    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    /** The customer-facing name of a view/zone, with a sensible NL/EN pair. */
    $labels = static function (array $item, string $fallback): array {
        $nl = $item['label'] ?? null;
        $en = $item['label_en'] ?? null;
        $nl = ($nl !== null && $nl !== '') ? $nl : $fallback;
        $en = ($en !== null && $en !== '') ? $en : $nl;

        return [$nl, $en];
    };

    $faceCss = PersonalizationFonts::faceCss($fontKeys);
    ?>
<section class="personalizer-section" id="personaliseren" data-personalizer-section>
  <div class="container">
    <div class="personalizer" data-personalizer aria-labelledby="personalizer-heading">
      <?php /* JSON_HEX_TAG so a "</script" sequence can never terminate this
               block early, whatever a CMS label or instruction text contains. */ ?>
      <script type="application/json" data-personalizer-config><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

      <?php if ($faceCss !== ''): ?>
        <?php /* The uploaded engraving faces, hosted by this site. Built only
                 from server-generated paths and validated keys — see
                 PersonalizationFonts::faceCss(). */ ?>
        <style><?= $faceCss ?></style>
      <?php endif; ?>

      <header class="personalizer__head">
        <p class="personalizer__eyebrow" data-nl="Op maat" data-en="Made to order">Op maat</p>
        <h2 class="personalizer__title" id="personalizer-heading" data-nl="Personaliseer je product" data-en="Personalise your product">Personaliseer je product</h2>
        <?php if ($instructions !== ''): ?>
          <p class="personalizer__intro" data-nl="<?= $esc($instructions) ?>" data-en="<?= $esc($instructionsEn) ?>"><?= $esc($instructions) ?></p>
        <?php elseif ($isRequired): ?>
          <p class="personalizer__intro" data-nl="Dit product wordt speciaal voor jou gegraveerd. Vul hieronder in wat erop moet komen." data-en="This product is engraved especially for you. Fill in below what it should say.">Dit product wordt speciaal voor jou gegraveerd. Vul hieronder in wat erop moet komen.</p>
        <?php endif; ?>
      </header>

      <div class="personalizer__layout">

        <div class="personalizer__preview">
          <?php if ($isMultiView): ?>
            <?php /* Only rendered for a product that actually has more than one
                     side — a single-view product must not grow a pointless tab
                     strip. Switching a tab changes ONLY which dedicated preview
                     image is shown; the normal product gallery above is
                     unrelated to it, and nothing the customer has entered is
                     touched. */ ?>
            <div class="personalizer__tabs" role="tablist" aria-label="Kanten van dit product"
                 data-nl-aria="Kanten van dit product" data-en-aria="Sides of this product">
              <?php foreach ($views as $index => $view): ?>
                <?php [$viewNl, $viewEn] = $labels($view, (string) $view['view_key']); ?>
                <button type="button" class="personalizer__tab<?= $index === 0 ? ' is-active' : '' ?>"
                        role="tab" id="personalizer-tab-<?= $esc($view['view_key']) ?>"
                        aria-controls="personalizer-panel-<?= $esc($view['view_key']) ?>"
                        aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                        data-personalizer-tab="<?= $esc($view['view_key']) ?>"
                        data-nl="<?= $esc($viewNl) ?>" data-en="<?= $esc($viewEn) ?>"><?= $esc($viewNl) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="personalizer__stages">
            <?php foreach ($views as $index => $view): ?>
              <?php [$viewNl, $viewEn] = $labels($view, (string) $view['view_key']); ?>
              <div class="personalizer__stage" data-personalizer-stage="<?= $esc($view['view_key']) ?>" <?= $index === 0 ? '' : 'hidden' ?>>
                <img class="personalizer__base" src="<?= $esc('/' . ltrim((string) $view['preview_image_path'], '/')) ?>" alt=""
                     data-nl-alt="Voorbeeld van <?= $esc($viewNl) ?> met jouw personalisatie"
                     data-en-alt="Preview of <?= $esc($viewEn) ?> with your personalisation">
                <?php foreach ($view['zones'] as $zone): ?>
                  <?php
                    [$zoneNl, $zoneEn] = $labels($zone, (string) $zone['zone_key']);
                    $area = $zone['area'];
                  ?>
                  <?php /* The zone clips its own overflow, so customer content
                           can never appear outside the area the administrator
                           defined — the clamped transform keeps its centre
                           inside, this keeps every last pixel inside. */ ?>
                  <div class="personalizer__zone" data-personalizer-zone="<?= $esc($zone['zone_key']) ?>"
                       style="left:<?= $esc((string) $area['x']) ?>%;top:<?= $esc((string) $area['y']) ?>%;width:<?= $esc((string) $area['width']) ?>%;height:<?= $esc((string) $area['height']) ?>%;">
                    <?php if ($zone['allow_image']): ?>
                      <img class="personalizer__layer personalizer__layer--image"
                           data-personalizer-layer="image" data-personalizer-layer-zone="<?= $esc($zone['zone_key']) ?>"
                           src="" alt="" hidden
                           tabindex="0" role="application"
                           aria-label="<?= $esc($zoneNl) ?> — jouw afbeelding, verplaats met de pijltjestoetsen"
                           data-nl-aria="<?= $esc($zoneNl) ?> — jouw afbeelding, verplaats met de pijltjestoetsen"
                           data-en-aria="<?= $esc($zoneEn) ?> — your image, move it with the arrow keys">
                    <?php endif; ?>
                    <?php if ($zone['allow_text']): ?>
                      <span class="personalizer__layer personalizer__layer--text"
                            data-personalizer-layer="text" data-personalizer-layer-zone="<?= $esc($zone['zone_key']) ?>"
                            hidden
                            tabindex="0" role="application"
                            aria-label="<?= $esc($zoneNl) ?> — jouw tekst, verplaats met de pijltjestoetsen"
                            data-nl-aria="<?= $esc($zoneNl) ?> — jouw tekst, verplaats met de pijltjestoetsen"
                            data-en-aria="<?= $esc($zoneEn) ?> — your text, move it with the arrow keys"></span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <p class="personalizer__hint" data-nl="Sleep je tekst of afbeelding naar de juiste plek binnen het gemarkeerde gebied. Met het toetsenbord: klik erop en gebruik de pijltjestoetsen." data-en="Drag your text or image to the right spot inside the highlighted area. With a keyboard: focus it and use the arrow keys.">Sleep je tekst of afbeelding naar de juiste plek binnen het gemarkeerde gebied. Met het toetsenbord: klik erop en gebruik de pijltjestoetsen.</p>
        </div>

        <div class="personalizer__controls">
          <?php foreach ($views as $index => $view): ?>
            <div class="personalizer__panel" data-personalizer-panel="<?= $esc($view['view_key']) ?>"
                 <?= $isMultiView ? 'role="tabpanel" id="personalizer-panel-' . $esc($view['view_key']) . '" aria-labelledby="personalizer-tab-' . $esc($view['view_key']) . '"' : '' ?>
                 <?= $index === 0 ? '' : 'hidden' ?>>
              <?php foreach ($view['zones'] as $zone): ?>
                <?php
                  [$zoneNl, $zoneEn] = $labels($zone, (string) $zone['zone_key']);
                  $zoneKey = (string) $zone['zone_key'];
                  $fieldId = 'pz-' . preg_replace('/[^a-z0-9_-]/', '', $zoneKey);
                  $instructionsNl = (string) ($zone['instructions'] ?? '');
                  $instructionsEnZone = (string) ($zone['instructions_en'] ?? '');
                  if ($instructionsEnZone === '') {
                      $instructionsEnZone = $instructionsNl;
                  }
                  $placeholderNl = (string) ($zone['placeholder'] ?? '');
                  $placeholderEnZone = (string) ($zone['placeholder_en'] ?? '');
                  if ($placeholderEnZone === '') {
                      $placeholderEnZone = $placeholderNl;
                  }
                ?>
                <fieldset class="personalizer__zone-fields" data-personalizer-zone-fields="<?= $esc($zoneKey) ?>">
                  <legend>
                    <span class="personalizer__zone-name" data-nl="<?= $esc($zoneNl) ?>" data-en="<?= $esc($zoneEn) ?>"><?= $esc($zoneNl) ?></span>
                    <?php if ($zone['is_required']): ?>
                      <span class="personalizer__required" data-nl="verplicht" data-en="required">verplicht</span>
                    <?php else: ?>
                      <span class="personalizer__optional" data-nl="optioneel" data-en="optional">optioneel</span>
                    <?php endif; ?>
                    <?php if ($zone['surcharge_cents'] > 0): ?>
                      <span class="personalizer__surcharge-tag">+ &euro;&nbsp;<?= $esc(Money::formatDutch($zone['surcharge_cents'])) ?></span>
                    <?php endif; ?>
                  </legend>

                  <?php if ($instructionsNl !== ''): ?>
                    <p class="personalizer__zone-intro" data-nl="<?= $esc($instructionsNl) ?>" data-en="<?= $esc($instructionsEnZone) ?>"><?= $esc($instructionsNl) ?></p>
                  <?php endif; ?>

                  <?php if ($zone['allow_text']): ?>
                    <div class="personalizer__field">
                      <label for="<?= $esc($fieldId) ?>-text" data-nl="Tekst" data-en="Text">Tekst</label>
                      <?php /* maxlength is UX only — the authoritative limit is
                               checked server-side at checkout, never here. */ ?>
                      <input type="text" id="<?= $esc($fieldId) ?>-text"
                             maxlength="<?= (int) $zone['max_text_length'] ?>" autocomplete="off"
                             <?= $zone['is_required'] && $zone['mode'] === PersonalizationRules::MODE_TEXT ? 'aria-required="true"' : '' ?>
                             placeholder="<?= $esc($placeholderNl) ?>"
                             data-nl-placeholder="<?= $esc($placeholderNl) ?>" data-en-placeholder="<?= $esc($placeholderEnZone) ?>"
                             data-personalizer-text-input="<?= $esc($zoneKey) ?>"
                             aria-describedby="<?= $esc($fieldId) ?>-counter">
                      <p class="personalizer__counter" id="<?= $esc($fieldId) ?>-counter">
                        <span data-personalizer-text-count="<?= $esc($zoneKey) ?>">0</span>/<?= (int) $zone['max_text_length'] ?>
                      </p>
                    </div>

                    <?php if (count($fonts) > 1): ?>
                      <?php /* Every ACTIVE font in the shop's library, whatever
                               product this is. Each option previews itself, so
                               the customer picks a shape rather than a name. A
                               library with one font shows no selector at all —
                               there is nothing to choose. */ ?>
                      <div class="personalizer__field">
                        <label for="<?= $esc($fieldId) ?>-font" data-nl="Lettertype" data-en="Font">Lettertype</label>
                        <select id="<?= $esc($fieldId) ?>-font" class="personalizer__font-select" data-personalizer-font="<?= $esc($zoneKey) ?>">
                          <?php foreach ($fonts as $font): ?>
                            <option value="<?= $esc($font['key']) ?>" <?= $font['key'] === $defaultFont ? 'selected' : '' ?>
                                    style="font-family:<?= $esc($font['stack']) ?>;"><?= $esc($font['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    <?php endif; ?>

                    <?php /* A small fixed palette, not a colour picker: this is a
                             preview choice so the customer can read their own text
                             against a dark or a pale product photo, and every value
                             is one this server defined. */ ?>
                    <div class="personalizer__field">
                      <span class="personalizer__field-label" id="<?= $esc($fieldId) ?>-color-label" data-nl="Tekstkleur" data-en="Text colour">Tekstkleur</span>
                      <div class="personalizer__colors" role="radiogroup" aria-labelledby="<?= $esc($fieldId) ?>-color-label">
                        <?php foreach ($colors as $color): ?>
                          <label class="personalizer__color">
                            <input type="radio" name="<?= $esc($fieldId) ?>-color" value="<?= $esc($color['key']) ?>"
                                   <?= $color['key'] === $defaultColor ? 'checked' : '' ?>
                                   data-personalizer-color="<?= $esc($zoneKey) ?>">
                            <span class="personalizer__color-swatch" style="background:<?= $esc($color['hex']) ?>;" aria-hidden="true"></span>
                            <span class="personalizer__color-name"
                                  data-nl="<?= $esc($color['label']) ?>" data-en="<?= $esc($color['label_en']) ?>"><?= $esc($color['label']) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>

                    <div class="personalizer__field">
                      <label for="<?= $esc($fieldId) ?>-text-scale" data-nl="Tekstgrootte" data-en="Text size">Tekstgrootte</label>
                      <input type="range" id="<?= $esc($fieldId) ?>-text-scale"
                             min="<?= PersonalizationRules::MIN_SCALE ?>" max="<?= PersonalizationRules::MAX_SCALE ?>" step="0.05" value="1"
                             data-personalizer-scale="text" data-personalizer-scale-zone="<?= $esc($zoneKey) ?>">
                    </div>

                    <?php if ($zone['allow_rotation']): ?>
                      <div class="personalizer__field">
                        <label for="<?= $esc($fieldId) ?>-text-rotation" data-nl="Tekst draaien" data-en="Rotate text">Tekst draaien</label>
                        <input type="range" id="<?= $esc($fieldId) ?>-text-rotation"
                               min="-<?= PersonalizationRules::MAX_ROTATION ?>" max="<?= PersonalizationRules::MAX_ROTATION ?>" step="1" value="0"
                               data-personalizer-rotation="text" data-personalizer-rotation-zone="<?= $esc($zoneKey) ?>">
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($zone['allow_image']): ?>
                    <div class="personalizer__field">
                      <label for="<?= $esc($fieldId) ?>-image"
                             data-nl="Eigen afbeelding (PNG of JPG, max. <?= $maxUploadMb ?> MB)"
                             data-en="Your own image (PNG or JPG, max. <?= $maxUploadMb ?> MB)">Eigen afbeelding (PNG of JPG, max. <?= $maxUploadMb ?> MB)</label>
                      <input type="file" id="<?= $esc($fieldId) ?>-image"
                             accept="image/png,image/jpeg,.png,.jpg,.jpeg"
                             <?= $zone['is_required'] && $zone['mode'] === PersonalizationRules::MODE_IMAGE ? 'aria-required="true"' : '' ?>
                             data-personalizer-file-input="<?= $esc($zoneKey) ?>">
                      <p class="personalizer__filename" data-personalizer-file-name="<?= $esc($zoneKey) ?>" hidden></p>
                      <button type="button" class="btn btn--ghost btn--sm" data-personalizer-remove-image="<?= $esc($zoneKey) ?>" hidden
                              data-nl="Afbeelding verwijderen" data-en="Remove image">Afbeelding verwijderen</button>
                    </div>

                    <div class="personalizer__field" data-personalizer-image-controls="<?= $esc($zoneKey) ?>" hidden>
                      <label for="<?= $esc($fieldId) ?>-image-scale" data-nl="Afbeeldingsgrootte" data-en="Image size">Afbeeldingsgrootte</label>
                      <input type="range" id="<?= $esc($fieldId) ?>-image-scale"
                             min="<?= PersonalizationRules::MIN_SCALE ?>" max="<?= PersonalizationRules::MAX_SCALE ?>" step="0.05" value="1"
                             data-personalizer-scale="image" data-personalizer-scale-zone="<?= $esc($zoneKey) ?>">
                      <?php if ($zone['allow_rotation']): ?>
                        <label for="<?= $esc($fieldId) ?>-image-rotation" data-nl="Afbeelding draaien" data-en="Rotate image">Afbeelding draaien</label>
                        <input type="range" id="<?= $esc($fieldId) ?>-image-rotation"
                               min="-<?= PersonalizationRules::MAX_ROTATION ?>" max="<?= PersonalizationRules::MAX_ROTATION ?>" step="1" value="0"
                               data-personalizer-rotation="image" data-personalizer-rotation-zone="<?= $esc($zoneKey) ?>">
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <button type="button" class="personalizer__clear" data-personalizer-reset-zone="<?= $esc($zoneKey) ?>"
                          data-nl="Deze zone wissen" data-en="Clear this area">Deze zone wissen</button>
                </fieldset>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>

          <div class="personalizer__summary">
            <?php /* The live price breakdown. Every amount here is
                     server-rendered or derived from server-rendered
                     configuration; the authoritative total is recalculated at
                     checkout regardless. */ ?>
            <div class="personalizer__price" data-personalizer-price hidden>
              <div class="personalizer__price-row">
                <span data-nl="Product" data-en="Product">Product</span>
                <strong data-personalizer-price-base>&euro; 0,00</strong>
              </div>
              <div data-personalizer-price-lines></div>
              <div class="personalizer__price-row personalizer__price-row--total">
                <span data-nl="Totaal per stuk" data-en="Total each">Totaal per stuk</span>
                <strong data-personalizer-price-total>&euro; 0,00</strong>
              </div>
            </div>

            <?php /* One live region for both the upload state and any other
                     progress message. aria-busy is set on the whole
                     personalizer while an image is uploading, so assistive
                     technology announces the wait rather than silently
                     ignoring disabled controls. */ ?>
            <p class="personalizer__status" data-personalizer-status hidden role="status"></p>
            <p class="personalizer__error" data-personalizer-error hidden role="alert"></p>

            <?php if ($renderAddToCart !== null): ?>
              <?php /* THE purchase action for a personalization-required
                       product, and the only one on the page: the product
                       column deliberately renders none. */ ?>
              <?php $renderAddToCart(); ?>
            <?php endif; ?>

            <button type="button" class="personalizer__clear personalizer__clear--all" data-personalizer-reset
                    data-nl="Alles wissen" data-en="Clear everything">Alles wissen</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
    <?php
}
