<?php

require_once __DIR__ . '/eyebrow.php';
require_once __DIR__ . '/responsive-image.php';

use App\Service\Language\SiteText;
use App\Service\ReviewsContent;
use App\Service\Theme\ButtonStyles;

/**
 * Renders ONE Reviews block (App\Service\ReviewsContent): an optional
 * heading, the reviews in the chosen layout, and an optional button. Caller
 * must already have checked $content['state'] !== ReviewsContent::STATE_HIDDEN;
 * a block without a review to show renders nothing at all, heading included,
 * so an empty block leaves no gap.
 *
 * THIS PARTIAL KNOWS REVIEWS, NOT TABLES. Every review arrives in the shape of
 * ReviewsContent::review(), whoever made it; the four layouts below are only
 * ways of arranging that shape (CONTENT-BLOCKS.md, "Reviews").
 *
 *   cards     a grid of cards: stars on top, the text, then who said it
 *   minimal   one calm quote after another: large type, a large decorative
 *             quotation mark, no card, lots of room
 *   featured  ONE review as the page's focal point (the chosen one, else the
 *             first): display type, an accent quotation mark, the portrait
 *             large beside it
 *   carousel  the cards side by side in a strip that scrolls: swipe on a
 *             phone, the arrows or the keyboard elsewhere
 *             (assets/js/blocks/reviews.js); it never moves by itself
 *
 * The layout is a class on the <section> (reviews-section--<layout>), which is
 * also the root that "Extra vormgeving" puts its classes on
 * (App\Service\Blocks\BlockAppearance::apply()).
 *
 * ACCESSIBLE BY CONSTRUCTION. A review is a <figure>: the text a
 * <blockquote>, the person a <figcaption>. Stars are one role="img" element
 * named "4 van de 5 sterren", the five shapes inside it decoration; a review
 * without stars prints nothing there. The quotation marks are decoration
 * (aria-hidden). A source is a plain link (rel="nofollow noopener
 * noreferrer"), and a date a <time> with its machine-readable value. The
 * carousel is a named region with a roledescription, each review a named
 * group ("2 van 5"), and its list can be scrolled with the keyboard.
 *
 * Everything an editor typed goes through htmlspecialchars(); a review's
 * line breaks are kept (nl2br() after escaping).
 *
 * @param array<string, mixed> $content     see ReviewsContent::forSection()
 * @param string               $revealGroup the instance's reveal group (SectionRegistry)
 */
