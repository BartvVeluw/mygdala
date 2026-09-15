<?php

/**
 * Renders the Page Hero section (App\Service\PageHeroContent) — the header at
 * the top of an ordinary page, once identical across shop.php, diensten.php,
 * portfolio.php, over-mij.php and contact.php and extracted verbatim so the
 * page builder's dynamic render loop and any future direct caller share one
 * copy. Caller must already have checked $pageHero['state'] ===
 * PageHeroContent::STATE_ACTIVE before calling this.
 *
 * The `<h1>` is what a page hero is for, so a hero without a title renders
 * nothing — never a hero band around an empty heading, and never a picture
 * band without one either. The editor requires a title and
 * PageHeroBlock::create() writes one, so only data written outside them gets
 * here.
 *
 * Everything optional leaves no trace when it is empty: no eyebrow element
 * without an eyebrow, no lead paragraph without a lead, no media wrapper
 * without an image. "Empty" is judged on what a visitor sees first, the
 * primary language's own text (SiteText::visible()): a translation on its own
 * would be an empty decoration for everyone reading the primary language.
 *
 * THE CHOICES become modifier classes through the closed maps below, and only
 * a choice that differs from its default adds one. A header that was never
 * given a choice prints the markup it always printed, which
 * assets/css/blocks/page-hero.css leaves alone.
 *
 * THE IMAGE sits behind the text, under a veil in the site's own ground
 * colour, and is loaded eagerly because it is the first thing on the page.
 * Its alt text is the library item's; an item without one renders `alt=""`,
 * which is what a photograph that only sets the mood should have
 * (App\Service\Media\BlockImage).
 *
 * $titleMaxWidthCh reproduces each page's own hand-tuned `<h1>` line-wrap
 * width (a purely cosmetic, per-page value that was never CMS content —
 * see App\Service\Blocks\PageHeroBlock); null omits
 * the inline style entirely.
 *
 * The breadcrumb is printed exactly as before. Where a breadcrumb belongs, and
 * whether a page shows one, is not this block's to decide.
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

    $hasEyebrow = \App\Service\Language\SiteText::visible($pageHero['eyebrow_nl'], $pageHero['eyebrow_en']) !== '';
    $hasLead = \App\Service\Language\SiteText::visible($pageHero['lead_nl'], $pageHero['lead_en']) !== '';
    $hasImage = (string) ($pageHero['image_path'] ?? '') !== '';

    // choice => [value => class]. The default of each choice is deliberately
    // absent, so it adds nothing; a value that is not here adds nothing either.
    $modifiers = [
        'content_position' => [
            \App\Service\PageHeroContent::POSITION_CENTER => 'page-hero--content-center',
            \App\Service\PageHeroContent::POSITION_RIGHT => 'page-hero--content-right',
        ],
        'title_size' => [
            \App\Service\PageHeroContent::SIZE_SMALL => 'page-hero--title-small',
            \App\Service\PageHeroContent::SIZE_LARGE => 'page-hero--title-large',
        ],
        'text_size' => [
            \App\Service\PageHeroContent::SIZE_SMALL => 'page-hero--text-small',
            \App\Service\PageHeroContent::SIZE_LARGE => 'page-hero--text-large',
        ],
    ];

    $classes = ['page-hero'];

    if ($hasImage) {
        $classes[] = 'page-hero--media';
    }

    foreach ($modifiers as $choice => $classForValue) {
        $class = $classForValue[(string) ($pageHero[$choice] ?? '')] ?? null;

        if ($class !== null) {
            $classes[] = $class;
        }
    }
    ?>
    <section class="<?= $h(implode(' ', $classes)) ?>">
      <?php if ($hasImage): ?>
      <div class="page-hero__media">
        <img src="<?= $h((string) $pageHero['image_path']) ?>" alt="<?= $h(\App\Service\Language\SiteText::visible($pageHero['image_alt_nl'], $pageHero['image_alt_en'])) ?>"<?= \App\Service\Language\SiteText::attrsFor('alt', $pageHero['image_alt_nl'], $pageHero['image_alt_en']) ?><?= \App\Service\Media\BlockImage::dimensionAttributes(['width' => $pageHero['image_width'] ?? null, 'height' => $pageHero['image_height'] ?? null]) ?> loading="eager" decoding="async" fetchpriority="high">
      </div>
      <?php endif; ?>
      <div class="container">
        <div class="breadcrumb">
          <a href="index.php" data-nl="Home" data-en="Home">Home</a><span>/</span><span <?= \App\Service\Language\SiteText::attrs($pageHero['breadcrumb_label_nl'], $pageHero['breadcrumb_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['breadcrumb_label_nl'], $pageHero['breadcrumb_label_en'])) ?></span>
        </div>
        <?php if ($hasEyebrow): ?>
        <p class="eyebrow" <?= \App\Service\Language\SiteText::attrs($pageHero['eyebrow_nl'], $pageHero['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['eyebrow_nl'], $pageHero['eyebrow_en'])) ?></p>
        <?php endif; ?>
        <h1<?= $titleStyle ?> <?= \App\Service\Language\SiteText::attrs($pageHero['title_nl'], $pageHero['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['title_nl'], $pageHero['title_en'])) ?></h1>
        <?php if ($hasLead): ?>
          <p class="lead" style="margin-top:1rem;" <?= \App\Service\Language\SiteText::attrs($pageHero['lead_nl'], $pageHero['lead_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($pageHero['lead_nl'], $pageHero['lead_en'])) ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
