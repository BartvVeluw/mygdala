<?php

declare(strict_types=1);

/**
 * Shared site-wide footer for public pages. Include right before </body>.
 * Columns and links are fully CMS-managed (App\Service\FooterService,
 * admin/footer.php) — see db/migrations/20260907220000_create_footer_tables.php.
 * Company identity (logo/name/email/phone/KVK/description) stays the
 * single source of truth in App\Service\SiteSettings; this partial only
 * decides whether to show each one, via FooterService::brandSettings().
 *
 * Two more pieces became data rather than markup in Header & Footer V1: the
 * closing SLOGAN (FooterService::slogan(), which used to be a literal here)
 * and the SOCIAL row (App\Service\SocialProfiles — a closed registry of
 * networks; since Footer phase B the profiles are repeatable rows with their
 * own order and visibility). Neither renders anything at all when it is not
 * configured: no empty line, no empty icon row, no heading over nothing.
 * Everything here is managed on the one Footer screen (admin/footer.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\Branding;
use App\Service\CookieConsentConfig;
use App\Service\FooterService;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;

$cookieFooterLink = CookieConsentConfig::footerLink();
$footerColumns = FooterService::columns();
$brand = FooterService::brandSettings();
$slogan = FooterService::slogan();
$socialProfiles = SocialProfiles::forFooter();

$siteName = SiteSettings::get('site_name');
// The ALTERNATE logo, which falls back to the primary one when none is set —
// App\Service\Branding owns that fallback, and also the root-relative form
// this partial needs (see partials/header.php's identical note). Empty means
// no logo is configured at all, and the footer then shows the site name.
$logoPath = Branding::alternateLogoPath();
$email = SiteSettings::get('email');
$phone = SiteSettings::get('company_phone');
$kvkNumber = SiteSettings::get('kvk_number');
$footerDescriptionNl = SiteSettings::get('footer_description_nl');
$footerDescriptionEn = SiteSettings::get('footer_description_en');
$copyright = FooterService::renderCopyright();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <?php if ($brand['show_logo'] && $logoPath !== ''): ?>
        <div class="footer-brand">
          <img class="footer-brand__logo" src="<?= $h($logoPath) ?>" alt="<?= $h($siteName) ?>"/>
        </div>
        <?php elseif ($brand['show_logo']): ?>
        <p class="footer-brand__name"><?= $h($siteName) ?></p>
        <?php endif; ?>
        <?php if ($brand['show_company_name']): ?>
        <p class="footer-brand__name"><?= $h($siteName) ?></p>
        <?php endif; ?>
        <?php // Optional since Site-instellingen stopped requiring it: no text, no empty paragraph. ?>
        <?php if (trim($footerDescriptionNl) !== '' || trim($footerDescriptionEn) !== ''): ?>
        <p <?= \App\Service\Language\SiteText::attrs($footerDescriptionNl, $footerDescriptionEn) ?>><?= $h(\App\Service\Language\SiteText::visible($footerDescriptionNl, $footerDescriptionEn)) ?></p>
        <?php endif; ?>
        <?php if ($brand['show_email'] && $email !== ''): ?>
        <p><a href="mailto:<?= $h($email) ?>"><?= $h($email) ?></a></p>
        <?php endif; ?>
        <?php if ($brand['show_phone'] && $phone !== ''): ?>
        <p><a href="tel:<?= $h((string) preg_replace('/\s+/', '', $phone)) ?>"><?= $h($phone) ?></a></p>
        <?php endif; ?>
        <?php if ($brand['show_kvk'] && $kvkNumber !== ''): ?>
        <p data-nl="KVK <?= $h($kvkNumber) ?>" data-en="Chamber of Commerce <?= $h($kvkNumber) ?>">KVK <?= $h($kvkNumber) ?></p>
        <?php endif; ?>
        <?php if ($socialProfiles !== []): ?>
        <ul class="social-row">
          <?php foreach ($socialProfiles as $profile): ?>
          <?php
            // The accessible name is built here, from the site name and the
            // network label, so an icon-only link is never announced as
            // "link" or read out as its URL. Both are already-known values:
            // the network label comes from the closed registry and the site
            // name from Site Settings, so nothing a visitor or an editor
            // typed becomes markup. Two profiles on one network get a number
            // each, so a screen reader can tell the two links apart.
            $socialLabel = $siteName . ' op ' . $profile['label'];
            $socialLabelEn = $siteName . ' on ' . $profile['label'];
            if ($profile['number'] !== null) {
                $socialLabel .= ' (' . $profile['number'] . ')';
                $socialLabelEn .= ' (' . $profile['number'] . ')';
            }
          ?>
          <li><a href="<?= $h($profile['url']) ?>" target="_blank" rel="noopener noreferrer me" aria-label="<?= $h($socialLabel) ?>" data-nl-aria="<?= $h($socialLabel) ?>" data-en-aria="<?= $h($socialLabelEn) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $profile['icon'] ?></svg></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
<?php foreach ($footerColumns as $column): ?>
      <div class="footer-col">
        <h4 <?= \App\Service\Language\SiteText::attrs($column['title_nl'], $column['title_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($column['title_nl'], $column['title_en'])) ?></h4>
        <ul>
<?php foreach ($column['links'] as $link): ?>
<?php if ($link['is_action']): ?>
          <li><button type="button" class="footer-col__action-link" data-cookie-settings-open <?= \App\Service\Language\SiteText::attrs($link['label_nl'], $link['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($link['label_nl'], $link['label_en'])) ?></button></li>
<?php else: ?>
          <li><a href="<?= $h((string) $link['href']) ?>"<?= $link['open_in_new_tab'] ? ' target="_blank" rel="' . $h((string) $link['rel']) . '"' : '' ?> <?= \App\Service\Language\SiteText::attrs($link['label_nl'], $link['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($link['label_nl'], $link['label_en'])) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
        </ul>
      </div>
<?php endforeach; ?>
    </div>
    <div class="footer-bottom">
      <span><?= $h($copyright) ?></span>
      <span class="footer-legal-links">
        <a href="cookiebeleid.php" <?= \App\Service\Language\SiteText::attrs($cookieFooterLink['policy_label_nl'], $cookieFooterLink['policy_label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cookieFooterLink['policy_label_nl'], $cookieFooterLink['policy_label_en'])) ?></a>
        <button type="button" class="footer-legal-links__btn" data-cookie-settings-open <?= \App\Service\Language\SiteText::attrs($cookieFooterLink['label_nl'], $cookieFooterLink['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($cookieFooterLink['label_nl'], $cookieFooterLink['label_en'])) ?></button>
      </span>
<?php if ($slogan !== null): ?>
      <span <?= \App\Service\Language\SiteText::attrs($slogan['nl'], $slogan['en']) ?>><?= $h(\App\Service\Language\SiteText::visible($slogan['nl'], $slogan['en'])) ?></span>
<?php endif; ?>
    </div>
  </div>
</footer>

<?php require __DIR__ . '/cookie-consent.php'; ?>
