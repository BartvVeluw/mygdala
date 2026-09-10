<?php

/**
 * Renders the CTA Band section (App\Service\CtaBandContent) — identical
 * markup on index.php, shop.php, diensten.php and portfolio.php, extracted
 * verbatim. Caller must already have checked $cta['state'] !==
 * CtaBandContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $cta see CtaBandContent::forSection()
 */
function render_section_cta_band(array $cta): void
{
    if ($cta['title_nl'] === '' && $cta['primary_label_nl'] === '') {
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
          <p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($cta['eyebrow_nl'], $cta['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cta['eyebrow_nl'], $cta['eyebrow_en'])) ?></p>
          <h2 <?= \App\Service\Language\SiteText::attrs($cta['title_nl'], $cta['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cta['title_nl'], $cta['title_en'])) ?></h2>
          <?php if ($cta['lead_nl'] !== ''): ?>
          <p class="lead" <?= \App\Service\Language\SiteText::attrs($cta['lead_nl'], $cta['lead_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cta['lead_nl'], $cta['lead_en'])) ?></p>
          <?php endif; ?>
          <div class="cta-band__actions">
            <a href="<?= $h($cta['primary_url']) ?>" class="btn" <?= \App\Service\Language\SiteText::attrs($cta['primary_label_nl'], $cta['primary_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cta['primary_label_nl'], $cta['primary_label_en'])) ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </a>
            <?php if ($cta['secondary_label_nl'] !== ''): ?>
            <a href="<?= $h($cta['secondary_url']) ?>" class="btn btn--ghost" <?= \App\Service\Language\SiteText::attrs($cta['secondary_label_nl'], $cta['secondary_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cta['secondary_label_nl'], $cta['secondary_label_en'])) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
    <?php
}
