<?php

/**
 * Renders ONE "Detailsectie" block instance
 * (App\Service\DetailSectionContent) — the full-width, optionally anchored
 * content section the Diensten page is built out of.
 *
 * The markup is the `.service-detail` section diensten.php used to render
 * four times from a closed set of material keys, kept as-is (class names
 * included) so the four migrated sections render exactly as before; phase 3
 * only changed WHERE the content comes from, plus two additions this type
 * needs to be genuinely reusable: rich body content in place of the plain
 * paragraph list, and an optional main image that sits beside the text on
 * the left or the right (`image_position`).
 *
 * The main image and the "kenmerken" share the head's second column
 * (`.service-detail__aside`); `image_left` flips the two columns via a
 * modifier class on the head, so there is one markup path and no duplicated
 * "mirror" branch. With no main image — every migrated section — the aside
 * holds nothing but the points list, exactly the old two-column head.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization) — the section's, each
 * point's and each image's alt text — so this file knows no language, no
 * default and no fallback. The default language decides whether the lead,
 * the CTA and the closing note show.
 *
 * All of it is plain text except the body, which is sanitized HTML
 * (RichTextSanitizer at save time, and again on read in BlockLocalization) —
 * printed as real markup, never escaped back to plain text. Every other
 * field is escaped.
 *
 * Caller must already have checked $content['state'] !==
 * DetailSectionContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $content App\Service\DetailSectionContent::forSection()
 * @param array{index_label: string, bg_soft: bool} $markers position-derived presentation
 */
function render_section_detail_section(array $content, array $markers, string $revealGroup = 'detail-section'): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $body = $content['body'];
    $hasBody = trim($body) !== '';

    $anchor = (string) $content['anchor'];
    $hasMainImage = (string) $content['main_image_path'] !== '';
    $flip = $hasMainImage && $content['image_position'] === 'image_left';
    ?>
  <section class="service-detail<?= $markers['bg_soft'] ? ' bg-soft' : '' ?>"<?= $anchor !== '' ? ' id="' . $h($anchor) . '"' : '' ?>>
    <div class="container">
      <div class="service-detail__head<?= $flip ? ' service-detail__head--image-left' : '' ?>">
        <div data-reveal>
          <span class="service-row__index"><?= $h($markers['index_label']) ?></span>
          <h2><?= $h($content['title']) ?></h2>
          <?php if ($content['lead'] !== ''): ?>
          <p class="lead" style="margin-top:0.75rem;"><?= $h($content['lead']) ?></p>
          <?php endif; ?>
          <?php if ($hasBody): ?>
          <div class="rich-content service-detail__body"><?= $body ?></div>
          <?php endif; ?>
          <?php if ($content['cta_label'] !== ''): ?>
          <a href="<?= $h($content['cta_url']) ?>" class="btn" style="margin-top:0.5rem;"><?= $h($content['cta_label']) ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </a>
          <?php endif; ?>
        </div>
        <div class="service-detail__aside" data-reveal>
          <?php if ($hasMainImage): ?>
          <div class="service-detail__media">
            <img src="<?= $h($content['main_image_path']) ?>" alt="<?= $h($content['main_image_alt']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes(['width' => $content['main_image_width'] ?? null, 'height' => $content['main_image_height'] ?? null]) ?> loading="lazy">
          </div>
          <?php endif; ?>
          <?php if ($content['points'] !== []): ?>
          <div class="service-detail__points">
            <?php foreach ($content['points'] as $point): ?>
            <div class="service-detail__point">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <div><strong><?= $h($point['title']) ?></strong><p><?= $h($point['body']) ?></p></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($content['images'] !== []): ?>
      <div class="service-detail__gallery" data-reveal data-reveal-group="<?= $h($revealGroup) ?>-gallery">
        <?php foreach ($content['images'] as $image): ?>
        <img src="<?= $h($image['image_path']) ?>" alt="<?= $h($image['alt']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?> loading="lazy">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($content['closing_note'] !== ''): ?>
      <p class="service-detail__note" data-reveal><?= $h($content['closing_note']) ?></p>
      <?php endif; ?>
    </div>
  </section>
  <?php
}
