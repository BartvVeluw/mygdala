<?php

declare(strict_types=1);


require_once __DIR__ . '/_translate.php';
// The localized-fields component below; required here as well as by the
// screen that includes this file, so neither can forget.
require_once __DIR__ . '/_localized_fields.php';

use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationLocalization;
use App\Service\Personalization\PersonalizationPreviewImageUploader;
use App\Service\Personalization\PersonalizationRules;

/**
 * The personalization configurator for one product: the product-level
 * settings, its dedicated preview images ("voorbeelden"), and the engraving
 * zones on each of them.
 *
 * Included by admin/personalization-product.php and by nothing else — the
 * product editor no longer renders any of this. One configuration, one
 * editor.
 *
 * ## The hierarchy, and the mistake this screen has to stop
 *
 *   Product
 *     └── Voorbeeld "Voorkant"   — its OWN uploaded image
 *           └── zones on that image
 *     └── Voorbeeld "Achterkant" — its OWN uploaded image
 *           └── zones on that image
 *
 * A second side of the product is a second VOORBEELD with its own photo, not
 * a second zone on the first photo. That distinction is easy to get wrong
 * (both are "add something"), and getting it wrong produces a front and a
 * back drawn on the same picture. So the two actions are deliberately not
 * peers here: adding a voorbeeld is a primary action that sits above and
 * below the list, while adding a zone is a small action INSIDE the voorbeeld
 * it belongs to, and both say in words what they do.
 *
 * ## Compact by construction
 *
 * Every voorbeeld and every zone is a collapsible block (`<details>`), closed
 * by default once there is more than one, so a product with three voorbeelden
 * and six zones is a short list rather than a page of forms. The collapsed
 * summary carries the facts you scan for — "Voorkant · 2 zones · afbeelding
 * ingesteld", "Naam · Tekst · Verplicht" — so opening a block is a decision,
 * not a search. `<details>` needs no JavaScript, is keyboard operable and
 * still prints/find-in-pages correctly.
 *
 * Fields sit in a real grid (.admin-pz-grid) instead of a stack of
 * full-width rows, and the four engraving-area percentages are one group
 * (.admin-pz-area) because they are one thought.
 *
 * ## Every action is its own small form
 *
 * Add a voorbeeld, rename it, replace its image, move it, delete it, add a
 * zone, edit a zone... each posts to its own endpoint with only its own
 * fields. That is what keeps the builder non-destructive: editing the back's
 * message zone posts only that zone, so it can never rewrite the front's name
 * zone or drop a preview image every coordinate on that view is measured
 * against.
 *
 * ## Preview images are dedicated, and there is no fallback
 *
 * The image a voorbeeld carries is uploaded HERE, for personalization, into
 * assets/images/personalization/ — never a product photo, and never the first
 * image of the product gallery. A voorbeeld without its own image shows a
 * configuration error and renders nothing in the shop, rather than silently
 * borrowing a photo.
 *
 * ## Fonts are not configured here
 *
 * They are a shop-wide library (admin/personalization-fonts.php): every text
 * zone offers exactly the fonts that are active there, and the customer
 * picks.
 *
 * @param array<string, mixed> $product
 * @param array{settings: array<string, mixed>, views: array<int, array<string, mixed>>} $personalization
 * @param array{errors: list<string>, old: ?array<string, mixed>, updated: bool} $flash
 */
