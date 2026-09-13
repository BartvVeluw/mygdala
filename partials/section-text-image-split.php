<?php

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
    // button_label_nl is already '' whenever the button is half-filled —
    // TextImageSplitContent drops a label without a URL.
    $hasContent = $section['eyebrow_nl'] !== ''
        || $section['title_nl'] !== ''
        || $section['paragraphs'] !== []
        || $section['images'] !== []
        || $section['button_label_nl'] !== '';

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
            <?php if ($section['eyebrow_nl'] !== ''): ?>
              <p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($section['eyebrow_nl'], $section['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($section['eyebrow_nl'], $section['eyebrow_en'])) ?></p>
            <?php endif; ?>
            <?php if ($section['title_nl'] !== ''): ?>
              <h2 style="margin-top:0.75rem;" <?= \App\Service\Language\SiteText::attrs($section['title_nl'], $section['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($section['title_nl'], $section['title_en'])) ?></h2>
            <?php endif; ?>
            <?php foreach ($section['paragraphs'] as $pIndex => $paragraph): ?>
              <?php if ($pIndex === 0 && $section['title_nl'] === ''): ?>
                <p class="lead" <?= \App\Service\Language\SiteText::attrs($paragraph['content_nl'], $paragraph['content_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($paragraph['content_nl'], $paragraph['content_en'])) ?></p>
              <?php else: ?>
                <p style="margin-top:1.25rem; color:var(--color-text-muted);" <?= \App\Service\Language\SiteText::attrs($paragraph['content_nl'], $paragraph['content_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($paragraph['content_nl'], $paragraph['content_en'])) ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($section['button_label_nl'] !== ''): ?>
              <a href="<?= $h($section['button_url']) ?>" class="btn" style="margin-top:1.5rem;" <?= \App\Service\Language\SiteText::attrs($section['button_label_nl'], $section['button_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($section['button_label_nl'], $section['button_label_en'])) ?>
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
