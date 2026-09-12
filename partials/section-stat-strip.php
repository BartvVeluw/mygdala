<?php

/**
 * Renders the Stat Strip section (App\Service\StatStripContent) — extracted
 * verbatim from index.php. This type never has a heading (see
 * StatStripContent's docblock). Caller must already have checked
 * $strip['state'] !== StatStripContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $strip see StatStripContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_stat_strip(array $strip, string $revealGroup = 'stats'): void
{
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
