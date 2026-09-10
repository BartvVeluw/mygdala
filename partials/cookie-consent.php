<?php

declare(strict_types=1);

/**
 * Shared site-wide cookie consent banner + preferences modal. Included once
 * from partials/footer.php (which every public page already requires), so
 * it exists exactly once per page without any per-page duplication.
 *
 * All copy/category data comes from App\Service\CookieConsentConfig — the
 * single source of truth also read by assets/js/cookie-consent.js (via the
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

  <div class="cookie-banner" data-cookie-banner role="region" aria-label="Cookiemelding" data-nl-aria="Cookiemelding" data-en-aria="Cookie notice" hidden>
    <div class="cookie-banner__inner">
      <div class="cookie-banner__text">
        <p class="cookie-banner__title" <?= \App\Service\Language\SiteText::attrs($banner['title_nl'], $banner['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($banner['title_nl'], $banner['title_en'])) ?></p>
        <p class="cookie-banner__desc" data-nl="<?= $h($banner['description_nl']) ?>" data-en="<?= $h($banner['description_en']) ?>"><?= $banner['description_nl'] ?></p>
      </div>
      <div class="cookie-banner__actions">
        <button type="button" class="btn btn--ghost btn--sm" data-cookie-action="manage" <?= \App\Service\Language\SiteText::attrs($banner['manage_nl'], $banner['manage_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($banner['manage_nl'], $banner['manage_en'])) ?></button>
        <button type="button" class="btn btn--sm" data-cookie-action="reject" <?= \App\Service\Language\SiteText::attrs($banner['reject_optional_nl'], $banner['reject_optional_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($banner['reject_optional_nl'], $banner['reject_optional_en'])) ?></button>
        <button type="button" class="btn btn--sm" data-cookie-action="accept-all" <?= \App\Service\Language\SiteText::attrs($banner['accept_all_nl'], $banner['accept_all_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($banner['accept_all_nl'], $banner['accept_all_en'])) ?></button>
      </div>
    </div>
  </div>

  <div class="cookie-modal" data-cookie-modal hidden>
    <div class="cookie-modal__backdrop" data-cookie-modal-backdrop></div>
    <div class="cookie-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cookie-modal-title" tabindex="-1">
      <button type="button" class="cookie-modal__close" data-cookie-action="close-modal" aria-label="<?= $h($modal['close_nl']) ?>" data-nl-aria="<?= $h($modal['close_nl']) ?>" data-en-aria="<?= $h($modal['close_en']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>

      <h2 id="cookie-modal-title" <?= \App\Service\Language\SiteText::attrs($modal['title_nl'], $modal['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($modal['title_nl'], $modal['title_en'])) ?></h2>
      <p class="cookie-modal__intro" data-nl="<?= $h($modal['description_nl']) ?>" data-en="<?= $h($modal['description_en']) ?>"><?= $modal['description_nl'] ?></p>

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
              <span class="cookie-category__label" <?= \App\Service\Language\SiteText::attrs($cat['label_nl'], $cat['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cat['label_nl'], $cat['label_en'])) ?></span>
            </label>
<?php if ($cat['required']): ?>
            <span class="cookie-category__badge" <?= \App\Service\Language\SiteText::attrs($modal['always_on_nl'], $modal['always_on_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($modal['always_on_nl'], $modal['always_on_en'])) ?></span>
<?php endif; ?>
          </div>
          <p class="cookie-category__desc" <?= \App\Service\Language\SiteText::attrs($cat['description_nl'], $cat['description_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cat['description_nl'], $cat['description_en'])) ?></p>
        </li>
<?php endforeach; ?>
      </ul>

      <p class="cookie-modal__policy-link">
        <a href="cookiebeleid.php" <?= \App\Service\Language\SiteText::attrs($modal['policy_link_nl'], $modal['policy_link_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($modal['policy_link_nl'], $modal['policy_link_en'])) ?></a>
      </p>

      <div class="cookie-modal__actions">
        <button type="button" class="btn btn--sm" data-cookie-action="accept-all-modal" <?= \App\Service\Language\SiteText::attrs($modal['accept_all_nl'], $modal['accept_all_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($modal['accept_all_nl'], $modal['accept_all_en'])) ?></button>
        <button type="button" class="btn btn--ghost btn--sm" data-cookie-action="save" <?= \App\Service\Language\SiteText::attrs($modal['save_nl'], $modal['save_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($modal['save_nl'], $modal['save_en'])) ?></button>
      </div>
    </div>
  </div>

</div>
