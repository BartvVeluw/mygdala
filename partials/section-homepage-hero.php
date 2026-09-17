<?php

/**
 * Renders the Homepage Hero section (App\Service\HomepageHeroContent) —
 * extracted verbatim from index.php so the page builder's dynamic render
 * loop (App\Service\SectionRegistry::render()) and any future direct caller
 * share one copy of this markup. Caller must already have checked
 * $hero['state'] === HomepageHeroContent::STATE_ACTIVE before calling this.
 *
 * The headline is what the Hero is for, so a Hero without a title renders
 * nothing — never the full hero band, its decoration and its buttons around
 * an empty `<h1>`. The editor requires a title in the default language, and
 * both the fresh-install bootstrap and HomepageHeroContent::startingWords()
 * write one, so only data written outside them gets here.
 *
 * Every word arrives as one LocalizedValue per field, the stats' too
 * (App\Service\Blocks\BlockLocalization): SiteText prints the words a visitor
 * sees first and the escaped data-nl/data-en pair for the V1 switch, so this
 * file knows no language, no default and no fallback. All of it is plain
 * text except the headline, whose title and highlight are composed into one
 * safe fragment per language (HomepageHeroContent::titleHtml()).
 *
 * @param array<string, mixed> $hero see HomepageHeroContent::current()
 */
function render_section_homepage_hero(array $hero): void
{
    $text = static fn (string $field): string => \App\Service\Language\SiteText::visibleOf($hero[$field]);

    if ($text('title') === '') {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $pair = static fn (string $field): string => \App\Service\Language\SiteText::attrsOf($hero[$field]);

    $heroTitle = \App\Service\HomepageHeroContent::titleHtml($hero['title'], $hero['title_highlight']);
    $heroLayoutClasses = [
        \App\Service\HomepageHeroContent::LAYOUT_MEDIA_LEFT => 'hero--media-left',
        \App\Service\HomepageHeroContent::LAYOUT_BACKGROUND => 'hero--background',
    ];
    $heroLayoutClass = $heroLayoutClasses[$hero['layout']] ?? '';
    // A PERCENTAGE of the H1's own font size, handed to CSS as a custom
    // property rather than as a font-size — `.hero h1 em` resolves it with
    // `font-size: var(--hero-highlight-size, 100%)`, so the highlight keeps
    // inheriting the existing clamp()-based responsive headline scale at
    // every breakpoint instead of being pinned to a fixed size. Always an
    // int inside HIGHLIGHT_SIZE_MIN..MAX (clampHighlightSize also maps a
    // legacy NULL onto 100 = today's rendering), so it is safe to
    // interpolate straight into the style attribute.
    $heroHighlightSize = \App\Service\HomepageHeroContent::clampHighlightSize($hero['title_highlight_size'] ?? null);
    // A Hero with neither an image nor a video renders no media column, and
    // says so on the section so the grid can close up behind it instead of
    // leaving half the row empty. An `<img src="">` would resolve to the page
    // itself and draw the browser's broken-image icon, which is exactly what
    // a fresh installation would have shown.
    $heroHasMedia = \App\Service\HomepageHeroContent::hasMedia($hero);
    $heroHasBadge = $text('badge_title') !== '';
    $heroClasses = array_filter(['hero', $heroLayoutClass, $heroHasMedia ? '' : 'hero--no-media']);
    ?>
    <section class="<?= $h(implode(' ', $heroClasses)) ?>">
      <div class="spark-field" aria-hidden="true"></div>
      <div class="laser-line" style="top: 22%; left: 0; width: 38%" aria-hidden="true"></div>
      <div class="laser-line" style="bottom: 14%; right: 0; width: 26%" aria-hidden="true"></div>
      <div class="container hero__grid">
        <div class="hero__content">
          <p class="eyebrow hero__eyebrow" <?= $pair('eyebrow') ?>><?= $h($text('eyebrow')) ?></p>
          <?php /* data-lang-html: the title fragment is real HTML — the title
                   text is escaped and only the highlight is wrapped in a
                   hardcoded <em> (HomepageHeroContent::titleHtml()), so
                   assets/js/core.js's applyLang() re-renders it with innerHTML,
                   and it is printed unescaped for the same reason. Every
                   plain-text field around it stays textContent, the XSS-safe
                   default; the marker is what carves out this one. */ ?>
          <h1 style="--hero-highlight-size: <?= $heroHighlightSize ?>%" data-lang-html<?= \App\Service\Language\SiteText::attrsOf($heroTitle) ?>><?= \App\Service\Language\SiteText::visibleOf($heroTitle) ?></h1>
          <p class="lead hero__lead" <?= $pair('lead') ?>><?= $h($text('lead')) ?></p>
          <div class="hero__actions">
            <a href="<?= $h((string) $hero['primary_url']) ?>" class="btn" <?= $pair('primary_label') ?>><?= $h($text('primary_label')) ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </a>
            <?php if ($text('secondary_label') !== ''): ?>
            <a href="<?= $h((string) $hero['secondary_url']) ?>" class="btn btn--ghost" <?= $pair('secondary_label') ?>><?= $h($text('secondary_label')) ?></a>
            <?php endif; ?>
          </div>
          <div class="hero__meta">
            <?php foreach ($hero['stats'] as $stat): ?>
            <div>
              <strong <?= \App\Service\Language\SiteText::attrsOf($stat['primary_text']) ?>><?= $h(\App\Service\Language\SiteText::visibleOf($stat['primary_text'])) ?></strong><span <?= \App\Service\Language\SiteText::attrsOf($stat['secondary_text']) ?>><?= $h(\App\Service\Language\SiteText::visibleOf($stat['secondary_text'])) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($heroHasMedia || $heroHasBadge): ?>
        <div class="hero__media">
          <?php if ($heroHasMedia): ?>
          <div class="hero__media-frame">
            <?php if ($hero['media_type'] === \App\Service\HomepageHeroContent::MEDIA_TYPE_VIDEO): ?>
            <video class="hero__media-video" src="<?= $h($hero['video_path']) ?>"<?= $hero['image_path'] !== '' ? ' poster="' . $h($hero['image_path']) . '"' : '' ?> autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>
            <?php else: ?>
            <img src="<?= $h($hero['image_path']) ?>" alt="<?= $h($text('image_alt')) ?>"<?= \App\Service\Language\SiteText::attrsForOf('alt', $hero['image_alt']) ?> width="800" height="1000" loading="eager" />
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($heroHasBadge): ?>
          <div class="hero__badge">
            <strong <?= $pair('badge_title') ?>><?= $h($text('badge_title')) ?></strong>
            <span <?= $pair('badge_text') ?>><?= $h($text('badge_text')) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
