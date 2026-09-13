<?php

/**
 * Renders the Stat Strip section (App\Service\StatStripContent) — extracted
 * verbatim from index.php. This type never has a heading (see
 * StatStripContent's docblock). Caller must already have checked
 * $strip['state'] === StatStripContent::STATE_ACTIVE before calling this.
 *
 * With no heading to fall back on, a strip renders nothing without a single
 * active stat — the state it is in right after it is added.
 *
 * @param array<string, mixed> $strip see StatStripContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_stat_strip(array $strip, string $revealGroup = 'stats'): void
{
    if ($strip['items'] === []) {
        // Nothing to show yet: an empty dark band leaves no gap, the same rule
        // as the Marquee and CTA band partials.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="bg-forest">
      <div class="container">
        <div class="stat-strip">
          <?php foreach ($strip['items'] as $stat): ?>
          <div class="stat" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <strong <?= \App\Service\Language\SiteText::attrs($stat['primary_text_nl'], $stat['primary_text_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($stat['primary_text_nl'], $stat['primary_text_en'])) ?></strong><span <?= \App\Service\Language\SiteText::attrs($stat['secondary_text_nl'], $stat['secondary_text_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($stat['secondary_text_nl'], $stat['secondary_text_en'])) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
