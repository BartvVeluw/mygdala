<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// A Portfolio block: with the Portfolio off there is no project to show and
// no block to edit (App\Module\ModuleGuard).
\App\Module\ModuleGuard::requireAdmin('portfolio');
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_block_editor.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Repository\ProjectInfoRepository;
use App\Service\AdminAuth;
use App\Service\ContentOwners\ContentPages;
use App\Service\Csrf;
use App\Service\ProjectInfoContent;
use App\Service\SectionRegistry;

/**
 * Editor for one Projectinformatie block (?section=<page content_key>:<section_key>,
 * App\Service\Blocks\ProjectInfoBlock): where the project's picture sits and
 * whether its extra photos follow. The same "valid only when the page and its
 * content row really exist" gate as admin/spacer.php.
 *
 * NO WORDS HERE, on purpose: the picture, title, short text, intro,
 * description and categories are the project's own, edited on the project's
 * Project tab, and the block shows them as they are there. This screen says
 * so and links to it, rather than offering a second place to type them.
 */

AdminAuth::requireLogin();
\App\Service\ContentOwners\ContentBlockAccess::requireAny();

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ProjectInfoRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : \App\Service\ContentOwners\ContentBlockAccess::pageForKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_project_info_errors'] ?? [];
unset($_SESSION['admin_project_info_errors']);
$saved = isset($_GET['saved']);

$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$owner = ContentPages::ownerOf($page);
$projectUrl = $owner === null ? null : str_replace('&tab=inhoud', '', $owner['owner']->editUrl($owner['id']));
// Whether the project's page uses this block at all: only the free layout
// leaves the project's head to it.
$projectRow = $owner === null ? null : (new \App\Repository\PortfolioGalleryRepository())->findItemById($owner['id']);
$projectLayout = $projectRow === null ? null : \App\Service\PortfolioProjectLayout::forItem($projectRow);
$position = ProjectInfoContent::position((string) ($section['image_position'] ?? ''));
$showGallery = (bool) $section['show_gallery'];
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('project_info')) ?> — <?= $h($pageLabel) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <?php if (!block_editor_draft_notice('project_info', $csrfToken)): ?>
    <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\ContentOwners\ContentBlockAccess::listUrl($page), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_project_info.back', ['page' => $h($pageLabel)]) ?></a></p>
  <?php endif; ?>
  <h1><?= $h(SectionRegistry::label('project_info')) ?></h1>
  <p class="admin-text-muted">
    <?= admin_te('block_project_info.intro') ?>
    <?php if ($projectUrl !== null): ?>
      <a href="<?= $h($projectUrl) ?>"><?= admin_te('block_project_info.edit_project') ?></a>
    <?php endif; ?>
  </p>

  <?php if ($projectLayout !== null && $projectLayout !== \App\Service\PortfolioProjectLayout::FREE): ?>
    <p class="admin-alert admin-alert--info"><?= admin_te('block_project_info.not_free', ['layout' => \App\Service\PortfolioProjectLayout::label($projectLayout)]) ?></p>
  <?php endif; ?>

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
    <form method="post" action="/api/admin/update-project-info.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <div class="admin-field">
        <?= admin_field_label('project-info-position', admin_t('block_project_info.position'), admin_t('help.block_project_info.position')) ?>
        <select class="admin-select" id="project-info-position" name="image_position">
          <?php foreach (ProjectInfoContent::POSITIONS as $value): ?>
            <option value="<?= $h($value) ?>"<?= $position === $value ? ' selected' : '' ?>><?= admin_te('block_project_info.position_' . $value) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php /* Hidden companion: an unticked switch sends nothing. */ ?>
      <input type="hidden" name="show_gallery" value="0">
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="show_gallery" value="1"<?= $showGallery ? ' checked' : '' ?>>
          <?= admin_te('block_project_info.show_gallery') ?>
        </label>
        <?= admin_help(admin_t('block_project_info.show_gallery'), admin_t('help.block_project_info.show_gallery')) ?>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
