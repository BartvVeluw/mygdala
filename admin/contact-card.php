<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use App\Repository\PageRepository;
use App\Repository\ContactCardRepository;

/**
 * Editor for one Contactkaart block
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/cta-band.php, ...). A page may carry several of these; each is
 * edited here under its own key.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ContactCardRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Onbekende sectie.');
}

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);

$errors = $_SESSION['admin_contact_card_errors'] ?? [];
$old = $_SESSION['admin_contact_card_old'] ?? null;
unset($_SESSION['admin_contact_card_errors'], $_SESSION['admin_contact_card_old']);

$saved = isset($_GET['saved']);

$values = $old ?? [
    'title_nl' => (string) $section['title_nl'],
    'title_en' => (string) ($section['title_en'] ?? ''),
    'body_nl' => (string) ($section['body_nl'] ?? ''),
    'body_en' => (string) ($section['body_en'] ?? ''),
    'button_label_nl' => (string) $section['button_label_nl'],
    'button_label_en' => (string) ($section['button_label_en'] ?? ''),
    'button_url' => (string) ($section['button_url'] ?? ''),
    'is_active' => (bool) $section['is_active'],
];

$siteEmail = (string) SiteSettings::get('email');

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('contact_card')) ?> — <?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>">&larr; <?= $h((string) $page['title']) ?></a></p>
  <h1><?= $h(SectionRegistry::label('contact_card')) ?></h1>
  <p class="admin-text-muted">Een kaart met een kop, een korte tekst en één knop, op <strong><?= $h((string) $page['title']) ?></strong>.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
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
    <form method="post" action="/api/admin/update-contact-card.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Kop (NL)*
          <input type="text" name="title_nl" maxlength="255" required value="<?= $h((string) ($values['title_nl'] ?? '')) ?>">
        </label>
        <label>Kop (EN)
          <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($values['title_en'] ?? '')) ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Tekst (NL)
          <textarea name="body_nl" maxlength="600" rows="4"><?= $h((string) ($values['body_nl'] ?? '')) ?></textarea>
        </label>
        <label>Tekst (EN)
          <textarea name="body_en" maxlength="600" rows="4" placeholder="Leeg = zelfde als NL"><?= $h((string) ($values['body_en'] ?? '')) ?></textarea>
        </label>
      </div>

      <h2 style="margin-top:2rem;">Knop</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Label (NL)
          <input type="text" name="button_label_nl" maxlength="150" value="<?= $h((string) ($values['button_label_nl'] ?? '')) ?>">
        </label>
        <label>Label (EN)
          <input type="text" name="button_label_en" maxlength="150" value="<?= $h((string) ($values['button_label_en'] ?? '')) ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>
      <div class="admin-form-row">
        <label>URL
          <input type="text" name="button_url" maxlength="255" value="<?= $h((string) ($values['button_url'] ?? '')) ?>" placeholder="Leeg = mailto:<?= $h($siteEmail) ?>">
        </label>
      </div>
      <p class="admin-text-muted">Laat de URL leeg om te mailen naar het adres uit <a href="/admin/settings.php">Site-instellingen</a> (nu <code><?= $h($siteEmail) ?></code>) — wijzig je dat adres daar, dan volgt deze knop automatisch. Laat het label leeg om helemaal geen knop te tonen.</p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze sectie wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
