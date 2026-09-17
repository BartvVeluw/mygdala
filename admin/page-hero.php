<?php

declare(strict_types=1);

/**
 * The Paginakop editor for one page: its texts, the image behind them and
 * three presentation choices, in one form to api/admin/update-page-hero.php.
 *
 * Three groups — Inhoud, Afbeelding, Vormgeving — inside that one form, the
 * way admin/project-cards.php groups its settings, so the save bar watches one
 * form and one save stores everything. The image comes from the shared media
 * picker (MEDIA.md), and each choice is a select whose options are
 * PageHeroContent's closed list, so the form cannot send a value the endpoint
 * refuses. Nothing is shown conditionally: every choice applies with and
 * without an image.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the eyebrow, title and lead show the language chosen in the CMS shell, as
 * stored and without the default language's words in an empty translation,
 * and the title is required only in the default language; the save writes
 * that language only. The image, the choices and "Tonen op de pagina" are the
 * same in every language and stay on screen in each. Input a refused save
 * hands back comes back in the language it was typed in, and the form then
 * starts out unsaved in the save bar.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\PageHeroContent;
use App\Repository\PageHeroRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$slug = (string) ($_GET['slug'] ?? '');

// Known keys (PageHeroContent::PAGES) are the originally hardcoded pages;
// any other slug is only valid when the page builder has actually attached
// a Page Hero to it (App\Service\SectionRegistry::create()) — never trust an
// arbitrary slug from the query string beyond that.
$isDynamicallyAttached = (new \App\Repository\PageRepository())->findByContentKey($slug) !== null
    && (new PageHeroRepository())->findBySlug($slug) !== null;

if (!array_key_exists($slug, PageHeroContent::PAGES) && !$isDynamicallyAttached) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_pagina'));
}

$pageLabelRow = (new \App\Repository\PageRepository())->findByContentKey($slug);
$pageLabel = PageHeroContent::PAGES[$slug]
    ?? ($pageLabelRow !== null ? \App\Service\PageLocalization::name((int) $pageLabelRow['id']) : $slug);

$errors = $_SESSION['admin_page_hero_errors'] ?? [];
$old = $_SESSION['admin_page_hero_old'] ?? null;
unset($_SESSION['admin_page_hero_errors'], $_SESSION['admin_page_hero_old']);

$saved = isset($_GET['saved']);
$editLanguage = admin_localized_language();

try {
    $row = (new PageHeroRepository())->findBySlug($slug);
} catch (\Throwable $e) {
    error_log('[admin/page-hero.php] ' . $e->getMessage());
    $row = null;
}

// What is the same in every language: handed back, else stored, else what a
// header that does not exist yet starts out with.
if ($old !== null) {
    $values = $old;
} elseif ($row !== null) {
    $values = [
        'media_id' => isset($row['media_id']) ? (int) $row['media_id'] : null,
        'content_position' => (string) ($row['content_position'] ?? PageHeroContent::POSITION_LEFT),
        'title_size' => (string) ($row['title_size'] ?? PageHeroContent::SIZE_NORMAL),
        'text_size' => (string) ($row['text_size'] ?? PageHeroContent::SIZE_NORMAL),
        'is_active' => (bool) $row['is_active'],
    ];
} else {
    $values = PageHeroContent::startingValues() + ['is_active' => true];
}

$heroId = (int) ($row['id'] ?? 0);
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/**
 * The words of one field on screen: typed and handed back in this language,
 * else stored in it, else (a header without a row yet, in the default
 * language only) its starting words.
 */
$word = static function (string $field) use ($old, $oldInThisLanguage, $heroId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    if ($heroId > 0) {
        return BlockLocalization::raw('page_heroes', $heroId, $field, $editLanguage);
    }

    return $editLanguage === BlockLocalization::defaultLanguage() ? (PageHeroContent::startingWords()[$field] ?? '') : '';
};

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$placeholder = admin_localized_placeholder_attr($editLanguage);
$required = admin_localized_required($editLanguage);

// The options of the three choices, in the order the selects offer them. The
// values are PageHeroContent's constants, so the lists stay the ones the
// endpoint checks against.
$positionLabels = [
    PageHeroContent::POSITION_LEFT => admin_t('block_pagehero.position_left'),
    PageHeroContent::POSITION_CENTER => admin_t('block_pagehero.position_center'),
    PageHeroContent::POSITION_RIGHT => admin_t('block_pagehero.position_right'),
];

