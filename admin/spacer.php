<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Repository\PageRepository;
use App\Repository\SpacerRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Service\SpacerContent;

/**
 * Editor for one Witruimte block (?section=<page content_key>:<section_key>):
 * its height, and nothing else. The same "valid only when the page and its
 * content row really exist" gate as admin/cta-band.php.
 *
 * No website language on this screen: a spacer has no words, so there is no
 * language bar and the height is the same in every language. Whether the
 * block shows is the page builder's eye, like for every block; this screen
 * has no second switch for it.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new SpacerRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_spacer_errors'] ?? [];
unset($_SESSION['admin_spacer_errors']);
$saved = isset($_GET['saved']);

$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$size = SpacerContent::size((string) ($section['size'] ?? ''));
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('spacer')) ?> — <?= $h($pageLabel) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_spacer.terug', ['v1' => $h($pageLabel)]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('spacer')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_spacer.uitleg', ['v1' => $h($pageLabel)]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error" role="alert">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-spacer.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <div class="admin-field">
        <?= admin_field_label('spacer-size', admin_t('block_spacer.hoogte'), admin_t('help.block_spacer.hoogte')) ?>
        <select class="admin-select" id="spacer-size" name="size">
          <?php foreach (array_keys(SpacerContent::SIZES) as $value): ?>
            <option value="<?= $h($value) ?>"<?= $size === $value ? ' selected' : '' ?>><?= admin_te('block_spacer.hoogte_' . $value) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
