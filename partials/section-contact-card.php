<?php

/**
 * Renders the "Contactkaart" block (App\Service\ContactCardContent) — a card
 * with a heading, a short text and one button. Caller must already have
 * checked $card['state'] !== ContactCardContent::STATE_HIDDEN.
 *
 * The markup is the Contact page's former "Liever direct mailen?" card,
 * extracted verbatim (same `.contact-card`, same heading and text styling,
 * same `.btn.btn--ghost.btn--block`). What changed when it became a block of
 * its own is where it sits: it used to be stacked under the "Direct contact"
 * card inside the quote form block's two-column `.contact-grid`, and now it
 * is a positioned block in the page's one ordered list, centred in the same
 * narrow reading column the Rich text block uses.
 *
 * The button renders only when it has both a label and a URL — a half-filled
 * button would be dead. An empty stored URL is resolved to the site's
 * `mailto:` address by ContactCardContent, so a card with no configured link
 * still mails the address from Site-instellingen, exactly as before.
 *
 * @param array<string, string> $card see ContactCardContent::forSection()
 */
function render_section_contact_card(array $card): void
{
    if ($card['title_nl'] === '' && $card['body_nl'] === '') {
        // An empty card renders nothing at all rather than an empty box —
        // the same "empty block leaves no gap" rule as the Rich text block.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $hasButton = $card['button_label_nl'] !== '' && $card['button_url'] !== '';
    ?>
  <section style="padding-top:0;">
    <div class="container container--narrow">
      <div class="contact-card" data-reveal>
        <?php if ($card['title_nl'] !== ''): ?>
        <h3 style="margin-bottom:0.75rem;" data-nl="<?= $h($card['title_nl']) ?>" data-en="<?= $h($card['title_en']) ?>"><?= $h($card['title_nl']) ?></h3>
        <?php endif; ?>
        <?php if ($card['body_nl'] !== ''): ?>
        <p style="color:var(--color-text-muted); font-size:0.92rem; margin-bottom:<?= $hasButton ? '1rem' : '0' ?>;" data-nl="<?= $h($card['body_nl']) ?>" data-en="<?= $h($card['body_en']) ?>"><?= $h($card['body_nl']) ?></p>
        <?php endif; ?>
        <?php if ($hasButton): ?>
        <a href="<?= $h($card['button_url']) ?>" class="btn btn--ghost btn--block" data-nl="<?= $h($card['button_label_nl']) ?>" data-en="<?= $h($card['button_label_en']) ?>"><?= $h($card['button_label_nl']) ?></a>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php
}
