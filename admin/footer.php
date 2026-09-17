<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_link_destination.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\FooterRepository;
use App\Repository\FooterSocialLinkRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Branding;
use App\Service\Csrf;
use App\Service\FooterLocalization;
use App\Service\LocalizedSiteSettings;
use App\Service\RouteRegistry;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * Footer: everything an editor manages at the bottom of every page of the
 * website, on one screen since Footer phase B (HEADER-FOOTER.md). Four cards,
 * in the order they appear in the footer itself:
 *
 *   Bedrijfsblok           which of the company's details the footer shows,
 *                          and the footer description
 *   Kolommen & links       footer_columns and footer_links
 *   Social media           footer_social_links, one editable row each
 *   Slotregel & copyright  the copyright text and the closing line
 *
 * WHAT THIS SCREEN DOES NOT OWN. The site name, e-mail address, phone number,
 * KVK number and logo belong to Site-instellingen: the company block shows
 * each value next to its switch, read-only, with a link there for whoever may
 * change them. There is no second editor for any of them, and switching one
 * off never changes or removes the value. The colours are Vormgeving's, the
 * structure of the footer is Core's.
 *
 * ORDER WITHOUT A MOUSE. Columns, links and social profiles each have ↑/↓
 * (move-footer-column.php, move-footer-link.php, move-footer-social-link.php),
 * which work with a keyboard, on a phone and without JavaScript. Columns and
 * links can still be dragged with a mouse (admin/assets/admin.js); the drag
 * handle is aria-hidden, like on Header & navigatie.
 *
 * WHAT A ROW SAYS. A link names its destination in words and asks the same
 * question the public footer asks (admin/_link_destination.php); a column
 * without a working visible link, and a social profile whose address its
 * network does not own, say they are not on the website. Hidden things say
 * they are hidden. Nothing is ever dropped: hiding and a switched-off module
 * both keep what was stored.
 *
 * FORMS AND THE SAVE BAR. The two settings forms and every social row are
 * ordinary forms the save bar watches (admin/_save_bar.php); each posts to
 * its own endpoint, which writes only its own fields. The one-button forms
 * (move, hide, delete) are `.admin-inline-form`, and the two "add" forms opt
 * out with data-no-dirty-track: adding is not an edit to save later. A
 * refused save comes back with what was typed, marked data-save-bar-unsaved.
 * Deleting asks first in the CMS's own dialog (admin_confirm_attributes()).
 */

$repository = new FooterRepository();
$columns = $repository->findAllColumnsForAdmin();
$linksByColumn = [];
foreach ($columns as $column) {
    $linksByColumn[(int) $column['id']] = $repository->findLinksForColumn((int) $column['id']);
}
FooterLocalization::preload(
    array_map(static fn (array $column): int => (int) $column['id'], $columns),
    array_map(static fn (array $link): int => (int) $link['id'], array_merge([], ...array_values($linksByColumn)))
);

$socialLinks = (new FooterSocialLinkRepository())->findAll();

$pagesById = [];
foreach ((new PageRepository())->findAllForAdmin() as $page) {
    $pagesById[(int) $page['id']] = $page;
}
\App\Service\PageLocalization::preload(array_keys($pagesById));
$routes = RouteRegistry::all();

$settings = SiteSettings::all();

$footerError = $_SESSION['admin_footer_error'] ?? null;
$settingsError = $_SESSION['admin_footer_settings_error'] ?? null;
$socialError = $_SESSION['admin_footer_social_error'] ?? null;
unset($_SESSION['admin_footer_error'], $_SESSION['admin_footer_settings_error'], $_SESSION['admin_footer_social_error']);

$saved = isset($_GET['saved']) && $footerError === null && $settingsError === null && $socialError === null;
$deleted = (string) ($_GET['deleted'] ?? '');

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * A settings value as the form should show it: what a refused save sent,
 * otherwise what is stored.
 */
