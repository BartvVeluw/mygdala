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
 * card in the second column, whose e-mail address and workshop city come
 * from Site-instellingen rather than from page content.
 *
 * THE ATTACHMENT IS THIS BLOCK'S, not the form engine's. Forms V1 has no
 * upload field and the builder cannot create one (FORMS.md); this site's
 * quote form has accepted a photo or a PDF since long before Core Forms, and
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
 * @param array<string, mixed> $content    see ContactFormContent::forSection()
 * @param string               $pageSlug   the page this instance sits on
 * @param string               $sectionKey this instance's key
 */

require_once __DIR__ . '/form.php';

use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormRenderState;

function render_section_contact_form(array $content, string $pageSlug, string $sectionKey): void
{
    $contactEmail = \App\Service\SiteSettings::get('email');
    $contactCityNl = \App\Service\SiteSettings::get('city_nl');
    $contactCityEn = \App\Service\SiteSettings::get('city_en');
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $form = FormCatalog::renderable($content['form_id'] ?? null);
    $state = FormRenderState::forInstance($pageSlug, $sectionKey);
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <div class="contact-grid">

        <div class="contact-card" data-reveal>
          <h2 style="font-size:1.4rem; margin-bottom:1.5rem;" data-nl="<?= $h($content['title_nl']) ?>" data-en="<?= $h($content['title_en']) ?>"><?= $h($content['title_nl']) ?></h2>

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
            <h2 style="font-size:1.2rem; margin-bottom:1.25rem;" data-nl="Direct contact" data-en="Direct contact">Direct contact</h2>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>
              <div><strong data-nl="E-mail" data-en="Email">E-mail</strong><a href="mailto:<?= $h($contactEmail) ?>"><?= $h($contactEmail) ?></a></div>
            </div>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s7-7.4 7-12.5A7 7 0 105 9.5C5 14.6 12 22 12 22z"/><circle cx="12" cy="9.5" r="2.4"/></svg>
              <div><strong data-nl="Werkplaats" data-en="Workshop">Werkplaats</strong><span data-nl="<?= $h($contactCityNl) ?> — ophalen op afspraak" data-en="<?= $h($contactCityEn) ?> — pickup by appointment"><?= $h($contactCityNl) ?> — ophalen op afspraak</span></div>
            </div>
            <div class="contact-detail">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
              <div><strong data-nl="Reactietijd" data-en="Response time">Reactietijd</strong><span data-nl="Meestal binnen enkele werkdagen" data-en="Usually within a few business days">Meestal binnen enkele werkdagen</span></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
  <?php
}

/**
 * The optional "Foto, logo of ontwerp" control, in the shape
 * partials/form.php appends after the form's own fields. Fixed markup with
 * fixed copy — it is not a field an editor can configure, and it is not
 * editor input, so it is safe to hand over as HTML.
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
      <label for="<?= $h($id) ?>"><span data-nl="Foto, logo of ontwerp (optioneel)" data-en="Photo, logo or design (optional)">Foto, logo of ontwerp (optioneel)</span></label>
      <span class="hint" id="<?= $h($id) ?>-hint" data-nl="JPG, PNG, WEBP, GIF of PDF, max. 8 MB." data-en="JPG, PNG, WEBP, GIF or PDF, max. 8 MB.">JPG, PNG, WEBP, GIF of PDF, max. 8 MB.</span>
      <input type="file" id="<?= $h($id) ?>" name="bestand" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf" aria-describedby="<?= $h($id) ?>-hint">
    </div>
    <?php

    return [['html' => (string) ob_get_clean()]];
}
