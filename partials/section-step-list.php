<?php

/**
 * Renders the Step List / Werkwijze section (App\Service\StepListContent) —
 * extracted verbatim from index.php. Step numbers are rendered purely by a
 * CSS counter on `.process` (see StepListContent's docblock), never stored.
 * Caller must already have checked $stepList['state'] !==
 * StepListContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $stepList see StepListContent::forSection()
 */
function render_section_step_list(array $stepList): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $hasHeading = $stepList['eyebrow_nl'] !== '' || $stepList['title_nl'] !== '';
    ?>
    <section>
      <div class="container">
        <?php if ($hasHeading): ?>
        <div class="section-head center" data-reveal>
          <?php if ($stepList['eyebrow_nl'] !== ''): ?><p class="eyebrow" data-nl="<?= $h($stepList['eyebrow_nl']) ?>" data-en="<?= $h($stepList['eyebrow_en']) ?>"><?= $h($stepList['eyebrow_nl']) ?></p><?php endif; ?>
          <?php if ($stepList['title_nl'] !== ''): ?><h2 data-nl="<?= $h($stepList['title_nl']) ?>" data-en="<?= $h($stepList['title_en']) ?>"><?= $h($stepList['title_nl']) ?></h2><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="process">
          <?php foreach ($stepList['items'] as $step): ?>
          <div class="process-step" data-reveal data-reveal-group="process">
            <h3 data-nl="<?= $h($step['title_nl']) ?>" data-en="<?= $h($step['title_en']) ?>"><?= $h($step['title_nl']) ?></h3>
            <p data-nl="<?= $h($step['body_nl']) ?>" data-en="<?= $h($step['body_en']) ?>"><?= $h($step['body_nl']) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