function renderPersonalizationBuilder(array $product, array $personalization, string $csrfToken, array $flash): void
{
    $productId = (int) $product['id'];
    $settings = $personalization['settings'];
    $views = $personalization['views'];
    $old = $flash['old'];

    /** The rejected input for one specific form, or null when it was another form's. */
    $oldFor = static function (string $form, ?int $id = null) use ($old): ?array {
        if ($old === null || ($old['form'] ?? null) !== $form) {
            return null;
        }
        if ($id !== null && (int) ($old['view_id'] ?? $old['zone_id'] ?? 0) !== $id) {
            return null;
        }

        return $old['fields'] ?? null;
    };

    // The website language this screen's words are in. ONE language on the
    // screen and in every request it sends; the CMS shell's switch is the only
    // control (admin/_localized_fields.php).
    $editingLanguage = admin_localized_language();

    /** What the CMS calls a zone: its default language's label, else its key. */
    $zoneName = static function (array $zone): string {
        $name = PersonalizationLocalization::zoneName((int) $zone['id']);

        return $name !== '' ? $name : (string) $zone['zone_key'];
    };

    $settingsOld = $oldFor('settings');
    $isEnabled = $settingsOld !== null
        ? !empty($settingsOld['personalization_enabled'])
        : (int) $settings['is_enabled'] === 1;
    $mode = PersonalizationRules::purchaseMode(
        $settingsOld !== null
            ? ($settingsOld['personalization_mode'] ?? null)
            : ($settings['personalization_mode'] ?? null)
    );
    // The words of ONE language, the one the CMS shell points at
    // (Multilingual 2.0 phase 5 wave D). A refused save comes back with what
    // was typed, in the language it was typed in.
    $instructions = $settingsOld !== null
        ? (string) ($settingsOld['instructions'] ?? '')
        : PersonalizationLocalization::rawInstructions((int) ($settings['id'] ?? 0), $editingLanguage);

    // A product that is not in the shop has no ordinary purchase path, so
    // personalization is mandatory for it whatever the radio below says —
    // the same derivation App\Service\Personalization\ProductPersonalizationContent
    // makes, surfaced here so the screen never contradicts the storefront.
    $isPersonalizationOnly = (int) ($product['in_shop'] ?? 1) !== 1;

    // "Renderable" is exactly the rule the resolver applies: a voorbeeld
    // needs its own image, and at least one enabled zone that allows
    // something. Warning about it here beats an administrator wondering why
    // the shop shows nothing.
    $renderableViews = 0;
    $viewsWithoutImage = 0;
    foreach ($views as $view) {
        if (trim((string) ($view['preview_image_path'] ?? '')) === '') {
            $viewsWithoutImage++;
            continue;
        }
        foreach ($view['zones'] as $zone) {
            if ((int) $zone['is_enabled'] === 1 && ((int) $zone['allow_text'] === 1 || (int) $zone['allow_image'] === 1)) {
                $renderableViews++;
                break;
            }
        }
    }

    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $maxPreviewMb = PersonalizationPreviewImageUploader::maxMegabytes();

    // Blocks start open while there is only one of them (nothing to scan),
    // and closed as soon as there are several (everything to scan).
    $viewsOpenByDefault = count($views) <= 1;
    ?>
<?php if ($flash['updated']): ?>
  <p class="admin-alert admin-alert--success"><?= admin_te('personalization.personalisatie_opgeslagen') ?></p>
<?php endif; ?>

<?php if ($flash['errors'] !== []): ?>
  <div class="admin-alert admin-alert--error">
    <ul class="admin-error-list">
      <?php foreach ($flash['errors'] as $error): ?>
        <li><?= $esc((string) $error) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="admin-card" id="personalisatie">
  <h2><?= admin_te('common.settings') ?></h2>

  <form method="post" action="/api/admin/update-product-personalization.php" class="admin-personalization-form">
    <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
    <input type="hidden" name="product_id" value="<?= $productId ?>">

    <div class="admin-pz-grid admin-pz-grid--settings">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="personalization_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>>
        <span><strong><?= admin_t('personalization.personalisatie_inschakelen_uit_gewone') ?></span></span>
      </label>

      <fieldset class="admin-pz-radios">
        <legend><?= admin_te('personalization.aankoop') ?></legend>
        <label class="admin-checkbox-label">
          <input type="radio" name="personalization_mode" value="<?= $esc(PersonalizationRules::PURCHASE_OPTIONAL) ?>"
                 <?= $mode === PersonalizationRules::PURCHASE_OPTIONAL ? 'checked' : '' ?>>
          <span><?= admin_te('personalization.optioneel_mag_ook_zonder') ?></span>
        </label>
        <label class="admin-checkbox-label">
          <input type="radio" name="personalization_mode" value="<?= $esc(PersonalizationRules::PURCHASE_REQUIRED) ?>"
                 <?= $mode === PersonalizationRules::PURCHASE_REQUIRED ? 'checked' : '' ?>>
          <span><?= admin_te('personalization.verplicht_alleen_gepersonaliseerd_bestellen') ?></span>
        </label>
      </fieldset>
    </div>

    <?php if ($isPersonalizationOnly): ?>
      <p class="admin-alert admin-alert--info">
        <?= admin_t('personalization.product_staat_shop_zie') ?>
        <a href="/admin/product-form.php?id=<?= $productId ?>"><?= admin_t('personalization.producten_dus_personalisatie_hoe') ?>
      </p>
    <?php endif; ?>

    <?= admin_localized_input($editingLanguage) ?>
    <?php admin_localized_bar($editingLanguage); ?>
    <div class="admin-pz-grid">
        <label><?= admin_te('personalization.algemene_uitleg') ?>
          <textarea name="instructions" rows="2" maxlength="<?= PersonalizationLocalization::INSTRUCTIONS_MAX_LENGTH ?>" placeholder="Bijv. Personaliseer dit product met een naam of logo."<?= admin_localized_placeholder_attr($editingLanguage) ?>><?= $esc($instructions) ?></textarea>
        </label>
    </div>

    <button type="submit"><?= admin_te('personalization.instellingen_opslaan') ?></button>
  </form>

  <?php if ($isEnabled && $renderableViews === 0): ?>
    <p class="admin-alert admin-alert--error" style="margin-top:var(--admin-sp-3);">
      <strong><?= admin_t('personalization.configuratiefout_personalisatie_staat_maar') ?>
    </p>
  <?php elseif ($viewsWithoutImage > 0): ?>
    <p class="admin-alert admin-alert--error" style="margin-top:var(--admin-sp-3);">
      <strong><?= admin_t('personalization.configuratiefout_voorbeeld_eigen_afbeelding', ['v1' => $viewsWithoutImage, 'v2' => $viewsWithoutImage === 1 ? '' : 'en', 'v3' => $viewsWithoutImage === 1 ? 'heeft' : 'hebben', 'v4' => $viewsWithoutImage === 1 ? 'wordt' : 'worden']) ?>
    </p>
  <?php endif; ?>
</section>

<section class="admin-card">
  <div class="admin-pz-head">
    <div>
      <h2><?= admin_te('personalization.voorbeelden') ?></h2>
      <p class="admin-text-muted admin-pz-head__hint">
        <?= admin_t('personalization.e_n_voorbeeld_n') ?>
      </p>
    </div>
    <a class="admin-btn-link" href="#voorbeeld-toevoegen"><?= admin_te('personalization.voorbeeld_toevoegen') ?></a>
  </div>

  <?php if ($views === []): ?>
    <p class="admin-text-muted"><?= admin_te('personalization.voorbeelden_voeg_er_hieronder') ?></p>
  <?php endif; ?>

  <?php foreach ($views as $viewIndex => $view): ?>
    <?php
      $viewId = (int) $view['id'];
      $viewOld = $oldFor('view', $viewId);
      $viewLabel = $viewOld !== null
          ? (string) ($viewOld['label'] ?? '')
          : PersonalizationLocalization::rawViewLabel($viewId, $editingLanguage);
      $viewImage = trim((string) ($view['preview_image_path'] ?? ''));
      $isFirstView = $viewIndex === 0;
      $isLastView = $viewIndex === count($views) - 1;
      // The heading of the block names the view the way the CMS does — in the
      // default language — so a view stays findable while its translation is
      // being written.
      $viewName = PersonalizationLocalization::viewName($viewId);
      $displayName = $viewName !== '' ? $viewName : (string) $view['view_key'];
      $zoneCount = count($view['zones']);
      // A block whose own form was just rejected must open regardless, or
      // the administrator cannot see what went wrong.
      $viewHasFlash = $viewOld !== null
          || $oldFor('zone-create', $viewId) !== null
          || array_filter($view['zones'], static fn (array $z): bool => $oldFor('zone', (int) $z['id']) !== null) !== [];
    ?>
    <details class="admin-pz-block" <?= $viewsOpenByDefault || $viewHasFlash ? 'open' : '' ?>>
      <summary class="admin-pz-block__summary">
        <span class="admin-pz-block__title"><?= $esc($displayName) ?></span>
        <span class="admin-pz-block__facts">
          <?= $zoneCount ?> zone<?= $zoneCount === 1 ? '' : 's' ?>
          &middot;
          <?php if ($viewImage !== ''): ?>
            <?= admin_te('personalization.image_set') ?>
          <?php else: ?>
            <span class="admin-pz-block__warn"><?= admin_te('personalization.no_image') ?></span>
          <?php endif; ?>
        </span>
        <code class="admin-text-muted"><?= $esc((string) $view['view_key']) ?></code>
      </summary>

      <div class="admin-pz-block__body">
        <div class="admin-pz-actions">
          <form method="post" action="/api/admin/move-personalization-view.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
            <input type="hidden" name="view_id" value="<?= $viewId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirstView ? 'disabled' : '' ?> aria-label="Voorbeeld omhoog">&uarr;</button>
          </form>
          <form method="post" action="/api/admin/move-personalization-view.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
            <input type="hidden" name="view_id" value="<?= $viewId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLastView ? 'disabled' : '' ?> aria-label="Voorbeeld omlaag">&darr;</button>
          </form>
          <form method="post" action="/api/admin/delete-personalization-view.php" class="admin-inline-form"
                onsubmit="return confirm('Dit voorbeeld en al zijn zones verwijderen? Bestaande bestellingen blijven ongewijzigd.');">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
            <input type="hidden" name="view_id" value="<?= $viewId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('personalization.voorbeeld_verwijderen') ?></button>
          </form>
        </div>

        <form method="post" action="/api/admin/update-personalization-view.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
          <input type="hidden" name="view_id" value="<?= $viewId ?>">

          <?= admin_localized_input($editingLanguage) ?>
          <div class="admin-pz-grid">
              <label><?= admin_te('personalization.naam_klant') ?>
                <input type="text" name="label" maxlength="<?= PersonalizationLocalization::LABEL_MAX_LENGTH ?>" value="<?= $esc($viewLabel) ?>" placeholder="Bijv. Voorkant"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
              </label>
          </div>

          <div class="admin-pz-image">
            <?php if ($viewImage !== ''): ?>
              <div class="admin-pz-image__thumb">
                <img src="/<?= $esc(ltrim($viewImage, '/')) ?>" alt="" loading="lazy">
              </div>
            <?php endif; ?>
            <div class="admin-pz-image__fields">
              <label><?= admin_t('personalization.eigen_afbeelding', ['v1' => $viewImage === '' ? '' : ' vervangen']) ?>
                <input type="file" name="preview_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
              </label>
              <p class="admin-text-muted">
                <?= admin_t('personalization.jpg_png_webp_gif', ['v1' => $maxPreviewMb]) ?>
              </p>
              <?php if ($viewImage !== ''): ?>
                <label class="admin-checkbox-label">
                  <input type="checkbox" name="remove_preview_image" value="1">
                  <?= admin_te('personalization.afbeelding_verwijderen_opslaan') ?>
                </label>
              <?php endif; ?>
            </div>
          </div>

          <button type="submit"><?= admin_te('personalization.voorbeeld_opslaan') ?></button>
        </form>

        <?php /* ---- the visual multi-zone editor for this voorbeeld ---- */ ?>
        <?php if ($viewImage !== '' && $view['zones'] !== []): ?>
          <p class="admin-text-muted admin-pz-editor-hint">
            <?= admin_te('personalization.klik_zone_hem_selecteren') ?>
          </p>
          <div class="admin-zone-editor" data-zone-editor>
            <div class="admin-zone-editor__stage" data-zone-stage>
              <img class="admin-zone-editor__image" src="/<?= $esc(ltrim($viewImage, '/')) ?>"
                   alt="Voorbeeldafbeelding met de gravuregebieden" data-zone-image>
              <?php foreach ($view['zones'] as $zoneIndex => $zone): ?>
                <?php $zoneId = (int) $zone['id']; ?>
                <div class="admin-zone-editor__box<?= $zoneIndex === 0 ? ' is-selected' : '' ?>"
                     data-zone-box="<?= $zoneId ?>"
                     tabindex="0" role="application"
                     aria-label="Zone <?= $esc($zoneName($zone)) ?> — verplaats met de pijltjestoetsen, houd Shift ingedrukt om het formaat te wijzigen"
                     style="left:<?= $esc(number_format((float) $zone['area_x'], 3, '.', '')) ?>%;top:<?= $esc(number_format((float) $zone['area_y'], 3, '.', '')) ?>%;width:<?= $esc(number_format((float) $zone['area_width'], 3, '.', '')) ?>%;height:<?= $esc(number_format((float) $zone['area_height'], 3, '.', '')) ?>%;">
                  <span class="admin-zone-editor__tag"><?= $esc($zoneName($zone)) ?></span>
                  <span class="admin-zone-editor__handle admin-zone-editor__handle--nw" data-zone-handle="nw" aria-hidden="true"></span>
                  <span class="admin-zone-editor__handle admin-zone-editor__handle--ne" data-zone-handle="ne" aria-hidden="true"></span>
                  <span class="admin-zone-editor__handle admin-zone-editor__handle--sw" data-zone-handle="sw" aria-hidden="true"></span>
                  <span class="admin-zone-editor__handle admin-zone-editor__handle--se" data-zone-handle="se" aria-hidden="true"></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php elseif ($viewImage === ''): ?>
          <p class="admin-alert admin-alert--error admin-pz-editor-hint">
            <?= admin_te('personalization.voorbeeld_heeft_eigen_afbeelding') ?>
          </p>
        <?php endif; ?>

        <?php /* ---- the zones of this voorbeeld ---- */ ?>
        <h4 class="admin-pz-subhead"><?= admin_te('personalization.zones_afbeelding') ?></h4>
        <?php if ($view['zones'] === []): ?>
          <p class="admin-text-muted"><?= admin_te('personalization.zones_voorbeeld') ?></p>
        <?php endif; ?>

        <?php foreach ($view['zones'] as $zoneIndex => $zone): ?>
          <?php
            renderPersonalizationZoneForm(
                $zone,
                $oldFor('zone', (int) $zone['id']),
                $csrfToken,
                $zoneIndex === 0,
                $zoneIndex === count($view['zones']) - 1,
                $zoneCount <= 1
            );
          ?>
        <?php endforeach; ?>

        <?php renderPersonalizationZoneCreateForm($viewId, $displayName, $oldFor('zone-create', $viewId), $csrfToken); ?>
      </div>
    </details>
  <?php endforeach; ?>

  <?php /* ---- add a voorbeeld ---- */ ?>
  <?php $viewCreateOld = $oldFor('view-create'); ?>
  <div class="admin-pz-create" id="voorbeeld-toevoegen">
    <h3><?= admin_te('personalization.voorbeeld_toevoegen_2') ?></h3>
    <p class="admin-text-muted">
      <?= admin_t('personalization.elke_kant_foto_product') ?>
    </p>
    <form method="post" action="/api/admin/create-personalization-view.php" class="admin-pz-grid admin-pz-grid--create">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
      <input type="hidden" name="product_id" value="<?= $productId ?>">
      <?php /* A NEW view is named in the default language, like a new page
               or a new blog post, and translated on the view itself
               afterwards (Multilingual 2.0 phase 5 wave D). */ ?>
      <?php admin_localized_new_item_note($editingLanguage); ?>
      <label><?= admin_te('personalization.sleutel') ?>*
        <input type="text" name="view_key" maxlength="32" required
               value="<?= $esc($viewCreateOld !== null ? (string) ($viewCreateOld['view_key'] ?? '') : '') ?>"
               placeholder="achterkant">
      </label>
      <label><?= admin_te('common.name') ?>
        <input type="text" name="label" maxlength="<?= PersonalizationLocalization::LABEL_MAX_LENGTH ?>"
               value="<?= $esc($viewCreateOld !== null ? (string) ($viewCreateOld['label'] ?? '') : '') ?>"
               placeholder="Achterkant">
      </label>
      <div class="admin-pz-grid__action">
        <button type="submit"><?= admin_te('personalization.voorbeeld_toevoegen_3') ?></button>
      </div>
    </form>
  </div>
</section>
    <?php
}

