<?php

/**
 * Renders the CTA Band section (App\Service\CtaBandContent) — identical
 * markup on index.php, shop.php, diensten.php and portfolio.php, extracted
 * verbatim, and borrowed by portfolio-detail.php. Caller must already have
 * checked $cta['state'] !== CtaBandContent::STATE_HIDDEN before calling this.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization), so this file knows no
 * language, no default and no fallback. All of it is plain text.
 *
 * @param array<string, mixed> $cta see CtaBandContent::forSection()
 */
function render_section_cta_band(array $cta): void
{
    $text = static fn (string $field): string => $cta[$field];

    if ($text('title') === '' && $text('primary_label') === '') {
        // Nothing to say and nowhere to go — a missing/unreachable content
        // row must leave no empty band behind, the same "empty block leaves
        // no gap" rule as the Rich text block.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section>
      <div class="container">
        <div class="cta-band cta-band--card" data-reveal>
          <p class="eyebrow"><?= $h($text('eyebrow')) ?></p>
          <h2><?= $h($text('title')) ?></h2>
          <?php if ($text('lead') !== ''): ?>
          <p class="lead"><?= $h($text('lead')) ?></p>
          <?php endif; ?>
          <div class="cta-band__actions">
            <a href="<?= $h((string) $cta['primary_url']) ?>" class="btn"><?= $h($text('primary_label')) ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </a>
            <?php if ($text('secondary_label') !== ''): ?>
            <a href="<?= $h((string) $cta['secondary_url']) ?>" class="btn btn--ghost"><?= $h($text('secondary_label')) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
    <?php
}
