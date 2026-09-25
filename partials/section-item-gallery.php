<?php

require_once __DIR__ . '/eyebrow.php';
require_once __DIR__ . '/lightbox.php';

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
 * arrow), otherwise it follows the block's `fallback_link_url` — unless its
 * source decides every card's link itself and says so per item
 * (`follows_fallback_link` false); with neither it stays a plain, non-linked
 * card, which is what makes it lightbox-able.
 *
 * A ZOOMABLE CARD's picture is a real <button> that opens the lightbox
 * (assets/js/lightbox.js): every plain card while the block's lightbox
 * setting is on, and every card whose source asks for it (`opens_lightbox`,
 * which every Portfolio item does since Portfolio 2.0, whatever the block
 * says). Such a card may also carry a separate call to action (`cta`: a url
 * and a label, "Bekijk project" for a Portfolio item) — a real <a> in the
 * overlay, so the picture always zooms and only the button navigates. On a
 * screen without hover that overlay stays visible, so the button is always
 * reachable (assets/css/blocks/item-gallery.css).
 *
 * The overlay reads top to bottom: title, then the short text, then the call
 * to action.
 *
 * A card draws only the words it has. A portfolio item's title and caption are
 * optional, so a card with neither gets no overlay at all — not an empty,
 * darkened strip over its photo — and one with only a title gets no empty
 * caption line. An empty alt text stays alt="": that marks a decorative image,
 * and nothing here invents a description from the file name.
 *
 * The lightbox OVERLAY (partials/lightbox.php) is emitted once per page, by
 * the first block with a zoomable card, as a sibling of the sections rather
 * than inside one — a `position: fixed` overlay inside a GSAP-transformed
 * section would be positioned against that section instead of the viewport.
 * Each block is its own lightbox group (data-lightbox-group), so previous and
 * next step through the cards of this block that are shown right now, and a
 * category filter changes that sequence with it.
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

    // The block's own words arrive as one string per field, already in the
    // language of the request (App\Service\Blocks\BlockLocalization), all
    // plain text. So do the items' words, whichever source they come from: a
    // Portfolio item (App\Service\PortfolioLocalization) or a product
    // (App\Service\ShopLocalization). This partial knows no language, no
    // default and no fallback.
    $text = static fn (string $field): string => $content[$field];

    $hasHead = $text('eyebrow') !== '' || $text('title') !== '' || $text('lead') !== '';
    $hasButton = $text('button_label') !== '' && $content['button_url'] !== '';

    $sectionAttrs = $content['background'] === 'soft' ? ' class="bg-soft"' : '';
    $sectionAttrs .= $content['tight_top'] ? ' style="padding-top:0;"' : '';
    $sectionAttrs .= ' data-gallery-block data-lightbox-group';
    $sectionAttrs .= $lightbox ? ' data-gallery-lightbox' : '';
    $printsOverlay = false;
    ?>
  <section<?= $sectionAttrs ?>>
    <div class="container">
      <?php if ($hasHead): ?>
      <div class="section-head" data-reveal>
        <?php render_eyebrow($text('eyebrow')); ?>
        <?php if ($text('title') !== ''): ?>
        <h2><?= $h($text('title')) ?></h2>
        <?php endif; ?>
        <?php if ($text('lead') !== ''): ?>
        <p class="lead"><?= $h($text('lead')) ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($filterCategories !== []): ?>
      <div class="filter-bar" role="group" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Filter op categorie', 'en' => 'Filter by category']) ?>">
        <button type="button" data-filter="all" aria-pressed="true"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Alles', 'en' => 'All']) ?></button>
        <?php foreach ($filterCategories as $filterCategory): ?>
        <button type="button" data-filter="<?= $h($filterCategory['slug']) ?>" aria-pressed="false"><?= $h($filterCategory['name']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="gallery-grid">
        <?php foreach ($content['items'] as $item): ?>
        <?php
          // A card without a URL of its own follows the block's fallback link,
          // unless its source decides every card's link itself and says so per
          // item (`follows_fallback_link` false): such a card stays plain.
          $followsFallbackLink = (bool) ($item['follows_fallback_link'] ?? true);
          $itemUrl = (string) $item['url'] !== '' ? (string) $item['url'] : ($followsFallbackLink ? $fallbackUrl : '');
          $isDetailLink = (bool) $item['is_detail_link'] && (string) $item['url'] !== '';
          $categoryAttr = (string) $item['categories'] !== ''
              ? ' data-category="' . $h((string) $item['categories']) . '"'
              : '';
          // A source may hand over an item with no photo (a product without
          // an image, say). The card then renders as the theme's empty
          // surface tile rather than a broken <img>.
          $imageTag = (string) $item['image_path'] === '' ? '' : '<img src="'
              . $h($rootPath((string) $item['image_path'])) . '" alt="' . $h($item['alt'])
              . '" loading="lazy">';
          // Only the words the item has: the fallback of its source is
          // applied already, so '' means there are none.
          $itemTitle = $item['title'];
          $itemSubtitle = $item['subtitle'];
          $overlay = '';
          if ($itemTitle !== '') {
              $overlay .= '<p class="gallery-item__title">' . $h($itemTitle) . '</p>';
          }
          if ($itemSubtitle !== '') {
              $overlay .= '<span class="gallery-item__text">' . $h($itemSubtitle) . '</span>';
          }
          // A zoomable card: a photo the lightbox enlarges, and at most one
          // separate call to action. Never both a link card and a zoom.
          $zooms = $itemUrl === '' && (string) $item['image_path'] !== ''
              && ($lightbox || !empty($item['opens_lightbox']));
          $cta = $zooms && is_array($item['cta'] ?? null) && (string) ($item['cta']['url'] ?? '') !== ''
              ? $item['cta']
              : null;
          if ($cta !== null) {
              $overlay .= '<a class="gallery-item__cta" href="' . $h((string) $cta['url']) . '">'
                  . $h((string) $cta['label'])
                  . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'
                  . '</a>';
          }
          $printsOverlay = $printsOverlay || $zooms;
          // What a screen reader hears on the zoom button: the picture it
          // enlarges, by its title, else its alt text.
          $zoomName = $itemTitle !== '' ? $itemTitle : (string) $item['alt'];
          $zoomLabel = $zoomName !== ''
              ? \App\Service\Language\SiteText::pick(['nl' => 'Vergroot afbeelding: ', 'en' => 'Enlarge image: ']) . $zoomName
              : \App\Service\Language\SiteText::pick(['nl' => 'Vergroot afbeelding', 'en' => 'Enlarge image']);
        ?>
        <?php if ($itemUrl !== ''): ?>
        <a class="gallery-item<?= $isDetailLink ? ' gallery-item--linked' : '' ?>" href="<?= $h($itemUrl) ?>"<?= $categoryAttr ?> data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
          <?= $imageTag ?>
          <?php if ($overlay !== ''): ?><span class="gallery-item__overlay"><?= $overlay ?></span><?php endif; ?>
          <?php if ($isDetailLink): ?>
          <span class="gallery-item__arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg></span>
          <?php endif; ?>
        </a>
        <?php elseif ($zooms): ?>
        <div class="gallery-item gallery-item--zoom<?= $cta !== null ? ' gallery-item--has-cta' : '' ?>"<?= $categoryAttr ?> data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
          <button type="button" class="gallery-item__zoom" data-lightbox-trigger
            data-src="<?= $h($rootPath((string) $item['image_path'])) ?>"
            data-alt="<?= $h((string) $item['alt']) ?>"
            data-caption="<?= $h($itemTitle) ?>"
            aria-label="<?= $h($zoomLabel) ?>"><?= $imageTag ?></button>
          <?php if ($overlay !== ''): ?><span class="gallery-item__overlay"><?= $overlay ?></span><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="gallery-item"<?= $categoryAttr ?> data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
          <?= $imageTag ?>
          <?php if ($overlay !== ''): ?><span class="gallery-item__overlay"><?= $overlay ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($text('footer_note') !== ''): ?>
      <p class="lead" style="margin-top:var(--sp-6); max-width: 60ch;" data-reveal><?= $h($text('footer_note')) ?></p>
      <?php endif; ?>

      <?php if ($hasButton): ?>
      <div class="text-center" style="margin-top: var(--sp-5)">
        <a href="<?= $h($content['button_url']) ?>" class="btn btn--ghost"><?= $h($text('button_label')) ?></a>
      </div>
      <?php endif; ?>
    </div>
  </section>
    <?php
    // The overlay every zoomable card on a page shares, printed outside any
    // section and at most once per page.
    if ($printsOverlay && \App\Service\ItemGalleryContent::claimLightboxOverlay()) {
        render_lightbox_overlay();
    }
}
