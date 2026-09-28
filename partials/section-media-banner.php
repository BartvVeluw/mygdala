<?php

require_once __DIR__ . '/media-sequence.php';

use App\Service\Language\SiteText;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaType;
use App\Service\MediaBannerContent;

/**
 * Renders ONE Mediabanner block (App\Service\MediaBannerContent): a picture or
 * a video from the Media Library in a frame of a chosen height, inside the
 * container or across the whole page. No words of its own.
 *
 * FULL WIDTH WITHOUT A TRICK, the same way as the full-width Oproep met knop
 * (CONTENT-BLOCKS.md, "Oproep met knop"): every block's <section> already
 * spans the page, so a full-width banner simply puts its frame in the section
 * instead of in a .container. No 100vw, no negative margin, nothing that could
 * make the page scroll sideways. The section keeps the spacing every section
 * has; a frame inside the container has the theme's rounded corners, a frame
 * across the page has none, since its edges are the window's.
 *
 * NOTHING FROM THE DATABASE BECOMES CSS. The width, the height and the kind
 * are words of closed lists, checked again here, and become classes; the one
 * style attribute is a picture's object-position, from ImageFocus's own
 * closed list. The picture's alt text is the library's; an empty one makes it
 * decorative (alt=""), which is what the library means by it.
 *
 * THE VIDEO: native <video>, the file from the library, always playsinline so
 * a phone does not jump to full screen. Autoplay always comes with muted
 * (MediaBannerContent decides the rest: no video without a way to start it).
 * preload="metadata" loads no more than the first bytes until it plays. A
 * video without controls is decoration and is hidden from assistive
 * technology, like the Homepage hero's; one with controls is not.
 * assets/js/blocks/media-banner.js stops a video that plays by itself for a
 * visitor who asked for less motion, and gives it its controls.
 *
 * MORE THAN ONE ITEM (`items`, MediaBannerContent) is a media sequence in the
 * same frame (partials/media-sequence.php): the items one after the other,
 * with the chosen transition, time per picture and buttons, a pause button
 * when it plays by itself, and the frame a region named "Diavoorstelling".
 * A banner with one item prints exactly the markup it always printed.
 *
 * Caller must already have checked $content['state'] !==
 * MediaBannerContent::STATE_HIDDEN. STATE_FALLBACK, and an active row without
 * a picture or video, render nothing: no empty frame, no room.
 *
 * @param array<string, mixed> $content  see MediaBannerContent::forSection()
 * @param bool                 $tightTop directly under a page header, which
 *                                       already has room at its bottom
 */
function render_section_media_banner(array $content, bool $tightTop = false): void
{
    $kind = (string) ($content['kind'] ?? '');
    $src = (string) ($content['src'] ?? '');

    if (($content['state'] ?? '') !== MediaBannerContent::STATE_ACTIVE
        || !in_array($kind, [MediaType::IMAGE, MediaType::VIDEO], true)
        || $src === ''
    ) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $width = MediaBannerContent::width($content['width'] ?? null);
    $height = MediaBannerContent::height($content['height'] ?? null);
    $inContainer = $width !== 'full';

    $sectionClass = 'media-banner-section media-banner-section--' . $width . ($tightTop ? ' media-banner-section--tight-top' : '');
    $items = array_values($content['items'] ?? []);
    $isSequence = count($items) > 1;
    $frameClass = 'media-banner media-banner--' . $height . ' media-banner--' . ($isSequence ? 'sequence' : $kind);
    $autoplay = !empty($content['autoplay']);
    $nav = (string) ($content['nav'] ?? 'both');
    ?>
    <section class="<?= $h($sectionClass) ?>">
      <?php if ($inContainer): ?><div class="container"><?php endif; ?>
        <?php if ($isSequence): ?>
        <div class="<?= $h($frameClass) ?>" data-reveal role="region" aria-roledescription="carousel" aria-label="<?= SiteText::escaped(['nl' => 'Diavoorstelling', 'en' => 'Slideshow']) ?>"<?= media_sequence_attributes([
            'transition' => $content['transition'] ?? null,
            'duration' => $content['duration'] ?? null,
            'autoplay' => $autoplay,
            'loop' => !empty($content['loop']),
            'hover_pause' => true,
            'swipe' => $nav !== 'none',
        ]) ?>>
          <?php render_media_sequence_slides($items, [
              'transition' => $content['transition'] ?? null,
              'focus' => $content['focus'] ?? null,
              'media_class' => 'media-banner__media',
              'video_autoplay' => $autoplay,
              'video_controls' => !empty($content['controls']),
              'poster' => (string) ($content['poster'] ?? ''),
          ]); ?>
          <?php render_media_sequence_controls(count($items), ['controls' => $nav, 'pause' => $autoplay]); ?>
        </div>
        <?php else: ?>
        <div class="<?= $h($frameClass) ?>" data-reveal>
          <?php if ($kind === MediaType::IMAGE):
              $focus = ImageFocus::normalise($content['focus'] ?? null);
              $size = '';
              if (($content['intrinsic_width'] ?? null) !== null && ($content['intrinsic_height'] ?? null) !== null) {
                  $size = ' width="' . (int) $content['intrinsic_width'] . '" height="' . (int) $content['intrinsic_height'] . '"';
              }
              ?>
          <img class="media-banner__media" src="<?= $h($src) ?>" alt="<?= $h((string) ($content['alt'] ?? '')) ?>"<?= $size ?> loading="lazy" decoding="async"<?= $focus !== ImageFocus::DEFAULT ? ' style="object-position: ' . $h(ImageFocus::objectPosition($focus)) . ';"' : '' ?>>
          <?php else:
              $autoplay = !empty($content['autoplay']);
              // No video without a way to start it, whatever arrives here.
              $controls = !$autoplay || !empty($content['controls']);
              $poster = (string) ($content['poster'] ?? '');
              ?>
          <video class="media-banner__media" src="<?= $h($src) ?>"<?= $poster !== '' ? ' poster="' . $h($poster) . '"' : '' ?> playsinline preload="metadata"<?= $controls ? ' controls' : '' ?><?= $autoplay ? ' autoplay muted data-media-banner-autoplay' : '' ?><?= !empty($content['loop']) ? ' loop' : '' ?><?= $controls ? '' : ' aria-hidden="true"' ?>></video>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php if ($inContainer): ?></div><?php endif; ?>
    </section>
    <?php
}
