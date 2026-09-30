<?php

require_once __DIR__ . '/eyebrow.php';
require_once __DIR__ . '/responsive-image.php';

use App\Service\HoverCardGridContent;
use App\Service\Media\BlockImage;

/**
 * Renders ONE Hover kaarten grid (App\Service\HoverCardGridContent): an
 * optional heading, then the cards in a grid. Caller must already have
 * checked $content['state'] !== HoverCardGridContent::STATE_HIDDEN; a grid
 * without a card to show renders nothing at all, heading included, so an
 * empty block leaves no gap.
 *
 * THE CHOICES become classes on the list, and only a choice that differs from
 * its default adds one (CONTENT-BLOCKS.md, "Een weergavekeuze is een woord uit
 * een gesloten lijst"); assets/css/blocks/hover-card-grid.css turns them into
 * shapes, columns and veils. Nothing from the database becomes CSS.
 *
 * ONE LINK PER CARD, AND IT COVERS THE CARD. A card that goes somewhere has
 * exactly one real <a>: its link label as a visible call to action when it
 * has one (with the title after it for a screen reader, so two "Bekijk"
 * links on a page are two different links), else its title. The stylesheet
 * stretches that link over the whole card, so a click anywhere on it
 * follows it, while a screen reader hears one short link name instead of the
 * whole card read out as a link. A card without a link has no <a>, no cursor
 * and nothing to focus.
 *
 * WHAT A HOVER SHOWS IS NEVER ONLY FOR A MOUSE. An overlay card with a link
 * tucks its text away until it is reached, by a pointer OR the keyboard
 * (:focus-within), and on a touch screen it is always shown. An overlay card
 * without a link cannot be reached by the keyboard, so its text is always in
 * view (hover-card--open-text). The second picture is an alternative view:
 * alt="" and aria-hidden, since nothing essential may depend on a hover.
 *
 * THE MAIN PICTURE is printed by partials/responsive-image.php (Responsive
 * Media 2.0): its focus point, its fit and a phone picture of its own. A card
 * read without a presentation (the block library's sample) prints it plainly.
 * The second picture keeps its plain <img>: it fills the frame from its
 * middle whatever the main picture does.
 *
 * HEADINGS. The grid's title is an h2, like every block heading; under it a
 * card's title is an h3, and in a grid without a title it is an h2
 * (App\Service\Blocks\CardHeading). `.hover-card__title` keeps its look the
 * same either way.
 *
 * @param array<string, mixed> $content     see HoverCardGridContent::forSection()
 * @param string               $revealGroup the instance's reveal group (SectionRegistry)
 */
