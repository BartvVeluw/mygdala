<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;

AdminAuth::requireLogin();

/**
 * The signed-in person's own preferences. No permission beyond being logged
 * in, because everything here is about them: a CMS user who may only edit one
 * block still gets to read the panel in their own language.
 *
 * V1 holds exactly one preference — the CMS interface language. The screen
 * exists rather than a dropdown in the sidebar because this is where the next
 * personal preference will go, and because the distinction it has to explain
 * (this is not the website's language) needs a sentence, not a tooltip.
 *
 * NOT a settings screen. Nothing here touches the website, and the copy says
 * so twice: once in the intro, once by pointing at Site settings for the
 * thing an editor might have come looking for.
 */

$user = AdminAuth::user();
$isBreakGlass = ($user['is_break_glass'] ?? false) === true;

$currentLocale = AdminLocale::current();
$saved = isset($_GET['saved']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$t = static fn (string $key, array $r = []): string => AdminTranslator::trans($key, $r);
?>
<!doctype html>
<html lang="<?= $h($currentLocale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($t('account.title')) ?> <?= admin_t('account.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= $h($t('account.title')) ?></h1>
  </div>

  <p class="admin-text-muted"><?= $h($t('account.intro')) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= $h($t('account.saved')) ?></p>
  <?php endif; ?>

  <?php if ($isBreakGlass): ?>
    <p class="admin-alert admin-alert--warning"><?= $h($t('account.break_glass')) ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= $h($t('account.interface_language')) ?></h2>
    <p class="admin-text-muted"><?= $h($t('account.interface_language_help')) ?></p>

    <?php if ($isBreakGlass): ?>
      <p class="admin-text-muted">
        <?= $h(LanguageRegistry::label($currentLocale, $currentLocale)) ?>
      </p>
    <?php else: ?>
      <form method="post" action="/api/admin/update-account-preferences.php" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

        <div class="admin-form-row">
          <label for="field-interface-language"><?= $h($t('account.interface_language')) ?>
            <select name="interface_language" id="field-interface-language">
              <?php foreach (AdminLocale::choices() as $code => $definition): ?>
                <option value="<?= $h($code) ?>"<?= $code === $currentLocale ? ' selected' : '' ?>>
                  <?= $h($definition->nativeLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <button type="submit" class="admin-btn"><?= $h($t('common.save')) ?></button>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= $h($t('language.website')) ?></h2>
    <p class="admin-text-muted"><?= $h($t('account.website_language_hint')) ?></p>
    <p>
      <?php
        $labels = [];
        foreach (ContentLanguages::definitions() as $definition) {
            $labels[] = $definition->labelIn($currentLocale);
        }
      ?>
      <strong><?= $h(implode(' + ', $labels)) ?></strong>
    </p>
    <?php if (AdminAuth::can('settings.manage')): ?>
      <p><a href="/admin/settings.php#tab-talen" class="admin-btn-link"><?= $h($t('language.settings_title')) ?> &rarr;</a></p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
