<?php

/**
 * Renders one "Portfolio-/collectiegalerij" block
 * (App\Service\ItemGalleryContent) — the `.gallery-grid` > `.gallery-item`
 * component over whichever content source the block points at.
 *
 * One rendering path for every source: the Content class hands over an
 * already-normalised item list, so nothing here knows what a portfolio item
 * or a product is. Phase 4 of docs/content-blocks/ROADMAP.md replaced two
 * fixed, page-specific partials (section-portfolio-gallery.php on Portfolio
 * and section-portfolio-teaser.php on the homepage) with this one; the
 * differences between those two — heading, footer copy, button, filter bar,
 * lightbox, section background and top spacing — are all block settings now,
 * which is why both pages still render exactly what they did.
 *
 * A card links to its own detail page when it has one (and then carries the
 * arrow), otherwise it follows the block's `fallback_link_url`; with neither
 * it stays a plain, non-linked card, which is what makes it lightbox-able.
 *
 * A card draws only the words it has. A portfolio item's title and caption are
 * optional, so a card with neither gets no overlay at all — not an empty,
 * darkened strip over its photo — and one with only a title gets no empty
 * caption line. An empty alt text stays alt="": that marks a decorative image,
 * and nothing here invents a description from the file name.
 *
 * The lightbox OVERLAY is emitted once per page, by the first block that
 * enables it, as a sibling of the sections rather than inside one — a
 * `position: fixed` overlay inside a GSAP-transformed section would be
 * positioned against that section instead of the viewport. It carries
 * `data-item-lightbox` so assets/js/blocks/item-gallery.js can tell it apart from
 * portfolio-detail.php's own project lightbox.
 *
 * Image and item URLs are root-relative on purpose: this block may sit on
 * any CMS page, including one served from a nested path, where a relative
 * "assets/..." or "portfolio/..." would resolve against the wrong directory.
 *
 * A block whose source yields no items renders nothing at all — no empty
 * grid, no stray heading, no vertical gap — the same rule Marquee, CTA Band,
 * Contactkaart and Kaarten-carrousel follow.
 *
 * @param array<string, mixed> $content App\Service\ItemGalleryContent::forSection()
 */
