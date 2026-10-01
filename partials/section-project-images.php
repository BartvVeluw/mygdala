<?php

declare(strict_types=1);

/**
 * The Projectafbeeldingen block (App\Service\Blocks\ProjectImagesBlock): a
 * project's extra photos, wherever the block stands on the project page. The
 * markup is the gallery the project's head used to print under itself
 * (partials/project-hero.php until Portfolio 3.0), unchanged: the same
 * classes, the same thumbnails, the same lightbox buttons and labels.
 *
 * Everything comes in as arguments: the photos as
 * App\Service\PortfolioGalleryContent::itemForDetailPage() read them for this
 * request, the project's name for the zoom labels, and the lightbox group the
 * project's head carries too, so the main picture and the photos are one
 * sequence (assets/js/lightbox.js, a named group).
 *
 * No photos, nothing at all: no empty section with its spacing.
 *
 * @param list<array{image_path: string, thumbnail_path: string, alt: string}> $images
 */
function render_section_project_images(array $images, string $projectName, string $lightboxGroup, string $revealGroup): void
{
    if ($images === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $zoomLabel = static fn (string $name): string => \App\Service\Language\SiteText::pick(['nl' => 'Vergroot afbeelding: ', 'en' => 'Enlarge image: ']) . $name;
    ?>
  <section class="project-images" data-lightbox-group="<?= $h($lightboxGroup) ?>">
    <div class="container">
      <div class="project-gallery-section">
        <h2 class="visually-hidden"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Meer afbeeldingen', 'en' => 'More images']) ?></h2>
        <div class="project-gallery">
          <?php foreach ($images as $position => $extraImage): ?>
            <?php $photoName = $extraImage['alt'] !== '' ? $extraImage['alt'] : $projectName . ' (' . ($position + 2) . ')'; ?>
            <button type="button" class="project-gallery__item" data-lightbox-trigger
              data-src="/<?= $h($extraImage['image_path']) ?>"
              data-alt="<?= $h($extraImage['alt']) ?>"
              data-caption="<?= $h($extraImage['alt']) ?>"
              aria-label="<?= $h($zoomLabel($photoName)) ?>"
              data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
              <img src="/<?= $h($extraImage['thumbnail_path']) ?>" alt="<?= $h($extraImage['alt']) ?>" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>
<?php
}