function render_section_reviews(array $content, string $revealGroup = 'reviews'): void
{
    $reviews = array_values($content['reviews'] ?? []);
    if ($reviews === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $settings = ReviewsContent::settings($content);
    $layout = $settings['layout'];

    $title = (string) ($content['title'] ?? '');
    $hasHead = (string) ($content['eyebrow'] ?? '') !== '' || $title !== '' || (string) ($content['lead'] ?? '') !== '';
    // A minimal quote and a featured review are centred compositions: their
    // heading follows them unless the editor chose otherwise.
    $headClass = $settings['header_align'] === 'center' ? ' center reviews__head--center' : '';

    $button = is_array($content['button'] ?? null) ? $content['button'] : null;
    ?>
    <section class="reviews-section reviews-section--<?= $h($layout) ?>" data-reviews>
      <div class="container">
        <?php if ($hasHead): ?>
        <div class="section-head reviews__head<?= $h($headClass) ?>" data-reveal>
          <?php render_eyebrow((string) ($content['eyebrow'] ?? '')); ?>
          <?php if ($title !== ''): ?>
          <h2><?= $h($title) ?></h2>
          <?php endif; ?>
          <?php if ((string) ($content['lead'] ?? '') !== ''): ?>
          <p class="lead"><?= $h((string) $content['lead']) ?></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($layout === 'featured'): ?>
          <?php
          $index = (int) ($content['featured'] ?? 0);
          $featured = $reviews[$index] ?? $reviews[0];
          ?>
        <div class="reviews reviews--featured" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
          <?= reviews_figure($featured, 'featured') ?>
        </div>
        <?php elseif ($layout === 'carousel'): ?>
          <?php $count = count($reviews); ?>
        <div class="reviews-carousel" data-reviews-carousel role="region" aria-roledescription="<?= SiteText::escaped(['nl' => 'carrousel', 'en' => 'carousel']) ?>" aria-label="<?= $title !== '' ? $h($title) : SiteText::escaped(['nl' => 'Reviews', 'en' => 'Reviews']) ?>">
          <ul class="reviews reviews--carousel" role="list" tabindex="0" data-reviews-track>
            <?php foreach ($reviews as $position => $review): ?>
            <li class="reviews__slide" role="group" aria-roledescription="<?= SiteText::escaped(['nl' => 'review', 'en' => 'review']) ?>" aria-label="<?= $h(($position + 1) . ' ' . SiteText::pick(['nl' => 'van', 'en' => 'of']) . ' ' . $count) ?>" data-reviews-slide>
              <?= reviews_figure($review, 'card') ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($count > 1): ?>
          <div class="reviews-carousel__controls" data-reviews-controls hidden>
            <button type="button" class="reviews-carousel__button" data-reviews-prev aria-label="<?= SiteText::escaped(['nl' => 'Vorige review', 'en' => 'Previous review']) ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
            </button>
            <p class="reviews-carousel__status" data-reviews-status aria-live="polite"></p>
            <button type="button" class="reviews-carousel__button" data-reviews-next aria-label="<?= SiteText::escaped(['nl' => 'Volgende review', 'en' => 'Next review']) ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <ul class="reviews reviews--<?= $h($layout) ?><?= count($reviews) === 1 ? ' reviews--single' : '' ?>" role="list">
          <?php foreach ($reviews as $review): ?>
          <li class="reviews__item" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">
            <?= reviews_figure($review, $layout === 'minimal' ? 'minimal' : 'card') ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($button !== null): ?>
          <?php $classes = ButtonStyles::classes(ButtonStyles::storedChoice($content['button_style'] ?? null), ['btn', 'btn--ghost']); ?>
        <div class="reviews__actions<?= $settings['header_align'] === 'center' || $layout !== 'cards' ? ' reviews__actions--center' : '' ?>">
          <a class="<?= $h($classes['class']) ?>" href="<?= $h((string) $button['href']) ?>"><?= $h((string) $button['label']) ?></a>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
}

if (!function_exists('reviews_figure')) {
    /**
     * One review as a <figure>, in the look of one layout: 'card' (the grid
     * and the carousel), 'minimal' or 'featured'. Only what the review has is
     * printed: no empty star row, no empty caption.
     *
     * @param array<string, mixed> $review a ReviewsContent::review() shape
     */
    function reviews_figure(array $review, string $variant): string
    {
        $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $text = (string) ($review['text'] ?? '');
        $name = (string) ($review['name'] ?? '');
        $role = (string) ($review['role'] ?? '');
        $date = (string) ($review['date'] ?? '');
        $dateIso = (string) ($review['date_iso'] ?? '');
        $sourceLabel = (string) ($review['source_label'] ?? '');
        $sourceHref = (string) ($review['source_href'] ?? '');
        $image = is_array($review['image'] ?? null) ? $review['image'] : null;
        $rating = (int) ($review['rating'] ?? 0);

        $portrait = '';
        if ($image !== null) {
            $picture = is_array($image['picture'] ?? null) ? $image['picture'] : (new \App\Service\Media\ResponsiveImage())->forRender($image);
            // The name beside it already says who it is: an empty alt text
            // unless the library describes the picture.
            $portrait = '<span class="review__portrait">'
                . responsive_image_html($picture, ['class' => 'review__portrait-image', 'loading' => 'lazy', 'decoding' => true])
                . '</span>';
        }

        $stars = '';
        if ($rating > 0) {
            $shape = '<path d="M12 2.8l2.83 5.73 6.33.92-4.58 4.46 1.08 6.3L12 17.24l-5.66 2.97 1.08-6.3-4.58-4.46 6.33-.92z"/>';
            $stars = '<div class="review__stars" role="img" aria-label="' . $h((string) ($review['rating_label'] ?? '')) . '">';
            for ($star = 1; $star <= ReviewsContent::MAX_RATING; $star++) {
                $stars .= '<svg class="review__star' . ($star <= $rating ? ' is-filled' : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $shape . '</svg>';
            }
            $stars .= '</div>';
        }

        $details = [];
        if ($date !== '') {
            $details[] = '<time datetime="' . $h($dateIso) . '">' . $h($date) . '</time>';
        }
        if ($sourceHref !== '') {
            $details[] = '<a class="review__source" href="' . $h($sourceHref) . '" rel="nofollow noopener noreferrer">' . $h($sourceLabel) . '</a>';
        } elseif ($sourceLabel !== '') {
            $details[] = '<span class="review__source">' . $h($sourceLabel) . '</span>';
        }

        $mark = '<span class="review__mark" aria-hidden="true"><svg viewBox="0 0 48 40" focusable="false"><path d="M0 40V24.4C0 10.9 6.9 2.8 20.6 0l2.2 5.3C15.4 7.6 11.7 12 11.4 18.6H20V40H0zm27.9 0V24.4C27.9 10.9 34.8 2.8 48.5 0l2.2 5.3c-7.4 2.3-11.1 6.7-11.4 13.3h8.6V40H27.9z" transform="scale(.94)"/></svg></span>';

        $who = '';
        if ($name !== '' || $role !== '' || $details !== []) {
            $who = '<span class="review__who">'
                . ($name !== '' ? '<span class="review__name">' . $h($name) . '</span>' : '')
                . ($role !== '' ? '<span class="review__role">' . $h($role) . '</span>' : '')
                . ($details !== [] ? '<span class="review__details">' . implode('<span class="review__dot" aria-hidden="true"> · </span>', $details) . '</span>' : '')
                . '</span>';
        }

        $featured = $variant === 'featured';
        // The featured layout shows the portrait large beside the quote; the
        // others keep it small, next to the name.
        $caption = ($featured ? '' : $portrait) . $who;

        $classes = 'review review--' . $variant
            . ($image !== null ? ' review--with-portrait' : '')
            . ($rating > 0 ? ' review--rated' : '');

        $html = '<figure class="' . $h($classes) . '">' . $mark;
        if ($featured && $portrait !== '') {
            $html .= '<div class="review__visual">' . $portrait . '</div>';
        }
        $html .= '<div class="review__body">'
            . $stars
            . '<blockquote class="review__quote"><p>' . nl2br($h($text), false) . '</p></blockquote>'
            . ($caption !== '' ? '<figcaption class="review__meta">' . $caption . '</figcaption>' : '')
            . '</div>'
            . '</figure>';

        return $html;
    }
}
