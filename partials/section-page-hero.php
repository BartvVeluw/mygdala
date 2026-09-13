<?php

/**
 * Renders the Page Hero section (App\Service\PageHeroContent) — the
 * eyebrow/breadcrumb/H1/lead block identical across shop.php, diensten.php,
 * portfolio.php, over-mij.php and contact.php, extracted verbatim so the
 * page builder's dynamic render loop and any future direct caller share one
 * copy. Caller must already have checked $pageHero['state'] ===
 * PageHeroContent::STATE_ACTIVE before calling this.
 *
 * The `<h1>` is what a page hero is for, so a hero without a title renders
 * nothing — never a hero band around an empty heading. The editor requires a
 * title and PageHeroBlock::create() writes one, so only data written outside
 * them gets here.
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
    if ($pageHero['title_nl'] === '') {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $titleStyle = $titleMaxWidthCh !== null ? ' style="max-width:' . $h($titleMaxWidthCh) . ';"' : '';
    ?>
    <section class="page-hero">
      <div class="container">
        <div class="breadcrumb">
          <a href="index.php" data-nl="Home" data-en="Home">Home</a><span>/</span><span <?= \App\Service\Language\SiteText::attrs($pageHero['breadcrumb_label_nl'], $pageHero['breadcrumb_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['breadcrumb_label_nl'], $pageHero['breadcrumb_label_en'])) ?></span>
        </div>
        <p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($pageHero['eyebrow_nl'], $pageHero['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['eyebrow_nl'], $pageHero['eyebrow_en'])) ?></p>
        <h1<?= $titleStyle ?> <?= \App\Service\Language\SiteText::attrs($pageHero['title_nl'], $pageHero['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['title_nl'], $pageHero['title_en'])) ?></h1>
        <?php if ($pageHero['lead_nl'] !== ''): ?>
          <p class="lead" style="margin-top:1rem;" <?= \App\Service\Language\SiteText::attrs($pageHero['lead_nl'], $pageHero['lead_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['lead_nl'], $pageHero['lead_en'])) ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
