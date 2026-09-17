<?php

/**
 * Renders the FAQ section (App\Service\FaqContent) — extracted verbatim
 * from diensten.php. Caller must already have checked $faq['state'] ===
 * FaqContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing without a heading and without a single active question —
 * the state a FAQ is in right after it is added.
 *
 * Every word arrives as one LocalizedValue per field
 * (App\Service\Blocks\BlockLocalization), the section's and each question's:
 * SiteText prints the words a visitor sees first and the escaped
 * data-nl/data-en pair for the V1 switch, so this file knows no language, no
 * default and no fallback. All of it is plain text.
 *
 * @param array<string, mixed> $faq see FaqContent::forSection()
 */
function render_section_faq(array $faq): void
{
    $text = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::visibleOf($value);
    $pair = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::attrsOf($value);

    $hasHeading = $text($faq['eyebrow']) !== '' || $text($faq['title']) !== '';

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
          <?php if ($text($faq['eyebrow']) !== ''): ?><p class="eyebrow" <?= $pair($faq['eyebrow']) ?>><?= $h($text($faq['eyebrow'])) ?></p><?php endif; ?>
          <?php if ($text($faq['title']) !== ''): ?><h2 <?= $pair($faq['title']) ?>><?= $h($text($faq['title'])) ?></h2><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="faq-list" data-reveal>
          <?php foreach ($faq['items'] as $item): ?>
          <details class="faq-item">
            <summary><span <?= $pair($item['question']) ?>><?= $h($text($item['question'])) ?></span><span class="plus" aria-hidden="true"></span></summary>
            <div class="faq-answer"><p <?= $pair($item['answer']) ?>><?= $h($text($item['answer'])) ?></p></div>
          </details>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
