<?php

declare(strict_types=1);

use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationColors;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationRules;

/**
 * Renders one order line's personalization in the CMS order detail
 * (admin/order.php): what the customer typed in each zone, which font they
 * chose, what they uploaded, what it cost extra, and a reconstruction of the
 * preview they saw — grouped by the view (front/back) each zone sat on.
 *
 * Every reconstruction is built entirely from the STRUCTURED data stored with
 * the order — the per-zone configuration snapshot plus the normalized
 * transform. That is what makes it reproducible and verifiable: the picture
 * the owner sees is provably the picture the stored data describes, and it
 * stays correct years later even though the product itself has moved on.
 *
 * Beside it, when the order has one, sits the COMPOSED PREVIEW: the PNG the
 * customer's own browser rasterised of that view at the moment they ordered,
 * validated and re-encoded server-side (see api/personalization-preview.php).
 * That one is downloadable, because it is the file the workshop wants — and
 * because it is the only version that can show a webfont the browser had and
 * the server does not. It is SUPPLEMENTARY: the structured data and the
 * customer's original upload remain the source of truth, an order without a
 * snapshot still renders its reconstruction exactly as before, and the
 * original upload is downloadable separately either way.
 *
 * ## Layout
 *
 * Deliberately STACKED — facts first, pictures underneath — rather than two
 * columns. This block lives inside a table cell, and a fixed-width preview
 * beside a column of text made that cell wider than the viewport, so the
 * whole order screen scrolled sideways.
 *
 * ## Phase 1 orders
 *
 * A `version: 1` snapshot has no view and no font, and its order row has no
 * `view_key`/`font_key`/`surcharge`. Everything below therefore treats those
 * as optional: an order placed before views existed renders exactly as it
 * always did — one block, no view heading, no font line, no surcharge line.
 *
 * Nothing here ever prints a filesystem path. The customer's file is only
 * reachable through the authenticated endpoint
 * api/admin/order-personalization-file.php, addressed by the record's own id.
 *
 */
/**
 * The `@font-face` rules an order screen needs so its reconstructions render
 * in the faces the customer actually chose — including uploaded fonts that
 * have since been deactivated or deleted from the library.
 *
 * Built from the order's OWN copy of the font first (`font_file_path`, and
 * the snapshot's `font` block for a row written before those columns existed)
 * and only then from the live library. Every value interpolated is a
 * server-generated path or a key from the validated charset; a stray row can
 * only ever produce a rule that fails to load, never one that escapes.
 *
 * @param array<int, array<int, array<string, mixed>>> $grouped rows from
 *        App\Repository\OrderItemPersonalizationRepository::findByOrderIdGrouped()
 */
