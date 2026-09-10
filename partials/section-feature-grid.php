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
 * Caller must already have checked $grid['state'] !==
 * FeatureGridContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $grid see FeatureGridContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_feature_grid(array $grid, string $revealGroup = 'feature-grid'): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $hasHeading = $grid['eyebrow_nl'] !== '' || $grid['title_nl'] !== '' || $grid['lead_nl'] !== '';
    ?>
    <section<?= $hasHeading ? ' class="bg-soft"' : '' ?>>
      <div class="container">
        <?php if ($hasHeading): ?>
        <div class="section-head center" data-reveal>
          <?php if ($grid['eyebrow_nl'] !== ''): ?><p class="eyebrow" data-nl="<?= $h($grid['eyebrow_nl']) ?>" data-en="<?= $h($grid['eyebrow_en']) ?>"><?= $h($grid['eyebrow_nl']) ?></p><?php endif; ?>
          <?php if ($grid['title_nl'] !== ''): ?><h2 data-nl="<?= $h($grid['title_nl']) ?>" data-en="<?= $h($grid['title_en']) ?>"><?= $h($grid['title_nl']) ?></h2><?php endif; ?>
          <?php if ($grid['lead_nl'] !== ''): ?><p class="lead" style="margin-inline:auto;" data-nl="<?= $h($grid['lead_nl']) ?>" data-en="<?= $h($grid['lead_en']) ?>"><?= $h($grid['lead_nl']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="feature-grid">
          <?php foreach ($grid['items'] as $item): ?>
          <div class="feature-card" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <div class="feature-card__icon"><?= feature_grid_icon_svg($item['icon_key']) ?></div>
            <h3 data-nl="<?= $h($item['title_nl']) ?>" data-en="<?= $h($item['title_en']) ?>"><?= $h($item['title_nl']) ?></h3>
            <p data-nl="<?= $h($item['body_nl']) ?>" data-en="<?= $h($item['body_en']) ?>"><?= $h($item['body_nl']) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
