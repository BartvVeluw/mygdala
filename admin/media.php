<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\MediaRepository;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use App\Service\AssetVersion;
use App\Service\Csrf;
use App\Service\Media\MediaFilename;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUploader;

/**
 * The Media Library: one grid of every reusable public image on this site,
 * and one detail view per item.
 *
 * TWO VIEWS, ONE FILE, on purpose — the same shape as admin/pages.php plus
 * admin/page.php would be, except that a media item's detail is a preview and
 * five facts rather than a screenful of fields. `?id=` switches between them.
 *
 * ADDING FILES. One form, sent one of two ways. Without JavaScript the chosen
 * files are posted together to api/admin/create-media.php, which redirects
 * back here. With admin/assets/media-upload.js they first wait in "Nieuwe
 * bestanden", where an editor sees what they chose and can still rename one
 * or take it out, and then go to api/admin/media-upload.php one file per
 * request. The file control is the shared admin_file_input() (ADMIN-UI.md);
 * the drop zone around it belongs to this screen and is never the only way
 * in. The limits the queue checks come from App\Service\Media\MediaUploader,
 * which checks them again.
 *
 * WHAT IT IS NOT. There are no folders, no tags, no bulk actions, no crop
 * tool and no raw filesystem operations: nothing here lets somebody type a
 * path, browse a directory or move a file. An editor uploads, searches, fixes
 * an alt text, sees where an image is used, and deletes one that nothing
 * uses. MEDIA.md lists what was deliberately left out and why.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('media.view');

$canManage = AdminAuth::can(AdminPermissions::MEDIA_MANAGE);

$service = new MediaService();
$csrfToken = Csrf::token();

$errors = $_SESSION['admin_media_errors'] ?? [];
unset($_SESSION['admin_media_errors']);

$notice = (string) ($_SESSION['admin_media_notice'] ?? '');
unset($_SESSION['admin_media_notice']);

$saved = isset($_GET['saved']);
$deleted = isset($_GET['deleted']);
$reused = isset($_GET['reused']);

$requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$item = ($requestedId === false || $requestedId === null) ? null : MediaService::find($requestedId);

$term = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** Bytes as something a person reads, not a number with nine digits. */
$formatBytes = static function (?int $bytes): string {
    if ($bytes === null || $bytes < 1) {
        return 'onbekend';
    }

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' kB';
    }

    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
};

