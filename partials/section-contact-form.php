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
 * from Site-instellingen rather than from page content.
 *
 * THE CARD SAYS NOTHING ABOUT THE BUSINESS ITSELF. It prints exactly what
 * Site-instellingen holds, under labels that fit any site ("E-mail",
 * "Plaats"), and leaves a line out when its value is empty. A promise such as
 * a response time, opening hours or "pickup by appointment" is content, not a
 * label: there is no field for it, so it is not printed at all. This card
 * once carried such copy from the site this CMS grew out of; it must not come
 * back as a hardcoded sentence (Tests\Service\BlockSampleContractTest scans
 * the rendered card).
 *
 * THE ATTACHMENT IS THIS BLOCK'S, not the form engine's. Forms V1 has no
 * upload field and the builder cannot create one (FORMS.md); this block's
 * form accepted an image or a PDF long before Core Forms existed, and
 * removing it would be a regression rather than a simplification. So the
 * control is printed here, the endpoint only accepts a file for a form a
 * block like this actually offers it on
 * (App\Service\Forms\FormAttachmentPolicy), and everything else about the
 * submission is ordinary Forms data.
 *
 * Two cards, not three: the "Liever direct mailen?" card that used to sit
 * under the details card is its own repeatable block now
 * (partials/section-contact-card.php).
 *
 * The block's own heading arrives as one LocalizedValue
 * (App\Service\Blocks\BlockLocalization) and is printed through SiteText's
 * visibleOf()/attrsOf(), plain text. The contact details are Site-instellingen
 * and still a Dutch/English pair until that domain moves (Multilingual 2.0
 * phase 4), so they keep the V1 helpers.
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
 * @param array{email: string, city_nl: string, city_en: string} $contact from Site-instellingen
 */

require_once __DIR__ . '/form.php';

use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormRenderState;

function render_section_contact_form(array $content, ?FormDefinition $form, FormRenderState $state, array $contact): void
{
    // Both are optional in Site-instellingen. A line whose value is missing is
    // left out rather than printed as a bare label.
    $contactEmail = trim($contact['email']);
    $contactCity = \App\Service\Language\LocalizedValue::ofDutchEnglish($contact['city_nl'], $contact['city_en']);
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    // A text in both site languages, printed like every other bilingual text
    // on the page: its data-nl/data-en pair for the language switch, then the
    // primary language visible (App\Service\Language\SiteText). Opens right
    // after the tag name and closes the start tag itself.
    $bilingual = static fn (string $nl, string $en): string => \App\Service\Language\SiteText::attrs($nl, $en) . '>'
        . htmlspecialchars(\App\Service\Language\SiteText::visible($nl, $en), ENT_QUOTES, 'UTF-8');
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <div class="contact-grid">

        <div class="contact-card" data-reveal>
          <h2 style="font-size:1.4rem; margin-bottom:1.5rem;" <?= \App\Service\Language\SiteText::attrsOf($content['title']) ?>><?= $h(\App\Service\Language\SiteText::visibleOf($content['title'])) ?></h2>

          <?php if ($form === null): ?>
            <?php
            // No form chosen, or the one that was chosen is gone or switched
            // off. The card keeps its heading and says nothing further —
            // never an empty form with a button that cannot work. The page
            // builder is where the editor is told about it.
            ?>
          <?php else: ?>
            <?php render_form($form, $state, render_contact_form_attachment_control($content, $state)); ?>
          <?php endif; ?>
        </div>

        <div data-reveal>
          <div class="contact-card">
            <h2 style="font-size:1.2rem; margin-bottom:1.25rem;"<?= $bilingual('Direct contact', 'Direct contact') ?></h2>
            <?php if ($contactEmail !== ''): ?>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>
              <div><strong<?= $bilingual('E-mail', 'Email') ?></strong><a href="mailto:<?= $h($contactEmail) ?>"><?= $h($contactEmail) ?></a></div>
            </div>
            <?php endif; ?>
            <?php if (!$contactCity->isEmpty()): ?>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s7-7.4 7-12.5A7 7 0 105 9.5C5 14.6 12 22 12 22z"/><circle cx="12" cy="9.5" r="2.4"/></svg>
              <div><strong<?= $bilingual('Plaats', 'Location') ?></strong><span<?= $bilingual($contact['city_nl'], $contact['city_en']) ?></span></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </section>
  <?php
}

/**
 * The optional attachment control, in the shape partials/form.php appends
 * after the form's own fields. Fixed markup with fixed, generic labels — it
 * is not a field an editor can configure, and it is not editor input, so it
 * is safe to hand over as HTML. The label says what the control is, never
 * what a particular business expects to receive.
 *
 * The accepted types and the 8 MB ceiling match what
 * App\Service\ContactAttachmentValidator has always enforced server-side;
 * the `accept` attribute is a convenience for the file picker and decides
 * nothing.
 *
 * @param array<string, mixed> $content
 * @return array<int, array{html: string}> empty when this block has attachments off
 */
function render_contact_form_attachment_control(array $content, FormRenderState $state): array
{
    if (empty($content['allow_attachment'])) {
        return [];
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = $state->id('bestand');

    ob_start();
    ?>
    <div class="form-field form-field--full">
      <label for="<?= $h($id) ?>"><span<?= \App\Service\Language\SiteText::attrs('Bijlage (optioneel)', 'Attachment (optional)') ?>><?= $h(\App\Service\Language\SiteText::visible('Bijlage (optioneel)', 'Attachment (optional)')) ?></span></label>
      <span class="hint" id="<?= $h($id) ?>-hint"<?= \App\Service\Language\SiteText::attrs('JPG, PNG, WEBP, GIF of PDF, max. 8 MB.', 'JPG, PNG, WEBP, GIF or PDF, max. 8 MB.') ?>><?= $h(\App\Service\Language\SiteText::visible('JPG, PNG, WEBP, GIF of PDF, max. 8 MB.', 'JPG, PNG, WEBP, GIF or PDF, max. 8 MB.')) ?></span>
      <input type="file" id="<?= $h($id) ?>" name="bestand" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf" aria-describedby="<?= $h($id) ?>-hint">
    </div>
    <?php

    return [['html' => (string) ob_get_clean()]];
}
