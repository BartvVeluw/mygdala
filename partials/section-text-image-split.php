<?php

require_once __DIR__ . '/eyebrow.php';
require_once __DIR__ . '/text-image-split-media.php';

/**
 * Renders a Text + Image Split section (App\Service\TextImageSplitContent) —
 * extracted verbatim from over-mij.php's two instances. Caller must already
 * have checked $section['state'] === TextImageSplitContent::STATE_ACTIVE
 * before calling this.
 *
 * Renders nothing unless there is an eyebrow, a title, a paragraph, an image
 * or a button. `layout` only decides where things go, so a section with
 * nothing but a layout — one that was just added — has nothing to show.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization) — the section's and each
 * paragraph's — so this file knows no language, no default and no fallback.
 * All of it is plain text.
 *
 * $tightTop reproduces the original "intro" instance's `padding-top:0` —
 * that instance sits directly beneath a Page Hero, which already ends with
 * generous bottom spacing, so the section immediately below always
 * originally omitted its own top padding whenever it followed a hero. The
 * page-builder render loop (App\Service\SectionRegistry) derives this from
 * actual adjacency at render time rather than hardcoding it to one instance,
 * so it keeps applying correctly if sections are reordered.
 *
 * @param array<string, mixed> $section see TextImageSplitContent::forSection()
 * @param string|null $revealGroup optional data-reveal-group for the image gallery variant
 */
function render_section_text_image_split(array $section, bool $tightTop = false, ?string $revealGroup = null): void
{

    // The button label is already empty whenever the button is half-filled —
    // TextImageSplitContent drops a label without a URL.
    $hasContent = $section['eyebrow'] !== ''
        || $section['title'] !== ''
        || $section['paragraphs'] !== []
        || $section['images'] !== []
        || $section['button_label'] !== '';

    if (!$hasContent) {
        // Nothing to show yet: an empty block leaves no gap, the same rule as
        // the Marquee and CTA band partials.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section<?= $tightTop ? ' style="padding-top:0;"' : '' ?>>
      <div class="container">
        <div class="service-detail__head" style="align-items:center;">
          <?php if ($section['layout'] === 'image_left'): ?>
          <?php render_text_image_split_media($section['images'], $revealGroup); ?>
          <?php endif; ?>
          <div data-reveal>
            <?php render_eyebrow($section['eyebrow']); ?>
            <?php if ($section['title'] !== ''): ?>
              <h2<?= $section['eyebrow'] !== '' ? ' style="margin-top:0.75rem;"' : '' ?>><?= $h($section['title']) ?></h2>
            <?php endif; ?>
            <?php foreach ($section['paragraphs'] as $pIndex => $paragraph): ?>
              <?php if ($pIndex === 0 && $section['title'] === ''): ?>
                <p class="lead"><?= $h($paragraph['content']) ?></p>
              <?php else: ?>
                <p style="margin-top:1.25rem; color:var(--color-text-muted);"><?= $h($paragraph['content']) ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($section['button_label'] !== ''): ?>
              <a href="<?= $h($section['button_url']) ?>" class="btn" style="margin-top:1.5rem;"><?= $h($section['button_label']) ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
              </a>
            <?php endif; ?>
          </div>
          <?php if ($section['layout'] !== 'image_left'): ?>
          <?php render_text_image_split_media($section['images'], $revealGroup); ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php
}
