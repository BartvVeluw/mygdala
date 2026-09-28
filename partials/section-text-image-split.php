<?php

require_once __DIR__ . '/eyebrow.php';
require_once __DIR__ . '/responsive-image.php';

/**
 * Renders a Tekst met afbeelding block (App\Service\TextImageSplitContent):
 * its items one under the other, each a text beside at most one picture.
 * Caller must already have checked $section['state'] ===
 * TextImageSplitContent::STATE_ACTIVE before calling this.
 *
 * Renders nothing when there is no item to show: an empty block leaves no
 * gap, the same rule as the Marquee and CTA band partials. The block's own
 * heading is the heading ABOVE its items, so without an item it is not
 * shown either.
 *
 * THE HEADING: the block's optional title (an h2) and lead above all items,
 * in the shared .section-head of every block heading. Under such a title
 * each item's own title is an h3, one level down, so the outline of the page
 * stays right; without it an item's title is the h2 it always was
 * (App\Service\Blocks\CardHeading, the rule every block of cards follows).
 * Unlike a card, an item takes the step of the type scale its tag has.
 *
 * ONE MARKUP PATH. The text comes first in the document, the picture second,
 * whatever side the picture is on: `text-image__item--image-left` only moves
 * the picture to the left on a wide screen. On a narrow screen the item is
 * one column and the text always leads, the rule the Detailsectie has too
 * (core.css, `.service-detail__head--image-left`), so a heading is never
 * buried under its picture and a stack of items keeps one rhythm.
 *
 * Everything that is a choice is a class naming a key (side, column, height,
 * a phone's own height; assets/css/blocks/text-image-split.css turns them
 * into sizes). The picture is printed by partials/responsive-image.php
 * (Responsive Media 2.0): its focus point as an inline object-position, its
 * fit, and a phone's own picture, point and fit when it has them.
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
    $blockTitle = (string) ($section['title'] ?? '');
    $blockLead = (string) ($section['lead'] ?? '');
    $itemHeading = \App\Service\Blocks\CardHeading::under($blockTitle !== '');
    ?>
    <section class="text-image"<?= $tightTop ? ' style="padding-top:0;"' : '' ?>>
      <div class="container">
        <?php if ($blockTitle !== '' || $blockLead !== ''): ?>
        <div class="section-head text-image__head" data-reveal>
          <?php if ($blockTitle !== ''): ?>
          <h2><?= $h($blockTitle) ?></h2>
          <?php endif; ?>
          <?php if ($blockLead !== ''): ?>
          <p class="lead"><?= $h($blockLead) ?></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="text-image__items">
          <?php foreach ($items as $index => $item): ?>
            <?php
            $image = $item['image'];
            $hasText = $item['eyebrow'] !== '' || $item['title'] !== '' || trim($item['body']) !== '' || $item['button_label'] !== '';
            $classes = 'text-image__item'
                . ' text-image__item--image-' . $item['image_side']
                . ' text-image__item--column-' . $item['image_column']
                . ' text-image__item--height-' . $item['image_height']
                . (($item['mobile_height'] ?? null) !== null ? ' text-image__item--mobile-' . $item['mobile_height'] : '')
                . ($image === null ? ' text-image__item--text-only' : '')
                . (!$hasText ? ' text-image__item--image-only' : '');
            $revealAttr = ' data-reveal data-reveal-group="' . $h($group . '-' . $index) . '"';
            ?>
          <div class="<?= $h($classes) ?>">
            <?php if ($hasText): ?>
            <div class="text-image__text"<?= $revealAttr ?>>
              <?php render_eyebrow($item['eyebrow']); ?>
              <?php if ($item['title'] !== ''): ?>
              <<?= $itemHeading ?>><?= $h($item['title']) ?></<?= $itemHeading ?>>
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
              <?php
                // An item read without a presentation (the block library's
                // sample) prints its picture plainly: the middle, cover.
                render_responsive_image($item['picture'] ?? (new \App\Service\Media\ResponsiveImage())->forRender($image), ['loading' => 'lazy', 'position' => 'always']);
              ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
