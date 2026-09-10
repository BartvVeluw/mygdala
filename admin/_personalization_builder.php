<?php

declare(strict_types=1);

use App\Service\Personalization\Money;
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

    $settingsOld = $oldFor('settings');
    $isEnabled = $settingsOld !== null
        ? !empty($settingsOld['personalization_enabled'])
        : (int) $settings['is_enabled'] === 1;
    $mode = PersonalizationRules::purchaseMode(
        $settingsOld !== null
            ? ($settingsOld['personalization_mode'] ?? null)
            : ($settings['personalization_mode'] ?? null)
    );
    $instructions = $settingsOld !== null
        ? (string) ($settingsOld['instructions'] ?? '')
        : (string) ($settings['instructions'] ?? '');
    $instructionsEn = $settingsOld !== null
        ? (string) ($settingsOld['instructions_en'] ?? '')
        : (string) ($settings['instructions_en'] ?? '');

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
  <p class="admin-alert admin-alert--success">Personalisatie opgeslagen.</p>
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
  <h2>Instellingen</h2>

  <form method="post" action="/api/admin/update-product-personalization.php" class="admin-personalization-form">
    <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
    <input type="hidden" name="product_id" value="<?= $productId ?>">

    <div class="admin-pz-grid admin-pz-grid--settings">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="personalization_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>>
        <span><strong>Personalisatie inschakelen</strong><br>
        <span class="admin-text-muted">Uit = gewone productpagina, geen personalisatieblok.</span></span>
      </label>

      <fieldset class="admin-pz-radios">
        <legend>Aankoop</legend>
        <label class="admin-checkbox-label">
          <input type="radio" name="personalization_mode" value="<?= $esc(PersonalizationRules::PURCHASE_OPTIONAL) ?>"
                 <?= $mode === PersonalizationRules::PURCHASE_OPTIONAL ? 'checked' : '' ?>>
          <span>Optioneel — mag ook zonder gravure besteld worden.</span>
        </label>
        <label class="admin-checkbox-label">
          <input type="radio" name="personalization_mode" value="<?= $esc(PersonalizationRules::PURCHASE_REQUIRED) ?>"
                 <?= $mode === PersonalizationRules::PURCHASE_REQUIRED ? 'checked' : '' ?>>
          <span>Verplicht — alleen gepersonaliseerd te bestellen.</span>
        </label>
      </fieldset>
    </div>

    <?php if ($isPersonalizationOnly): ?>
      <p class="admin-alert admin-alert--info">
        Dit product staat <strong>niet in de shop</strong> (zie
        <a href="/admin/product-form.php?id=<?= $productId ?>">Producten</a>), dus personalisatie is hoe dan ook
        verplicht: er is geen andere manier om het te bestellen. De keuze hierboven telt pas weer mee zodra je het
        product ook in de shop zet.
      </p>
    <?php endif; ?>

    <div class="admin-pz-grid">
      <label>Algemene uitleg (NL)
        <textarea name="instructions" rows="2" maxlength="500" placeholder="Bijv. Personaliseer dit product met een naam of logo."><?= $esc($instructions) ?></textarea>
      </label>
      <label>Algemene uitleg (EN)
        <textarea name="instructions_en" rows="2" maxlength="500" placeholder="Leeg = Nederlandse tekst"><?= $esc($instructionsEn) ?></textarea>
      </label>
    </div>

    <button type="submit">Instellingen opslaan</button>
  </form>

  <?php if ($isEnabled && $renderableViews === 0): ?>
    <p class="admin-alert admin-alert--error" style="margin-top:var(--admin-sp-3);">
      <strong>Configuratiefout:</strong> personalisatie staat aan, maar er is niets te tonen. Er is minstens één
      voorbeeld nodig met een <em>eigen</em> afbeelding én minstens één actieve zone. Zolang dat er niet is, laat de
      productpagina niets zien — er wordt bewust nooit teruggevallen op een gewone productfoto.
    </p>
  <?php elseif ($viewsWithoutImage > 0): ?>
    <p class="admin-alert admin-alert--error" style="margin-top:var(--admin-sp-3);">
      <strong>Configuratiefout:</strong> <?= $viewsWithoutImage ?> voorbeeld<?= $viewsWithoutImage === 1 ? '' : 'en' ?>
      <?= $viewsWithoutImage === 1 ? 'heeft' : 'hebben' ?> nog geen eigen afbeelding en
      <?= $viewsWithoutImage === 1 ? 'wordt' : 'worden' ?> daarom niet getoond in de shop.
    </p>
  <?php endif; ?>
