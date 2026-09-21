<?php

/**
 * Renders the FAQ section (App\Service\FaqContent) — extracted verbatim
 * from diensten.php. Caller must already have checked $faq['state'] ===
 * FaqContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing without a heading and without a single active question —
 * the state a FAQ is in right after it is added.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization) — the section's and each
 * question's — so this file knows no language, no default and no fallback.
 * All of it is plain text.
 *
 * @param array<string, mixed> $faq see FaqContent::forSection()
 */
function render_section_faq(array $faq): void
{

    $hasHeading = $faq['eyebrow'] !== '' || $faq['title'] !== '';

    if (!$hasHeading && $faq['items'] === []) {
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
          <?php if ($faq['eyebrow'] !== ''): ?><p class="eyebrow"><?= $h($faq['eyebrow']) ?></p><?php endif; ?>
          <?php if ($faq['title'] !== ''): ?><h2><?= $h($faq['title']) ?></h2><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="faq-list" data-reveal>
          <?php foreach ($faq['items'] as $item): ?>
          <details class="faq-item">
            <summary><span><?= $h($item['question']) ?></span><span class="plus" aria-hidden="true"></span></summary>
            <div class="faq-answer"><p><?= $h($item['answer']) ?></p></div>
          </details>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
