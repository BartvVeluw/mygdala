<?php

declare(strict_types=1);

/**
 * A Portfolio project's fixed head: its main picture, categories, title, short
 * text, intro and description and the way back to the Portfolio (Portfolio
 * 2.0). Not a content block: portfolio-detail.php prints it above the
 * project's blocks, laid out by the project layout
 * (App\Service\PortfolioProjectLayout) and by nothing else.
 *
 * The project's extra photos are no longer part of it (Portfolio 3.0): they
 * are the Projectafbeeldingen block (partials/section-project-images.php),
 * which stands wherever the editor put it among the blocks. The head and that
 * block carry the same named lightbox group, so the main picture and the
 * photos are still one sequence.
 *
 * $imagePosition is left, right or top. "left" prints no modifier class, so
 * the project page every site already had is the same markup.
 *
 * @param array<string, mixed> $portfolioItem App\Service\PortfolioGalleryContent::itemForDetailPage()
 */
function render_project_hero(
    array $portfolioItem,
    string $imagePosition = 'left',
    ?string $backUrl = null,
    string $lightboxGroup = ''
): void {
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $projectName = trim((string) $portfolioItem['title']) !== ''
        ? (string) $portfolioItem['title']
        : \App\Service\Language\SiteText::pick(['nl' => 'Project', 'en' => 'Project']);
    $zoomLabel = static fn (string $name): string => \App\Service\Language\SiteText::pick(['nl' => 'Vergroot afbeelding: ', 'en' => 'Enlarge image: ']) . $name;
    $hasMainImage = (string) $portfolioItem['image_path'] !== '';

    $modifiers = '';
    if ($imagePosition === 'right' || $imagePosition === 'top') {
        $modifiers .= ' project-hero--image-' . $imagePosition;
    }
    ?>
  <section class="project-hero<?= $modifiers ?>" data-lightbox-group="<?= $h($lightboxGroup) ?>">
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

        <div class="project-hero__panel" data-reveal data-reveal-group="project-hero">
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
    </div>
  </section>
<?php
}
