<?php

use App\Service\Language\SiteText;
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaSequence;
use App\Service\Media\MediaType;

/**
 * THE markup of a media sequence (App\Service\Media\MediaSequence): more than
 * one picture or video in one frame, one after the other. The Paginakop and
 * the Mediabanner print it through these three functions; the behaviour is
 * assets/js/media-sequence.js and the shapes are assets/css/media-sequence.css,
 * both asked for by the blocks that print it.
 *
 * THREE PIECES, because the blocks need them in different places:
 *
 *   media_sequence_attributes()        on the element that holds everything
 *                                      of one sequence (the ROOT): a banner's
 *                                      frame, a header's band or its figure
 *   render_media_sequence_slides()     the slides, where the picture belongs
 *   render_media_sequence_controls()   arrows, dots and the pause button,
 *                                      anywhere inside the root
 *
 * WITHOUT THE SCRIPT the first item shows exactly as a single picture would
 * (the stylesheet hides the rest until the script starts), and the controls
 * stay `hidden`: a button that could do nothing is not offered.
 *
 * SOMETHING THAT MOVES BY ITSELF CAN BE STOPPED. A sequence that plays by
 * itself always gets a pause button, whatever its other controls (WCAG 2.2.2),
 * and for a visitor who asked for less motion it starts paused, with that
 * button saying "afspelen".
 *
 * A picture that is only decoration (a header's pictures behind its text)
 * keeps alt="" and the whole track is hidden from assistive technology.
 * Otherwise every slide is a group named "2 van 4", and the script hides the
 * ones not in view. Every word here is code-owned text in the language of the
 * request (SiteText); nothing from the database becomes CSS, and the only
 * inline style is a picture's object-position from ImageFocus.
 */

/**
 * The data attributes of a sequence's root.
 *
 * @param array{transition: string, duration: int, autoplay: bool, loop: bool, hover_pause?: bool, swipe?: bool} $options
 */
function media_sequence_attributes(array $options): string
{
    return ' data-media-sequence'
        . ' data-media-sequence-transition="' . htmlspecialchars(MediaSequence::transition($options['transition'] ?? null), ENT_QUOTES, 'UTF-8') . '"'
        . ' data-media-sequence-duration="' . MediaSequence::duration($options['duration'] ?? null) . '"'
        . (!empty($options['autoplay']) ? ' data-media-sequence-autoplay' : '')
        . (!empty($options['loop']) ? ' data-media-sequence-loop' : '')
        . (!empty($options['hover_pause']) ? ' data-media-sequence-hover-pause' : '')
        . (!empty($options['swipe']) ? ' data-media-sequence-swipe' : '');
}

/**
 * The slides, the first one showing.
 *
 * @param list<array{kind: string, src: string, mime?: string, alt?: string, width?: int|null, height?: int|null}> $slides
 *        MediaSequence::slide() shapes, in their order
 * @param array{transition: string, decorative?: bool, eager?: bool, focus?: string, media_class?: string,
 *              video_autoplay?: bool, video_controls?: bool, poster?: string} $options
 *        eager: the first picture is the first thing on the page (a header);
 *        poster: the first item's poster, when it is a video
 */
