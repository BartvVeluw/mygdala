<?php

/**
 * Renders the Stat Strip section (App\Service\StatStripContent) — extracted
 * verbatim from index.php. This type never has a heading (see
 * StatStripContent's docblock). Caller must already have checked
 * $strip['state'] !== StatStripContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $strip see StatStripContent::forSection()
 */
function render_section_stat_strip(array $strip): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="bg-forest">
      <div class="container">
        <div class="stat-strip">
          <?php foreach ($strip['items'] as $stat): ?>
          <div class="stat" data-reveal data-reveal-group="stats">
            <strong data-nl="<?= $h($stat['primary_text_nl']) ?>" data-en="<?= $h($stat['primary_text_en']) ?>"><?= $h($stat['primary_text_nl']) ?></strong><span data-nl="<?= $h($stat['secondary_text_nl']) ?>" data-en="<?= $h($stat['secondary_text_en']) ?>"><?= $h($stat['secondary_text_nl']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