/**
 * One zone's edit form, as a collapsible block whose summary already answers
 * "what is this zone" — name, what it accepts, and whether it is required.
 *
 * Every field the customer-facing panel and the checkout validator read is
 * here, and nothing else: the zone's key and the voorbeeld it lives on are
 * set at creation and never rewritten, because order rows point at the key
 * and the coordinates only mean anything against this voorbeeld's image.
 *
 * There is deliberately no font field — fonts are a shop-wide library the
 * CUSTOMER picks from (admin/personalization-fonts.php).
 *
 * @param array<string, mixed> $zone
 * @param array<string, mixed>|null $old
 */
function renderPersonalizationZoneForm(
    array $zone,
    ?array $old,
    string $csrfToken,
    bool $isFirst,
    bool $isLast,
    bool $openByDefault
): void {
    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $zoneId = (int) $zone['id'];
    $editingLanguage = admin_localized_language();

    /** One word of this zone in the language on screen, or what was typed. */
    $word = static function (string $field) use ($old, $zoneId, $editingLanguage): string {
        if ($old !== null && array_key_exists($field, $old)) {
            return (string) $old[$field];
        }

        return PersonalizationLocalization::rawZoneWord($zoneId, $field, $editingLanguage);
    };

    $value = static function (string $key, mixed $fallback) use ($old, $zone) {
        if ($old !== null && array_key_exists($key, $old)) {
            return $old[$key];
        }

        return $zone[$key] ?? $fallback;
    };

    $checked = static function (string $key, bool $storedDefault) use ($old, $zone): bool {
        if ($old !== null) {
            return !empty($old[$key]);
        }

        return array_key_exists($key, $zone) ? (int) $zone[$key] === 1 : $storedDefault;
    };

    $surchargeCents = $old !== null
        ? (int) ($old['surcharge_cents'] ?? 0)
        : Money::toCents($zone['surcharge'] ?? 0);

    $area = [];
    foreach (['x', 'y', 'width', 'height'] as $key) {
        $area[$key] = $old !== null
            ? (float) ($old['area_' . $key] ?? 0)
            : (float) ($zone['area_' . $key] ?? 0);
    }

    $cmsName = PersonalizationLocalization::zoneName($zoneId);
    $label = $cmsName !== '' ? $cmsName : (string) $zone['zone_key'];
    $allowsText = $checked('allow_text', true);
    $allowsImage = $checked('allow_image', true);
    $accepts = $allowsText && $allowsImage ? admin_t('personalization.text_and_image') : ($allowsImage ? admin_t('common.image_label') : admin_t('common.text'));
    $requiredLabel = $checked('is_required', false) ? admin_t('personalization.required') : admin_t('common.optional');
    $isDisabled = !$checked('is_enabled', true);
    ?>
<details class="admin-pz-block admin-pz-block--zone" <?= $openByDefault || $old !== null ? 'open' : '' ?>>
  <summary class="admin-pz-block__summary">
    <span class="admin-pz-block__title"><?= $esc($label) ?></span>
    <span class="admin-pz-block__facts">
      <?= $esc($accepts) ?> &middot; <?= $esc($requiredLabel) ?>
      <?php if ($surchargeCents > 0): ?>
        &middot; + &euro;&nbsp;<?= $esc(Money::formatDutch($surchargeCents)) ?>
      <?php endif; ?>
      <?php if ($isDisabled): ?>
        &middot; <span class="admin-pz-block__warn"><?= admin_te('personalization.not_active') ?></span>
      <?php endif; ?>
    </span>
    <code class="admin-text-muted"><?= $esc((string) $zone['zone_key']) ?></code>
  </summary>

  <div class="admin-pz-block__body">
    <div class="admin-pz-actions">
      <form method="post" action="/api/admin/move-personalization-zone.php" class="admin-inline-form">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
        <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
        <input type="hidden" name="direction" value="up">
        <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?> aria-label="Zone omhoog">&uarr;</button>
      </form>
      <form method="post" action="/api/admin/move-personalization-zone.php" class="admin-inline-form">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
        <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
        <input type="hidden" name="direction" value="down">
        <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?> aria-label="Zone omlaag">&darr;</button>
      </form>
      <form method="post" action="/api/admin/delete-personalization-zone.php" class="admin-inline-form"
            onsubmit="return confirm('Deze zone verwijderen? Bestaande bestellingen blijven ongewijzigd.');">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
        <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('personalization.zone_verwijderen') ?></button>
      </form>
    </div>

    <form method="post" action="/api/admin/update-personalization-zone.php">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
      <input type="hidden" name="zone_id" value="<?= $zoneId ?>">

      <?= admin_localized_input($editingLanguage) ?>
      <div class="admin-pz-grid">
          <label><?= admin_te('personalization.naam_klant_3') ?>
            <input type="text" name="label" maxlength="<?= PersonalizationLocalization::LABEL_MAX_LENGTH ?>" value="<?= $esc($word(PersonalizationLocalization::LABEL)) ?>" placeholder="Bijv. Naam"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
          </label>
      </div>

      <fieldset class="admin-pz-checks">
        <legend><?= admin_te('personalization.wat_mag_hier') ?></legend>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="allow_text" value="1" <?= $allowsText ? 'checked' : '' ?>> <?= admin_te('personalization.tekst') ?>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="allow_image" value="1" <?= $allowsImage ? 'checked' : '' ?>> <?= admin_te('common.image') ?>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_required" value="1" <?= $checked('is_required', false) ? 'checked' : '' ?>> <?= admin_te('common.required') ?>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_enabled" value="1" <?= $checked('is_enabled', true) ? 'checked' : '' ?>> <?= admin_te('common.active') ?>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="allow_rotation" value="1" <?= $checked('allow_rotation', true) ? 'checked' : '' ?>> <?= admin_te('personalization.draaien') ?>
        </label>
      </fieldset>

      <div class="admin-pz-grid">
        <label><?= admin_te('personalization.max_tekstlengte') ?>
          <input type="number" name="max_text_length" min="<?= PersonalizationRules::MIN_TEXT_LENGTH_SETTING ?>"
                 max="<?= PersonalizationRules::MAX_TEXT_LENGTH_SETTING ?>" step="1"
                 value="<?= (int) PersonalizationRules::clampMaxTextLength($value('max_text_length', PersonalizationRules::DEFAULT_TEXT_LENGTH_SETTING)) ?>">
        </label>
        <label><?= admin_t('personalization.meerprijs') ?>
          <input type="text" inputmode="decimal" name="surcharge" value="<?= $esc(Money::format($surchargeCents)) ?>" placeholder="0.00">
        </label>
          <label><?= admin_te('personalization.voorbeeldtekst') ?>
            <input type="text" name="placeholder" maxlength="<?= PersonalizationLocalization::PLACEHOLDER_MAX_LENGTH ?>" value="<?= $esc($word(PersonalizationLocalization::PLACEHOLDER)) ?>" placeholder="Bijv. Bart"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
          </label>
      </div>

      <div class="admin-pz-grid">
          <label><?= admin_te('personalization.uitleg_zone') ?>
            <textarea name="instructions" rows="2" maxlength="<?= PersonalizationLocalization::INSTRUCTIONS_MAX_LENGTH ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>><?= $esc($word(PersonalizationLocalization::INSTRUCTIONS)) ?></textarea>
          </label>
      </div>

      <fieldset class="admin-pz-area">
        <legend><?= admin_te('personalization.gravuregebied_afbeelding') ?></legend>
        <label><?= admin_te('personalization.links') ?>
          <input type="number" name="area_x" step="0.1" min="0" max="100" required
                 data-zone-input="x" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['x'], 1, '.', '')) ?>">
        </label>
        <label><?= admin_te('personalization.boven') ?>
          <input type="number" name="area_y" step="0.1" min="0" max="100" required
                 data-zone-input="y" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['y'], 1, '.', '')) ?>">
        </label>
        <label><?= admin_te('personalization.breedte') ?>
          <input type="number" name="area_width" step="0.1" min="<?= PersonalizationRules::MIN_AREA_SIZE_PERCENT ?>" max="100" required
                 data-zone-input="width" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['width'], 1, '.', '')) ?>">
        </label>
        <label><?= admin_te('personalization.hoogte') ?>
          <input type="number" name="area_height" step="0.1" min="<?= PersonalizationRules::MIN_AREA_SIZE_PERCENT ?>" max="100" required
                 data-zone-input="height" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['height'], 1, '.', '')) ?>">
        </label>
      </fieldset>

      <button type="submit"><?= admin_te('personalization.zone_opslaan') ?></button>
    </form>
  </div>
