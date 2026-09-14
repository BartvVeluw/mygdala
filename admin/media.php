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
use App\Service\Media\MediaType;
use App\Service\Media\MediaUploader;
use App\Service\Media\VisibleMediaUsages;

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
 * FINDING FILES. A search field and a choice of kind
 * (App\Service\Media\MediaType) above the grid — both shared controls, both an
 * ordinary GET — so every view of the library has an address: ?q=, ?type=,
 * ?page=. With admin/assets/media-library.js the results block is swapped in
 * place instead of the page being reloaded, but the markup is rendered here,
 * once, either way.
 *
 * CHANGING AND REMOVING, for a manager only (media.manage). A card carries a
 * checkbox for the one bulk action there is — deleting a selection, through
 * api/admin/delete-media-items.php — and a way to rename the item. Both work
 * without the script: the checkboxes belong to an ordinary form, and the item
 * view has a plain rename form. The script adds the selection's own
 * confirmation dialog, the counter and the rename dialog; a single delete on
 * the item view asks in the CMS's shared dialog instead (ADMIN-UI.md). Nothing
 * used is ever deleted: the server
 * keeps it and says where it is used — naming only the places the reader may
 * open, and counting the others (App\Service\Media\VisibleMediaUsages).
 *
 * WHAT IT IS NOT. There are no folders, no tags, no crop tool and no raw
 * filesystem operations: nothing here lets somebody type a path, browse a
 * directory or move a file, and a new name never renames a file on disk.
 * MEDIA.md lists what was deliberately left out and why.
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
$renamed = isset($_GET['renamed']);

$requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$item = ($requestedId === false || $requestedId === null) ? null : MediaService::find($requestedId);

