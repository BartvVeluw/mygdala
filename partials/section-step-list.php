<?php

/**
 * Renders the Step List / Werkwijze section (App\Service\StepListContent) —
 * extracted verbatim from index.php. Step numbers are rendered purely by a
 * CSS counter on `.process` (see StepListContent's docblock), never stored.
 * Caller must already have checked $stepList['state'] ===
 * StepListContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing without a heading and without a single active step — the
 * state a step list is in right after it is added.
 *
 * @param array<string, mixed> $stepList see StepListContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_step_list(array $stepList, string $revealGroup = 'process'): void
{
    $hasHeading = $stepList['eyebrow_nl'] !== '' || $stepList['title_nl'] !== '';

    if (!$hasHeading && $stepList['items'] === []) {
        // Nothing to show yet: an empty block leaves no gap, the same rule as
        // the Marquee and CTA band partials.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section>
      <div class="container">
        <?php if ($hasHeading): ?>
        <div class="section-head center" data-reveal>
          <?php if ($stepList['eyebrow_nl'] !== ''): ?><p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($stepList['eyebrow_nl'], $stepList['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($stepList['eyebrow_nl'], $stepList['eyebrow_en'])) ?></p><?php endif; ?>
          <?php if ($stepList['title_nl'] !== ''): ?><h2 <?= \App\Service\Language\SiteText::attrs($stepList['title_nl'], $stepList['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($stepList['title_nl'], $stepList['title_en'])) ?></h2><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="process">
          <?php foreach ($stepList['items'] as $step): ?>
          <div class="process-step" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <h3 <?= \App\Service\Language\SiteText::attrs($step['title_nl'], $step['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($step['title_nl'], $step['title_en'])) ?></h3>
            <p <?= \App\Service\Language\SiteText::attrs($step['body_nl'], $step['body_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($step['body_nl'], $step['body_en'])) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
