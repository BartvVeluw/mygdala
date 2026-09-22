<?php

/**
 * Renders ONE "Kaarten-carrousel" block instance
 * (App\Service\CardCarouselContent) in the theme's existing orbit carousel.
 *
 * The markup is the homepage Diensten-carrousel's, kept as-is (class names
 * and controls included) so the migrated carousel renders exactly as before.
 * What changed in phase 3 is where the content comes from: the block used to
 * project the four fixed material services, and now renders whatever cards
 * an editor has put in it — nothing here assumes a card count. A carousel
 * with no cards renders nothing at all rather than an empty stage with
 * vertical space, the same "empty block leaves no gap" rule the Marquee,
 * CTA Band and Contactkaart partials follow.
 *
 * A card without an image gets the theme's fixed icon instead of a photo —
 * the presentation the Acryl & glas card has always had, decided purely on
 * whether image_path is empty.
 *
 * The number above a card's title is the card's own label, or its position
 * ("01") when it has none — CardCarouselContent decides, this file prints.
 *
 * The carousel's aria labels are generic ("kaart", not "materiaal") because
 * the block is: the region announces itself with the block's own title.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization) — the carousel's, each
 * card's and each tag's — so this file knows no language, no default and no
 * fallback. All of it is plain text. Only the region's own fallback label,
 * like the control labels below, is fixed interface text, written per
 * language right here (App\Service\Language\SiteText::pick()).
 *
 * Caller must already have checked $content['state'] !==
 * CardCarouselContent::STATE_HIDDEN before calling this.
 *
 * @param array<string, mixed> $content App\Service\CardCarouselContent::forSection()
 */
function render_section_card_carousel(array $content): void
{
    if ($content['cards'] === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $hasHead = $content['eyebrow'] !== '' || $content['title'] !== '' || $content['lead'] !== '';
    // "Kaarten naast elkaar": the flat strip on every screen
    // (CardCarouselContent::LAYOUT_ROW); anything else is the rotating ring.
    $isRow = ($content['desktop_layout'] ?? '') === \App\Service\CardCarouselContent::LAYOUT_ROW;
    ?>
      <section class="bg-soft">
        <div class="container">
          <?php if ($hasHead): ?>
          <div class="section-head" data-reveal>
            <?php if ($content['eyebrow'] !== ''): ?>
            <p class="eyebrow"><?= $h($content['eyebrow']) ?></p>
            <?php endif; ?>
            <?php if ($content['title'] !== ''): ?>
            <h2><?= $h($content['title']) ?></h2>
            <?php endif; ?>
            <?php if ($content['lead'] !== ''): ?>
            <p class="lead"><?= $h($content['lead']) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div
            class="orbit-carousel<?= $isRow ? ' orbit-carousel--row' : '' ?>"
            data-orbit
            data-orbit-layout="<?= $isRow ? 'row' : 'orbit' ?>"
            data-reveal
            role="region"
            aria-roledescription="carousel"
            <?php if ($content['title'] !== ''): ?>
            aria-label="<?= $h($content['title']) ?>"
            <?php else: ?>
            aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Carrousel', 'en' => 'Carousel']) ?>"
            <?php endif; ?>
          >
            <div class="orbit-carousel__stage">
              <ul class="orbit-carousel__track" data-orbit-track role="list">
                <?php foreach ($content['cards'] as $card): ?>
                <li class="orbit-card" data-orbit-card>
                  <div class="orbit-card__inner">
                    <?php if ($card['image_path'] !== ''): ?>
                    <div class="orbit-card__media">
                      <img
                        src="<?= $h($card['image_path']) ?>"
                        alt="<?= $h($card['image_alt']) ?>"
                        <?= \App\Service\Media\BlockImage::dimensionAttributes(['width' => $card['image_width'] ?? null, 'height' => $card['image_height'] ?? null]) ?>
                        loading="lazy"
                      />
                    </div>
                    <?php else: ?>
                    <div class="orbit-card__media orbit-card__media--icon">
                      <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.2"
                        stroke-linejoin="round"
                        aria-hidden="true"
                      >
                        <path d="M6 3h12l4 6-10 12L2 9z" />
                        <path
                          d="M2 9h20M8.5 3L12 9l3.5-6M12 9l-4 12M12 9l4 12"
                        />
                      </svg>
                    </div>
                    <?php endif; ?>
                    <div class="orbit-card__body">
                      <span class="service-row__index"><?= $h($card['index_label']) ?></span>
                      <h3>
                        <?= $h($card['title']) ?>
                      </h3>
                      <?php if ($card['body'] !== ''): ?>
                      <p
                       
                      >
                        <?= $h($card['body']) ?>
                      </p>
                      <?php endif; ?>
                      <?php if ($card['tags'] !== []): ?>
                      <div class="tag-list">
                        <?php foreach ($card['tags'] as $tag): ?>
                        <span
                          class="tag"
                         
                          ><?= $h($tag['label']) ?></span
                        >
                        <?php endforeach; ?>
                      </div>
                      <?php endif; ?>
                      <?php if ($card['link_url'] !== ''): ?>
                      <a
                        href="<?= $h($card['link_url']) ?>"
                        class="btn btn--ghost btn--sm"
                        tabindex="-1"
                       
                        ><?= $h($card['link_label']) ?>
                        <svg
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="1.8"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          aria-hidden="true"
                        >
                          <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                      </a>
                      <?php endif; ?>
                    </div>
                  </div>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="orbit-carousel__controls">
              <button
                type="button"
                class="orbit-carousel__nav orbit-carousel__nav--prev"
                data-orbit-prev
                aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Vorige kaart', 'en' => 'Previous card']) ?>"
              >
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M15 6l-6 6 6 6" />
                </svg>
              </button>
              <div
                class="orbit-carousel__dots"
                data-orbit-dots
                role="tablist"
                aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Ga naar kaart', 'en' => 'Go to card']) ?>"
              ></div>
              <button
                type="button"
                class="orbit-carousel__nav orbit-carousel__nav--next"
                data-orbit-next
                aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Volgende kaart', 'en' => 'Next card']) ?>"
              >
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M9 6l6 6-6 6" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </section>
    <?php
}
