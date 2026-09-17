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
 * default language's words (SiteText::visibleOf()): a translation on its own
 * would be an empty decoration for everyone reading the default language.
 *
 * Every word arrives as one LocalizedValue per field
 * (App\Service\Blocks\BlockLocalization): SiteText prints the words a visitor
 * sees first and the escaped data-nl/data-en pair for the V1 switch, so this
 * file knows no language, no default and no fallback. All of it is plain
 * text, so nothing here is marked data-lang-html.
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
 * THE BREADCRUMB IS NOT HERE and must not come back. It is the page's own
 * navigation, printed by the route before its content
 * (partials/breadcrumb.php), so a page whose header is hidden, deleted or
 * never added still tells a visitor where they are. See HEADER-FOOTER.md.
 *
 * @param array<string, mixed> $pageHero see PageHeroContent::forSlug()
 */
function render_section_page_hero(array $pageHero, ?string $titleMaxWidthCh = null): void
{
    $text = static fn (string $field): string => \App\Service\Language\SiteText::visibleOf($pageHero[$field]);

    if ($text('title') === '') {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $pair = static fn (string $field): string => \App\Service\Language\SiteText::attrsOf($pageHero[$field]);
    $titleStyle = $titleMaxWidthCh !== null ? ' style="max-width:' . $h($titleMaxWidthCh) . ';"' : '';

    $hasEyebrow = $text('eyebrow') !== '';
    $hasLead = $text('lead') !== '';
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
        <img src="<?= $h((string) $pageHero['image_path']) ?>" alt="<?= $h($text('image_alt')) ?>"<?= \App\Service\Language\SiteText::attrsForOf('alt', $pageHero['image_alt']) ?><?= \App\Service\Media\BlockImage::dimensionAttributes(['width' => $pageHero['image_width'] ?? null, 'height' => $pageHero['image_height'] ?? null]) ?> loading="eager" decoding="async" fetchpriority="high">
      </div>
      <?php endif; ?>
      <div class="container">
        <?php if ($hasEyebrow): ?>
        <p class="eyebrow" <?= $pair('eyebrow') ?>><?= $h($text('eyebrow')) ?></p>
        <?php endif; ?>
        <h1<?= $titleStyle ?> <?= $pair('title') ?>><?= $h($text('title')) ?></h1>
        <?php if ($hasLead): ?>
          <p class="lead" style="margin-top:1rem;" <?= $pair('lead') ?>><?= $h($text('lead')) ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
