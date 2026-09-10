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
 * Caller must already have checked $content['state'] !==
 * DetailSectionContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $content App\Service\DetailSectionContent::forSection()
 * @param array{index_label: string, bg_soft: bool} $markers position-derived presentation
 */
function render_section_detail_section(array $content, array $markers, string $revealGroup = 'detail-section'): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $hasBody = trim((string) $content['content_html']) !== '';
    $englishBody = trim((string) $content['content_html_en']);
    $bodyLangAttributes = '';
    if ($hasBody && $englishBody !== '' && $englishBody !== trim((string) $content['content_html'])) {
        $bodyLangAttributes = ' data-nl="' . $h((string) $content['content_html']) . '" data-en="' . $h($englishBody) . '"';
    }

    $anchor = (string) $content['anchor'];
    $hasMainImage = (string) $content['main_image_path'] !== '';
    $flip = $hasMainImage && $content['image_position'] === 'image_left';
    ?>
  <section class="service-detail<?= $markers['bg_soft'] ? ' bg-soft' : '' ?>"<?= $anchor !== '' ? ' id="' . $h($anchor) . '"' : '' ?>>
    <div class="container">
      <div class="service-detail__head<?= $flip ? ' service-detail__head--image-left' : '' ?>">
        <div data-reveal>
          <span class="service-row__index"><?= $h($markers['index_label']) ?></span>
          <h2 data-nl="<?= $h($content['title_nl']) ?>" data-en="<?= $h($content['title_en']) ?>"><?= $h($content['title_nl']) ?></h2>
          <?php if ($content['lead_nl'] !== ''): ?>
          <p class="lead" style="margin-top:0.75rem;" data-nl="<?= $h($content['lead_nl']) ?>" data-en="<?= $h($content['lead_en']) ?>"><?= $h($content['lead_nl']) ?></p>
          <?php endif; ?>
          <?php if ($hasBody): ?>
          <div class="rich-content service-detail__body"<?= $bodyLangAttributes ?>><?= $content['content_html'] ?></div>
          <?php endif; ?>
          <?php if ($content['cta_label_nl'] !== ''): ?>
          <a href="<?= $h($content['cta_url']) ?>" class="btn" style="margin-top:0.5rem;" data-nl="<?= $h($content['cta_label_nl']) ?>" data-en="<?= $h($content['cta_label_en']) ?>"><?= $h($content['cta_label_nl']) ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </a>
          <?php endif; ?>
        </div>
        <div class="service-detail__aside" data-reveal>
          <?php if ($hasMainImage): ?>
          <div class="service-detail__media">
            <img src="<?= $h($content['main_image_path']) ?>" alt="<?= $h($content['main_image_alt_nl']) ?>" data-nl-alt="<?= $h($content['main_image_alt_nl']) ?>" data-en-alt="<?= $h($content['main_image_alt_en']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes(['width' => $content['main_image_width'] ?? null, 'height' => $content['main_image_height'] ?? null]) ?> loading="lazy">
          </div>
          <?php endif; ?>
          <?php if ($content['points'] !== []): ?>
          <div class="service-detail__points">
            <?php foreach ($content['points'] as $point): ?>
            <div class="service-detail__point">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <div><strong data-nl="<?= $h($point['title_nl']) ?>" data-en="<?= $h($point['title_en']) ?>"><?= $h($point['title_nl']) ?></strong><p data-nl="<?= $h($point['body_nl']) ?>" data-en="<?= $h($point['body_en']) ?>"><?= $h($point['body_nl']) ?></p></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($content['images'] !== []): ?>
      <div class="service-detail__gallery" data-reveal data-reveal-group="<?= $h($revealGroup) ?>-gallery">
        <?php foreach ($content['images'] as $image): ?>
        <img src="<?= $h($image['image_path']) ?>" alt="<?= $h($image['alt_nl']) ?>" data-nl-alt="<?= $h($image['alt_nl']) ?>" data-en-alt="<?= $h($image['alt_en']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?> loading="lazy">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($content['closing_note_nl'] !== ''): ?>
      <p class="service-detail__note" data-reveal data-nl="<?= $h($content['closing_note_nl']) ?>" data-en="<?= $h($content['closing_note_en']) ?>"><?= $h($content['closing_note_nl']) ?></p>
      <?php endif; ?>
    </div>
  </section>
  <?php
}