function render_section_item_gallery(array $content, string $revealGroup = 'gallery'): void
{
    if ($content['items'] === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $rootPath = static fn (string $path): string => '/' . ltrim($path, '/');

    $lightbox = (bool) $content['enable_lightbox'];
    $filterCategories = $content['filter_categories'];
    $fallbackUrl = (string) $content['fallback_link_url'];

    $hasHead = $content['eyebrow_nl'] !== '' || $content['title_nl'] !== '' || $content['lead_nl'] !== '';
    $hasButton = $content['button_label_nl'] !== '' && $content['button_url'] !== '';

    $sectionAttrs = $content['background'] === 'soft' ? ' class="bg-soft"' : '';
    $sectionAttrs .= $content['tight_top'] ? ' style="padding-top:0;"' : '';
    $sectionAttrs .= ' data-gallery-block';
    $sectionAttrs .= $lightbox ? ' data-gallery-lightbox' : '';
    ?>
  <section<?= $sectionAttrs ?>>
    <div class="container">
      <?php if ($hasHead): ?>
      <div class="section-head" data-reveal>
        <?php if ($content['eyebrow_nl'] !== ''): ?>
        <p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($content['eyebrow_nl'], $content['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($content['eyebrow_nl'], $content['eyebrow_en'])) ?></p>
        <?php endif; ?>
        <?php if ($content['title_nl'] !== ''): ?>
        <h2 <?= \App\Service\Language\SiteText::attrs($content['title_nl'], $content['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($content['title_nl'], $content['title_en'])) ?></h2>
        <?php endif; ?>
        <?php if ($content['lead_nl'] !== ''): ?>
        <p class="lead" <?= \App\Service\Language\SiteText::attrs($content['lead_nl'], $content['lead_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($content['lead_nl'], $content['lead_en'])) ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($filterCategories !== []): ?>
      <div class="filter-bar" role="group" aria-label="Filter op categorie">
        <button type="button" data-filter="all" aria-pressed="true" data-nl="Alles" data-en="All">Alles</button>
        <?php foreach ($filterCategories as $filterCategory): ?>
        <button type="button" data-filter="<?= $h($filterCategory['slug']) ?>" aria-pressed="false" <?= \App\Service\Language\SiteText::attrs($filterCategory['name_nl'], $filterCategory['name_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($filterCategory['name_nl'], $filterCategory['name_en'])) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="gallery-grid">
        <?php foreach ($content['items'] as $item): ?>
        <?php
          $itemUrl = (string) $item['url'] !== '' ? (string) $item['url'] : $fallbackUrl;
          $isDetailLink = (bool) $item['is_detail_link'] && (string) $item['url'] !== '';
          $categoryAttr = (string) $item['categories'] !== ''
              ? ' data-category="' . $h((string) $item['categories']) . '"'
              : '';
          // A source may hand over an item with no photo (a product without
          // an image, say). The card then renders as the theme's empty
          // surface tile rather than a broken <img>.
          $imageTag = (string) $item['image_path'] === '' ? '' : '<img src="'
              . $h($rootPath((string) $item['image_path'])) . '" alt="' . $h((string) $item['alt_nl'])
              . '" data-nl-alt="' . $h((string) $item['alt_nl'])
              . '" data-en-alt="' . $h((string) $item['alt_en']) . '" loading="lazy">';
          // Only the words the item has, in either language: SiteText::visible()
          // already falls back to the other one, so '' means there are none.
          $itemTitle = \App\Service\Language\SiteText::visible((string) $item['title_nl'], (string) $item['title_en']);
          $itemSubtitle = \App\Service\Language\SiteText::visible((string) $item['subtitle_nl'], (string) $item['subtitle_en']);
          $overlay = '';
          if ($itemTitle !== '') {
              $overlay .= '<p ' . \App\Service\Language\SiteText::attrs((string) $item['title_nl'], (string) $item['title_en']) . '>' . $h($itemTitle) . '</p>';
          }
          if ($itemSubtitle !== '') {
              $overlay .= '<span ' . \App\Service\Language\SiteText::attrs((string) $item['subtitle_nl'], (string) $item['subtitle_en']) . '>' . $h($itemSubtitle) . '</span>';
          }
        ?>
        <?php if ($itemUrl !== ''): ?>
        <a class="gallery-item<?= $isDetailLink ? ' gallery-item--linked' : '' ?>" href="<?= $h($itemUrl) ?>"<?= $categoryAttr ?> data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
          <?= $imageTag ?>
          <?php if ($overlay !== ''): ?><span class="gallery-item__overlay"><?= $overlay ?></span><?php endif; ?>
          <?php if ($isDetailLink): ?>
          <span class="gallery-item__arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg></span>
          <?php endif; ?>
        </a>
        <?php else: ?>
        <div class="gallery-item"<?= $categoryAttr ?><?= $lightbox ? ' data-lightbox-item' : '' ?> data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
          <?= $imageTag ?>
          <?php if ($overlay !== ''): ?><span class="gallery-item__overlay"><?= $overlay ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($content['footer_note_nl'] !== ''): ?>
      <p class="lead" style="margin-top:var(--sp-6); max-width: 60ch;" data-reveal <?= \App\Service\Language\SiteText::attrs($content['footer_note_nl'], $content['footer_note_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($content['footer_note_nl'], $content['footer_note_en'])) ?></p>
      <?php endif; ?>

      <?php if ($hasButton): ?>
      <div class="text-center" style="margin-top: var(--sp-5)">
        <a href="<?= $h($content['button_url']) ?>" class="btn btn--ghost" <?= \App\Service\Language\SiteText::attrs($content['button_label_nl'], $content['button_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($content['button_label_nl'], $content['button_label_en'])) ?></a>
      </div>
      <?php endif; ?>
    </div>
  </section>
    <?php
    if ($lightbox && \App\Service\ItemGalleryContent::claimLightboxOverlay()) {
        render_item_lightbox_overlay();
    }
}

/**
 * The zoom overlay every lightbox-enabled gallery block on a page shares.
 * Printed outside any section — see the partial's docblock for why it cannot
 * live inside one — and at most once per page, which
 * App\Service\ItemGalleryContent::claimLightboxOverlay() decides.
 */
function render_item_lightbox_overlay(): void
{
    ?>
<div class="lightbox" data-item-lightbox>
  <button class="lightbox__close" aria-label="Sluiten" data-nl-aria="Sluiten" data-en-aria="Close">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <div class="lightbox__inner">
    <img src="" alt="">
    <p class="lightbox__caption"></p>
  </div>
</div>
    <?php
}
