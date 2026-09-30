<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_button_style_field.php';
require_once __DIR__ . '/_save_bar.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Theme\ButtonIcons;
use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ButtonStyles;

/**
 * One button style of the website: new (no id) or an existing one (?id=).
 * The design on the left, a live preview on the right. See THEMING.md,
 * "Knopstijlen".
 *
 * WHAT SAVING DOES depends on who uses the style, and the screen says so up
 * front: a default changes every button without a choice of its own, a style
 * chosen by content buttons changes those, and an unused or new style
 * changes nothing a visitor sees.
 *
 * THE PREVIEW is admin/button-style-preview.php in a frame, drawn with the
 * real core.css and the website's tokens and fonts. admin/assets/
 * button-style-admin.js sets the style's --btn-* properties on its sample
 * buttons for every change — composed from ButtonStyleCss::recipe(), printed
 * below as data, so the preview and the website use one set of values. No
 * reload and no request per change. Unsaved changes stay in the form: the
 * save bar says so and warns before leaving.
 *
 * Every choice is a closed list; a colour is a theme colour word or a fixed
 * #RRGGBB (admin/_button_style_field.php). Behind settings.manage, like the
 * rest of Vormgeving.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$style = null;

if ($idParam !== null) {
    $style = $idParam === false ? null : ButtonStyles::find($idParam);

    if ($style === null) {
        http_response_code(404);
        exit(admin_t('buttons.not_found'));
    }
}

$isNew = $style === null;
$styleId = $isNew ? 0 : (int) $style['id'];
$usage = $isNew ? ['roles' => [], 'buttons' => 0] : ButtonStyles::usage($styleId);

$errors = $_SESSION['admin_button_style_errors'] ?? [];
$old = $_SESSION['admin_button_style_old'] ?? null;
unset($_SESSION['admin_button_style_errors'], $_SESSION['admin_button_style_old']);

// A refused save's handback belongs to the style it was typed for.
if (!is_array($old) || (int) ($old['id'] ?? 0) !== $styleId) {
    $old = null;
}

$stored = $isNew ? ButtonStyles::startingValues() : $style;

/** A field's value: the handback, else what is stored. Colours as posted. */
$value = static function (string $field) use ($old, $stored): mixed {
    if ($old !== null && array_key_exists($field, $old)) {
        return $old[$field];
    }

    return $stored[$field] ?? null;
};

/** A colour field's value for the control: the typed fixed colour on a handback. */
$colorValue = static function (string $field) use ($old, $value): ?string {
    if ($old !== null && ($old[$field] ?? null) === 'custom') {
        return (string) ($old[$field . '_custom'] ?? '');
    }

    $current = $value($field);

    return is_string($current) && $current !== '' ? $current : null;
};

$flag = static function (string $field) use ($old, $stored): bool {
    if ($old !== null) {
        return !empty($old[$field]);
    }

    return (bool) ($stored[$field] ?? false);
};

$notice = is_string($_GET['done'] ?? null) && in_array($_GET['done'], ['created', 'saved', 'duplicated'], true)
    ? $_GET['done'] : '';

$state = match (true) {
    $isNew => 'new',
    $usage['roles'] !== [] => 'default',
    $usage['buttons'] > 0 => 'used',
    default => 'unused',
};

$csrfToken = Csrf::token();
$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

/**
 * A select of one closed list, labelled from the catalog
 * (buttons.<field>.<word>).
 *
 * @param list<string> $words
 */
$choice = static function (string $field, array $words, string $help = '') use ($value, $errors, $h): string {
    $id = 'button-' . str_replace('_', '-', $field);
    $current = (string) $value($field);
    $html = '<div class="admin-field" data-button-field="' . $h($field) . '">'
        . admin_field_label($id, admin_t('buttons.' . $field), $help)
        . '<select id="' . $h($id) . '" name="' . $h($field) . '" class="admin-select"' . (isset($errors[$field]) ? ' aria-invalid="true"' : '') . '>';
    foreach ($words as $word) {
        $html .= '<option value="' . $h($word) . '"' . ($current === $word ? ' selected' : '') . '>' . admin_te('buttons.' . $field . '.' . $word) . '</option>';
    }

    return $html . '</select></div>';
};

$checkbox = static function (string $field) use ($flag, $h): string {
    return '<div class="admin-field admin-field--inline" data-button-field="' . $h($field) . '">'
        . '<label class="admin-checkbox-label"><input type="checkbox" class="admin-checkbox" name="' . $h($field) . '" value="1"' . ($flag($field) ? ' checked' : '') . '> '
        . admin_te('buttons.' . $field) . '</label></div>';
};

$choices = ButtonStyles::choices();

// The data the live preview needs, from the class that writes the website's CSS.
$recipe = ButtonStyleCss::recipe();

