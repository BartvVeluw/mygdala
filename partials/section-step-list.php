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
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization) — the section's and each
 * step's — so this file knows no language, no default and no fallback. All of
 * it is plain text.
 *
 * @param array<string, mixed> $stepList see StepListContent::forSection()
 * @param string $revealGroup unique data-reveal-group value for this instance's stagger animation
 */
function render_section_step_list(array $stepList, string $revealGroup = 'process'): void
{

    $hasHeading = $stepList['eyebrow'] !== '' || $stepList['title'] !== '';

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
          <?php if ($stepList['eyebrow'] !== ''): ?><p class="eyebrow"><?= $h($stepList['eyebrow']) ?></p><?php endif; ?>
          <?php if ($stepList['title'] !== ''): ?><h2><?= $h($stepList['title']) ?></h2><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="process">
          <?php foreach ($stepList['items'] as $step): ?>
          <div class="process-step" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <h3><?= $h($step['title']) ?></h3>
            <p><?= $h($step['body']) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
