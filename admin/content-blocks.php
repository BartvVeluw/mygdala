<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_block_visual.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\SectionRegistry;

/**
 * The Contentblokken catalogue: every content block this site currently has,
 * what it puts on a page, and what it is good for.
 *
 * IT IS DOCUMENTATION, NOT AN EDITOR. Nothing on this screen creates,
 * changes or deletes anything — there is no form on it at all. Blocks are
 * added where they are used, from the picker inside a page
 * (admin/_block_picker.php); this is the place to find out what exists
 * before going there, and the answer to "wat was dat blok ook alweer?".
 *
 * IT READS THE SAME METADATA THE PICKER DOES. Label, description, category,
 * icon, schematic preview and example uses all come off each block's own
 * App\Service\Blocks\BlockDefinition. There is deliberately no second copy of
 * that copy here: a description written twice is a description that will
 * disagree with itself. A new block therefore appears on this page, correctly
 * described, without this file being touched.
 *
 * WHAT IS NOT LISTED. Exactly what the CMS does not currently have. A block
 * belonging to a switched-off module is not registered while the module is
 * off (App\Service\Blocks\BlockDefinitions), so with the Shop off the Shop's
 * blocks are simply absent — not greyed out, not "unavailable", absent, the
 * same as everywhere else in this admin.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$groups = BlockCategories::group(BlockDefinitions::all());
$total = count(BlockDefinitions::types());

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contentblokken — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title">Contentblokken</h1>
      <p class="admin-page-head__desc">Alle bouwstenen waar je een pagina mee kunt vullen &mdash; <?= $total ?> in totaal. Je voegt ze toe vanuit een pagina zelf, met de knop <em>Contentblok toevoegen</em> onder de inhoud.</p>
    </div>
    <a href="/admin/pages.php" class="admin-btn-secondary">Naar pagina's &#8594;</a>
  </header>

  <?php foreach ($groups as $categoryKey => $blocks): ?>
    <section class="admin-catalogue-group">
      <h2 class="admin-catalogue-group__title"><?= $h(BlockCategories::label($categoryKey)) ?></h2>
      <p class="admin-catalogue-group__count"><?= count($blocks) === 1 ? '1 blok' : count($blocks) . ' blokken' ?></p>

      <div class="admin-catalogue-grid">
        <?php foreach ($blocks as $type => $definition): ?>
          <article class="admin-catalogue-card">
            <?php block_visual($definition); ?>

            <div class="admin-catalogue-card__head">
              <?php block_icon_svg($definition, 'admin-catalogue-card__icon'); ?>
              <h3 class="admin-catalogue-card__title"><?= $h($definition->label()) ?></h3>
              <?php if (!SectionRegistry::isManuallyAddable($type)): ?>
                <?php /* A fixed block: it is on the page because the site put
                         it there, and its content is managed somewhere else
                         entirely. Saying so here saves an editor from
                         hunting for it in the picker. */ ?>
                <span class="admin-badge admin-badge--info">Staat er automatisch</span>
              <?php endif; ?>
            </div>

            <p class="admin-catalogue-card__desc"><?= $h($definition->description()) ?></p>

            <?php $cases = $definition->useCases(); ?>
            <?php if ($cases !== []): ?>
              <div>
                <p class="admin-catalogue-card__cases-title">Geschikt voor</p>
                <ul class="admin-catalogue-card__cases">
                  <?php foreach ($cases as $case): ?>
                    <li><?= $h($case) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php foreach (SectionRegistry::types()[$type]['edit_links'] ?? [] as $editLink): ?>
              <p class="admin-text-muted">Inhoud beheer je bij <a href="<?= $h($editLink['url']) ?>"><?= $h($editLink['label']) ?></a>.</p>
            <?php endforeach; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</body>
</html>