$setting = static function (string $section, string $key) use ($settings, $settingsError): string {
    if (is_array($settingsError) && ($settingsError['section'] ?? '') === $section && array_key_exists($key, $settingsError['old'] ?? [])) {
        return (string) $settingsError['old'][$key];
    }

    return (string) ($settings[$key] ?? '');
};
$settingErrors = static fn (string $section): array => is_array($settingsError) && ($settingsError['section'] ?? '') === $section
    ? (array) ($settingsError['errors'] ?? [])
    : [];

/**
 * The description and the closing line are website text in ONE language at a
 * time (admin/_localized_fields.php, App\Service\LocalizedSiteSettings): the
 * language chosen in the CMS shell, as stored, or what a refused save typed
 * in that same language.
 */
$editLanguage = admin_localized_language();
$localizedSetting = static function (string $section, string $key) use ($settingsError, $editLanguage): string {
    if (is_array($settingsError) && ($settingsError['section'] ?? '') === $section
        && ($settingsError['old']['language_code'] ?? null) === $editLanguage
        && array_key_exists($key, $settingsError['old'] ?? [])
    ) {
        return (string) $settingsError['old'][$key];
    }

    return LocalizedSiteSettings::raw($key, $editLanguage);
};

/**
 * The company details the switches decide about, read from Site-instellingen
 * and shown as they are. `value` is what the footer would print; empty means
 * it prints nothing for that line, whatever the switch says.
 */
$logoPath = Branding::alternateLogoPath();
$companyDetails = [
    'footer_show_logo' => [
        'label' => admin_t('footer.show_logo'),
        'value' => $logoPath !== '' ? admin_t('footer.value_logo_set') : admin_t('footer.value_logo_missing'),
        'missing' => false,
    ],
    'footer_show_company_name' => ['label' => admin_t('footer.show_company_name'), 'value' => (string) ($settings['site_name'] ?? ''), 'missing' => false],
    'footer_show_email' => ['label' => admin_t('footer.show_email'), 'value' => (string) ($settings['email'] ?? ''), 'missing' => false],
    'footer_show_phone' => ['label' => admin_t('footer.show_phone'), 'value' => (string) ($settings['company_phone'] ?? ''), 'missing' => false],
    'footer_show_kvk' => ['label' => admin_t('footer.show_kvk'), 'value' => (string) ($settings['kvk_number'] ?? ''), 'missing' => false],
];
foreach ($companyDetails as $key => $detail) {
    if (trim($detail['value']) === '') {
        $companyDetails[$key]['value'] = admin_t('footer.value_missing');
        $companyDetails[$key]['missing'] = true;
    }
}

/**
 * The two ↑/↓ forms of one row.
 */
function footer_move_buttons(string $endpoint, int $id, string $label, int $position, int $count, string $csrfToken): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    foreach (['up' => ['navigation.move_up_label', '&uarr;', $position === 0], 'down' => ['navigation.move_down_label', '&darr;', $position === $count - 1]] as $direction => [$key, $arrow, $disabled]) {
        ?>
        <form method="post" action="<?= $h($endpoint) ?>" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="direction" value="<?= $direction ?>">
          <button type="submit" class="admin-btn-ghost admin-row-move" aria-label="<?= admin_te($key, ['item' => $label]) ?>"<?= $disabled ? ' disabled' : '' ?>><span aria-hidden="true"><?= $arrow ?></span></button>
        </form>
        <?php
    }
}

/**
 * Verbergen/Tonen for a column or a link.
 */
function footer_toggle_button(string $endpoint, int $id, bool $isHidden, string $csrfToken): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <form method="post" action="<?= $h($endpoint) ?>" class="admin-inline-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="is_visible" value="<?= $isHidden ? '1' : '0' ?>">
      <button type="submit" class="admin-btn-secondary admin-section-row__button"><?= $isHidden ? admin_te('common.show') : admin_te('common.hide') ?></button>
    </form>
    <?php
}

/**
 * A delete that asks first in the CMS's own dialog.
 */
