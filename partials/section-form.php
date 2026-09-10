<?php

/**
 * Renders the reusable "Formulier" block (App\Service\FormBlockContent):
 * an optional heading and introduction, then the form the editor selected.
 * Caller must already have checked $content['state'] !==
 * FormBlockContent::STATE_HIDDEN.
 *
 * The form itself is rendered by partials/form.php, which every form on this
 * site goes through. This file is only the section around it — which is
 * exactly the split Core Forms is built on: the block decides WHERE a form
 * appears, the form definition decides WHAT it asks (FORMS.md).
 *
 * A BLOCK POINTING AT NOTHING RENDERS NOTHING. No form chosen yet, the form
 * deleted, switched off, or left without a single usable field: the section
 * is skipped in silence rather than leaving an empty card with a button on a
 * public page. The page builder is where the editor is told about it — see
 * App\Service\Blocks\FormBlock::instanceTitle().
 *
 * @param array<string, mixed> $content see FormBlockContent::forSection()
 * @param string               $pageSlug   the page this instance sits on
 * @param string               $sectionKey this instance's key
 */

require_once __DIR__ . '/form.php';

use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormRenderState;

function render_section_form(array $content, string $pageSlug, string $sectionKey): void
{
    $form = FormCatalog::renderable($content['form_id'] ?? null);

    if ($form === null) {
        return;
    }

    $state = FormRenderState::forInstance($pageSlug, $sectionKey);
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $title = (string) ($content['title_nl'] ?? '');
    $intro = (string) ($content['intro_nl'] ?? '');
    ?>
  <section class="form-block">
    <div class="container">
      <div class="form-block__card contact-card" data-reveal>
        <?php if ($title !== ''): ?>
          <h2 class="form-block__title" data-nl="<?= $h($title) ?>" data-en="<?= $h((string) $content['title_en']) ?>"><?= $h($title) ?></h2>
        <?php endif; ?>

        <?php if ($intro !== ''): ?>
          <p class="form-block__intro" data-nl="<?= $h($intro) ?>" data-en="<?= $h((string) $content['intro_en']) ?>"><?= $h($intro) ?></p>
        <?php endif; ?>

        <?php render_form($form, $state); ?>
      </div>
    </div>
  </section>
    <?php
}
