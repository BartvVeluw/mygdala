<?php

require_once __DIR__ . '/text-image-split-media.php';

/**
 * Renders a Text + Image Split section (App\Service\TextImageSplitContent) —
 * extracted verbatim from over-mij.php's two instances. Caller must already
 * have checked $section['state'] !== TextImageSplitContent::STATE_HIDDEN
 * before calling this.
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
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section<?= $tightTop ? ' style="padding-top:0;"' : '' ?>>
      <div class="container">
        <div class="service-detail__head" style="align-items:center;">
          <?php if ($section['layout'] === 'image_left'): ?>
          <?php render_text_image_split_media($section['images'], $revealGroup); ?>
          <?php endif; ?>
          <div data-reveal>
            <?php if ($section['eyebrow_nl'] !== ''): ?>
              <p class="eyebrow" data-nl="<?= $h($section['eyebrow_nl']) ?>" data-en="<?= $h($section['eyebrow_en']) ?>"><?= $h($section['eyebrow_nl']) ?></p>
            <?php endif; ?>
            <?php if ($section['title_nl'] !== ''): ?>
              <h2 style="margin-top:0.75rem;" data-nl="<?= $h($section['title_nl']) ?>" data-en="<?= $h($section['title_en']) ?>"><?= $h($section['title_nl']) ?></h2>
            <?php endif; ?>
            <?php foreach ($section['paragraphs'] as $pIndex => $paragraph): ?>
              <?php if ($pIndex === 0 && $section['title_nl'] === ''): ?>
                <p class="lead" data-nl="<?= $h($paragraph['content_nl']) ?>" data-en="<?= $h($paragraph['content_en']) ?>"><?= $h($paragraph['content_nl']) ?></p>
              <?php else: ?>
                <p style="margin-top:1.25rem; color:var(--color-text-muted);" data-nl="<?= $h($paragraph['content_nl']) ?>" data-en="<?= $h($paragraph['content_en']) ?>"><?= $h($paragraph['content_nl']) ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($section['button_label_nl'] !== ''): ?>
              <a href="<?= $h($section['button_url']) ?>" class="btn" style="margin-top:1.5rem;" data-nl="<?= $h($section['button_label_nl']) ?>" data-en="<?= $h($section['button_label_en']) ?>"><?= $h($section['button_label_nl']) ?>
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
