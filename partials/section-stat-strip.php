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
 * Every word arrives as one string per field, each stat's own, already in
 * the language of the request (App\Service\Blocks\BlockLocalization), so this
 * file knows no language, no default and no fallback. All of it is plain
 * text.
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
            <strong><?= $h($stat['primary_text']) ?></strong><span><?= $h($stat['secondary_text']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
