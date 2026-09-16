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
 * The form and its state arrive as arguments, looked up by
 * App\Service\Blocks\FormBlock::render() (FormCatalog::renderable() and
 * FormRenderState::forInstance()). This file only renders, so the block
 * library can hand it a form built in memory and show the real markup
 * (App\Service\Blocks\BlockSamples).
 *
 * @param array<string, mixed> $content see FormBlockContent::forSection()
 * @param FormDefinition|null  $form    null when there is nothing to show
 * @param FormRenderState      $state   this instance's state
 */

require_once __DIR__ . '/form.php';

use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormRenderState;

function render_section_form(array $content, ?FormDefinition $form, FormRenderState $state): void
{
    if ($form === null) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $title = (string) ($content['title_nl'] ?? '');
    $intro = (string) ($content['intro_nl'] ?? '');
    ?>
  <section class="form-block">
    <div class="container">
      <div class="form-block__card contact-card" data-reveal>
        <?php if ($title !== ''): ?>
          <h2 class="form-block__title" <?= \App\Service\Language\SiteText::attrs($title, (string) $content['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($title, (string) $content['title_en'])) ?></h2>
        <?php endif; ?>

        <?php if ($intro !== ''): ?>
          <p class="form-block__intro" <?= \App\Service\Language\SiteText::attrs($intro, (string) $content['intro_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($intro, (string) $content['intro_en'])) ?></p>
        <?php endif; ?>

        <?php render_form($form, $state); ?>
      </div>
    </div>
  </section>
    <?php
}