$pageTitle = $isNew ? admin_t('buttons.new') : admin_t('buttons.edit_title', ['name' => (string) $style['name']]);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/theme.php?tab=knoppen#knoppen"><?= admin_te('buttons.back') ?></a></p>
  <h1>
    <?= $h($pageTitle) ?>
    <?php foreach ($usage['roles'] as $role): ?>
      <span class="admin-badge admin-badge--published" data-button-status="<?= $h($role) ?>"><?= admin_te('buttons.status_' . $role) ?></span>
    <?php endforeach; ?>
  </h1>

  <p class="admin-alert <?= $state === 'default' || $state === 'used' ? 'admin-alert--warning' : 'admin-alert--note' ?>" data-button-state="<?= $h($state) ?>">
    <?= admin_te('buttons.state_' . $state, ['count' => $usage['buttons']]) ?>
  </p>

  <?php if ($notice !== '' && $errors === []): ?>
    <p class="admin-alert admin-alert--success" data-button-notice="<?= $h($notice) ?>"><?= admin_te('buttons.done_' . $notice) ?></p>
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

  <form method="post" action="/api/admin/save-button-style.php" class="admin-product-form admin-palette-form admin-button-style-form" autocomplete="off"
        data-button-style-form data-button-recipe="<?= $h(json_encode($recipe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>"
        <?= $errors !== [] ? 'data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= $styleId ?>">
    <?php endif; ?>

    <div class="admin-palette-editor">
      <div class="admin-palette-editor__settings">
        <section class="admin-card">
          <div class="admin-field">
            <?= admin_field_label('button-name', admin_t('buttons.name'), admin_t('help.buttons.name'), true) ?>
            <input type="text" id="button-name" name="name" maxlength="<?= ButtonStyles::MAX_NAME_LENGTH ?>" required value="<?= $h((string) $value('name')) ?>"<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
          </div>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('buttons.section_shape') ?></h2>
          <?= $choice('appearance', $choices['appearance'], admin_t('help.buttons.appearance')) ?>
          <?= $choice('shape', $choices['shape']) ?>
          <?= $choice('size', $choices['size']) ?>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('buttons.section_colors') ?></h2>
          <?= admin_info_panel(admin_t('help.buttons.colors')) ?>
          <div data-button-when="filled">
            <?= admin_button_color_field('fill_color', admin_t('buttons.fill_color'), $colorValue('fill_color'), false, $errors['fill_color'] ?? null) ?>
            <?= $checkbox('fill_gradient') ?>
          </div>
          <?= admin_button_color_field('text_color', admin_t('buttons.text_color'), $colorValue('text_color'), false, $errors['text_color'] ?? null) ?>
          <div data-button-when="filled outline ghost">
            <?= admin_button_color_field('border_color', admin_t('buttons.border_color'), $colorValue('border_color'), false, $errors['border_color'] ?? null) ?>
          </div>
        </section>

        <section class="admin-card" data-button-when="filled outline ghost">
          <h2><?= admin_te('buttons.section_border') ?></h2>
          <?= $choice('border_width', $choices['border_width']) ?>
          <?= $choice('shadow', $choices['shadow']) ?>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('buttons.section_text') ?></h2>
          <?= admin_info_panel(admin_t('help.buttons.text')) ?>
          <?= $choice('font_weight', $choices['font_weight']) ?>
          <?= $choice('font_role', $choices['font_role']) ?>
          <?= $checkbox('uppercase') ?>
          <div data-button-when="text"><?= $checkbox('underline') ?></div>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('buttons.section_icon') ?></h2>
          <?= admin_info_panel(admin_t('help.buttons.icon')) ?>
          <?= $choice('icon', $choices['icon']) ?>
          <div class="admin-button-icons" aria-hidden="true">
            <?php foreach (ButtonIcons::keys() as $iconKey): ?>
              <?php if ($iconKey !== 'none'): ?>
                <span class="admin-button-icons__item" title="<?= admin_te('buttons.icon.' . $iconKey) ?>"><?= ButtonIcons::inlineSvg($iconKey) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?= $choice('icon_position', $choices['icon_position']) ?>
          <?= $choice('icon_gap', $choices['icon_gap']) ?>
          <?= $checkbox('icon_motion') ?>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('buttons.section_hover') ?></h2>
          <?= admin_info_panel(admin_t('help.buttons.hover')) ?>
          <?= $choice('hover_effect', $choices['hover_effect']) ?>
          <?= admin_button_color_field('hover_fill_color', admin_t('buttons.hover_fill_color'), $colorValue('hover_fill_color'), true, $errors['hover_fill_color'] ?? null) ?>
          <?= admin_button_color_field('hover_text_color', admin_t('buttons.hover_text_color'), $colorValue('hover_text_color'), true, $errors['hover_text_color'] ?? null) ?>
          <div data-button-when="filled outline ghost">
            <?= admin_button_color_field('hover_border_color', admin_t('buttons.hover_border_color'), $colorValue('hover_border_color'), true, $errors['hover_border_color'] ?? null) ?>
          </div>
        </section>
      </div>

      <section class="admin-card admin-palette-editor__preview" id="knop-voorbeeld">
        <h2><?= admin_te('buttons.preview_title') ?></h2>
        <p class="admin-text-muted"><?= admin_te('buttons.preview_intro') ?></p>
        <?php /* allow-same-origin and nothing else: the editor's script sets
                 the style's properties inside this document, which itself
                 runs no script (its Content-Security-Policy refuses scripts
                 and forms). */ ?>
        <iframe class="admin-palette-preview admin-button-preview" title="<?= admin_te('buttons.preview_frame') ?>"
                src="/admin/button-style-preview.php<?= $isNew ? '' : '?id=' . $styleId ?>"
                data-button-preview sandbox="allow-same-origin" referrerpolicy="same-origin"></iframe>
      </section>
    </div>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te($isNew ? 'buttons.create' : 'common.save') ?></button>
      <a href="/admin/theme.php?tab=knoppen#knoppen" class="admin-btn-secondary" data-save-bar-discard data-button-cancel><?= admin_te('buttons.cancel') ?></a>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/theme-admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/button-style-admin.js') ?>" defer></script>
</body>
</html>