</details>
    <?php
}

/**
 * The "add a zone" form. Deliberately short — a new zone starts with sensible
 * defaults and is then refined in its own block above — and deliberately
 * named after the voorbeeld it will land on, so it can never be mistaken for
 * "add another side of the product".
 *
 * @param array<string, mixed>|null $old
 */
function renderPersonalizationZoneCreateForm(int $viewId, string $viewName, ?array $old, string $csrfToken): void
{
    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $editingLanguage = admin_localized_language();
    ?>
<form method="post" action="/api/admin/create-personalization-zone.php" class="admin-pz-grid admin-pz-grid--create">
  <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
  <input type="hidden" name="view_id" value="<?= $viewId ?>">
  <?php /* A new zone starts as a centred, text-and-image, optional zone, and
           is named in the default language (Multilingual 2.0 phase 5 wave D). */ ?>
  <?php admin_localized_new_item_note($editingLanguage); ?>
  <input type="hidden" name="allow_text" value="1">
  <input type="hidden" name="allow_image" value="1">
  <input type="hidden" name="is_enabled" value="1">
  <input type="hidden" name="allow_rotation" value="1">
  <input type="hidden" name="max_text_length" value="<?= PersonalizationRules::DEFAULT_TEXT_LENGTH_SETTING ?>">
  <input type="hidden" name="area_x" value="25">
  <input type="hidden" name="area_y" value="35">
  <input type="hidden" name="area_width" value="50">
  <input type="hidden" name="area_height" value="30">
  <input type="hidden" name="surcharge" value="0.00">

  <label><?= admin_te('personalization.nieuwe_zone_sleutel') ?>*
    <input type="text" name="zone_key" maxlength="32" required
           value="<?= $esc($old !== null ? (string) ($old['zone_key'] ?? '') : '') ?>"
           placeholder="naam">
  </label>
  <label><?= admin_te('common.name') ?>
    <input type="text" name="label" maxlength="<?= PersonalizationLocalization::LABEL_MAX_LENGTH ?>"
           value="<?= $esc($old !== null ? (string) ($old['label'] ?? '') : '') ?>"
           placeholder="Naam">
  </label>
  <div class="admin-pz-grid__action">
    <button type="submit"><?= admin_t('personalization.zone', ['v1' => $esc($viewName)]) ?></button>
  </div>
</form>
    <?php
}
