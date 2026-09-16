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
 * an empty `<h1>`. The editor requires a title, and both the fresh-install
 * bootstrap and HomepageHeroContent::startingValues() write one, so only data
 * written outside them gets here.
 *
 * @param array<string, mixed> $hero see HomepageHeroContent::current()
 */
function render_section_homepage_hero(array $hero): void
{
    if ($hero['title_nl'] === '') {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $heroTitleFragmentNl = \App\Service\HomepageHeroContent::renderTitleFragment($hero['title_nl'], $hero['title_highlight_nl']);
    $heroTitleFragmentEn = \App\Service\HomepageHeroContent::renderTitleFragment($hero['title_en'], $hero['title_highlight_en']);
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
    $heroHasBadge = $hero['badge_title_nl'] !== '';
    $heroClasses = array_filter(['hero', $heroLayoutClass, $heroHasMedia ? '' : 'hero--no-media']);
    ?>
    <section class="<?= $h(implode(' ', $heroClasses)) ?>">
      <div class="spark-field" aria-hidden="true"></div>
      <div class="laser-line" style="top: 22%; left: 0; width: 38%" aria-hidden="true"></div>
      <div class="laser-line" style="bottom: 14%; right: 0; width: 26%" aria-hidden="true"></div>
      <div class="container hero__grid">
        <div class="hero__content">
          <p class="eyebrow hero__eyebrow" <?= \App\Service\Language\SiteText::attrs($hero['eyebrow_nl'], $hero['eyebrow_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($hero['eyebrow_nl'], $hero['eyebrow_en'])) ?></p>
          <?php /* data-lang-html: the title fragment is real HTML — the title
                   text is escaped and only the highlight is wrapped in a
                   hardcoded <em> (HomepageHeroContent::renderTitleFragment), so
                   assets/js/core.js's applyLang() re-renders it with innerHTML.
                   Every plain-text field around it stays textContent, the
                   XSS-safe default; the marker is what carves out this one. */ ?>
          <h1 style="--hero-highlight-size: <?= $heroHighlightSize ?>%" data-lang-html data-nl="<?= $h($heroTitleFragmentNl) ?>" data-en="<?= $h($heroTitleFragmentEn) ?>"><?= $heroTitleFragmentNl ?></h1>
          <p class="lead hero__lead" <?= \App\Service\Language\SiteText::attrs($hero['lead_nl'], $hero['lead_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($hero['lead_nl'], $hero['lead_en'])) ?></p>
          <div class="hero__actions">
            <a href="<?= $h($hero['primary_url']) ?>" class="btn" <?= \App\Service\Language\SiteText::attrs($hero['primary_label_nl'], $hero['primary_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($hero['primary_label_nl'], $hero['primary_label_en'])) ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </a>
            <?php if ($hero['secondary_label_nl'] !== ''): ?>
            <a href="<?= $h($hero['secondary_url']) ?>" class="btn btn--ghost" <?= \App\Service\Language\SiteText::attrs($hero['secondary_label_nl'], $hero['secondary_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($hero['secondary_label_nl'], $hero['secondary_label_en'])) ?></a>
            <?php endif; ?>
          </div>
          <div class="hero__meta">
            <?php foreach ($hero['stats'] as $stat): ?>
            <div>
              <strong <?= \App\Service\Language\SiteText::attrs($stat['primary_text_nl'], $stat['primary_text_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($stat['primary_text_nl'], $stat['primary_text_en'])) ?></strong><span <?= \App\Service\Language\SiteText::attrs($stat['secondary_text_nl'], $stat['secondary_text_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($stat['secondary_text_nl'], $stat['secondary_text_en'])) ?></span>
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
            <img src="<?= $h($hero['image_path']) ?>" alt="<?= $h($hero['image_alt_nl']) ?>" data-nl-alt="<?= $h($hero['image_alt_nl']) ?>" data-en-alt="<?= $h($hero['image_alt_en']) ?>" width="800" height="1000" loading="eager" />
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($heroHasBadge): ?>
          <div class="hero__badge">
            <strong <?= \App\Service\Language\SiteText::attrs($hero['badge_title_nl'], $hero['badge_title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($hero['badge_title_nl'], $hero['badge_title_en'])) ?></strong>
            <span <?= \App\Service\Language\SiteText::attrs($hero['badge_text_nl'], $hero['badge_text_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($hero['badge_text_nl'], $hero['badge_text_en'])) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