if ($item === null) {
    $result = $service->browse($term, $page);
    /** @var list<MediaItem> $items */
    $items = $result['items'];
    $total = $result['total'];

    // ONE call for the whole page, not one per thumbnail: see
    // App\Service\Media\MediaUsageRegistry for why that is the contract.
    $usageCounts = $service->usageCountsFor($items);

    $perPage = MediaRepository::PAGE_SIZE;
    $lastPage = max(1, (int) ceil($total / $perPage));

    // What the upload queue checks before it sends anything: the limits
    // App\Service\Media\MediaUploader enforces, handed over rather than
    // written a second time, and the catalog's words for every answer.
    $imageTypeKey = static fn (int $type): string => (string) image_type_to_extension($type, false);
    $maxSize = MediaUploader::maxSizeLabel();

    $uploadAccept = implode(',', array_merge(
        array_map(static fn (string $extension): string => '.' . $extension, array_keys(MediaUploader::ALLOWED_EXTENSIONS)),
        array_values(MediaUploader::MIME_FOR_TYPE)
    ));

    $uploadConfig = [
        'uploadUrl' => '/api/admin/media-upload.php',
        'maxBytes' => MediaUploader::maxBytes(),
        'maxBaseLength' => MediaFilename::MAX_BASE_LENGTH,
        'extensions' => array_map($imageTypeKey, MediaUploader::ALLOWED_EXTENSIONS),
        'storedAs' => array_combine(
            array_map($imageTypeKey, array_keys(MediaUploader::ALLOWED_TYPES)),
            array_values(MediaUploader::ALLOWED_TYPES)
        ),
        'messages' => [
            'too_large' => admin_t('media.upload.too_large', ['max' => $maxSize]),
            'bad_type' => admin_t('media.upload.bad_type'),
            'svg' => admin_t('media.upload.svg'),
            'not_image' => admin_t('media.upload.not_image'),
            'failed' => admin_t('media.upload.failed'),
            'session' => admin_t('media.upload.session'),
            'name_empty' => admin_t('media.name.empty'),
            'name_characters' => admin_t('media.name.characters'),
            'name_forbidden' => admin_t('media.name.forbidden'),
            'name_dot' => admin_t('media.name.dot'),
            'name_long' => admin_t('media.name.long', ['max' => MediaFilename::MAX_BASE_LENGTH]),
            'added' => admin_t('media.queue.added'),
            'added_one' => admin_t('media.queue.added_one'),
            'queued' => admin_t('media.queue.count'),
            'queued_one' => admin_t('media.queue.count_one'),
            'remove_named' => admin_t('media.queue.remove_named'),
            'cleared' => admin_t('media.queue.cleared'),
            'progress' => admin_t('media.upload.progress'),
            'done' => admin_t('media.upload.done'),
            'done_one' => admin_t('media.upload.done_one'),
            'reused' => admin_t('media.upload.reused'),
            'reused_one' => admin_t('media.upload.reused_one'),
            'kept' => admin_t('media.upload.kept'),
            'kept_one' => admin_t('media.upload.kept_one'),
        ],
    ];
} else {
    $usages = $service->usagesOf($item->id);
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $item === null ? 'Media' : 'Media — ' . $h($item->displayName()) ?> <?= admin_te('media.admin') ?></title>
<link rel="stylesheet" href="<?= AssetVersion::url('/admin/assets/admin.css') ?>">
<link rel="stylesheet" href="<?= AssetVersion::url('/admin/assets/media-library.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">

<?php if ($errors !== []): ?>
  <div class="admin-alert admin-alert--error">
    <ul class="admin-error-list">
      <?php foreach ($errors as $error): ?>
        <li><?= $h((string) $error) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($item === null): ?>

  <h1><?= admin_te('media.media') ?></h1>
  <p class="admin-text-muted">
    <?= admin_te('media.alle_herbruikbare_afbeeldingen_website') ?>
  </p>

  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('media.afbeelding_verwijderd') ?></p>
  <?php endif; ?>

  <?php if ($notice !== ''): ?>
    <p class="admin-alert admin-alert--success"><?= $h($notice) ?></p>
  <?php endif; ?>

  <section class="admin-card admin-media-upload" aria-labelledby="media-upload-title">
    <h2 id="media-upload-title"><?= admin_te('media.upload.title') ?></h2>

    <form method="post" action="/api/admin/create-media.php" enctype="multipart/form-data" class="admin-media-upload__form" data-media-upload>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

      <div class="admin-media-dropzone" data-media-dropzone>
        <p class="admin-media-dropzone__title" data-media-drop-idle hidden><?= admin_te('media.upload.drop_idle') ?></p>
        <p class="admin-media-dropzone__title" data-media-drop-active hidden><?= admin_te('media.upload.drop_active') ?></p>

        <label class="admin-media-dropzone__control">
          <span class="admin-visually-hidden"><?= admin_te('media.upload.choose_label') ?></span>
          <?= admin_file_input([
              'id' => 'media-upload-files',
              'name' => 'files[]',
              'accept' => $uploadAccept,
              'multiple' => true,
              'required' => true,
              'aria-describedby' => 'media-upload-rules',
              'data-media-upload-input' => true,
          ]) ?>
        </label>

        <p class="admin-media-dropzone__rules" id="media-upload-rules"><?= admin_te('media.upload.rules', ['max' => $maxSize]) ?></p>
      </div>

      <div class="admin-media-queue" data-media-queue hidden>
        <div class="admin-media-queue__head">
          <h3 class="admin-media-queue__title" id="media-queue-title">
            <?= admin_te('media.queue.title') ?>
            <span class="admin-media-queue__count" data-media-queue-count></span>
          </h3>
          <p class="admin-text-muted"><?= admin_te('media.queue.hint') ?></p>
        </div>

        <ul class="admin-media-queue__list" role="list" aria-labelledby="media-queue-title" data-media-queue-list></ul>
      </div>

      <p class="admin-media-upload__status" role="status" aria-live="polite" data-media-upload-status></p>

      <div class="admin-media-upload__actions">
        <button type="submit" class="admin-btn-primary" data-media-upload-submit><?= admin_te('media.upload.submit') ?></button>
        <button type="button" class="admin-btn-ghost" data-media-queue-clear hidden><?= admin_te('media.queue.clear') ?></button>
      </div>
    </form>

    <?php /* One row of "Nieuwe bestanden". media-upload.js clones it per file and
             fills it with textContent and value only; the words are the
             catalog's, written here once. */ ?>
    <template data-media-queue-template>
      <li class="admin-media-queue__item">
        <span class="admin-media-queue__preview" aria-hidden="true" data-queue-preview></span>

        <div class="admin-media-queue__body">
          <div class="admin-media-queue__field">
            <label class="admin-media-queue__label" data-queue-label-for="name"><?= admin_te('media.queue.name_label') ?></label>
            <span class="admin-media-queue__name">
              <input type="text" autocomplete="off" spellcheck="false" data-queue-name>
              <span class="admin-media-queue__ext" data-queue-ext></span>
            </span>
          </div>

          <div class="admin-media-queue__field">
            <label class="admin-media-queue__label" data-queue-label-for="alt"><?= admin_te('media.queue.alt_label') ?></label>
            <input type="text" maxlength="255" data-queue-alt>
          </div>

          <p class="admin-media-queue__meta" data-queue-meta></p>
          <p class="admin-media-queue__error" data-queue-error hidden></p>
        </div>

        <button type="button" class="admin-btn-text admin-btn-text--danger admin-media-queue__remove" data-queue-remove><?= admin_te('media.queue.remove') ?></button>
      </li>
    </template>

    <script type="application/json" data-media-upload-config><?= json_encode($uploadConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  </section>

  <section class="admin-card" data-media-library>
    <form method="get" action="/admin/media.php" class="admin-inline-form">
      <label class="admin-media-search">
        <span class="admin-visually-hidden"><?= admin_te('common.search') ?></span>
        <input type="search" name="q" value="<?= $h($term) ?>" placeholder="Zoek op bestandsnaam of alt-tekst">
      </label>
      <button type="submit"><?= admin_te('common.search') ?></button>
      <?php if ($term !== ''): ?>
        <a class="admin-btn-text" href="/admin/media.php">Wis zoekopdracht</a>
      <?php endif; ?>
    </form>

    <p class="admin-text-muted">
      <?php if ($term === ''): ?>
        <?= $total === 1 ? admin_t('media.count_in_library_one') : admin_t('media.count_in_library', ['count' => (int) $total]) ?>
      <?php else: ?>
        <?= $total === 1 ? admin_t('media.results_for_one', ['v1' => $h($term)]) : admin_t('media.results_for', ['v1' => (int) $total, 'v2' => $h($term)]) ?>
      <?php endif; ?>
    </p>

    <?php if ($items === []): ?>
      <p class="admin-text-muted">
        <?= admin_te($term === '' ? 'media.empty_library' : 'media.nothing_found') ?>
      </p>
    <?php else: ?>
      <div class="admin-media-grid">
        <?php foreach ($items as $gridItem): ?>
          <?php $usageCount = $usageCounts[$gridItem->id] ?? 0; ?>
          <a class="admin-media-card<?= $gridItem->fileExists() ? '' : ' is-missing' ?>" href="/admin/media.php?id=<?= (int) $gridItem->id ?>">
            <span class="admin-media-card__media">
              <?php if ($gridItem->fileExists()): ?>
                <img src="<?= $h($gridItem->displayPath()) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="admin-media-card__warning"><?= admin_te('common.file_missing') ?></span>
              <?php endif; ?>
            </span>
            <span class="admin-media-card__name"><?= $h($gridItem->displayName()) ?></span>
            <span class="admin-media-card__meta">
              <?= $gridItem->hasDimensions() ? (int) $gridItem->width . ' &times; ' . (int) $gridItem->height : 'afmetingen onbekend' ?>
            </span>
            <span class="admin-media-card__usage<?= $usageCount === 0 ? ' is-unused' : '' ?>">
              <?= $usageCount === 0 ? 'Niet gebruikt' : $usageCount . ($usageCount === 1 ? ' plek' : ' plekken') ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($lastPage > 1): ?>
        <nav class="admin-pagination" aria-label="Paginering">
          <?php if ($page > 1): ?>
            <a class="admin-btn-text" href="/admin/media.php?<?= $h(http_build_query(['q' => $term, 'page' => $page - 1])) ?>">&larr; Vorige</a>
          <?php endif; ?>
          <span class="admin-text-muted"><?= admin_te('media.page_x_of_y', ['v1' => (int) $page, 'v2' => (int) $lastPage]) ?></span>
          <?php if ($page < $lastPage): ?>
            <a class="admin-btn-text" href="/admin/media.php?<?= $h(http_build_query(['q' => $term, 'page' => $page + 1])) ?>">Volgende &rarr;</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

<?php else: ?>

  <p><a class="admin-btn-text" href="/admin/media.php"><?= admin_t('media.terug_media') ?></a></p>

  <h1><?= $h($item->displayName()) ?></h1>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($reused): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('media.bestand_stond_al_bibliotheek') ?></p>
  <?php endif; ?>

  <?php if (!$item->fileExists()): ?>
    <p class="admin-alert admin-alert--error">
      <?= admin_te('media.bestand_afbeelding_staat_meer') ?>
    </p>
  <?php endif; ?>

  <section class="admin-card">
    <div class="admin-media-detail">
      <div class="admin-media-detail__preview">
        <?php if ($item->fileExists()): ?>
          <img src="<?= $h($item->publicPath()) ?>" alt="" loading="lazy">
        <?php else: ?>
          <p class="admin-media-card__warning"><?= admin_te('media.bestand_ontbreekt') ?></p>
        <?php endif; ?>
      </div>

      <dl class="admin-media-detail__facts">
        <dt><?= admin_te('media.oorspronkelijke_bestandsnaam') ?></dt>
        <dd><?= $h($item->originalFilename !== '' ? $item->originalFilename : '—') ?></dd>

        <dt><?= admin_te('media.opgeslagen') ?></dt>
        <dd><code><?= $h($item->path) ?></code></dd>

        <dt><?= admin_te('media.afmetingen') ?></dt>
        <dd><?= $item->hasDimensions() ? (int) $item->width . ' &times; ' . (int) $item->height . ' pixels' : 'onbekend' ?></dd>

        <dt><?= admin_te('media.bestandsgrootte') ?></dt>
        <dd><?= $h($formatBytes($item->fileSize)) ?></dd>

        <dt><?= admin_te('common.type') ?></dt>
        <dd><?= $h($item->mimeType !== '' ? $item->mimeType : 'onbekend') ?></dd>

        <dt><?= admin_te('media.toegevoegd') ?></dt>
        <dd><?= $h((string) ($item->createdAt ?? 'onbekend')) ?></dd>
      </dl>
    </div>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('common.alt_text') ?></h2>
    <p class="admin-text-muted">
      <?= admin_te('media.beschrijft_wat_er_afbeelding') ?>
    </p>

    <?php if ($canManage): ?>
      <form method="post" action="/api/admin/update-media.php" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="media_id" value="<?= (int) $item->id ?>">

        <div class="admin-form-row">
          <label><?= admin_te('common.alt_text') ?>
            <input type="text" name="alt_text" maxlength="255" value="<?= $h($item->altText) ?>">
          </label>
        </div>

        <button type="submit"><?= admin_te('common.save') ?></button>
      </form>
    <?php else: ?>
      <p><?= $item->altText !== '' ? $h($item->altText) : '<em>Nog geen alt-tekst.</em>' ?></p>
      <p class="admin-text-muted"><?= admin_t('media.hebt_recht_mediabibliotheek_beheren') ?></p>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('media.waar_gebruikt') ?></h2>

    <?php if ($usages === []): ?>
      <p class="admin-text-muted"><?= admin_te('media.nergens_afbeelding_veilig_verwijderd') ?></p>
    <?php else: ?>
      <ul class="admin-media-usage">
        <?php foreach ($usages as $usage): ?>
          <li>
            <?php if ($usage->editUrl !== null): ?>
              <a href="<?= $h($usage->editUrl) ?>"><?= $h($usage->label) ?></a>
            <?php else: ?>
              <?= $h($usage->label) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($canManage): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>

      <?php if ($usages !== []): ?>
        <p class="admin-text-muted">
          <?= admin_t('media.afbeelding_plek_gebruikt_daarom', ['v1' => count($usages), 'v2' => count($usages) === 1 ? '' : 'ken']) ?>
        </p>
        <button type="button" disabled><?= admin_te('common.delete') ?></button>
      <?php else: ?>
        <p class="admin-text-muted">
          <?= admin_t('media.verwijdert_afbeelding_uit_bibliotheek') ?>
        </p>
        <form method="post" action="/api/admin/delete-media.php" onsubmit="return confirm('Deze afbeelding definitief verwijderen?');">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="media_id" value="<?= (int) $item->id ?>">
          <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

<?php endif; ?>

</main>
<?php if ($item === null): ?>
<script src="<?= AssetVersion::url('/admin/assets/media-library.js') ?>" defer></script>
<script src="<?= AssetVersion::url('/admin/assets/media-upload.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