function render_section_hover_card_grid(array $content, string $revealGroup = 'hover-card-grid'): void
{
    $cards = $content['cards'] ?? [];
    if ($cards === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $settings = HoverCardGridContent::settings($content);
    $isOverlay = $settings['layout'] === 'overlay';

    // choice => [word => class]; a default word is deliberately absent.
    $modifiers = [
        'layout' => ['open' => 'hover-cards--open'],
        'shape' => ['square' => 'hover-cards--square', 'circle' => 'hover-cards--circle', 'organic' => 'hover-cards--organic'],
        'columns' => ['2' => 'hover-cards--cols-2', '4' => 'hover-cards--cols-4'],
        'effect' => ['subtle' => 'hover-cards--subtle'],
    ];
    // How dense the veil is only means something where there is one.
    if ($isOverlay) {
        $modifiers['overlay'] = ['light' => 'hover-cards--veil-light', 'dark' => 'hover-cards--veil-dark'];
    }

    $classes = ['hover-cards'];
    foreach ($modifiers as $choice => $classForWord) {
        if (isset($classForWord[$settings[$choice]])) {
            $classes[] = $classForWord[$settings[$choice]];
        }
    }

    $hasHead = (string) ($content['eyebrow'] ?? '') !== '' || (string) ($content['title'] ?? '') !== '' || (string) ($content['lead'] ?? '') !== '';
    $cardHeading = \App\Service\Blocks\CardHeading::under((string) ($content['title'] ?? '') !== '');
    $headClass = $settings['header_align'] !== 'left' ? ' hover-cards__head--' . $settings['header_align'] : '';
    $arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    ?>
    <section class="hover-cards-section" data-hover-card-grid>
      <div class="container">
        <?php if ($hasHead): ?>
        <div class="section-head hover-cards__head<?= $h($headClass) ?>" data-reveal>
          <?php render_eyebrow((string) ($content['eyebrow'] ?? '')); ?>
          <?php if ((string) ($content['title'] ?? '') !== ''): ?>
          <h2><?= $h((string) $content['title']) ?></h2>
          <?php endif; ?>
          <?php if ((string) ($content['lead'] ?? '') !== ''): ?>
          <p class="lead"><?= $h((string) $content['lead']) ?></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <ul class="<?= $h(implode(' ', $classes)) ?>" role="list">
          <?php foreach ($cards as $card):
              $title = (string) ($card['title'] ?? '');
              $body = (string) ($card['body'] ?? '');
              $badge = (string) ($card['badge'] ?? '');
              $href = (string) ($card['href'] ?? '');
              $label = $href !== '' ? (string) ($card['link_label'] ?? '') : '';
              $hover = $card['hover_image'] ?? null;
              $hasText = $body !== '' || $label !== '';

              $cardClasses = 'hover-card'
                  . ($href !== '' ? ' hover-card--linked' : '')
                  . ($hover !== null ? ' hover-card--swap' : '')
                  // Nothing to focus, so nothing may wait for a hover.
                  . ($isOverlay && $href === '' ? ' hover-card--open-text' : '')
                  // A picture without words needs no veil to read them on.
                  . ($title === '' && !$hasText ? ' hover-card--bare' : '');
              ?>
          <li class="<?= $h($cardClasses) ?>" data-reveal data-reveal-group="<?= $h($revealGroup) ?>"<?= $hover !== null && $href === '' ? ' data-hover-card-swap' : '' ?>>
            <div class="hover-card__inner">
              <div class="hover-card__visual">
                <div class="hover-card__frame">
                  <?php render_responsive_image(
                      $card['picture'] ?? (new \App\Service\Media\ResponsiveImage())->forRender($card['image']),
                      ['class' => 'hover-card__image', 'loading' => 'lazy', 'decoding' => true]
                  ); ?>
                  <?php if ($hover !== null): ?>
                  <img class="hover-card__image hover-card__image--hover" src="<?= $h((string) $hover['src']) ?>" alt=""<?= BlockImage::dimensionAttributes($hover) ?> loading="lazy" decoding="async" aria-hidden="true">
                  <?php endif; ?>
                </div>
                <?php if ($badge !== ''): ?>
                <span class="hover-card__badge"><?= $h($badge) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($title !== '' || $hasText): ?>
              <div class="hover-card__content">
                <?php if ($title !== ''): ?>
                <<?= $cardHeading ?> class="hover-card__title"><?php if ($href !== '' && $label === ''): ?><a class="hover-card__link" href="<?= $h($href) ?>"><?= $h($title) ?></a><?php else: ?><?= $h($title) ?><?php endif; ?></<?= $cardHeading ?>>
                <?php endif; ?>
                <?php if ($hasText): ?>
                <div class="hover-card__more">
                  <div class="hover-card__more-inner">
                    <?php if ($body !== ''): ?>
                    <p class="hover-card__text"><?= $h($body) ?></p>
                    <?php endif; ?>
                    <?php if ($label !== '' && \App\Service\Theme\ButtonStyles::storedChoice($card['button_style'] ?? null) !== null): ?>
                    <?php /* A chosen button style (Button Styles 2.0): the words
                             are that .btn, drawn on a span inside the card-wide
                             link, which keeps its ::after over the whole card. */ ?>
                    <?php $button = \App\Service\Theme\ButtonStyles::classes(\App\Service\Theme\ButtonStyles::storedChoice($card['button_style'] ?? null), []); ?>
                    <a class="hover-card__cta hover-card__cta--button hover-card__link" href="<?= $h($href) ?>"><span class="<?= $h($button['class']) ?>"><?= $h($label) ?></span><?php if ($title !== ''): ?><span class="visually-hidden">: <?= $h($title) ?></span><?php endif; ?></a>
                    <?php elseif ($label !== ''): ?>
                    <a class="hover-card__cta hover-card__link" href="<?= $h($href) ?>"><?= $h($label) ?><?php if ($title !== ''): ?><span class="visually-hidden">: <?= $h($title) ?></span><?php endif; ?><?= $arrow ?></a>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
    <?php
}
