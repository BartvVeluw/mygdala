<?php

require_once __DIR__ . '/eyebrow.php';

use App\Service\CtaBandContent;
use App\Service\Media\ImageFocus;

/**
 * Renders the CTA Band section (App\Service\CtaBandContent) — identical
 * markup on index.php, shop.php, diensten.php and portfolio.php, extracted
 * verbatim, and borrowed by portfolio-detail.php. Caller must already have
 * checked $cta['state'] !== CtaBandContent::STATE_HIDDEN before calling this.
 *
 * Every word arrives as one string per field, already in the language of
 * the request (App\Service\Blocks\BlockLocalization), so this file knows no
 * language, no default and no fallback. All of it is plain text.
 *
 * THE LAYERS (CTA 2.0, CONTENT-BLOCKS.md "Oproep met knop"), from the back:
 * the band's theme colour, the background picture, the overlay over it, the
 * text panel, the words. Which box carries the first three is the one choice
 * "Volledige paginabreedte" makes: the card inside the container (as every
 * band always was), or the <section> itself, which already spans the page —
 * so a full-width band needs no 100vw and no negative margin, and its words
 * stay in the ordinary .container.
 *
 * NOTHING FROM THE DATABASE BECOMES CSS. Every presentation value is a word
 * of a closed list, checked again here with CtaBandContent::choice(), and
 * becomes a class; the one style attribute is the object-position of the
 * picture's focus point, which comes from ImageFocus's own closed list, as on
 * the Paginakop. The picture is decorative (alt="" and hidden from assistive
 * technology): the words carry everything.
 *
 * @param array<string, mixed> $cta see CtaBandContent::forSection()
 */
function render_section_cta_band(array $cta): void
{
    $text = static fn (string $field): string => (string) ($cta[$field] ?? '');

    if ($text('title') === '' && $text('primary_label') === '') {
        // Nothing to say and nowhere to go — a missing/unreachable content
        // row must leave no empty band behind, the same "empty block leaves
        // no gap" rule as the Rich text block.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $align = CtaBandContent::choice(CtaBandContent::ALIGNMENTS, $cta['align'] ?? null);
    $leadWidth = CtaBandContent::choice(CtaBandContent::LEAD_WIDTHS, $cta['lead_width'] ?? null);
    $overlay = CtaBandContent::choice(CtaBandContent::OVERLAYS, $cta['overlay'] ?? null);
    $panel = ($cta['panel'] ?? '') === '' ? '' : CtaBandContent::choice(CtaBandContent::PANEL_OPACITIES, $cta['panel']);
    $fullWidth = !empty($cta['full_width']);
    $background = is_array($cta['background'] ?? null) && (string) ($cta['background']['image_path'] ?? '') !== '' ? $cta['background'] : null;
    $hasPrimary = $text('primary_label') !== '' && $text('primary_url') !== '';
    $hasSecondary = $hasPrimary && $text('secondary_label') !== '' && $text('secondary_url') !== '';

    $media = static function () use ($background, $overlay, $cta, $h): void {
        if ($background === null) {
            return;
        }

        $focus = ImageFocus::normalise($cta['background_focus'] ?? null);
        $size = '';
        if (($background['width'] ?? null) !== null && ($background['height'] ?? null) !== null) {
            $size = ' width="' . (int) $background['width'] . '" height="' . (int) $background['height'] . '"';
        }
        ?>
        <div class="cta-band__media cta-band__media--overlay-<?= $h($overlay) ?>" aria-hidden="true">
          <img src="<?= $h((string) $background['image_path']) ?>" alt=""<?= $size ?> loading="lazy" decoding="async"<?= $focus !== ImageFocus::DEFAULT ? ' style="object-position: ' . $h(ImageFocus::objectPosition($focus)) . ';"' : '' ?>>
        </div>
        <?php
    };

    $sectionClass = 'cta-section' . ($fullWidth ? ' cta-section--full' : '') . ($fullWidth && $background !== null ? ' cta-section--has-media' : '');
    $bandClass = 'cta-band'
        . ($fullWidth ? '' : ' cta-band--card')
        . ' cta-band--align-' . $align
        . ' cta-band--lead-' . $leadWidth
        . (!$fullWidth && $background !== null ? ' cta-band--has-media' : '')
        . ($panel !== '' ? ' cta-band--has-panel' : '');
    $contentClass = 'cta-band__content' . ($panel !== '' ? ' cta-band__content--panel cta-band__content--panel-' . $panel : '');
    ?>
    <section class="<?= $h($sectionClass) ?>">
      <?php if ($fullWidth) { $media(); } ?>
      <div class="container">
        <div class="<?= $h($bandClass) ?>" data-reveal>
          <?php if (!$fullWidth) { $media(); } ?>
          <div class="<?= $h($contentClass) ?>">
            <?php render_eyebrow($text('eyebrow')); ?>
            <?php if ($text('title') !== ''): ?>
            <h2><?= $h($text('title')) ?></h2>
            <?php endif; ?>
            <?php if ($text('lead') !== ''): ?>
            <p class="lead"><?= $h($text('lead')) ?></p>
            <?php endif; ?>
            <?php if ($hasPrimary): ?>
            <div class="cta-band__actions">
              <a href="<?= $h($text('primary_url')) ?>" class="btn"><?= $h($text('primary_label')) ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
              </a>
              <?php if ($hasSecondary): ?>
              <a href="<?= $h($text('secondary_url')) ?>" class="btn btn--ghost"><?= $h($text('secondary_label')) ?></a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
    <?php
}