function render_media_sequence_slides(array $slides, array $options): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $decorative = !empty($options['decorative']);
    $mediaClass = (string) ($options['media_class'] ?? '');
    $classAttr = $mediaClass !== '' ? ' class="' . $h($mediaClass) . '"' : '';
    $focus = ImageFocus::normalise($options['focus'] ?? null);
    $position = $focus !== ImageFocus::DEFAULT ? ' style="object-position: ' . $h(ImageFocus::objectPosition($focus)) . ';"' : '';
    $autoplay = !empty($options['video_autoplay']);
    // No video without a way to start it (MediaBannerContent's contract).
    $controls = !$autoplay || !empty($options['video_controls']);
    $total = count($slides);
    ?>
    <div class="media-sequence media-sequence--<?= $h(MediaSequence::transition($options['transition'] ?? null)) ?>" data-media-sequence-track<?= $decorative ? ' aria-hidden="true"' : '' ?>>
      <?php foreach (array_values($slides) as $index => $slide):
          $isVideo = ($slide['kind'] ?? '') === MediaType::VIDEO;
          $name = str_replace([':n', ':total'], [(string) ($index + 1), (string) $total], SiteText::pick(['nl' => ':n van :total', 'en' => ':n of :total']));
          ?>
      <div class="media-sequence__slide<?= $index === 0 ? ' is-active' : '' ?>" data-media-sequence-slide data-kind="<?= $isVideo ? 'video' : 'image' ?>"<?= $decorative ? '' : ' role="group" aria-roledescription="slide" aria-label="' . $h($name) . '"' ?>>
        <?php if ($isVideo):
            $poster = $index === 0 ? (string) ($options['poster'] ?? '') : '';
            ?>
        <video<?= $classAttr ?> src="<?= $h((string) $slide['src']) ?>"<?= $poster !== '' ? ' poster="' . $h($poster) . '"' : '' ?> playsinline preload="metadata"<?= $controls ? ' controls' : '' ?><?= $autoplay ? ' muted' : '' ?><?= $autoplay && $index === 0 ? ' autoplay' : '' ?><?= $controls ? '' : ' aria-hidden="true"' ?>></video>
        <?php else:
            $eager = !empty($options['eager']) && $index === 0;
            ?>
        <img<?= $classAttr ?> src="<?= $h((string) $slide['src']) ?>" alt="<?= $decorative ? '' : $h((string) ($slide['alt'] ?? '')) ?>"<?= BlockImage::dimensionAttributes(['width' => $slide['width'] ?? null, 'height' => $slide['height'] ?? null]) ?><?= $eager ? ' loading="eager" decoding="async" fetchpriority="high"' : ' loading="lazy" decoding="async"' ?><?= $position ?>>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * The buttons of a sequence, `hidden` until the script starts it.
 *
 * @param array{controls: string, pause: bool, class?: string} $options
 *        controls: MediaSequence::CONTROLS; pause: whether it plays by itself
 */
function render_media_sequence_controls(int $count, array $options): void
{
    $controls = MediaSequence::controls($options['controls'] ?? null);
    $arrows = MediaSequence::hasArrows($controls);
    $dots = MediaSequence::hasDots($controls);
    $pause = !empty($options['pause']);

    if ($count < 2 || (!$arrows && !$dots && !$pause)) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $extra = (string) ($options['class'] ?? '');
    $chevron = static fn (string $path): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
    ?>
    <div class="media-sequence__controls<?= $extra !== '' ? ' ' . $h($extra) : '' ?>" data-media-sequence-controls hidden>
      <?php if ($arrows): ?>
      <button type="button" class="media-sequence__arrow media-sequence__arrow--prev" data-media-sequence-prev aria-label="<?= SiteText::escaped(['nl' => 'Vorige', 'en' => 'Previous']) ?>"><?= $chevron('M15 18l-6-6 6-6') ?></button>
      <button type="button" class="media-sequence__arrow media-sequence__arrow--next" data-media-sequence-next aria-label="<?= SiteText::escaped(['nl' => 'Volgende', 'en' => 'Next']) ?>"><?= $chevron('M9 18l6-6-6-6') ?></button>
      <?php endif; ?>
      <?php if ($dots): ?>
      <div class="media-sequence__dots" role="group" aria-label="<?= SiteText::escaped(['nl' => 'Kies wat je ziet', 'en' => 'Choose what you see']) ?>">
        <?php for ($index = 0; $index < $count; $index++): ?>
        <button type="button" class="media-sequence__dot" data-media-sequence-dot="<?= $index ?>" aria-label="<?= $h(str_replace([':n', ':total'], [(string) ($index + 1), (string) $count], SiteText::pick(['nl' => ':n van :total', 'en' => ':n of :total']))) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <?php if ($pause): ?>
      <button type="button" class="media-sequence__pause" data-media-sequence-pause data-state="playing"
              aria-label="<?= SiteText::escaped(['nl' => 'Pauzeren', 'en' => 'Pause']) ?>"
              data-label-pause="<?= SiteText::escaped(['nl' => 'Pauzeren', 'en' => 'Pause']) ?>"
              data-label-play="<?= SiteText::escaped(['nl' => 'Afspelen', 'en' => 'Play']) ?>"><svg class="media-sequence__icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6.5" y="5" width="4" height="14" rx="1"/><rect x="13.5" y="5" width="4" height="14" rx="1"/></svg><svg class="media-sequence__icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z"/></svg></button>
      <?php endif; ?>
    </div>
    <?php
}
