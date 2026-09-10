<?php

/**
 * Renders the Page Hero section (App\Service\PageHeroContent) — the
 * eyebrow/breadcrumb/H1/lead block identical across shop.php, diensten.php,
 * portfolio.php, over-mij.php and contact.php, extracted verbatim so the
 * page builder's dynamic render loop and any future direct caller share one
 * copy. Caller must already have checked $pageHero['state'] !==
 * PageHeroContent::STATE_HIDDEN before calling this.
 *
 * $titleMaxWidthCh reproduces each page's own hand-tuned `<h1>` line-wrap
 * width (a purely cosmetic, per-page value that was never CMS content —
 * see App\Service\Blocks\PageHeroBlock); null omits
 * the inline style entirely.
 *
 * @param array<string, mixed> $pageHero see PageHeroContent::forSlug()
 */
function render_section_page_hero(array $pageHero, ?string $titleMaxWidthCh = null): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $titleStyle = $titleMaxWidthCh !== null ? ' style="max-width:' . $h($titleMaxWidthCh) . ';"' : '';
    ?>
    <section class="page-hero">
      <div class="container">
        <div class="breadcrumb">
          <a href="index.php" data-nl="Home" data-en="Home">Home</a><span>/</span><span data-nl="<?= $h($pageHero['breadcrumb_label_nl']) ?>" data-en="<?= $h($pageHero['breadcrumb_label_en']) ?>"><?= $h($pageHero['breadcrumb_label_nl']) ?></span>
        </div>
        <p class="eyebrow" data-nl="<?= $h($pageHero['eyebrow_nl']) ?>" data-en="<?= $h($pageHero['eyebrow_en']) ?>"><?= $h($pageHero['eyebrow_nl']) ?></p>
        <h1<?= $titleStyle ?> data-nl="<?= $h($pageHero['title_nl']) ?>" data-en="<?= $h($pageHero['title_en']) ?>"><?= $h($pageHero['title_nl']) ?></h1>
        <?php if ($pageHero['lead_nl'] !== ''): ?>
          <p class="lead" style="margin-top:1rem;" data-nl="<?= $h($pageHero['lead_nl']) ?>" data-en="<?= $h($pageHero['lead_en']) ?>"><?= $h($pageHero['lead_nl']) ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
