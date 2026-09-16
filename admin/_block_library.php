<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_block_visual.php';

use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockSamples;
use App\Service\SectionRegistry;

/**
 * The Contentblokken library (admin/content-blocks.php): a card per block,
 * and one dialog that shows any of them with sample content.
 *
 * EVERY WORD ON A CARD COMES OFF THE BLOCK'S DEFINITION, exactly like the
 * picker (admin/_block_picker.php): name, description, category, icon,
 * schematic drawing and example uses. There is no second copy of any of it
 * here, so a new block appears correctly described without this file being
 * touched, and Tests\Service\BlockPresentationTest fails if that changes.
 *
 * THE PREVIEW IS THE REAL BLOCK. The dialog loads admin/block-preview.php in
 * a sandboxed iframe: the block's own partial and stylesheets, the site's
 * theme, sample words (App\Service\Blocks\BlockSamples). The frame may run
 * the block's scripts (a carousel turns, a gallery zooms) and nothing more:
 * no form in it can be sent, no link can leave it, it can never open a
 * window or navigate this screen, and it does not share this CMS's origin
 * (see block_library_preview_dialog()). A frame and not the markup on this page,
 * because the site's CSS and the CMS's CSS style the same elements, and
 * because only a frame of its own width lets the site's media queries show a
 * tablet or a phone.
 *
 * A BLOCK WITHOUT A SAMPLE (BlockDefinition::sampleContent() null, today only
 * the product grid) still gets its button. The dialog then shows the
 * schematic drawing large, with one sentence saying why there is no example,
 * rather than an empty frame.
 *
 * WHAT IS NOT HERE. No form, no write endpoint, nothing that changes a page:
 * a block is added from the picker inside a page. The dialog's behaviour is
 * admin/assets/block-library.js; this file only prints.
 */

/**
 * Every block the site has right now, grouped by category. $definitions is
 * App\Service\Blocks\BlockDefinitions::all(): a switched-off module's blocks
 * are not registered, so they are simply not there.
 *
 * @param array<string, BlockDefinition> $definitions type => definition
 */
function block_library(array $definitions): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $samples = new BlockSamples();

    foreach (BlockCategories::group($definitions) as $categoryKey => $blocks) {
        ?>
    <section class="admin-catalogue-group">
      <h2 class="admin-catalogue-group__title"><?= $h(BlockCategories::label($categoryKey)) ?></h2>
      <p class="admin-catalogue-group__count"><?= count($blocks) === 1 ? '1 blok' : count($blocks) . ' blokken' ?></p>

      <div class="admin-catalogue-grid">
        <?php foreach ($blocks as $type => $definition): ?>
          <?php block_library_card((string) $type, $definition, $definition->sampleContent($samples) !== null); ?>
        <?php endforeach; ?>
      </div>
    </section>
        <?php
    }
}

/**
 * One block's card. $hasSample decides whether the dialog shows the real
 * block or the drawing with its explanation; the button is the same.
 */
function block_library_card(string $type, BlockDefinition $definition, bool $hasSample): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $label = $definition->label();
    ?>
          <article class="admin-catalogue-card" data-block-library-card>
            <?php block_visual($definition); ?>

            <p class="admin-catalogue-card__category" data-block-library-category><?= $h(BlockCategories::label($definition->category())) ?></p>

            <div class="admin-catalogue-card__head">
              <?php block_icon_svg($definition, 'admin-catalogue-card__icon'); ?>
              <h3 class="admin-catalogue-card__title" data-block-library-name><?= $h($label) ?></h3>
              <?php if (!SectionRegistry::isManuallyAddable($type)): ?>
                <?php /* A fixed block: it is on the page because the site put
                         it there, and its content is managed somewhere else
                         entirely. Saying so here saves an editor from
                         hunting for it in the picker. */ ?>
                <span class="admin-badge admin-badge--info">Staat er automatisch</span>
              <?php endif; ?>
            </div>

            <p class="admin-catalogue-card__desc" data-block-library-description><?= $h($definition->describedFor()) ?></p>

            <?php $cases = $definition->useCasesFor(); ?>
            <?php if ($cases !== []): ?>
              <div>
                <p class="admin-catalogue-card__cases-title"><?= admin_te('blocks.geschikt') ?></p>
                <ul class="admin-catalogue-card__cases">
                  <?php foreach ($cases as $case): ?>
                    <li><?= $h($case) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php foreach (SectionRegistry::types()[$type]['edit_links'] ?? [] as $editLink): ?>
              <p class="admin-text-muted"><?= admin_te('blocks.inhoud_beheer') ?> <a href="<?= $h($editLink['url']) ?>"><?= $h($editLink['label']) ?></a>.</p>
            <?php endforeach; ?>

            <div class="admin-catalogue-card__actions">
              <?php /* The type goes into the preview's address and nowhere on
                       screen; admin/block-preview.php only lets it hit or miss
                       a registry key. */ ?>
              <button type="button" class="admin-btn-secondary admin-catalogue-card__preview"
                      data-block-preview-open
                      <?php if ($hasSample): ?>data-block-preview-src="/admin/block-preview.php?type=<?= $h(rawurlencode($type)) ?>"<?php endif; ?>
                      data-block-preview-frame-title="<?= admin_te('blocks.preview_frame_title', ['block' => $label]) ?>"
                      aria-haspopup="dialog">
                <svg class="admin-catalogue-card__preview-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <span><?= admin_te('blocks.preview_open') ?><span class="admin-visually-hidden">: <?= $h($label) ?></span></span>
              </button>
            </div>
          </article>
    <?php
}