$sizeLabels = [
    PageHeroContent::SIZE_SMALL => admin_t('block_pagehero.size_small'),
    PageHeroContent::SIZE_NORMAL => admin_t('block_pagehero.size_normal'),
    PageHeroContent::SIZE_LARGE => admin_t('block_pagehero.size_large'),
];

/**
 * The <option>s of one choice, with the current value selected.
 *
 * @param array<string, string> $labels value => label
 */
function pageHeroOptions(array $labels, string $current): string
{
    $html = '';

    foreach ($labels as $value => $label) {
        $html .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"'
            . ((string) $value === $current ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }

    return $html;
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_pagehero.page_hero_admin', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($slug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_pagehero.text', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= admin_t('block_pagehero.page_hero', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_pagehero.bovenste_sectie_breadcrumb_eyebrow', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-page-hero.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
      <?= admin_localized_input($editLanguage) ?>

      <h2><?= admin_te('block_pagehero.group_content') ?></h2>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-field">
        <?= admin_field_label('page-hero-eyebrow', admin_t('block_pagehero.eyebrow'), admin_t('help.page_hero.eyebrow')) ?>
        <input type="text" id="page-hero-eyebrow" name="eyebrow" maxlength="150" value="<?= $h($word('eyebrow')) ?>"<?= $placeholder ?>>
      </div>

      <div class="admin-field">
        <?= admin_field_label('page-hero-title', admin_t('block_pagehero.titel_h1'), admin_t('help.page_hero.title'), $required !== '') ?>
        <input type="text" id="page-hero-title" name="title" maxlength="255"<?= $required ?> value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
      </div>

      <div class="admin-field">
        <?= admin_field_label('page-hero-lead', admin_t('block_pagehero.introtekst_lead'), admin_t('help.page_hero.lead')) ?>
        <textarea id="page-hero-lead" name="lead" maxlength="500" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
      </div>

      <?php /* There is no "Naam in het kruimelpad" here any more. The
               breadcrumb is the page's own navigation, its label is the
               page's own title, and whether it shows is a switch on the page
               itself (Pagina bewerken → Pagina). See HEADER-FOOTER.md. */ ?>

      <h2 style="margin-top:2rem;"><?= admin_te('block_pagehero.group_image') ?></h2>

      <?php media_picker_field(
          'media_id',
          MediaService::find(isset($values['media_id']) ? (int) $values['media_id'] : null),
          admin_t('block_pagehero.image'),
          admin_t('block_pagehero.image_help')
      ); ?>

      <h2 style="margin-top:2rem;"><?= admin_te('block_pagehero.group_layout') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('page-hero-content-position', admin_t('block_pagehero.content_position'), admin_t('help.page_hero.content_position')) ?>
        <select class="admin-select" id="page-hero-content-position" name="content_position">
          <?= pageHeroOptions($positionLabels, (string) ($values['content_position'] ?? PageHeroContent::POSITION_LEFT)) ?>
        </select>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('page-hero-title-size', admin_t('block_pagehero.title_size'), admin_t('help.page_hero.title_size')) ?>
          <select class="admin-select" id="page-hero-title-size" name="title_size">
            <?= pageHeroOptions($sizeLabels, (string) ($values['title_size'] ?? PageHeroContent::SIZE_NORMAL)) ?>
          </select>
        </div>
        <div class="admin-field">
          <?= admin_field_label('page-hero-text-size', admin_t('block_pagehero.text_size'), admin_t('help.page_hero.text_size')) ?>
          <select class="admin-select" id="page-hero-text-size" name="text_size">
            <?= pageHeroOptions($sizeLabels, (string) ($values['text_size'] ?? PageHeroContent::SIZE_NORMAL)) ?>
          </select>
        </div>
      </div>

      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1"<?= ($values['is_active'] ?? true) ? ' checked' : '' ?>>
          <?= admin_te('block_pagehero.show_on_page') ?>
        </label>
        <?= admin_help(admin_t('block_pagehero.show_on_page'), admin_t('help.page_hero.is_active')) ?>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php media_picker_modal(); ?>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
</body>
</html>