$term = trim((string) ($_GET['q'] ?? ''));
$requestedType = (string) ($_GET['type'] ?? '');
$type = MediaType::isKnown($requestedType) ? $requestedType : '';
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
    $result = $service->browse($term, $page, type: $type);
    $perPage = MediaRepository::PAGE_SIZE;
    $lastPage = max(1, (int) ceil($result['total'] / $perPage));

    // A page past the last one — an old address, or the last items on it
    // were just deleted — shows the last page there is, not an empty grid.
    if ($page > $lastPage) {
        $page = $lastPage;
        $result = $service->browse($term, $page, type: $type);
    }

    /** @var list<MediaItem> $items */
    $items = $result['items'];
    $total = $result['total'];

    // ONE call for the whole page, not one per thumbnail: see
    // App\Service\Media\MediaUsageRegistry for why that is the contract.
    $usageCounts = $service->usageCountsFor($items);

    /** One way to write a library address, so the paging and the filters agree on it. */
    $libraryUrl = static function (string $term, string $type, int $page): string {
        $query = http_build_query(array_filter(
            ['q' => $term, 'type' => $type, 'page' => $page > 1 ? $page : ''],
            static fn (string|int $value): bool => $value !== ''
        ));

        return '/admin/media.php' . ($query === '' ? '' : '?' . $query);
    };

    if ($term !== '') {
        // These two sentences wrap the term in quotation-mark entities, so
        // the term is escaped on its own and the sentence printed as it is.
        $summary = $total === 1
            ? admin_t('media.results_for_one', ['v1' => $h($term)])
            : admin_t('media.results_for', ['v1' => (int) $total, 'v2' => $h($term)]);
    } elseif ($type !== '') {
        $summary = $total === 1 ? admin_te('media.filter.results_one') : admin_te('media.filter.results', ['count' => (int) $total]);
    } else {
        $summary = $total === 1 ? admin_te('media.count_in_library_one') : admin_te('media.count_in_library', ['count' => (int) $total]);
    }

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
    // Every place that uses the item, named only where this administrator may
    // open it and counted otherwise (App\Service\Media\VisibleMediaUsages).
    $usages = VisibleMediaUsages::of($service->usagesOf($item->id), AdminAuth::can(...));
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

  <section class="admin-card admin-media-library" aria-labelledby="media-library-title" data-media-library>
    <h2 id="media-library-title"><?= admin_te('media.library.title') ?></h2>

    <form method="get" action="/admin/media.php" class="admin-toolbar admin-media-toolbar" role="search" data-media-filter>
      <label class="admin-search">
        <span class="admin-visually-hidden"><?= admin_te('media.search.label') ?></span>
        <input type="search" name="q" value="<?= $h($term) ?>" placeholder="<?= admin_te('media.search.placeholder') ?>" autocomplete="off" data-media-search>
      </label>

      <label class="admin-media-toolbar__type">
        <span class="admin-visually-hidden"><?= admin_te('media.filter.label') ?></span>
        <select name="type" class="admin-select" data-media-type>
          <option value=""><?= admin_te('media.type.all') ?></option>
          <?php foreach (MediaType::all() as $mediaType): ?>
            <option value="<?= $h($mediaType) ?>"<?= $mediaType === $type ? ' selected' : '' ?>><?= admin_te('media.type.' . $mediaType) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button type="submit" class="admin-btn-secondary"><?= admin_te('media.search.submit') ?></button>
    </form>

    <?php /* What a screen reader hears when the results change without a
             reload. It stays in place; the block below is the one replaced. */ ?>
    <p class="admin-visually-hidden" role="status" aria-live="polite" data-media-results-status></p>

    <?php /* The outcome of deleting a selection, filled in by media-library.js.
             Outside the results block, so it survives the redraw that follows. */ ?>
    <div class="admin-alert admin-media-notice" data-media-notice hidden>
      <p class="admin-media-notice__message" data-media-notice-message></p>
      <ul class="admin-error-list" data-media-notice-details hidden></ul>
    </div>

    <div class="admin-media-results" data-media-results>
      <p class="admin-media-results__summary" tabindex="-1" data-media-summary>
        <span data-media-summary-text><?= $summary ?></span>
        <?php if ($term !== '' || $type !== ''): ?>
          <a class="admin-btn-text" href="/admin/media.php" data-media-reset><?= admin_te('media.filter.reset') ?></a>
        <?php endif; ?>
      </p>

      <?php if ($items === []): ?>
        <p class="admin-text-muted">
          <?= admin_te($term === '' && $type === '' ? 'media.empty_library' : 'media.nothing_found') ?>
        </p>
      <?php else: ?>
        <?php if ($canManage): ?>
          <?php /* The checkboxes on the cards belong to this form through their
                   `form` attribute, so the grid needs no form around it and the
                   bar works without the script as an ordinary POST. The way
                   back is these three values, never a URL. */ ?>
          <form method="post" action="/api/admin/delete-media-items.php" id="media-bulk-form" class="admin-media-bulkbar" data-media-bulk>
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="return_q" value="<?= $h($term) ?>">
            <input type="hidden" name="return_type" value="<?= $h($type) ?>">
            <input type="hidden" name="return_page" value="<?= (int) $page ?>">

            <label class="admin-checkbox-label admin-media-bulkbar__all" data-media-select-all-label hidden>
              <input type="checkbox" class="admin-checkbox" data-media-select-all>
              <?= admin_te('media.bulk.select_all') ?>
            </label>

            <p class="admin-media-bulkbar__count" role="status" data-media-selected-count
               data-text-one="<?= admin_te('media.bulk.count_one') ?>"
               data-text-many="<?= admin_te('media.bulk.count') ?>" hidden></p>

            <div class="admin-media-bulkbar__actions" data-media-bulk-actions>
              <p class="admin-media-bulkbar__warning" id="media-bulk-warning"><?= admin_te('media.bulk.warning') ?></p>
              <button type="submit" class="admin-btn-danger" aria-describedby="media-bulk-warning" data-media-bulk-delete><?= admin_te('media.bulk.delete') ?></button>
            </div>
          </form>
        <?php endif; ?>

        <ul class="admin-media-grid admin-media-library__grid" role="list">
          <?php foreach ($items as $gridItem): ?>
            <?php
            $usageCount = $usageCounts[$gridItem->id] ?? 0;
            $detailUrl = '/admin/media.php?id=' . (int) $gridItem->id;
            $displayName = $gridItem->displayName();
            ?>
            <li class="admin-media-library__card<?= $gridItem->fileExists() ? '' : ' is-missing' ?>" data-media-card data-media-id="<?= (int) $gridItem->id ?>">
              <?php if ($canManage): ?>
                <label class="admin-media-library__select">
                  <input type="checkbox" class="admin-checkbox" name="media_ids[]" value="<?= (int) $gridItem->id ?>" form="media-bulk-form"
                         data-media-select data-media-usage="<?= (int) $usageCount ?>" data-media-name="<?= $h($displayName) ?>">
                  <span class="admin-visually-hidden"><?= admin_te('media.select.label', ['name' => $displayName]) ?></span>
                </label>
              <?php endif; ?>

              <?php /* The picture opens the item too, but a keyboard and a screen
                       reader get that link once, on the name. */ ?>
              <a class="admin-media-library__thumb" href="<?= $h($detailUrl) ?>" tabindex="-1" aria-hidden="true">
                <?php if ($gridItem->fileExists()): ?>
                  <img src="<?= $h($gridItem->displayPath()) ?>" alt="" loading="lazy" decoding="async">
                <?php else: ?>
                  <span class="admin-media-card__warning"><?= admin_te('common.file_missing') ?></span>
                <?php endif; ?>
              </a>

              <div class="admin-media-library__body">
                <a class="admin-media-library__name" href="<?= $h($detailUrl) ?>"><?= $h($displayName) ?></a>

                <p class="admin-media-library__meta">
                  <span class="admin-media-library__type"><?= $h($gridItem->typeLabel()) ?></span>
                  <?php if ($gridItem->hasDimensions()): ?>
                    <span><?= (int) $gridItem->width ?> &times; <?= (int) $gridItem->height ?></span>
                  <?php endif; ?>
                  <?php if ($gridItem->fileSize !== null && $gridItem->fileSize > 0): ?>
                    <span><?= $h($formatBytes($gridItem->fileSize)) ?></span>
                  <?php endif; ?>
                </p>

                <span class="admin-media-card__usage<?= $usageCount === 0 ? ' is-unused' : '' ?>">
                  <?php if ($usageCount === 0): ?>
                    <?= admin_te('media.card.unused') ?>
                  <?php elseif ($usageCount === 1): ?>
                    <?= admin_te('media.card.used_one') ?>
                  <?php else: ?>
                    <?= admin_te('media.card.used', ['count' => $usageCount]) ?>
                  <?php endif; ?>
                </span>

                <?php if ($canManage): ?>
                  <?php $nameExtension = $gridItem->nameExtension(); ?>
                  <button type="button" class="admin-btn-text admin-media-library__rename" hidden
                          aria-label="<?= admin_te('media.rename.button_named', ['name' => $displayName]) ?>"
                          data-media-rename
                          data-media-id="<?= (int) $gridItem->id ?>"
                          data-media-name-base="<?= $h(MediaFilename::withoutExtension($displayName, $nameExtension)) ?>"
                          data-media-name-extension="<?= $h($nameExtension) ?>"><?= admin_te('media.rename.button') ?></button>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($lastPage > 1): ?>
          <nav class="admin-pagination" aria-label="<?= admin_te('media.page.label') ?>">
            <?php if ($page > 1): ?>
              <a class="admin-btn-text" href="<?= $h($libraryUrl($term, $type, $page - 1)) ?>" data-media-page><?= admin_te('media.page.previous') ?></a>
            <?php endif; ?>
            <span class="admin-text-muted"><?= admin_te('media.page_x_of_y', ['v1' => (int) $page, 'v2' => (int) $lastPage]) ?></span>
            <?php if ($page < $lastPage): ?>
              <a class="admin-btn-text" href="<?= $h($libraryUrl($term, $type, $page + 1)) ?>" data-media-page><?= admin_te('media.page.next') ?></a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($canManage): ?>
    <?php /* The two dialogs media-library.js opens. A native <dialog>: the
             browser traps the focus, closes it on Escape and puts it above
             everything. Without the script they never open, the rename
             buttons stay hidden and the bulk form posts on its own. */ ?>
    <dialog class="admin-media-dialog" aria-labelledby="media-delete-title" aria-describedby="media-delete-text" data-media-delete-dialog
            data-text-one="<?= admin_te('media.bulk.confirm_one') ?>"
            data-text-many="<?= admin_te('media.bulk.confirm_many') ?>"
            data-kept-one="<?= admin_te('media.bulk.kept_intro_one') ?>"
            data-kept-many="<?= admin_te('media.bulk.kept_intro_many') ?>"
            data-all-used="<?= admin_te('media.bulk.all_used') ?>"
            data-failed="<?= admin_te('media.bulk.failed') ?>"
            data-session="<?= admin_te('media.bulk.session') ?>">
      <div class="admin-media-dialog__body">
        <h2 class="admin-media-dialog__title" id="media-delete-title"><?= admin_te('media.bulk.confirm_title') ?></h2>
        <p class="admin-media-dialog__text" id="media-delete-text" data-media-delete-text></p>

        <div class="admin-media-dialog__kept" data-media-delete-kept hidden>
          <p class="admin-media-dialog__text" data-media-delete-kept-text></p>
          <ul class="admin-media-dialog__list" data-media-delete-kept-list></ul>
        </div>

        <p class="admin-media-dialog__error" role="alert" data-media-dialog-error hidden></p>

        <div class="admin-media-dialog__actions">
          <button type="button" class="admin-btn-ghost" autofocus data-media-dialog-close><?= admin_te('media.dialog.cancel') ?></button>
          <button type="button" class="admin-btn-danger" data-media-delete-confirm><?= admin_te('media.bulk.confirm_delete') ?></button>
        </div>
      </div>
    </dialog>

    <dialog class="admin-media-dialog" aria-labelledby="media-rename-title" data-media-rename-dialog
            data-failed="<?= admin_te('media.rename.failed') ?>"
            data-session="<?= admin_te('media.bulk.session') ?>"
            data-done="<?= admin_te('media.rename.done_named') ?>">
      <form method="post" action="/api/admin/rename-media.php" class="admin-media-dialog__body" novalidate data-media-rename-form>
        <h2 class="admin-media-dialog__title" id="media-rename-title"><?= admin_te('media.rename.title') ?></h2>

        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="media_id" value="" data-media-rename-id>

        <label class="admin-media-dialog__label" for="media-rename-name"><?= admin_te('media.rename.label') ?></label>
        <span class="admin-media-dialog__name">
          <input type="text" id="media-rename-name" name="name" maxlength="<?= MediaFilename::MAX_BASE_LENGTH ?>" autocomplete="off" spellcheck="false"
                 aria-describedby="media-rename-hint media-rename-error" data-media-rename-name>
          <span class="admin-media-dialog__extension" data-media-rename-extension></span>
        </span>

        <p class="admin-media-dialog__hint" id="media-rename-hint"><?= admin_te('media.rename.hint') ?></p>
        <p class="admin-media-dialog__error" id="media-rename-error" role="alert" data-media-dialog-error hidden></p>

        <div class="admin-media-dialog__actions">
          <button type="button" class="admin-btn-ghost" data-media-dialog-close><?= admin_te('media.dialog.cancel') ?></button>
          <button type="submit" class="admin-btn-primary" data-media-rename-submit><?= admin_te('media.rename.submit') ?></button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>

