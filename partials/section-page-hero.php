<?php

require_once __DIR__ . '/eyebrow.php';
require_once __DIR__ . '/breadcrumb.php';
require_once __DIR__ . '/media-sequence.php';

use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Language\SiteText;
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaType;
use App\Service\PageHeroContent;

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
 * without an eyebrow (partials/eyebrow.php), no lead paragraph without a
 * lead, no media wrapper and no picture class without a picture. "Empty" is
 * judged on what a visitor sees first, the default language's words: a
 * translation on its own would be an empty decoration for everyone reading
 * the default language.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization), so this file knows no
 * language, no default and no fallback. All of it is plain text.
 *
 * THE CHOICES become modifier classes through the closed maps below, and only
 * a choice that differs from its default adds one. A header that was never
 * given a choice prints the markup it always printed, which
 * assets/css/blocks/page-hero.css leaves alone.
 *
 * THE PICTURE goes where PageHeroContent::effectiveImageMode() says, in one
 * of two shapes, and without a picture none of its markup is printed:
 *
 *   background   one band, the picture a layer behind everything: an
 *                absolutely placed wrapper, so it takes no room in the flow
 *                and cannot push the text anywhere, under a veil in the
 *                site's own ground colour. The band's height step
 *                (hero_height) is a modifier class. The picture only sets
 *                the mood, so it is `alt=""`: the header's words say what the
 *                page is, and a screen reader should not read out a
 *                description of the wallpaper first.
 *   left/right   one band with two columns: the text, and the picture in a
 *                frame of a fixed shape beside it. That picture is content,
 *                so it carries the layered alt text (the header's own, else
 *                the library's, App\Service\Media\BlockImage). The text comes
 *                first in the markup whichever side the picture is on, so the
 *                reading order never depends on a layout choice; the
 *                stylesheet places the picture.
 *
 * Both load the picture eagerly, because it is the first thing on the page,
 * and print its size so the browser reserves the space. The focus point
 * (App\Service\Media\ImageFocus) becomes object-position, and only when it is
 * not the middle, which is what the browser does by itself.
 *
 * MORE THAN ONE PICTURE (`slides`, the pictures after the header's own) makes
 * the picture a media sequence in the same place (partials/media-sequence.php):
 * the header's own picture first, the others after it, following each other
 * by themselves with the chosen transition and time, always again from the
 * first, with a pause button. Behind the text the whole sequence is
 * decoration, like the single picture; beside it every picture is content,
 * the further ones with the library's alt text, in a frame named
 * "Diavoorstelling" like the Mediabanner's. A header with one picture
 * prints exactly the markup it always printed.
 *
 * $titleMaxWidthCh reproduces each page's own hand-tuned `<h1>` line-wrap
 * width (a purely cosmetic, per-page value that was never CMS content —
 * see App\Service\Blocks\PageHeroBlock); null omits
 * the inline style entirely.
 *
 * THE BREADCRUMB is the page's own navigation (partials/breadcrumb.php), and
 * this file does not decide whether a page has one. When the page hands its
 * trail to a header with a picture ($trail, App\Service\Blocks\CarriesBreadcrumb),
 * the trail is printed inside the band as a zone of its own at the top: over
 * the picture of a background header, above both columns of a header with a
 * picture beside its text. It always starts at the left of the container,
 * whatever the text's position (assets/css/blocks/page-hero.css): the trail
 * is navigation, not header text. A header without a picture is never handed
 * one, so its trail stays where it always was, just before it. See
 * HEADER-FOOTER.md.
 *
 * @param array<string, mixed> $pageHero see PageHeroContent::forSlug()
 */
function render_section_page_hero(array $pageHero, ?string $titleMaxWidthCh = null, ?BreadcrumbTrail $trail = null): void
{
    $text = static fn (string $field): string => (string) ($pageHero[$field] ?? '');

    if ($text('title') === '') {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $titleStyle = $titleMaxWidthCh !== null ? ' style="max-width:' . $h($titleMaxWidthCh) . ';"' : '';

    $hasLead = $text('lead') !== '';
    $mode = PageHeroContent::effectiveImageMode($pageHero);
    $isBackground = $mode === PageHeroContent::IMAGE_BACKGROUND;
    $isBeside = $mode === PageHeroContent::IMAGE_LEFT || $mode === PageHeroContent::IMAGE_RIGHT;

    // choice => [value => class]. The default of each choice is deliberately
    // absent, so it adds nothing; a value that is not here adds nothing either.
    $modifiers = [
        'content_position' => [
            PageHeroContent::POSITION_CENTER => 'page-hero--content-center',
            PageHeroContent::POSITION_RIGHT => 'page-hero--content-right',
        ],
        'title_size' => [
            PageHeroContent::SIZE_SMALL => 'page-hero--title-small',
            PageHeroContent::SIZE_LARGE => 'page-hero--title-large',
        ],
        'text_size' => [
            PageHeroContent::SIZE_SMALL => 'page-hero--text-small',
            PageHeroContent::SIZE_LARGE => 'page-hero--text-large',
        ],
    ];

    $classes = ['page-hero'];

    if ($isBackground) {
        $classes[] = 'page-hero--background';

        // Only a picture behind the text gives the band a height of its own.
        $modifiers['hero_height'] = [
            PageHeroContent::HEIGHT_SMALL => 'page-hero--height-small',
            PageHeroContent::HEIGHT_LARGE => 'page-hero--height-large',
        ];
    } elseif ($isBeside) {
        $classes[] = 'page-hero--split';
        $classes[] = $mode === PageHeroContent::IMAGE_LEFT ? 'page-hero--image-left' : 'page-hero--image-right';
    }

    foreach ($modifiers as $choice => $classForValue) {
        $class = $classForValue[(string) ($pageHero[$choice] ?? '')] ?? null;

        if ($class !== null) {
            $classes[] = $class;
        }
    }

    $focus = ImageFocus::normalise($pageHero['image_focus'] ?? null);
    $picture = static fn (string $alt): string => '<img src="' . $h((string) ($pageHero['image_path'] ?? '')) . '" alt="' . $h($alt) . '"'
        . BlockImage::dimensionAttributes(['width' => $pageHero['image_width'] ?? null, 'height' => $pageHero['image_height'] ?? null])
        . ($focus !== ImageFocus::DEFAULT ? ' style="object-position: ' . $h(ImageFocus::objectPosition($focus)) . ';"' : '')
        . ' loading="eager" decoding="async" fetchpriority="high">';

    // The header's own picture and the ones after it, when there are more.
    $further = ($isBackground || $isBeside) ? array_values($pageHero['slides'] ?? []) : [];
    $slides = $further === [] ? [] : array_merge([[
        'kind' => MediaType::IMAGE,
        'src' => (string) ($pageHero['image_path'] ?? ''),
        'alt' => $text('image_alt'),
        'width' => $pageHero['image_width'] ?? null,
        'height' => $pageHero['image_height'] ?? null,
    ]], $further);
    $sequence = $slides === [] ? '' : media_sequence_attributes([
        'transition' => $pageHero['slide_transition'] ?? null,
        'duration' => $pageHero['slide_duration'] ?? null,
        'autoplay' => true,
        'loop' => true,
    ]);
    if ($slides !== []) {
        $classes[] = 'page-hero--sequence';
    }
    $slideOptions = [
        'transition' => $pageHero['slide_transition'] ?? null,
        'eager' => true,
        'focus' => $focus,
    ];
    ?>
    <section class="<?= $h(implode(' ', $classes)) ?>"<?= $isBackground ? $sequence : '' ?>>
      <?php if ($isBackground): ?>
      <div class="page-hero__media">
        <?php if ($slides !== []): ?>
          <?php render_media_sequence_slides($slides, $slideOptions + ['decorative' => true]); ?>
        <?php else: ?>
        <?= $picture('') ?>
        <?php endif; ?>
      </div>
      <?php render_breadcrumb($trail); ?>
      <div class="container page-hero__body">
        <?php render_eyebrow($text('eyebrow')); ?>
        <h1<?= $titleStyle ?>><?= $h($text('title')) ?></h1>
        <?php if ($hasLead): ?>
          <p class="lead" style="margin-top:1rem;"><?= $h($text('lead')) ?></p>
        <?php endif; ?>
      </div>
      <?php if ($slides !== []): ?>
        <?php render_media_sequence_controls(count($slides), ['controls' => 'none', 'pause' => true, 'class' => 'page-hero__sequence-controls']); ?>
      <?php endif; ?>
      <?php elseif ($isBeside): ?>
      <?php render_breadcrumb($trail); ?>
      <div class="container page-hero__split">
        <div class="page-hero__text">
          <?php render_eyebrow($text('eyebrow')); ?>
          <h1<?= $titleStyle ?>><?= $h($text('title')) ?></h1>
          <?php if ($hasLead): ?>
            <p class="lead" style="margin-top:1rem;"><?= $h($text('lead')) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($slides !== []): ?>
        <figure class="page-hero__figure page-hero__figure--sequence" role="region" aria-roledescription="carousel" aria-label="<?= SiteText::escaped(['nl' => 'Diavoorstelling', 'en' => 'Slideshow']) ?>"<?= $sequence ?>>
          <?php render_media_sequence_slides($slides, $slideOptions); ?>
          <?php render_media_sequence_controls(count($slides), ['controls' => 'none', 'pause' => true]); ?>
        </figure>
        <?php else: ?>
        <figure class="page-hero__figure">
          <?= $picture($text('image_alt')) ?>
        </figure>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="container">
        <?php render_eyebrow($text('eyebrow')); ?>
        <h1<?= $titleStyle ?>><?= $h($text('title')) ?></h1>
        <?php if ($hasLead): ?>
          <p class="lead" style="margin-top:1rem;"><?= $h($text('lead')) ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php
}
