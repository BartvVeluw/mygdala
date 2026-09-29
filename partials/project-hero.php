<?php

declare(strict_types=1);

/**
 * A Portfolio project's own head: its main picture, categories, title, short
 * text, intro and description, the way back to the Portfolio, and its extra
 * photos — one lightbox group (Portfolio 2.0).
 *
 * TWO CALLERS, ONE MARKUP (Portfolio layout 2.0, App\Service\PortfolioProjectLayout):
 * portfolio-detail.php for a project whose layout puts the picture on the
 * left, on the right or on top, and the Projectinformatie block
 * (App\Service\Blocks\ProjectInfoBlock) wherever an editor places it on a
 * project with the free layout. Both hand in the project as
 * App\Service\PortfolioGalleryContent::itemForDetailPage() read it for this
 * request — live, in the request's language; nothing here is a copy.
 *
 * $imagePosition is left, right or top. "left" prints no modifier class, so
 * the project page every site already had is the same markup, byte for byte.
 * $revealGroup null keeps the page's own reveal groups ("project-hero",
 * "project-gallery"); a block passes its instance's own, so two blocks never
 * share a stagger (CONTENT-BLOCKS.md, "Instantie-identiteit"). $inFlow marks a
 * head that is not the page's first section: ordinary section spacing
 * instead of the room a page head leaves under the site header.
 *
 * @param array<string, mixed> $portfolioItem itemForDetailPage()
 */
function render_project_hero(
    array $portfolioItem,
    string $imagePosition = 'left',
    bool $showGallery = true,
    ?string $backUrl = null,
    ?string $revealGroup = null,
    bool $inFlow = false
): void {
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $projectName = trim((string) $portfolioItem['title']) !== ''
        ? (string) $portfolioItem['title']
        : \App\Service\Language\SiteText::pick(['nl' => 'Project', 'en' => 'Project']);
    $zoomLabel = static fn (string $name): string => \App\Service\Language\SiteText::pick(['nl' => 'Vergroot afbeelding: ', 'en' => 'Enlarge image: ']) . $name;
    $hasMainImage = (string) $portfolioItem['image_path'] !== '';
    $heroGroup = $revealGroup ?? 'project-hero';
    $galleryGroup = $revealGroup === null ? 'project-gallery' : $revealGroup . '-gallery';

    $modifiers = '';
    if ($imagePosition === 'right' || $imagePosition === 'top') {
        $modifiers .= ' project-hero--image-' . $imagePosition;
    }
    if ($inFlow) {
        $modifiers .= ' project-hero--in-flow';
    }
    ?>
  <section class="project-hero<?= $modifiers ?>" data-lightbox-group>
    <div class="container">
      <div class="project-hero__grid">
        <?php if ($hasMainImage): ?>
        <figure class="project-hero__media" data-reveal>
          <button type="button" class="project-hero__zoom" data-lightbox-trigger
            data-src="/<?= $h($portfolioItem['image_path']) ?>"
            data-alt="<?= $h($portfolioItem['alt']) ?>"
            data-caption="<?= $h($portfolioItem['alt']) ?>"
            aria-label="<?= $h($zoomLabel($projectName)) ?>">
            <img src="/<?= $h($portfolioItem['image_path']) ?>" alt="<?= $h($portfolioItem['alt']) ?>" class="project-hero__image" fetchpriority="high">
          </button>
        </figure>
        <?php endif; ?>

        <div class="project-hero__panel" data-reveal data-reveal-group="<?= $h($heroGroup) ?>">
          <?php if ($portfolioItem['categories'] !== []): ?>
            <ul class="tag-list">
              <?php foreach ($portfolioItem['categories'] as $category): ?>
                <li class="tag"><?= $h($category['name']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <h1 class="project-hero__title"><?= $h($projectName) ?></h1>

          <?php if ($portfolioItem['subtitle'] !== ''): ?>
            <p class="lead project-hero__subtitle"><?= $h($portfolioItem['subtitle']) ?></p>
          <?php endif; ?>

          <span class="project-hero__divider" aria-hidden="true"></span>

          <?php if ($portfolioItem['intro'] !== ''): ?>
            <?php
              // `intro` and `description` are already sanitized HTML in the
              // language of the request (RichTextSanitizer, both at save time
              // and again in App\Service\PortfolioLocalization::itemRich()) —
              // rendered here as real markup, never escaped back to plain
              // text.
            ?>
            <div class="rich-content rich-content--intro"><?= $portfolioItem['intro'] ?></div>
          <?php endif; ?>
          <?php if ($portfolioItem['description'] !== ''): ?>
            <div class="rich-content"><?= $portfolioItem['description'] ?></div>
          <?php endif; ?>

          <?php if ($backUrl !== null): ?>
            <a class="project-hero__back" href="<?= $h($backUrl) ?>"><?= \App\Service\Language\SiteText::escaped(['nl' => '← Terug naar portfolio', 'en' => '← Back to portfolio']) ?></a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($showGallery && $portfolioItem['images'] !== []): ?>
      <div class="project-gallery-section">
        <h2 class="visually-hidden"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Meer afbeeldingen', 'en' => 'More images']) ?></h2>
        <div class="project-gallery">
          <?php foreach ($portfolioItem['images'] as $position => $extraImage): ?>
            <?php $photoName = $extraImage['alt'] !== '' ? $extraImage['alt'] : $projectName . ' (' . ($position + 2) . ')'; ?>
            <button type="button" class="project-gallery__item" data-lightbox-trigger
              data-src="/<?= $h($extraImage['image_path']) ?>"
              data-alt="<?= $h($extraImage['alt']) ?>"
              data-caption="<?= $h($extraImage['alt']) ?>"
              aria-label="<?= $h($zoomLabel($photoName)) ?>"
              data-reveal data-reveal-group="<?= $h($galleryGroup) ?>">
              <img src="/<?= $h($extraImage['thumbnail_path']) ?>" alt="<?= $h($extraImage['alt']) ?>" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>
<?php
}