function orderPersonalizationFontFaceCss(array $grouped): string
{
    $seen = [];
    $css = '';

    foreach ($grouped as $rows) {
        foreach ($rows as $row) {
            $key = trim((string) ($row['font_key'] ?? ''));

            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $snapshot = json_decode((string) ($row['config_snapshot_json'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $fontSnapshot = is_array($snapshot['font'] ?? null) ? $snapshot['font'] : [];

            $filePath = trim((string) ($row['font_file_path'] ?? ($fontSnapshot['file_path'] ?? '')));

            if ($filePath === '') {
                // Either a built-in family (nothing to load) or a Phase 1/2
                // row: fall back to whatever the library still says.
                $css .= PersonalizationFonts::faceCss([$key]);
                continue;
            }

            $format = strtolower((string) ($row['font_format'] ?? ($fontSnapshot['file_format'] ?? '')));
            if ($format === '') {
                $format = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            }

            $hint = match ($format) {
                'woff2' => "woff2",
                'woff' => "woff",
                'ttf' => 'truetype',
                'otf' => 'opentype',
                default => '',
            };

            $url = implode('/', array_map('rawurlencode', explode('/', '/' . ltrim($filePath, '/'))));

            $css .= "@font-face{font-family:'" . PersonalizationFonts::familyName($key) . "';"
                . "src:url('" . $url . "')" . ($hint === '' ? '' : " format('" . $hint . "')") . ';'
                . "font-display:swap;font-weight:normal;font-style:normal;}
";
        }
    }

    return $css;
}

/**
 * @param array<int, array<string, mixed>> $personalizations rows from
 *        App\Repository\OrderItemPersonalizationRepository::findByOrderIdGrouped()
 * @param array<string, array<string, mixed>> $snapshots this line's composed
 *        previews, keyed by view_key — see
 *        App\Repository\PersonalizationPreviewSnapshotRepository::findByOrderIdGrouped().
 *        Empty for every order placed before snapshots existed, and for any
 *        order whose browser could not produce one.
 */
function renderOrderItemPersonalizations(array $personalizations, array $snapshots = []): void
{
    if ($personalizations === []) {
        return;
    }

    // Group by view, in the order the zones were stored. A Phase 1 row has no
    // view at all and falls into a single unnamed group, which is why a
    // historical order shows no view heading.
    $groups = [];
    foreach ($personalizations as $row) {
        $snapshot = json_decode((string) ($row['config_snapshot_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $viewKey = (string) ($row['view_key'] ?? ($snapshot['view']['view_key'] ?? ''));
        $groups[$viewKey]['label'] = $snapshot['view']['label'] ?? null;
        $groups[$viewKey]['rows'][] = [$row, $snapshot];
    }

    $showViewHeadings = count($groups) > 1;
    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ?>
<div class="admin-personalization">
  <h4 class="admin-personalization__title">Personalisatie</h4>

  <?php foreach ($groups as $viewKey => $group): ?>
    <?php if ($showViewHeadings): ?>
      <h5 class="admin-personalization__view-title">
        <?= $esc($group['label'] !== null && $group['label'] !== '' ? (string) $group['label'] : ($viewKey !== '' ? $viewKey : 'Weergave')) ?>
      </h5>
    <?php endif; ?>

    <?php foreach ($group['rows'] as [$row, $snapshot]): ?>
      <?php renderOrderPersonalizationZone($row, $snapshot); ?>
    <?php endforeach; ?>

    <?php
      // One composed preview per VIEW, not per zone: it is a picture of the
      // whole side of the product, with every zone on it at once.
      renderOrderPersonalizationSnapshot($snapshots[$viewKey] ?? null);
    ?>
  <?php endforeach; ?>
</div>
    <?php
}

/**
 * The composed preview of one view — the picture the customer approved —
 * plus the action that downloads it.
 *
 * Renders nothing at all when the order has no snapshot for this view, which
 * is every order placed before snapshots existed. The reconstruction above it
 * is unaffected either way.
 *
 * @param array<string, mixed>|null $snapshot
 */
function renderOrderPersonalizationSnapshot(?array $snapshot): void
{
    if ($snapshot === null) {
        return;
    }

    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $endpoint = '/api/admin/order-preview-snapshot.php?id=' . (int) $snapshot['id'];
    ?>
<div class="admin-personalization__snapshot">
  <h6 class="admin-personalization__snapshot-title">Samengesteld voorbeeld</h6>
  <p class="admin-personalization__note">
    Precies wat de klant zag toen ze bestelden, inclusief het gekozen lettertype en de kleur. Dit is een extra
    bestand — de gegevens hierboven en het originele bestand van de klant blijven leidend.
  </p>
  <div class="admin-personalization__snapshot-media">
    <img src="<?= $esc($endpoint) ?>" alt="Samengesteld voorbeeld zoals de klant het zag" loading="lazy">
  </div>
  <p class="admin-personalization__snapshot-actions">
    <a class="admin-btn-link" href="<?= $esc($endpoint . '&mode=download') ?>">Download voorbeeld</a>
    <a href="<?= $esc($endpoint) ?>" target="_blank" rel="noopener">Openen in tabblad</a>
    <?php if (!empty($snapshot['image_width']) && !empty($snapshot['image_height'])): ?>
      <span class="admin-text-muted"><?= (int) $snapshot['image_width'] ?>&times;<?= (int) $snapshot['image_height'] ?> px</span>
    <?php endif; ?>
  </p>
</div>
    <?php
}

/**
 * One zone of one order line: the facts first, the reconstruction underneath.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $snapshot
 */
function renderOrderPersonalizationZone(array $row, array $snapshot): void
{
    $esc = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $id = (int) $row['id'];

    $zoneSnapshot = is_array($snapshot['zone'] ?? null) ? $snapshot['zone'] : [];
    $zoneLabel = $zoneSnapshot['label'] ?? null;
    if ($zoneLabel === null || $zoneLabel === '') {
        $zoneLabel = (string) $row['zone_key'];
    }

    $transform = json_decode((string) ($row['transform_json'] ?? ''), true);
    $transform = PersonalizationRules::normalizeTransformSet(is_array($transform) ? $transform : []);

    $area = isset($zoneSnapshot['area']) && is_array($zoneSnapshot['area'])
        ? PersonalizationRules::clampArea($zoneSnapshot['area'])
        : null;

    // Ratios come from the SNAPSHOT first: if the project ever changes what
    // "scale 1.0" means, an old order must keep rendering the way it did.
    $textRatio = (float) ($snapshot['render']['text_base_height_ratio'] ?? PersonalizationRules::TEXT_BASE_HEIGHT_RATIO);
    $imageRatio = (float) ($snapshot['render']['image_base_width_ratio'] ?? PersonalizationRules::IMAGE_BASE_WIDTH_RATIO);

    $previewPath = trim((string) ($snapshot['preview_image_path'] ?? ''));
    $previewUrl = $previewPath !== '' ? '/' . ltrim($previewPath, '/') : '';
    $previewExists = $previewPath !== '' && is_file(dirname(__DIR__) . '/' . ltrim($previewPath, '/'));

    $text = (string) ($row['text_value'] ?? '');
    $hasUpload = $row['upload_id'] !== null;
    $fileEndpoint = '/api/admin/order-personalization-file.php?id=' . $id;

    // The font this line was ACTUALLY engraved in, resolved from the order's
    // own copy first (Phase 3 columns / the snapshot's `font` block) and only
    // then from the live library. That order matters: the library is
    // editable, and a font that has since been renamed, deactivated or
    // deleted must not change — or blank — what a placed order says.
    $fontKey = (string) ($row['font_key'] ?? '');
    $fontSnapshot = is_array($snapshot['font'] ?? null) ? $snapshot['font'] : [];
    $fontLabel = (string) ($row['font_label'] ?? ($fontSnapshot['label'] ?? ''));
    if ($fontLabel === '' && $fontKey !== '') {
        $fontLabel = PersonalizationFonts::label($fontKey);
    }
    $fontStack = (string) ($row['font_stack'] ?? ($fontSnapshot['stack'] ?? ''));
    if ($fontStack === '' && $fontKey !== '') {
        $fontStack = PersonalizationFonts::stack($fontKey);
    }
    // The colour the customer previewed their text in. The order's own key
    // first, then the snapshot's copy, and only then the palette default —
    // the same precedence the font uses, and for the same reason: a later
    // palette change must not rewrite what a placed order shows.
    $colorSnapshot = is_array($snapshot['color'] ?? null) ? $snapshot['color'] : [];
    $colorKey = (string) ($row['text_color'] ?? ($colorSnapshot['key'] ?? ''));
    $colorLabel = $colorKey !== ''
        ? (string) ($colorSnapshot['label'] ?? PersonalizationColors::label($colorKey))
        : '';
    $colorHex = $colorKey !== ''
        ? (string) ($colorSnapshot['hex'] ?? PersonalizationColors::hex($colorKey))
        : '';

    $surchargeCents = Money::toCents($row['surcharge'] ?? 0);

    $percent = static fn (float $value): string => number_format($value * 100, 0, ',', '.') . '%';
    ?>
<div class="admin-personalization__zone">
  <h6 class="admin-personalization__zone-title">
    <?= $esc((string) $zoneLabel) ?>
    <code class="admin-text-muted"><?= $esc((string) $row['zone_key']) ?></code>
    <?php if (!empty($zoneSnapshot['is_required'])): ?>
      <span class="admin-text-muted">(verplicht)</span>
    <?php endif; ?>
  </h6>

  <div class="admin-personalization__body">

    <div class="admin-personalization__facts">
      <dl class="admin-personalization__list">
        <?php if ($text !== ''): ?>
          <dt>Tekst</dt>
          <dd><strong><?= $esc($text) ?></strong></dd>
          <?php if ($fontKey !== ''): ?>
            <dt>Lettertype</dt>
            <dd><?= $esc($fontLabel !== '' ? $fontLabel : $fontKey) ?></dd>
          <?php endif; ?>
          <?php if ($colorKey !== ''): ?>
            <dt>Tekstkleur</dt>
            <dd>
              <span class="admin-personalization__swatch" style="background:<?= $esc($colorHex) ?>;" aria-hidden="true"></span>
              <?= $esc($colorLabel !== '' ? $colorLabel : $colorKey) ?>
            </dd>
          <?php endif; ?>
          <dt>Tekstpositie</dt>
          <dd class="admin-text-muted">
            <?= $esc($percent($transform['text']['x'])) ?> van links,
            <?= $esc($percent($transform['text']['y'])) ?> van boven,
            grootte <?= $esc($percent($transform['text']['scale'])) ?><?php
              if (abs($transform['text']['rotation']) > 0.01) {
                  echo ', gedraaid ' . $esc(number_format($transform['text']['rotation'], 0, ',', '.')) . '&deg;';
              }
            ?>
            <span class="admin-personalization__note">(binnen het gravuregebied)</span>
          </dd>
        <?php else: ?>
          <dt>Tekst</dt>
          <dd class="admin-text-muted">Geen tekst opgegeven.</dd>
        <?php endif; ?>

        <?php if ($hasUpload): ?>
          <dt>Bestand van de klant</dt>
          <dd>
            <strong><?= $esc((string) ($row['original_filename'] ?? '')) ?></strong>
            <span class="admin-text-muted">
              (<?= $esc((string) ($row['mime_type'] ?? '')) ?><?php
                if (!empty($row['image_width']) && !empty($row['image_height'])) {
                    echo ', ' . (int) $row['image_width'] . '&times;' . (int) $row['image_height'] . ' px';
                }
                if (!empty($row['byte_size'])) {
                    echo ', ' . number_format(((int) $row['byte_size']) / 1024, 0, ',', '.') . ' kB';
                }
              ?>)
            </span>
            <br>
            <a href="<?= $esc($fileEndpoint . '&mode=download') ?>">Download origineel</a>
            &middot;
            <a href="<?= $esc($fileEndpoint) ?>" target="_blank" rel="noopener">Open origineel</a>
          </dd>
          <dt>Afbeeldingspositie</dt>
          <dd class="admin-text-muted">
            <?= $esc($percent($transform['image']['x'])) ?> van links,
            <?= $esc($percent($transform['image']['y'])) ?> van boven,
            grootte <?= $esc($percent($transform['image']['scale'])) ?><?php
              if (abs($transform['image']['rotation']) > 0.01) {
                  echo ', gedraaid ' . $esc(number_format($transform['image']['rotation'], 0, ',', '.')) . '&deg;';
              }
            ?>
          </dd>
        <?php else: ?>
          <dt>Bestand van de klant</dt>
          <dd class="admin-text-muted">Geen afbeelding geüpload.</dd>
        <?php endif; ?>

        <?php if ($surchargeCents > 0): ?>
          <dt>Meerprijs</dt>
          <dd><strong>&euro; <?= $esc(Money::formatDutch($surchargeCents)) ?></strong>
            <span class="admin-text-muted">(per stuk, zoals berekend bij deze bestelling)</span></dd>
        <?php endif; ?>

        <?php if ($area !== null): ?>
          <dt>Gravuregebied bij bestelling</dt>
          <dd class="admin-text-muted">
            <?= $esc(number_format($area['x'], 1, ',', '.')) ?>% /
            <?= $esc(number_format($area['y'], 1, ',', '.')) ?>% &mdash;
            <?= $esc(number_format($area['width'], 1, ',', '.')) ?>% breed,
            <?= $esc(number_format($area['height'], 1, ',', '.')) ?>% hoog
          </dd>
        <?php endif; ?>
      </dl>
    </div>

    <div class="admin-personalization__preview">
      <?php if ($previewExists && $area !== null): ?>
        <div class="admin-personalization__stage" data-personalization-preview>
          <img class="admin-personalization__base" src="<?= $esc($previewUrl) ?>" alt="" loading="lazy">
          <div class="admin-personalization__zone-area"
               data-personalization-zone
               style="left:<?= $esc(number_format($area['x'], 3, '.', '')) ?>%;top:<?= $esc(number_format($area['y'], 3, '.', '')) ?>%;width:<?= $esc(number_format($area['width'], 3, '.', '')) ?>%;height:<?= $esc(number_format($area['height'], 3, '.', '')) ?>%;">
            <?php if ($hasUpload): ?>
              <img class="admin-personalization__layer"
                   src="<?= $esc($fileEndpoint . '&variant=preview') ?>"
                   alt="Door de klant geüploade afbeelding"
                   loading="lazy"
                   style="left:<?= $esc(number_format($transform['image']['x'] * 100, 3, '.', '')) ?>%;top:<?= $esc(number_format($transform['image']['y'] * 100, 3, '.', '')) ?>%;width:<?= $esc(number_format($imageRatio * $transform['image']['scale'] * 100, 3, '.', '')) ?>%;transform:translate(-50%,-50%) rotate(<?= $esc(number_format($transform['image']['rotation'], 2, '.', '')) ?>deg);">
            <?php endif; ?>
            <?php if ($text !== ''): ?>
              <?php /* The font size is the one value that cannot be a
                       percentage in CSS, so admin/assets/personalization-admin.js
                       computes it from the zone's measured height using the
                       same ratio the customer's own preview used. The font
                       FAMILY comes from the stored key, so the owner sees the
                       typeface the customer picked. */ ?>
              <span class="admin-personalization__layer admin-personalization__layer--text"
                    data-personalization-text
                    data-text-ratio="<?= $esc(number_format($textRatio, 4, '.', '')) ?>"
                    data-text-scale="<?= $esc(number_format($transform['text']['scale'], 4, '.', '')) ?>"
                    style="left:<?= $esc(number_format($transform['text']['x'] * 100, 3, '.', '')) ?>%;top:<?= $esc(number_format($transform['text']['y'] * 100, 3, '.', '')) ?>%;transform:translate(-50%,-50%) rotate(<?= $esc(number_format($transform['text']['rotation'], 2, '.', '')) ?>deg);<?= $fontStack !== '' ? 'font-family:' . $esc($fontStack) . ';' : '' ?><?= $colorHex !== '' ? 'color:' . $esc($colorHex) . ';' : '' ?>"><?= $esc($text) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <p class="admin-personalization__note">Nagebouwd uit de opgeslagen gegevens van deze bestelling — niet uit de huidige productinstellingen.</p>
      <?php else: ?>
        <p class="admin-text-muted">
          De voorbeeldafbeelding van deze bestelling is niet meer beschikbaar, dus het voorbeeld kan niet worden
          nagebouwd. De tekst, het bestand en de posities hiernaast zijn volledig bewaard gebleven.
        </p>
      <?php endif; ?>
    </div>

  </div>
</div>
    <?php
}
