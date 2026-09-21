<?php

declare(strict_types=1);

/**
 * Shared site-wide cookie consent banner + preferences modal. Included once
 * from partials/footer.php (which every public page already requires), so
 * it exists exactly once per page without any per-page duplication.
 *
 * All copy/category data comes from App\Service\CookieConsentConfig, already
 * in the language of the request — the single source of truth also read by
 * assets/js/cookie-consent.js (via the
 * inline VVL_CONSENT_CONFIG blob printed in each page's <head>) and by
 * cookiebeleid.php. Behaviour (show/hide, storing the decision, focus
 * handling) lives entirely in assets/js/cookie-consent.js; this partial is
 * markup only.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\CookieConsentConfig;

$categories = CookieConsentConfig::categories();
$banner = CookieConsentConfig::banner();
$modal = CookieConsentConfig::modal();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="cookie-consent" data-cookie-consent data-consent-version="<?= CookieConsentConfig::CONSENT_VERSION ?>" hidden>

  <div class="cookie-banner" data-cookie-banner role="region" aria-label="<?= $h($banner['region']) ?>" hidden>
    <div class="cookie-banner__inner">
      <div class="cookie-banner__text">
        <p class="cookie-banner__title"><?= $h($banner['title']) ?></p>
        <?php /* The one value printed as markup: escaped text around a link to
                 the cookie policy that App\Service\CookieConsentConfig
                 builds itself, in the language being read. */ ?>
        <p class="cookie-banner__desc"><?= $banner['description_html'] ?></p>
      </div>
      <div class="cookie-banner__actions">
        <button type="button" class="btn btn--ghost btn--sm" data-cookie-action="manage"><?= $h($banner['manage']) ?></button>
        <button type="button" class="btn btn--sm" data-cookie-action="reject"><?= $h($banner['reject_optional']) ?></button>
        <button type="button" class="btn btn--sm" data-cookie-action="accept-all"><?= $h($banner['accept_all']) ?></button>
      </div>
    </div>
  </div>

  <div class="cookie-modal" data-cookie-modal hidden>
    <div class="cookie-modal__backdrop" data-cookie-modal-backdrop></div>
    <div class="cookie-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cookie-modal-title" tabindex="-1">
      <button type="button" class="cookie-modal__close" data-cookie-action="close-modal" aria-label="<?= $h($modal['close']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>

      <h2 id="cookie-modal-title"><?= $h($modal['title']) ?></h2>
      <p class="cookie-modal__intro"><?= $h($modal['description']) ?></p>

      <ul class="cookie-categories">
<?php foreach ($categories as $key => $cat): ?>
        <li class="cookie-category">
          <div class="cookie-category__head">
            <label class="cookie-category__toggle">
              <input
                type="checkbox"
                data-consent-category="<?= $h($key) ?>"
<?php if ($cat['required']): ?>
                checked
                disabled
<?php endif; ?>
              />
              <span class="cookie-category__label"><?= $h($cat['label']) ?></span>
            </label>
<?php if ($cat['required']): ?>
            <span class="cookie-category__badge"><?= $h($modal['always_on']) ?></span>
<?php endif; ?>
          </div>
          <p class="cookie-category__desc"><?= $h($cat['description']) ?></p>
        </li>
<?php endforeach; ?>
      </ul>

      <p class="cookie-modal__policy-link">
        <a href="<?= $h(CookieConsentConfig::policyUrl()) ?>"><?= $h($modal['policy_link']) ?></a>
      </p>

      <div class="cookie-modal__actions">
        <button type="button" class="btn btn--sm" data-cookie-action="accept-all-modal"><?= $h($modal['accept_all']) ?></button>
        <button type="button" class="btn btn--ghost btn--sm" data-cookie-action="save"><?= $h($modal['save']) ?></button>
      </div>
    </div>
  </div>

</div>
