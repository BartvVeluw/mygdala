<?php

require_once __DIR__ . '/feature-icons.php';

/**
 * Renders the Feature Grid section (App\Service\FeatureGridContent).
 * The two original instances differ visually purely by whether the section
 * has a heading: index.php's "Waardeproposities" grid has no eyebrow/title/
 * lead and sits in a plain `<section>`; over-mij.php's "Mijn stijl" grid has
 * a heading and sits in a `bg-soft` section with a centered heading block.
 * Rather than a separate stored "has_heading" flag, this reproduces both
 * exactly by keying off whether eyebrow/title/lead are actually filled in —
 * true for both current rows, so this is a lossless extraction, and it
 * degrades sensibly for a page-builder-added grid with no heading filled in.
 * Caller must already have checked $grid['state'] ===
 * FeatureGridContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing without a heading and without a single active card — the
 * state a grid is in right after it is added.
 *
 * Every word arrives as one LocalizedValue per field
 * (App\Service\Blocks\BlockLocalization), the grid's and each card's:
 * SiteText prints the words a visitor sees first and the escaped
 * data-nl/data-en pair for the V1 switch, so this file knows no language, no
 * default and no fallback. All of it is plain text.
 *
 * @param array<string, mixed> $grid see FeatureGridContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_feature_grid(array $grid, string $revealGroup = 'feature-grid'): void
{
    $text = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::visibleOf($value);
    $pair = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::attrsOf($value);

    $hasHeading = $text($grid['eyebrow']) !== '' || $text($grid['title']) !== '' || $text($grid['lead']) !== '';

    if (!$hasHeading && $grid['items'] === []) {
        // Nothing to show yet: an empty block leaves no gap, the same rule as
        // the Marquee and CTA band partials.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section<?= $hasHeading ? ' class="bg-soft"' : '' ?>>
      <div class="container">
        <?php if ($hasHeading): ?>
        <div class="section-head center" data-reveal>
          <?php if ($text($grid['eyebrow']) !== ''): ?><p class="eyebrow" <?= $pair($grid['eyebrow']) ?>><?= $h($text($grid['eyebrow'])) ?></p><?php endif; ?>
          <?php if ($text($grid['title']) !== ''): ?><h2 <?= $pair($grid['title']) ?>><?= $h($text($grid['title'])) ?></h2><?php endif; ?>
          <?php if ($text($grid['lead']) !== ''): ?><p class="lead" style="margin-inline:auto;" <?= $pair($grid['lead']) ?>><?= $h($text($grid['lead'])) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="feature-grid">
          <?php foreach ($grid['items'] as $item): ?>
          <div class="feature-card" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <div class="feature-card__icon"><?= feature_grid_icon_svg($item['icon_key']) ?></div>
            <h3 <?= $pair($item['title']) ?>><?= $h($text($item['title'])) ?></h3>
            <p <?= $pair($item['body']) ?>><?= $h($text($item['body'])) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
