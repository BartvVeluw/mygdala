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
 * @param array<string, mixed> $grid see FeatureGridContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_feature_grid(array $grid, string $revealGroup = 'feature-grid'): void
{
    $hasHeading = $grid['eyebrow_nl'] !== '' || $grid['title_nl'] !== '' || $grid['lead_nl'] !== '';

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
          <?php if ($grid['eyebrow_nl'] !== ''): ?><p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($grid['eyebrow_nl'], $grid['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($grid['eyebrow_nl'], $grid['eyebrow_en'])) ?></p><?php endif; ?>
          <?php if ($grid['title_nl'] !== ''): ?><h2 <?= \App\Service\Language\SiteText::attrs($grid['title_nl'], $grid['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($grid['title_nl'], $grid['title_en'])) ?></h2><?php endif; ?>
          <?php if ($grid['lead_nl'] !== ''): ?><p class="lead" style="margin-inline:auto;" <?= \App\Service\Language\SiteText::attrs($grid['lead_nl'], $grid['lead_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($grid['lead_nl'], $grid['lead_en'])) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="feature-grid">
          <?php foreach ($grid['items'] as $item): ?>
          <div class="feature-card" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <div class="feature-card__icon"><?= feature_grid_icon_svg($item['icon_key']) ?></div>
            <h3 <?= \App\Service\Language\SiteText::attrs($item['title_nl'], $item['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($item['title_nl'], $item['title_en'])) ?></h3>
            <p <?= \App\Service\Language\SiteText::attrs($item['body_nl'], $item['body_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($item['body_nl'], $item['body_en'])) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
