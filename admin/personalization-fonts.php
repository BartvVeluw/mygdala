<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationFontUploader;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\SiteSettings;

AdminAuth::requireLogin();
AdminAuth::requirePermission('personalization.manage');

/**
 * The GLOBAL engraving font library — Personalisatie -> Lettertypes.
 *
 * One list for the whole shop. Every text zone of every personalizable
 * product offers exactly the fonts that are ACTIVE here, and the CUSTOMER
 * picks one on the product page. Fonts are deliberately never assigned to a
 * product or a zone: the shop engraves with the same set of faces whatever it
 * is engraving, and re-picking them per zone was work with no payoff.
 *
 * ## Preview
 *
 * Each row previews itself in its own face, including the uploaded ones: the
 * `@font-face` rules for every uploaded font in the library are printed in
 * the <style> block below, exactly the way the product page prints them. So
 * what the owner sees here is what a customer will see.
 *
 * ## Deleting
 *
 * Deactivating is the normal way to retire a font, and the only one offered
 * for a font that a placed order was actually engraved in — the file behind
 * that order is still needed to produce it. A font nothing has ordered can be
 * deleted outright, file and all. Either way a historical order keeps its own
 * copy of the label, the CSS stack and the file path (see
 * App\Repository\OrderItemPersonalizationRepository), so no order screen can
 * be broken from here.
 *
 * ## Licensing
 *
 * Not enforced anywhere, and not something this CMS can check — the note in
 * the upload card says what the owner has to be sure of themselves.
 */
$loadError = null;

try {
    PersonalizationFonts::clearCache();
    $fonts = PersonalizationFonts::library();
} catch (\Throwable $e) {
    error_log('[admin/personalization-fonts.php] ' . $e->getMessage());
    $fonts = [];
    $loadError = 'De lettertypebibliotheek kon niet worden geladen.';
}

