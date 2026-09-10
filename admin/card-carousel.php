<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\CardCarouselRepository;
use App\Repository\PageRepository;

/**
 * Editor for one "Kaarten-carrousel" block instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page and its content row
 * really exist" gate as every other repeater section editor.
 *
 * The block's own heading and the ORDER of its cards live here; a card's
 * own content (text, image, tags) has its own screen
 * (admin/carousel-card.php), the same "list screen + item screen" split
 * admin/portfolio.php and admin/portfolio-item.php use — a card carries a
 * repeater of its own, which does not fit one flat form.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new CardCarouselRepository();
$pages = new PageRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || $pages->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Onbekende sectie.');
}

$page = $pages->findByContentKey($pageSlug);
$carousel = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$carouselId = (int) $carousel['id'];
$cards = $repository->findCardsByCarouselId($carouselId);

$errors = $_SESSION['admin_card_carousel_errors'] ?? [];
$old = $_SESSION['admin_card_carousel_old'] ?? null;
unset($_SESSION['admin_card_carousel_errors'], $_SESSION['admin_card_carousel_old']);

$cardErrors = $_SESSION['admin_carousel_card_errors'] ?? [];
unset($_SESSION['admin_carousel_card_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    $values = [
        'eyebrow_nl' => (string) ($carousel['eyebrow_nl'] ?? ''),
        'eyebrow_en' => (string) ($carousel['eyebrow_en'] ?? ''),
        'title_nl' => (string) ($carousel['title_nl'] ?? ''),
        'title_en' => (string) ($carousel['title_en'] ?? ''),
        'lead_nl' => (string) ($carousel['lead_nl'] ?? ''),
        'lead_en' => (string) ($carousel['lead_en'] ?? ''),
        'is_active' => (bool) $carousel['is_active'],
    ];
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * @param array<string, mixed> $values
 */
function carouselValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('card_carousel')) ?> — <?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>">&larr; Terug naar <?= $h((string) $page['title']) ?></a></p>
  <h1><?= $h(SectionRegistry::label('card_carousel')) ?></h1>
  <p class="admin-text-muted">Sectie op de pagina "<?= $h((string) $page['title']) ?>". Je bepaalt zelf welke kaarten erin staan en hoeveel.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>

  <?php foreach ([$errors, $cardErrors] as $errorList): ?>
    <?php if ($errorList !== []): ?>
      <div class="admin-alert admin-alert--error">
        <ul class="admin-error-list">
          <?php foreach ($errorList as $error): ?>
            <li><?= $h((string) $error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <section class="admin-card">
    <h2>Kop boven de carrousel</h2>
    <form method="post" action="/api/admin/update-card-carousel.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Eyebrow
          <input type="text" name="eyebrow_nl" maxlength="255" value="<?= carouselValue($values, 'eyebrow_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Eyebrow
          <input type="text" name="eyebrow_en" maxlength="255" value="<?= carouselValue($values, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel / H2
          <input type="text" name="title_nl" maxlength="255" value="<?= carouselValue($values, 'title_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel / H2
          <input type="text" name="title_en" maxlength="255" value="<?= carouselValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Lead
          <textarea name="lead_nl" maxlength="1000" rows="3" placeholder="Optioneel"><?= carouselValue($values, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Lead
          <textarea name="lead_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= carouselValue($values, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted">Alles hierboven is optioneel — laat je ze alle drie leeg, dan toont de carrousel alleen de kaarten.</p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = de hele carrousel wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Kaarten (<?= count($cards) ?>)</h2>

    <?php if ($cards === []): ?>
      <p class="admin-text-muted">Nog geen kaarten. Een carrousel zonder kaarten wordt niet getoond op de pagina.</p>
    <?php endif; ?>

    <?php foreach ($cards as $index => $card): ?>
      <?php
        $cardId = (int) $card['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($cards) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <?php if ((string) ($card['image_path'] ?? '') !== ''): ?>
          <div class="admin-image-card__media" style="max-width:180px;">
            <img src="/<?= $h((string) $card['image_path']) ?>" alt="">
          </div>
        <?php endif; ?>

        <p>
          <strong><?= $h((string) $card['title_nl']) ?></strong>
          <?php if (!(bool) $card['is_active']): ?>
            <span class="admin-text-muted">— verborgen</span>
          <?php endif; ?>
        </p>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <a class="admin-btn-text" href="/admin/carousel-card.php?card_id=<?= $cardId ?>">Bewerken</a>
          <form method="post" action="/api/admin/move-carousel-card.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-carousel-card.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-carousel-card.php" class="admin-inline-form" onsubmit="return confirm('Deze kaart definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuwe kaart toevoegen</h2>
    <form method="post" action="/api/admin/create-carousel-card.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="carousel_id" value="<?= $carouselId ?>">

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel
          <input type="text" name="title_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit">Kaart toevoegen</button>
    </form>
    <p class="admin-text-muted">Je komt daarna direct in de kaart zelf, om tekst, afbeelding, tags en de knop in te vullen.</p>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