<?php else: ?>

  <p><a class="admin-btn-text" href="/admin/media.php"><?= admin_t('media.terug_media') ?></a></p>

  <h1><?= $h($item->displayName()) ?></h1>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($renamed): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('media.rename.done') ?></p>
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

  <?php if ($canManage): ?>
    <?php $nameExtension = $item->nameExtension(); ?>
    <section class="admin-card">
      <h2><?= admin_te('media.rename.section') ?></h2>
      <p class="admin-text-muted"><?= admin_te('media.rename.hint') ?></p>

      <form method="post" action="/api/admin/rename-media.php" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="media_id" value="<?= (int) $item->id ?>">

        <div class="admin-field">
          <label for="media-name"><?= admin_te('media.rename.label') ?></label>
          <span class="admin-media-rename">
            <input type="text" id="media-name" name="name" maxlength="<?= MediaFilename::MAX_BASE_LENGTH ?>" required
                   value="<?= $h(MediaFilename::withoutExtension($item->displayName(), $nameExtension)) ?>"<?= $nameExtension !== '' ? ' aria-describedby="media-name-extension"' : '' ?>>
            <?php if ($nameExtension !== ''): ?>
              <span class="admin-media-rename__extension" aria-hidden="true">.<?= $h($nameExtension) ?></span>
            <?php endif; ?>
          </span>
          <?php if ($nameExtension !== ''): ?>
            <p class="admin-text-muted" id="media-name-extension"><?= admin_te('media.rename.extension', ['ext' => '.' . $nameExtension]) ?></p>
          <?php endif; ?>
        </div>

        <button type="submit" class="admin-btn-primary"><?= admin_te('media.rename.submit') ?></button>
      </form>
    </section>
  <?php endif; ?>

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

    <?php if ($usages->count() === 0): ?>
      <p class="admin-text-muted"><?= admin_te('media.nergens_afbeelding_veilig_verwijderd') ?></p>
    <?php else: ?>
      <ul class="admin-media-usage">
        <?php foreach ($usages->shown as $usage): ?>
          <li>
            <?php if ($usage->editUrl !== null): ?>
              <a href="<?= $h($usage->editUrl) ?>"><?= $h($usage->label) ?></a>
            <?php else: ?>
              <?= $h($usage->label) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if ($usages->hidden > 0): ?>
          <li class="admin-text-muted"><?= $h($usages->hiddenPlaces()) ?></li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($canManage): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>

      <?php if ($usages->count() > 0): ?>
        <p class="admin-text-muted">
          <?= admin_t('media.afbeelding_plek_gebruikt_daarom', ['v1' => $usages->count(), 'v2' => $usages->count() === 1 ? '' : 'ken']) ?>
        </p>
        <button type="button" disabled><?= admin_te('common.delete') ?></button>
      <?php else: ?>
        <p class="admin-text-muted">
          <?= admin_t('media.verwijdert_afbeelding_uit_bibliotheek') ?>
        </p>
        <?php /* Asks first, in the CMS's shared dialog printed at the end of
                 this screen, and names the file that would go. The form, its
                 token and the endpoint's guards are exactly what they were;
                 the server refuses an item in use either way. */ ?>
        <form method="post" action="/api/admin/delete-media.php"<?= admin_confirm_attributes(
            admin_t('media.delete.confirm_title'),
            admin_t('media.delete.confirm', ['name' => $item->displayName()]),
            admin_t('common.delete')
        ) ?>>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="media_id" value="<?= (int) $item->id ?>">
          <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

<?php endif; ?>

</main>
<?php if ($item !== null && $canManage): ?>
<?= admin_confirm_dialog() ?>
<?php endif; ?>
<?php if ($item === null): ?>
<?php /* The grid only: on the item view the one question left is the shared
         dialog above, which the shell's admin-ui.js asks. */ ?>
<script src="<?= AssetVersion::url('/admin/assets/media-library.js') ?>" defer></script>
<script src="<?= AssetVersion::url('/admin/assets/media-upload.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
