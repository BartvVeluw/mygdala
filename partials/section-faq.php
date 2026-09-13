<?php

/**
 * Renders the FAQ section (App\Service\FaqContent) — extracted verbatim
 * from diensten.php. Caller must already have checked $faq['state'] ===
 * FaqContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing without a heading and without a single active question —
 * the state a FAQ is in right after it is added.
 *
 * @param array<string, mixed> $faq see FaqContent::forSection()
 */
function render_section_faq(array $faq): void
{
    $hasHeading = $faq['eyebrow_nl'] !== '' || $faq['title_nl'] !== '';

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
          <?php if ($faq['eyebrow_nl'] !== ''): ?><p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($faq['eyebrow_nl'], $faq['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($faq['eyebrow_nl'], $faq['eyebrow_en'])) ?></p><?php endif; ?>
          <?php if ($faq['title_nl'] !== ''): ?><h2 <?= \App\Service\Language\SiteText::attrs($faq['title_nl'], $faq['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($faq['title_nl'], $faq['title_en'])) ?></h2><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="faq-list" data-reveal>
          <?php foreach ($faq['items'] as $item): ?>
          <details class="faq-item">
            <summary><span <?= \App\Service\Language\SiteText::attrs($item['question_nl'], $item['question_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($item['question_nl'], $item['question_en'])) ?></span><span class="plus" aria-hidden="true"></span></summary>
            <div class="faq-answer"><p <?= \App\Service\Language\SiteText::attrs($item['answer_nl'], $item['answer_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($item['answer_nl'], $item['answer_en'])) ?></p></div>
          </details>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