$errors = $_SESSION['admin_font_errors'] ?? [];
$old = $_SESSION['admin_font_old'] ?? [];
unset($_SESSION['admin_font_errors'], $_SESSION['admin_font_old']);

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);
$deleted = isset($_GET['deleted']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * The sample every row previews with. A real name reads far better than
 * "Aa Bb Cc" — customers engrave names, not alphabets — but it must not be
 * a person's: this file ships with every copy of the CMS, and the owner's
 * own name was compiled into it. The site's own name is the closest thing
 * to a real word this installation is guaranteed to have, and it falls back
 * to a neutral pangram-ish sample on an installation that has not been
 * named yet.
 */
$sampleText = SiteSettings::get('site_name');
if (trim($sampleText) === '') {
    $sampleText = 'Anna Bergman';
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lettertypes — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<?php /* The uploaded faces, so the previews below are the real thing. Built
         entirely from server-controlled values — see PersonalizationFonts::faceCss(). */ ?>
<style><?= PersonalizationFonts::faceCss(array_map(static fn (array $f): string => (string) $f['key'], $fonts)) ?></style>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/personalization.php">&larr; Terug naar Personalisatie</a></p>

  <div class="admin-main__heading">
    <h1>Lettertypes</h1>
  </div>

  <p class="admin-text-muted">
    Dit is de <strong>globale</strong> lettertypebibliotheek voor productpersonalisatie. Elk actief lettertype hier is
    op de hele shop beschikbaar: bij elke tekstzone van elk personaliseerbaar product kan de klant eruit kiezen. Je
    hoeft dus nergens per product of per zone lettertypes aan te vinken.
  </p>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Lettertype toegevoegd.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Lettertype opgeslagen.</p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Lettertype verwijderd.</p>
  <?php endif; ?>
  <?php if ($loadError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h($loadError) ?></p>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Lettertype toevoegen</h2>
    <p class="admin-text-muted">
      Upload een lettertypebestand:
      <strong><?= $h(strtoupper(implode(', ', PersonalizationFontUploader::allowedExtensions()))) ?></strong>,
      max. <?= PersonalizationFontUploader::maxMegabytes() ?> MB. <strong>WOFF2</strong> heeft de voorkeur: dat is
      hetzelfde lettertype in een aanzienlijk kleiner bestand, en elke moderne browser ondersteunt het. TTF en OTF
      werken ook — die zijn alleen groter om te downloaden. Het bestand wordt op deze website zelf gehost; er wordt
      geen enkele externe lettertypedienst gebruikt.
    </p>
    <p class="admin-alert admin-alert--info">
      <strong>Let op de licentie.</strong> Upload alleen lettertypes waarvan je zeker weet dat je ze commercieel én
      als webfont mag gebruiken. Deze website controleert dat niet — dat blijft jouw verantwoordelijkheid.
    </p>

    <form method="post" action="/api/admin/create-personalization-font.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Naam*
          <input type="text" name="label" maxlength="100" required
                 value="<?= $h((string) ($old['label'] ?? '')) ?>" placeholder="Bijv. Playfair Display">
          <span class="admin-text-muted">De naam die de klant ziet.</span>
        </label>
        <label>Bestand*
          <input type="file" name="font_file" required
                 accept=".woff2,.woff,.ttf,.otf,font/woff2,font/woff,font/ttf,font/otf">
        </label>
        <div style="align-self:end;">
          <button type="submit">Toevoegen</button>
        </div>
      </div>
    </form>
  </section>

  <section class="admin-card">
    <h2>Bibliotheek</h2>

    <?php if ($fonts === []): ?>
      <p class="admin-text-muted">Nog geen lettertypes.</p>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Lettertype</th>
              <th>Voorbeeld</th>
              <th>Herkomst</th>
              <th>Actief</th>
              <th>Volgorde</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fonts as $index => $font): ?>
              <?php
                $fontId = (int) $font['id'];
                $isActive = $font['is_active'] === true;
                $isUpload = $font['source'] === 'upload';
                $isFirst = $index === 0;
                $isLast = $index === count($fonts) - 1;
              ?>
              <tr>
                <td>
                  <form method="post" action="/api/admin/update-personalization-font.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $fontId ?>">
                    <input type="text" name="label" maxlength="100" value="<?= $h((string) $font['label']) ?>" required>
                    <label class="admin-checkbox-label">
                      <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                      Actief
                    </label>
                    <button type="submit" class="admin-btn-text">Opslaan</button>
                  </form>
                  <code class="admin-text-muted"><?= $h((string) $font['key']) ?></code>
                  <?php if ($isUpload && $font['original_filename'] !== null): ?>
                    <br><span class="admin-text-muted"><?= $h((string) $font['original_filename']) ?><?php
                      if ($font['byte_size'] !== null) {
                          echo ' — ' . number_format(((int) $font['byte_size']) / 1024, 0, ',', '.') . ' kB';
                      }
                    ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="admin-font-preview" style="font-family:<?= $h((string) $font['stack']) ?>;"><?= $h($sampleText) ?></span>
                </td>
                <td>
                  <?php if ($isUpload): ?>
                    <span class="admin-badge admin-badge--info"><?= $h(strtoupper((string) $font['file_format'])) ?></span>
                  <?php else: ?>
                    <span class="admin-badge admin-badge--muted" title="Een lettertype dat de browser zelf al heeft of dat de site al laadt.">Standaard</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="admin-badge admin-badge--<?= $isActive ? 'paid' : 'muted' ?>"><?= $isActive ? 'Ja' : 'Nee' ?></span>
                </td>
                <td>
                  <div class="admin-personalization-list__actions">
                    <form method="post" action="/api/admin/move-personalization-font.php" class="admin-inline-form">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                      <input type="hidden" name="id" value="<?= $fontId ?>">
                      <input type="hidden" name="direction" value="up">
                      <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?> aria-label="Omhoog">&uarr;</button>
                    </form>
                    <form method="post" action="/api/admin/move-personalization-font.php" class="admin-inline-form">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                      <input type="hidden" name="id" value="<?= $fontId ?>">
                      <input type="hidden" name="direction" value="down">
                      <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?> aria-label="Omlaag">&darr;</button>
                    </form>
                  </div>
                </td>
                <td>
                  <?php $confirm = 'Dit lettertype definitief verwijderen? Bestaande bestellingen blijven leesbaar, maar het bestand is daarna weg.'; ?>
                  <form method="post" action="/api/admin/delete-personalization-font.php" class="admin-inline-form"
                        onsubmit="return confirm('<?= $h($confirm) ?>');">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $fontId ?>">
                    <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="admin-text-muted" style="margin-top:var(--admin-sp-4);">
        Wil je een lettertype uit de shop halen? Zet het op <strong>niet actief</strong> — dan verdwijnt het meteen bij
        alle producten, terwijl bestaande bestellingen gewoon blijven werken. Verwijderen kan alleen als er nog nooit
        mee besteld is.
      </p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