/**
 * The one preview dialog, printed once at the end of the screen. A native
 * modal <dialog>, like admin_confirm_dialog() (ADMIN-UI.md): showModal()
 * makes the page behind it inert, holds the focus and closes on Escape. It is
 * NOT that confirmation dialog, which asks a yes-or-no question about a form;
 * this one shows something large and asks nothing.
 *
 * The heading, description and category are filled from the card that opened
 * it, as text. The frame's sandbox allows scripts and nothing else: no forms,
 * no popups, no navigation of this screen, and NO allow-same-origin. The
 * preview therefore runs in an opaque origin of its own: its scripts cannot
 * reach this screen's document, the admin session's cookie or this origin's
 * storage, and this screen cannot reach into the frame either. No block needs
 * more: its stylesheets, pictures and scripts load as ordinary requests, and
 * the one thing the frame tells this screen (Escape was pressed inside it)
 * travels as a message (assets/js/block-preview.js). Adding
 * allow-same-origin back would hand a preview script this CMS's own origin;
 * Tests\Service\BlockLibraryScreenTest fails if it returns.
 */
function block_library_preview_dialog(): void
{
    ?>
<dialog class="admin-block-preview" data-block-preview aria-labelledby="admin-block-preview-title" aria-describedby="admin-block-preview-description">
  <div class="admin-block-preview__panel">
    <header class="admin-block-preview__head">
      <div class="admin-block-preview__heading">
        <p class="admin-block-preview__category" data-block-preview-category></p>
        <h2 class="admin-block-preview__title" id="admin-block-preview-title" data-block-preview-title></h2>
        <p class="admin-block-preview__description" id="admin-block-preview-description" data-block-preview-description></p>
      </div>
      <button type="button" class="admin-block-preview__close" data-block-preview-close aria-label="<?= admin_te('common.close') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </header>

    <div class="admin-block-preview__toolbar" data-block-preview-live>
      <div class="admin-block-preview__viewports" role="group" aria-label="<?= admin_te('blocks.preview_viewports') ?>">
        <button type="button" class="admin-block-preview__viewport" data-block-preview-viewport="desktop" aria-pressed="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>
          <span><?= admin_te('blocks.preview_desktop') ?></span>
        </button>
        <button type="button" class="admin-block-preview__viewport" data-block-preview-viewport="tablet" aria-pressed="false">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M11 18.5h2"/></svg>
          <span><?= admin_te('blocks.preview_tablet') ?></span>
        </button>
        <button type="button" class="admin-block-preview__viewport" data-block-preview-viewport="mobile" aria-pressed="false">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/></svg>
          <span><?= admin_te('blocks.preview_mobile') ?></span>
        </button>
      </div>
      <p class="admin-block-preview__note"><?= admin_te('blocks.preview_note') ?></p>
    </div>

    <div class="admin-block-preview__stage" data-block-preview-stage data-viewport="desktop">
      <iframe class="admin-block-preview__frame" data-block-preview-frame title="" src="about:blank"
              sandbox="allow-scripts" referrerpolicy="same-origin"></iframe>
    </div>

    <div class="admin-block-preview__fallback" data-block-preview-fallback hidden>
      <div class="admin-block-preview__drawing" data-block-preview-drawing></div>
      <p class="admin-block-preview__fallback-text"><?= admin_te('blocks.preview_unavailable') ?></p>
    </div>
  </div>
</dialog>
    <?php
}
