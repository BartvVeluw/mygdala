<?php

/**
 * The site's one lightbox overlay, driven by assets/js/lightbox.js: a
 * gallery block's and a Portfolio project page's pictures open in it
 * (Portfolio 2.0, MODULES.md "Portfolio").
 *
 * Printed at most once per page, and outside any section: a `position:
 * fixed` overlay inside a GSAP-transformed section would be positioned
 * against that section instead of the viewport. A gallery block claims the
 * one print through App\Service\ItemGalleryContent::claimLightboxOverlay();
 * portfolio-detail.php, which has no blocks of its own, prints it itself.
 *
 * A dialog (role="dialog", aria-modal), hidden from assistive technology
 * until it opens. Every word is in the language of the request, through
 * SiteText, like every other fixed word of the public site.
 */
function render_lightbox_overlay(): void
{
    ?>
<div class="lightbox" data-lightbox role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Vergrote afbeelding', 'en' => 'Enlarged image']) ?>">
  <button type="button" class="lightbox__close" data-lightbox-close aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Sluiten', 'en' => 'Close']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <button type="button" class="lightbox__nav lightbox__nav--prev" data-lightbox-prev aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Vorige afbeelding', 'en' => 'Previous image']) ?>" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
  </button>
  <button type="button" class="lightbox__nav lightbox__nav--next" data-lightbox-next aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Volgende afbeelding', 'en' => 'Next image']) ?>" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
  </button>
  <div class="lightbox__inner">
    <img alt="" data-lightbox-image>
    <p class="lightbox__caption" data-lightbox-caption></p>
    <p class="lightbox__counter" data-lightbox-counter aria-live="polite" hidden></p>
  </div>
</div>
    <?php
}