function footer_delete_button(string $endpoint, int $id, string $title, string $message, string $csrfToken): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <form method="post" action="<?= $h($endpoint) ?>" class="admin-inline-form admin-section-row__delete"<?= admin_confirm_attributes($title, $message, admin_t('common.delete')) ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="admin-btn-danger admin-section-row__button"><?= admin_te('common.delete') ?></button>
    </form>
    <?php
}

/**
 * How a social profile is named to a screen reader on its row's buttons:
 * the network and the address without its scheme, so two Instagram rows are
 * never announced the same.
 *
 * @param array<string, mixed> $row
 */
function footer_social_item_name(array $row): string
{
    $network = SocialProfiles::label((string) $row['network']) ?? admin_t('footer.unknown_network');
    $address = (string) preg_replace('#^https?://#i', '', trim((string) $row['url']));

    return admin_t('footer.social_item', ['network' => $network, 'address' => $address]);
}

/**
 * One editable social profile: network, address and visibility in a form of
 * its own, and ↑/↓ and delete beside it. $id 0 is the add form.
 *
 * @param array{network: string, url: string, is_visible: bool} $values
 * @param list<string> $errors
 */
function footer_social_fields(int $id, array $values, array $errors): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $prefix = 'footer-social-' . ($id === 0 ? 'new' : (string) $id);
    $errorId = $prefix . '-errors';
    ?>
    <?php if ($errors !== []): ?>
      <div class="admin-alert admin-alert--error admin-footer-social__errors" id="<?= $h($errorId) ?>" role="alert">
        <ul class="admin-error-list">
          <?php foreach ($errors as $error): ?>
            <li><?= $h((string) $error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div class="admin-footer-social__fields">
      <div class="admin-field">
        <?= admin_field_label($prefix . '-network', admin_t('footer.social_network')) ?>
        <select class="admin-select" id="<?= $h($prefix) ?>-network" name="network"<?= $errors !== [] && !SocialProfiles::isKnownNetwork($values['network']) ? ' aria-invalid="true" aria-describedby="' . $h($errorId) . '"' : '' ?>>
          <?php if ($id === 0 || !SocialProfiles::isKnownNetwork($values['network'])): ?>
            <option value=""><?= admin_te('footer.social_choose') ?></option>
          <?php endif; ?>
          <?php foreach (SocialProfiles::networks() as $network => $definition): ?>
            <option value="<?= $h($network) ?>"<?= $values['network'] === $network ? ' selected' : '' ?>><?= $h($definition['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field admin-footer-social__url">
        <?= admin_field_label($prefix . '-url', admin_t('footer.social_url'), admin_t('help.footer.social_url')) ?>
        <input type="text" id="<?= $h($prefix) ?>-url" name="url" inputmode="url" autocomplete="url" maxlength="<?= SocialProfiles::MAX_URL_LENGTH ?>" value="<?= $h($values['url']) ?>" placeholder="https://www.instagram.com/jouwnaam"<?= $errors !== [] && SocialProfiles::isKnownNetwork($values['network']) ? ' aria-invalid="true" aria-describedby="' . $h($errorId) . '"' : '' ?>>
      </div>
      <?php if ($id !== 0): ?>
        <div class="admin-field admin-field--inline admin-footer-social__visible">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="is_visible" value="1"<?= $values['is_visible'] ? ' checked' : '' ?>>
            <?= admin_te('footer.social_visible') ?>
          </label>
        </div>
      <?php endif; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('footer.footer_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('footer.footer') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('footer.screen_desc') ?></p>
    </div>
  </header>

  <?= admin_info_panel(admin_t('help.footer.overview')) ?>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success" role="status"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>
  <?php if (in_array($deleted, ['column', 'link', 'social'], true)): ?>
    <p class="admin-alert admin-alert--success" role="status"><?= admin_te('footer.deleted_' . $deleted) ?></p>
  <?php endif; ?>
  <?php if ($footerError !== null): ?>
    <p class="admin-alert admin-alert--error" role="alert"><?= $h((string) $footerError) ?></p>
  <?php endif; ?>

  <?php /* ------------------------------------------------ Bedrijfsblok */ ?>
  <section class="admin-card" id="footer-brand" aria-labelledby="footer-brand-heading">
    <form method="post" action="/api/admin/update-footer-settings.php" class="admin-product-form admin-footer-settings-form" data-save-name="<?= admin_te('footer.brand_heading') ?>"<?= $settingErrors('brand') !== [] ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="brand">

      <h2 id="footer-brand-heading"><?= admin_te('footer.brand_heading') ?></h2>
      <p class="admin-text-muted"><?= admin_te('footer.brand_intro') ?></p>

      <?php if ($settingErrors('brand') !== []): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          <ul class="admin-error-list">
            <?php foreach ($settingErrors('brand') as $error): ?>
              <li><?= $h((string) $error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <fieldset class="admin-footer-details">
        <legend class="admin-footer-details__legend">
          <?= admin_te('footer.details_legend') ?>
          <?= admin_help(admin_t('footer.details_legend'), admin_t('help.footer.details')) ?>
        </legend>
        <ul class="admin-footer-details__list">
          <?php foreach ($companyDetails as $key => $detail): ?>
            <li class="admin-footer-details__item">
              <label class="admin-checkbox-label">
                <input type="checkbox" class="admin-switch" role="switch" name="<?= $h($key) ?>" value="1" aria-describedby="<?= $h($key) ?>-value"<?= $setting('brand', $key) === '1' ? ' checked' : '' ?>>
                <?= $h($detail['label']) ?>
              </label>
              <span class="admin-footer-details__value<?= $detail['missing'] ? ' admin-footer-details__value--missing' : '' ?>" id="<?= $h($key) ?>-value"><?= $h($detail['value']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="admin-text-muted admin-footer-details__owner">
          <?php if (AdminAuth::can('settings.manage')): ?>
            <a href="/admin/settings.php"><?= admin_te('footer.details_link') ?> &rarr;</a>
          <?php else: ?>
            <?= admin_te('footer.details_elsewhere') ?>
          <?php endif; ?>
        </p>
      </fieldset>

      <?php admin_localized_bar($editLanguage); ?>
      <?= admin_localized_input($editLanguage) ?>
      <div class="admin-field">
        <?= admin_field_label('footer-description', admin_t('footer.description_label'), admin_t('help.footer.description')) ?>
        <textarea id="footer-description" name="footer_description" maxlength="<?= LocalizedSiteSettings::KEYS[LocalizedSiteSettings::FOOTER_DESCRIPTION] ?>" rows="3"<?= admin_localized_placeholder_attr($editLanguage) ?>><?= $h($localizedSetting('brand', LocalizedSiteSettings::FOOTER_DESCRIPTION)) ?></textarea>
      </div>

      <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <?php /* -------------------------------------------- Kolommen & links */ ?>
  <section class="admin-card" id="footer-columns" aria-labelledby="footer-columns-heading">
    <h2 id="footer-columns-heading"><?= admin_te('footer.columns_heading') ?></h2>
    <p class="admin-text-muted"><?= admin_te('footer.columns_intro') ?></p>

    <?php if ($columns === []): ?>
      <p class="admin-text-muted"><?= admin_te('footer.columns_empty') ?></p>
    <?php endif; ?>

    <div class="admin-page-sections admin-footer-columns" data-footer-column-zone data-reorder-url="/api/admin/reorder-footer-columns.php" data-csrf-token="<?= $h($csrfToken) ?>">
      <?php foreach ($columns as $columnPosition => $column): ?>
        <?php
          $columnId = (int) $column['id'];
          $columnHidden = !(bool) $column['is_visible'];
          $columnTitle = FooterLocalization::columnName($columnId);
          $links = $linksByColumn[$columnId];
          $visibleWorkingLinks = array_filter(
              $links,
              static fn (array $link): bool => (bool) $link['is_visible'] && admin_link_is_reachable($link)
          );
          $columnOffSite = !$columnHidden && $visibleWorkingLinks === [];
        ?>
        <div class="admin-footer-column" data-footer-column-id="<?= $columnId ?>">
          <div class="admin-section-row admin-footer-column-row<?= $columnHidden ? ' is-hidden-section' : '' ?>" id="footer-column-<?= $columnId ?>">
            <span class="admin-drag-handle" draggable="true" aria-hidden="true">&#8801;</span>
            <div class="admin-section-row__body">
              <p class="admin-section-row__name">
                <?= $h($columnTitle) ?>
                <?php if ($columnHidden): ?>
                  <span class="admin-badge admin-badge--muted"><?= admin_te('common.hidden') ?></span>
                <?php elseif ($columnOffSite): ?>
                  <span class="admin-badge admin-badge--warning"><?= admin_te('navigation.badge_not_on_site') ?></span>
                <?php endif; ?>
              </p>
              <?php if ($columnOffSite): ?>
                <p class="admin-section-row__note"><?= admin_te('footer.column_not_on_site_note') ?></p>
              <?php endif; ?>
            </div>
            <div class="admin-section-row__actions">
              <a href="/admin/footer-column.php?id=<?= $columnId ?>" class="admin-section-row__edit" aria-label="<?= admin_te('navigation.edit_label', ['item' => $columnTitle]) ?>"><?= admin_te('common.edit') ?> &#8594;</a>
              <?php footer_move_buttons('/api/admin/move-footer-column.php', $columnId, $columnTitle, $columnPosition, count($columns), $csrfToken); ?>
              <a href="/admin/footer-link.php?column_id=<?= $columnId ?>" class="admin-btn-text" aria-label="<?= admin_te('footer.add_link_label', ['column' => $columnTitle]) ?>"><?= admin_te('footer.add_link') ?></a>
              <?php footer_toggle_button('/api/admin/toggle-footer-column.php', $columnId, $columnHidden, $csrfToken); ?>
              <?php footer_delete_button(
                  '/api/admin/delete-footer-column.php',
                  $columnId,
                  admin_t('footer.delete_column_title'),
                  admin_t('footer.delete_column_message', ['item' => $columnTitle]),
                  $csrfToken
              ); ?>
            </div>
          </div>

          <div class="admin-nav-children admin-footer-links" data-footer-link-zone data-column-id="<?= $columnId ?>" data-reorder-url="/api/admin/reorder-footer-links.php" data-csrf-token="<?= $h($csrfToken) ?>">
            <?php if ($links === []): ?>
              <p class="admin-text-muted admin-nav-children__empty"><?= admin_te('footer.links_empty') ?></p>
            <?php endif; ?>
            <?php foreach ($links as $linkPosition => $link): ?>
              <?php
                $linkId = (int) $link['id'];
                $linkHidden = !(bool) $link['is_visible'];
                $linkLabel = FooterLocalization::linkName($linkId);
                $linkReachable = admin_link_is_reachable($link);
              ?>
              <div class="admin-section-row admin-footer-link-row<?= $linkHidden ? ' is-hidden-section' : '' ?>" id="footer-link-<?= $linkId ?>" data-footer-link-id="<?= $linkId ?>">
                <span class="admin-drag-handle" draggable="true" aria-hidden="true">&#8801;</span>
                <div class="admin-section-row__body">
                  <p class="admin-section-row__name">
                    <?= $h($linkLabel) ?>
                    <?php if ($linkHidden): ?>
                      <span class="admin-badge admin-badge--muted"><?= admin_te('common.hidden') ?></span>
                    <?php elseif (!$linkReachable): ?>
                      <span class="admin-badge admin-badge--warning"><?= admin_te('navigation.badge_not_on_site') ?></span>
                    <?php endif; ?>
                  </p>
                  <p class="admin-section-row__note"><?= $h(admin_link_destination_summary($link, $pagesById, $routes)) ?></p>
                  <?php if (!$linkHidden && !$linkReachable): ?>
                    <p class="admin-section-row__note"><?= admin_te('navigation.not_on_site_note') ?></p>
                  <?php endif; ?>
                </div>
                <div class="admin-section-row__actions">
                  <a href="/admin/footer-link.php?id=<?= $linkId ?>" class="admin-section-row__edit" aria-label="<?= admin_te('navigation.edit_label', ['item' => $linkLabel]) ?>"><?= admin_te('common.edit') ?> &#8594;</a>
                  <?php footer_move_buttons('/api/admin/move-footer-link.php', $linkId, $linkLabel, $linkPosition, count($links), $csrfToken); ?>
                  <?php footer_toggle_button('/api/admin/toggle-footer-link.php', $linkId, $linkHidden, $csrfToken); ?>
                  <?php footer_delete_button(
                      '/api/admin/delete-footer-link.php',
                      $linkId,
                      admin_t('footer.delete_link_title'),
                      admin_t('footer.delete_link_message', ['item' => $linkLabel]),
                      $csrfToken
                  ); ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="/api/admin/create-footer-column.php" class="admin-inline-form admin-add-section-form admin-footer-add-column" data-no-dirty-track>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?php admin_localized_new_item_note(admin_localized_language()); ?>
      <div class="admin-field">
        <?= admin_field_label('footer-new-column', admin_t('footer.add_column_title')) ?>
        <input type="text" id="footer-new-column" name="title" maxlength="<?= FooterLocalization::TITLE_MAX_LENGTH ?>" required>
      </div>
      <button type="submit" class="admin-btn-secondary"><?= admin_te('footer.add_column') ?></button>
    </form>
  </section>

  <?php /* ---------------------------------------------------- Social media */ ?>
  <section class="admin-card" id="footer-social" aria-labelledby="footer-social-heading">
    <h2 id="footer-social-heading"><?= admin_te('footer.social_heading') ?></h2>
    <p class="admin-text-muted"><?= admin_te('footer.social_intro') ?></p>

    <?php if ($socialLinks === []): ?>
      <p class="admin-text-muted"><?= admin_te('footer.social_empty') ?></p>
    <?php else: ?>
      <div class="admin-page-sections admin-footer-social-list">
        <?php foreach ($socialLinks as $socialPosition => $socialLink): ?>
          <?php
            $socialId = (int) $socialLink['id'];
            $rowError = is_array($socialError) && (int) ($socialError['id'] ?? -1) === $socialId ? $socialError : null;
            $values = $rowError !== null
                ? ['network' => (string) $rowError['old']['network'], 'url' => (string) $rowError['old']['url'], 'is_visible' => (bool) $rowError['old']['is_visible']]
                : ['network' => (string) $socialLink['network'], 'url' => (string) $socialLink['url'], 'is_visible' => (bool) $socialLink['is_visible']];
            $socialHidden = !(bool) $socialLink['is_visible'];
            $socialValid = SocialProfiles::isValidProfileUrl((string) $socialLink['network'], (string) $socialLink['url']);
            $socialName = footer_social_item_name($socialLink);
            $networkLabel = SocialProfiles::label((string) $socialLink['network']) ?? admin_t('footer.unknown_network');
          ?>
          <div class="admin-section-row admin-footer-social-row<?= $socialHidden ? ' is-hidden-section' : '' ?>" id="footer-social-<?= $socialId ?>">
            <div class="admin-section-row__body">
              <p class="admin-section-row__name">
                <?= $h($networkLabel) ?>
                <?php if ($socialHidden): ?>
                  <span class="admin-badge admin-badge--muted"><?= admin_te('common.hidden') ?></span>
                <?php elseif (!$socialValid): ?>
                  <span class="admin-badge admin-badge--warning"><?= admin_te('navigation.badge_not_on_site') ?></span>
                <?php endif; ?>
              </p>
              <?php if (!$socialHidden && !$socialValid): ?>
                <p class="admin-section-row__note"><?= admin_te('footer.social_not_on_site_note', ['network' => $networkLabel]) ?></p>
              <?php endif; ?>
              <form method="post" action="/api/admin/update-footer-social-link.php" class="admin-footer-social__form" data-save-name="<?= $h($socialName) ?>"<?= $rowError !== null ? ' data-save-bar-unsaved' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $socialId ?>">
                <?php footer_social_fields($socialId, $values, $rowError !== null ? (array) $rowError['errors'] : []); ?>
                <button type="submit" class="admin-btn-secondary admin-section-row__button"><?= admin_te('common.save') ?></button>
              </form>
            </div>
            <div class="admin-section-row__actions">
              <?php footer_move_buttons('/api/admin/move-footer-social-link.php', $socialId, $socialName, $socialPosition, count($socialLinks), $csrfToken); ?>
              <?php footer_delete_button(
                  '/api/admin/delete-footer-social-link.php',
                  $socialId,
                  admin_t('footer.delete_social_title'),
                  admin_t('footer.delete_social_message', ['item' => $socialName]),
                  $csrfToken
              ); ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
      $newError = is_array($socialError) && (int) ($socialError['id'] ?? -1) === 0 ? $socialError : null;
      $newValues = $newError !== null
          ? ['network' => (string) $newError['old']['network'], 'url' => (string) $newError['old']['url'], 'is_visible' => true]
          : ['network' => '', 'url' => '', 'is_visible' => true];
    ?>
    <form method="post" action="/api/admin/create-footer-social-link.php" class="admin-footer-social__form admin-footer-social__add" id="footer-social-new" data-no-dirty-track>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <h3><?= admin_te('footer.social_add_heading') ?></h3>
      <?php footer_social_fields(0, $newValues, $newError !== null ? (array) $newError['errors'] : []); ?>
      <button type="submit" class="admin-btn-secondary"><?= admin_te('footer.social_add') ?></button>
    </form>
  </section>

  <?php /* ------------------------------------------ Slotregel & copyright */ ?>
  <section class="admin-card" id="footer-bottom" aria-labelledby="footer-bottom-heading">
    <form method="post" action="/api/admin/update-footer-settings.php" class="admin-product-form admin-footer-settings-form" data-save-name="<?= admin_te('footer.bottom_heading') ?>"<?= $settingErrors('bottom') !== [] ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="bottom">

      <h2 id="footer-bottom-heading"><?= admin_te('footer.bottom_heading') ?></h2>
      <p class="admin-text-muted"><?= admin_te('footer.bottom_intro') ?></p>

      <?php if ($settingErrors('bottom') !== []): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          <ul class="admin-error-list">
            <?php foreach ($settingErrors('bottom') as $error): ?>
              <li><?= $h((string) $error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="admin-field">
        <?= admin_field_label('footer-copyright', admin_t('footer.copyright_label'), admin_t('help.footer.copyright')) ?>
        <input type="text" id="footer-copyright" name="footer_copyright_template" maxlength="300" value="<?= $h($setting('bottom', 'footer_copyright_template')) ?>">
      </div>

      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="footer_slogan_enabled" value="1"<?= $setting('bottom', 'footer_slogan_enabled') === '1' ? ' checked' : '' ?>>
          <?= admin_te('footer.slogan_enabled') ?>
        </label>
        <?= admin_help(admin_t('footer.slogan_enabled'), admin_t('help.footer.slogan')) ?>
      </div>

      <?php admin_localized_bar($editLanguage); ?>
      <?= admin_localized_input($editLanguage) ?>
      <div class="admin-field">
        <?= admin_field_label('footer-slogan', admin_t('footer.slogan_label')) ?>
        <input type="text" id="footer-slogan" name="footer_slogan" maxlength="<?= LocalizedSiteSettings::KEYS[LocalizedSiteSettings::FOOTER_SLOGAN] ?>" value="<?= $h($localizedSetting('bottom', LocalizedSiteSettings::FOOTER_SLOGAN)) ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
      </div>

      <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
<?php save_bar_script(); ?>
</body>
</html>
