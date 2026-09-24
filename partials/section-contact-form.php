<?php

/**
 * Renders the "Offerte-/contactformulier" block (App\Service\
 * ContactFormContent): the form card and, beside it, the "Direct contact"
 * details card, in one `.contact-grid`. Caller must already have checked
 * $content['state'] !== ContactFormContent::STATE_HIDDEN.
 *
 * SINCE CORE FORMS THE FORM ITSELF IS NOT HERE. The fields, the validation,
 * the error messages and the confirmation all come from the Form definition
 * this block points at, rendered by partials/form.php like every other form
 * on the site. What stayed is the pairing this block exists for: the details
 * card in the second column, whose e-mail address and town or region come
 * from Instellingen rather than from page content.
 *
 * THE CARD SAYS NOTHING ABOUT THE BUSINESS ITSELF. It prints exactly what
 * Instellingen holds, under labels that fit any site ("E-mail",
 * "Plaats"), and leaves a line out when its value is empty. A promise such as
 * a response time, opening hours or "pickup by appointment" is content, not a
 * label: there is no field for it, so it is not printed at all. This card
 * once carried such copy from the site this CMS grew out of; it must not come
 * back as a hardcoded sentence (Tests\Service\BlockSampleContractTest scans
 * the rendered card).
 *
 * NO FILE INPUT OF ITS OWN any more. Until Forms 2.0 phase 2 this block
 * appended a fixed "Bijlage" control to its form, switched on per block. A
 * file is an ordinary field now (the `file` type, FORMS.md "Bestand
 * uploaden"): an editor adds one to the form, or leaves it out, like any
 * other field. Migration 20260925100000 gave every form such a block had the
 * attachment switched on for an explicit field, so no site lost it.
 *
 * Two cards, not three: the "Liever direct mailen?" card that used to sit
 * under the details card is its own repeatable block now
 * (partials/section-contact-card.php).
 *
 * The block's own heading arrives as one string, already in the language of
 * the request (App\Service\Blocks\BlockLocalization), plain text. So does the
 * place from Instellingen, which is website text per language
 * (App\Service\LocalizedSiteSettings). The card's own fixed labels
 * ("Plaats", "E-mail") are code catalogues read through
 * App\Service\Language\SiteText::escaped().
 *
 * Everything this file shows arrives as arguments: the form and its state,
 * and the contact details, all looked up by
 * App\Service\Blocks\ContactFormBlock::render(). This file only renders, so
 * the block library can show the real markup with a form and details that
 * exist only in memory (App\Service\Blocks\BlockSamples).
 *
 * @param array<string, mixed>                             $content see ContactFormContent::forSection()
 * @param FormDefinition|null                              $form    null when no usable form is chosen
 * @param FormRenderState                                  $state   this instance's state
 * @param array{email: string, city: string}               $contact from Instellingen
 */

require_once __DIR__ . '/form.php';

use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormRenderState;
use App\Service\Language\SiteText;

function render_section_contact_form(array $content, ?FormDefinition $form, FormRenderState $state, array $contact): void
{
    // Both are optional in Instellingen. A line whose value is missing is
    // left out rather than printed as a bare label.
    $contactEmail = trim($contact['email']);
    $contactCity = $contact['city'];
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <div class="contact-grid">

        <div class="contact-card" data-reveal>
          <h2 style="font-size:1.4rem; margin-bottom:1.5rem;"><?= $h($content['title']) ?></h2>

          <?php if ($form === null): ?>
            <?php
            // No form chosen, or the one that was chosen is gone or switched
            // off. The card keeps its heading and says nothing further —
            // never an empty form with a button that cannot work. The page
            // builder is where the editor is told about it.
            ?>
          <?php else: ?>
            <?php render_form($form, $state); ?>
          <?php endif; ?>
        </div>

        <div data-reveal>
          <div class="contact-card">
            <h2 style="font-size:1.2rem; margin-bottom:1.25rem;"><?= SiteText::escaped(['nl' => 'Direct contact', 'en' => 'Direct contact']) ?></h2>
            <?php if ($contactEmail !== ''): ?>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>
              <div><strong><?= SiteText::escaped(['nl' => 'E-mail', 'en' => 'Email']) ?></strong><a href="mailto:<?= $h($contactEmail) ?>"><?= $h($contactEmail) ?></a></div>
            </div>
            <?php endif; ?>
            <?php if ($contactCity !== ''): ?>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s7-7.4 7-12.5A7 7 0 105 9.5C5 14.6 12 22 12 22z"/><circle cx="12" cy="9.5" r="2.4"/></svg>
              <div><strong><?= SiteText::escaped(['nl' => 'Plaats', 'en' => 'Location']) ?></strong><span><?= $h($contactCity) ?></span></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </section>
  <?php
}
