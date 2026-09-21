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
 * arrow), otherwise it follows the block's `fallback_link_url` — unless its
 * source decides every card's link itself and says so per item
 * (`follows_fallback_link` false); with neither it stays a plain, non-linked
 * card, which is what makes it lightbox-able.
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
    $sectionAttrs .= ' data-gallery-block';
    $sectionAttrs .= $lightbox ? ' data-gallery-lightbox' : '';
    ?>
  <section<?= $sectionAttrs ?>>
    <div class="container">
      <?php if ($hasHead): ?>
      <div class="section-head" data-reveal>
        <?php if ($text('eyebrow') !== ''): ?>
        <p class="eyebrow"><?= $h($text('eyebrow')) ?></p>
        <?php endif; ?>
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
              $overlay .= '<p>' . $h($itemTitle) . '</p>';
          }
          if ($itemSubtitle !== '') {
              $overlay .= '<span>' . $h($itemSubtitle) . '</span>';
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
  <button class="lightbox__close" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Sluiten', 'en' => 'Close']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <div class="lightbox__inner">
    <img src="" alt="">
    <p class="lightbox__caption"></p>
  </div>
</div>
    <?php
}
