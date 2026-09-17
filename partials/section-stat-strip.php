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
 * Every word arrives as one LocalizedValue per field
 * (App\Service\Blocks\BlockLocalization), each stat's own: SiteText prints the
 * words a visitor sees first and the escaped data-nl/data-en pair for the V1
 * switch, so this file knows no language, no default and no fallback. All of
 * it is plain text.
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
    $text = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::visibleOf($value);
    $pair = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::attrsOf($value);
    ?>
    <section class="bg-forest">
      <div class="container">
        <div class="stat-strip">
          <?php foreach ($strip['items'] as $stat): ?>
          <div class="stat" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <strong <?= $pair($stat['primary_text']) ?>><?= $h($text($stat['primary_text'])) ?></strong><span <?= $pair($stat['secondary_text']) ?>><?= $h($text($stat['secondary_text'])) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