</section>

<section class="admin-card">
  <div class="admin-pz-head">
    <div>
      <h2>Voorbeelden</h2>
      <p class="admin-text-muted admin-pz-head__hint">
        Eén voorbeeld = één foto van het product met zijn eigen zones. <strong>Een achterkant is een nieuw
        voorbeeld met een eigen foto</strong> — niet een tweede zone op de voorkant.
      </p>
    </div>
    <a class="admin-btn-link" href="#voorbeeld-toevoegen">+ Voorbeeld toevoegen</a>
  </div>

  <?php if ($views === []): ?>
    <p class="admin-text-muted">Nog geen voorbeelden. Voeg er hieronder één toe en upload meteen de bijbehorende foto.</p>
  <?php endif; ?>

  <?php foreach ($views as $viewIndex => $view): ?>
    <?php
      $viewId = (int) $view['id'];
      $viewOld = $oldFor('view', $viewId);
      $viewLabel = $viewOld !== null ? (string) ($viewOld['label'] ?? '') : (string) ($view['label'] ?? '');
      $viewLabelEn = $viewOld !== null ? (string) ($viewOld['label_en'] ?? '') : (string) ($view['label_en'] ?? '');
      $viewImage = trim((string) ($view['preview_image_path'] ?? ''));
      $isFirstView = $viewIndex === 0;
      $isLastView = $viewIndex === count($views) - 1;
      $displayName = $viewLabel !== '' ? $viewLabel : (string) $view['view_key'];
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
            afbeelding ingesteld
          <?php else: ?>
            <span class="admin-pz-block__warn">geen afbeelding</span>
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
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Voorbeeld verwijderen</button>
          </form>
        </div>

        <form method="post" action="/api/admin/update-personalization-view.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
          <input type="hidden" name="view_id" value="<?= $viewId ?>">

          <div class="admin-pz-grid">
            <label>Naam voor de klant (NL)
              <input type="text" name="label" maxlength="100" value="<?= $esc($viewLabel) ?>" placeholder="Bijv. Voorkant">
            </label>
            <label>Naam voor de klant (EN)
              <input type="text" name="label_en" maxlength="100" value="<?= $esc($viewLabelEn) ?>" placeholder="Leeg = Nederlandse naam">
            </label>
          </div>

          <div class="admin-pz-image">
            <?php if ($viewImage !== ''): ?>
              <div class="admin-pz-image__thumb">
                <img src="/<?= $esc(ltrim($viewImage, '/')) ?>" alt="" loading="lazy">
              </div>
            <?php endif; ?>
            <div class="admin-pz-image__fields">
              <label>Eigen afbeelding<?= $viewImage === '' ? '' : ' vervangen' ?>
                <input type="file" name="preview_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
              </label>
              <p class="admin-text-muted">
                JPG, PNG, WEBP of GIF, max. <?= $maxPreviewMb ?> MB. De zones hieronder worden opgeslagen als
                percentages van <em>deze</em> afbeelding.
              </p>
              <?php if ($viewImage !== ''): ?>
                <label class="admin-checkbox-label">
                  <input type="checkbox" name="remove_preview_image" value="1">
                  Afbeelding verwijderen bij opslaan
                </label>
              <?php endif; ?>
            </div>
          </div>

          <button type="submit">Voorbeeld opslaan</button>
        </form>

        <?php /* ---- the visual multi-zone editor for this voorbeeld ---- */ ?>
        <?php if ($viewImage !== '' && $view['zones'] !== []): ?>
          <p class="admin-text-muted admin-pz-editor-hint">
            Klik een zone aan om hem te selecteren, sleep hem naar de juiste plek en gebruik de hoekgrepen voor het
            formaat. De percentages onder elke zone blijven leidend — je kunt ze ook intypen, en met de pijltjestoetsen
            verplaats je de geselecteerde zone (Shift = formaat).
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
                     aria-label="Zone <?= $esc($zone['label'] !== null && $zone['label'] !== '' ? (string) $zone['label'] : (string) $zone['zone_key']) ?> — verplaats met de pijltjestoetsen, houd Shift ingedrukt om het formaat te wijzigen"
                     style="left:<?= $esc(number_format((float) $zone['area_x'], 3, '.', '')) ?>%;top:<?= $esc(number_format((float) $zone['area_y'], 3, '.', '')) ?>%;width:<?= $esc(number_format((float) $zone['area_width'], 3, '.', '')) ?>%;height:<?= $esc(number_format((float) $zone['area_height'], 3, '.', '')) ?>%;">
                  <span class="admin-zone-editor__tag"><?= $esc($zone['label'] !== null && $zone['label'] !== '' ? (string) $zone['label'] : (string) $zone['zone_key']) ?></span>
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
            Dit voorbeeld heeft nog geen eigen afbeelding. Upload er één hierboven; daarna kun je de zones er direct
            op aanwijzen. Er wordt nooit automatisch een productfoto gebruikt.
          </p>
        <?php endif; ?>

        <?php /* ---- the zones of this voorbeeld ---- */ ?>
        <h4 class="admin-pz-subhead">Zones op deze afbeelding</h4>
        <?php if ($view['zones'] === []): ?>
          <p class="admin-text-muted">Nog geen zones op dit voorbeeld.</p>
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
    <h3>Voorbeeld toevoegen</h3>
    <p class="admin-text-muted">
      Voor elke kant of foto van het product één voorbeeld, elk met een <strong>eigen</strong> afbeelding. Maak hem
      hier aan en upload de foto daarna in het blok dat verschijnt.
    </p>
    <form method="post" action="/api/admin/create-personalization-view.php" class="admin-pz-grid admin-pz-grid--create">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
      <input type="hidden" name="product_id" value="<?= $productId ?>">
      <label>Sleutel*
        <input type="text" name="view_key" maxlength="32" required
               value="<?= $esc($viewCreateOld !== null ? (string) ($viewCreateOld['view_key'] ?? '') : '') ?>"
               placeholder="achterkant">
      </label>
      <label>Naam (NL)
        <input type="text" name="label" maxlength="100"
               value="<?= $esc($viewCreateOld !== null ? (string) ($viewCreateOld['label'] ?? '') : '') ?>"
               placeholder="Achterkant">
      </label>
      <label>Naam (EN)
        <input type="text" name="label_en" maxlength="100"
               value="<?= $esc($viewCreateOld !== null ? (string) ($viewCreateOld['label_en'] ?? '') : '') ?>">
      </label>
      <div class="admin-pz-grid__action">
        <button type="submit">+ Voorbeeld toevoegen</button>
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

    $label = $zone['label'] !== null && $zone['label'] !== '' ? (string) $zone['label'] : (string) $zone['zone_key'];
    $allowsText = $checked('allow_text', true);
    $allowsImage = $checked('allow_image', true);
    $accepts = $allowsText && $allowsImage ? 'Tekst + afbeelding' : ($allowsImage ? 'Afbeelding' : 'Tekst');
    $requiredLabel = $checked('is_required', false) ? 'Verplicht' : 'Optioneel';
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
        &middot; <span class="admin-pz-block__warn">niet actief</span>
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
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Zone verwijderen</button>
      </form>
    </div>

    <form method="post" action="/api/admin/update-personalization-zone.php">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
      <input type="hidden" name="zone_id" value="<?= $zoneId ?>">

      <div class="admin-pz-grid">
        <label>Naam voor de klant (NL)
          <input type="text" name="label" maxlength="100" value="<?= $esc((string) $value('label', '')) ?>" placeholder="Bijv. Naam">
        </label>
        <label>Naam voor de klant (EN)
          <input type="text" name="label_en" maxlength="100" value="<?= $esc((string) $value('label_en', '')) ?>" placeholder="Leeg = Nederlandse naam">
        </label>
      </div>

      <fieldset class="admin-pz-checks">
        <legend>Wat mag hier in?</legend>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="allow_text" value="1" <?= $allowsText ? 'checked' : '' ?>> Tekst
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="allow_image" value="1" <?= $allowsImage ? 'checked' : '' ?>> Afbeelding
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_required" value="1" <?= $checked('is_required', false) ? 'checked' : '' ?>> Verplicht
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_enabled" value="1" <?= $checked('is_enabled', true) ? 'checked' : '' ?>> Actief
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="allow_rotation" value="1" <?= $checked('allow_rotation', true) ? 'checked' : '' ?>> Draaien
        </label>
      </fieldset>

      <div class="admin-pz-grid">
        <label>Max. tekstlengte
          <input type="number" name="max_text_length" min="<?= PersonalizationRules::MIN_TEXT_LENGTH_SETTING ?>"
                 max="<?= PersonalizationRules::MAX_TEXT_LENGTH_SETTING ?>" step="1"
                 value="<?= (int) PersonalizationRules::clampMaxTextLength($value('max_text_length', PersonalizationRules::DEFAULT_TEXT_LENGTH_SETTING)) ?>">
        </label>
        <label>Meerprijs (&euro;)
          <input type="text" inputmode="decimal" name="surcharge" value="<?= $esc(Money::format($surchargeCents)) ?>" placeholder="0.00">
        </label>
        <label>Voorbeeldtekst (NL)
          <input type="text" name="placeholder" maxlength="100" value="<?= $esc((string) $value('placeholder', '')) ?>" placeholder="Bijv. Bart">
        </label>
        <label>Voorbeeldtekst (EN)
          <input type="text" name="placeholder_en" maxlength="100" value="<?= $esc((string) $value('placeholder_en', '')) ?>">
        </label>
      </div>

      <div class="admin-pz-grid">
        <label>Uitleg bij deze zone (NL)
          <textarea name="instructions" rows="2" maxlength="500"><?= $esc((string) $value('instructions', '')) ?></textarea>
        </label>
        <label>Uitleg bij deze zone (EN)
          <textarea name="instructions_en" rows="2" maxlength="500"><?= $esc((string) $value('instructions_en', '')) ?></textarea>
        </label>
      </div>

      <fieldset class="admin-pz-area">
        <legend>Gravuregebied (% van deze afbeelding)</legend>
        <label>Links
          <input type="number" name="area_x" step="0.1" min="0" max="100" required
                 data-zone-input="x" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['x'], 1, '.', '')) ?>">
        </label>
        <label>Boven
          <input type="number" name="area_y" step="0.1" min="0" max="100" required
                 data-zone-input="y" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['y'], 1, '.', '')) ?>">
        </label>
        <label>Breedte
          <input type="number" name="area_width" step="0.1" min="<?= PersonalizationRules::MIN_AREA_SIZE_PERCENT ?>" max="100" required
                 data-zone-input="width" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['width'], 1, '.', '')) ?>">
        </label>
        <label>Hoogte
          <input type="number" name="area_height" step="0.1" min="<?= PersonalizationRules::MIN_AREA_SIZE_PERCENT ?>" max="100" required
                 data-zone-input="height" data-zone-id="<?= $zoneId ?>"
                 value="<?= $esc(number_format($area['height'], 1, '.', '')) ?>">
        </label>
      </fieldset>

      <button type="submit">Zone opslaan</button>
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
    ?>
<form method="post" action="/api/admin/create-personalization-zone.php" class="admin-pz-grid admin-pz-grid--create">
  <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
  <input type="hidden" name="view_id" value="<?= $viewId ?>">
  <?php /* A new zone starts as a centred, text-and-image, optional zone. */ ?>
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

  <label>Nieuwe zone — sleutel*
    <input type="text" name="zone_key" maxlength="32" required
           value="<?= $esc($old !== null ? (string) ($old['zone_key'] ?? '') : '') ?>"
           placeholder="naam">
  </label>
  <label>Naam (NL)
    <input type="text" name="label" maxlength="100"
           value="<?= $esc($old !== null ? (string) ($old['label'] ?? '') : '') ?>"
           placeholder="Naam">
  </label>
  <label>Naam (EN)
    <input type="text" name="label_en" maxlength="100"
           value="<?= $esc($old !== null ? (string) ($old['label_en'] ?? '') : '') ?>">
  </label>
  <div class="admin-pz-grid__action">
    <button type="submit">+ Zone op &ldquo;<?= $esc($viewName) ?>&rdquo;</button>
  </div>
</form>
    <?php
}
