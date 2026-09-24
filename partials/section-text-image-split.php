<?php

require_once __DIR__ . '/eyebrow.php';

/**
 * Renders a Tekst met afbeelding block (App\Service\TextImageSplitContent):
 * its items one under the other, each a text beside at most one picture.
 * Caller must already have checked $section['state'] ===
 * TextImageSplitContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing when there is no item to show: an empty block leaves no
 * gap, the same rule as the Marquee and CTA band partials.
 *
 * ONE MARKUP PATH. The text comes first in the document, the picture second,
 * whatever side the picture is on: `text-image__item--image-left` only moves
 * the picture to the left on a wide screen. On a narrow screen the item is
 * one column and the text always leads, the rule the Detailsectie has too
 * (core.css, `.service-detail__head--image-left`), so a heading is never
 * buried under its picture and a stack of items keeps one rhythm.
 *
 * Everything that is a choice is a class naming a key (side, column, height;
 * assets/css/blocks/text-image-split.css turns them into sizes). The one
 * inline value is the picture's object-position, which
 * App\Service\Media\ImageFocus::objectPosition() derives from the item's
 * focus key, one of a closed list of nine points.
 *
 * An item without a title opens its body with the larger lead paragraph, as
 * the first paragraph of a title-less block always did. The body is
 * sanitized HTML (RichTextSanitizer, on save and on read) and printed as
 * markup; every other word is escaped. All of it arrives already in the
 * language of the request, so this file knows no language.
 *
 * $tightTop reproduces the original "intro" instance's `padding-top:0`: it
 * sits directly beneath a Page Hero, which already ends with generous bottom
 * spacing (App\Service\SectionRegistry derives it from adjacency).
 *
 * @param array<string, mixed> $section see TextImageSplitContent::forSection()
 * @param string|null $revealGroup the instance's reveal group; each item staggers its own two halves
 */
function render_section_text_image_split(array $section, bool $tightTop = false, ?string $revealGroup = null): void
{
    $items = $section['items'] ?? [];

    if ($items === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $group = $revealGroup ?? 'text-image-split';
    ?>
    <section class="text-image"<?= $tightTop ? ' style="padding-top:0;"' : '' ?>>
      <div class="container">
        <div class="text-image__items">
          <?php foreach ($items as $index => $item): ?>
            <?php
            $image = $item['image'];
            $hasText = $item['eyebrow'] !== '' || $item['title'] !== '' || trim($item['body']) !== '' || $item['button_label'] !== '';
            $classes = 'text-image__item'
                . ' text-image__item--image-' . $item['image_side']
                . ' text-image__item--column-' . $item['image_column']
                . ' text-image__item--height-' . $item['image_height']
                . ($image === null ? ' text-image__item--text-only' : '')
                . (!$hasText ? ' text-image__item--image-only' : '');
            $revealAttr = ' data-reveal data-reveal-group="' . $h($group . '-' . $index) . '"';
            ?>
          <div class="<?= $h($classes) ?>">
            <?php if ($hasText): ?>
            <div class="text-image__text"<?= $revealAttr ?>>
              <?php render_eyebrow($item['eyebrow']); ?>
              <?php if ($item['title'] !== ''): ?>
              <h2><?= $h($item['title']) ?></h2>
              <?php endif; ?>
              <?php if (trim($item['body']) !== ''): ?>
              <div class="rich-content text-image__body<?= $item['title'] === '' ? ' text-image__body--lead' : '' ?>"><?= $item['body'] ?></div>
              <?php endif; ?>
              <?php if ($item['button_label'] !== ''): ?>
              <a href="<?= $h($item['button_url']) ?>" class="btn text-image__button"><?= $h($item['button_label']) ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
              </a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($image !== null): ?>
            <div class="text-image__media"<?= $revealAttr ?>>
              <img src="<?= $h($image['image_path']) ?>" alt="<?= $h($image['alt']) ?>"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?> loading="lazy" style="object-position: <?= $h(\App\Service\Media\ImageFocus::objectPosition($item['image_focus'])) ?>;">
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
