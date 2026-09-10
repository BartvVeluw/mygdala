<?php

declare(strict_types=1);

/**
 * Shared site-wide header/navigation for public pages.
 *
 * Navigation is fully CMS-managed (App\Service\NavigationService, backed by
 * nav_items — admin/navigation.php); this partial only resolves and renders
 * the current tree, it never hardcodes a menu item.
 *
 * Include after <body> has been opened. The including page may set these
 * variables before requiring this file:
 *
 *   $activeNav        (string|null) a App\Service\RouteRegistry key to mark
 *                      with aria-current="page" (e.g. 'shop') — matches a
 *                      nav item whose own link_type is 'route' and points at
 *                      the same key. Defaults to none.
 * MODULE SLOT. The action area on the right ends with whatever the ENABLED
 * modules put there (App\Module\ModuleDefinition::headerPartials()). Today
 * that is the Shop's mini-cart and nothing else, and it used to be forty lines
 * of cart markup written out here — which is why every page on the site,
 * webshop or not, loaded the cart's CSS and JS. This partial now contains no
 * shop markup at all: with the Shop switched off the loop below simply has
 * nothing to include.
 *
 * The action area also ends with ONE optional call-to-action button, whose
 * visibility, two labels and target are settings rather than markup — see
 * App\Service\HeaderCta. It renders nothing at all when it is switched off
 * or when its target cannot currently be reached, and the header stays
 * coherent without it on both desktop and mobile.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Module\ModuleRegistry;
use App\Service\Analytics\PageViewTracker;
use App\Service\Branding;
use App\Service\HeaderCta;
use App\Service\NavigationService;
use App\Service\SiteSettings;

/**
 * First-party pageview statistics (CMS dashboard). This partial is included
 * by every public page and by nothing under /admin or /api, which is exactly
 * why the counting happens here: CMS pageviews can never reach it. The call
 * decides for itself whether the request qualifies (a successful GET, not a
 * crawler, not an admin path), stores no IP address or user agent, sets no
 * cookie, and swallows every error — it can never affect what a visitor
 * sees. See App\Service\Analytics\PageViewTracker.
 */
PageViewTracker::trackCurrentRequest();

$navItems = NavigationService::tree();
$activeNav = $activeNav ?? null;

$siteName = SiteSettings::get('site_name');
// App\Service\Branding returns one root-relative form (leading "/"), which
// this partial needs because it is also included from portfolio-detail.php,
// served from a nested path (/portfolio/project-slug) where a bare relative
// asset path would 404. It is an empty string when no logo is configured —
// a fresh install, before anybody has uploaded one — and the brand link then
// carries the site name as text rather than a broken image.
$logoPath = Branding::logoPath();
$headerCta = HeaderCta::forHeader();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<a class="skip-link" href="#main" data-nl="Ga naar inhoud" data-en="Skip to content">Ga naar inhoud</a>

<header class="site-header">
  <div class="container site-header__inner">
    <a href="/index.php" class="brand" aria-label="<?= $h($siteName) ?> — home" data-nl-aria="<?= $h($siteName) ?> — home" data-en-aria="<?= $h($siteName) ?> — home">
<?php if ($logoPath !== ''): ?>
      <img class="brand__logo" src="<?= $h($logoPath) ?>" alt="<?= $h($siteName) ?>"/>
<?php else: ?>
      <span class="brand__name"><?= $h($siteName) ?></span>
<?php endif; ?>
    </a>
    <nav class="main-nav" id="main-nav" aria-label="Hoofdnavigatie">
      <div class="main-nav__panel">
      <ul class="main-nav__list">
<?php foreach ($navItems as $item): ?>
<?php
  $isActive = $item['route_key'] !== null && $activeNav === $item['route_key'];
  $hasChildren = $item['children'] !== [];
?>
<?php if ($hasChildren): ?>
        <li class="main-nav__item main-nav__item--has-children">
          <button type="button" class="main-nav__toggle" aria-haspopup="true" aria-expanded="false" <?= \App\Service\Language\SiteText::attrs($item['label_nl'], $item['label_en']) ?>>
            <?= $h(\App\Service\Language\SiteText::visible($item['label_nl'], $item['label_en'])) ?>
            <svg class="main-nav__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <ul class="main-nav__submenu">
<?php foreach ($item['children'] as $child): ?>
<?php if ($child['href'] === null) continue; ?>
            <li><a href="<?= $h($child['href']) ?>"<?= $child['open_in_new_tab'] ? ' target="_blank" rel="' . $h((string) $child['rel']) . '"' : '' ?> <?= \App\Service\Language\SiteText::attrs($child['label_nl'], $child['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($child['label_nl'], $child['label_en'])) ?></a></li>
<?php endforeach; ?>
          </ul>
        </li>
<?php elseif ($item['href'] !== null): ?>
        <li><a href="<?= $h($item['href']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?><?= $item['open_in_new_tab'] ? ' target="_blank" rel="' . $h((string) $item['rel']) . '"' : '' ?> <?= \App\Service\Language\SiteText::attrs($item['label_nl'], $item['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($item['label_nl'], $item['label_en'])) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
      </ul>
      <div class="header-actions">
<?php /* The switch exists only on a site that actually publishes more than
         one language (Multilingual V1, MULTILINGUAL.md). On a single-language
         site both buttons would have shown the visitor the same page in the
         same words, so there is nothing to switch between and nothing is
         rendered — no empty control, no stray focus stop. */ ?>
<?php if (\App\Service\Language\SiteText::showsLanguageSwitch()): ?>
        <div class="lang-switch" role="group" aria-label="Taal / Language">
<?php foreach (\App\Service\Language\SiteText::switchableLanguages() as $switchLanguage): ?>
          <button type="button" data-lang="<?= $h($switchLanguage) ?>" aria-pressed="<?= $switchLanguage === \App\Service\Language\SiteText::documentLanguage() ? 'true' : 'false' ?>"><?= $h(strtoupper($switchLanguage)) ?></button>
<?php endforeach; ?>
        </div>
<?php endif; ?>
<?php foreach (ModuleRegistry::collect('headerPartials') as $headerPartial): ?>
<?php require $headerPartial; ?>
<?php endforeach; ?>
<?php if ($headerCta !== null): ?>
        <a href="<?= $h($headerCta['href']) ?>" class="btn btn--sm"<?= $headerCta['open_in_new_tab'] ? ' target="_blank" rel="' . $h((string) $headerCta['rel']) . '"' : '' ?> <?= \App\Service\Language\SiteText::attrs($headerCta['label_nl'], $headerCta['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($headerCta['label_nl'], $headerCta['label_en'])) ?></a>
<?php endif; ?>
      </div>
      </div>
    </nav>
    <button class="nav-toggle" aria-label="Menu" data-nl-aria="Menu" data-en-aria="Menu" aria-expanded="false" aria-controls="main-nav">
      <span class="nav-toggle__box" aria-hidden="true">
        <span class="nav-toggle__bar"></span>
        <span class="nav-toggle__bar"></span>
        <span class="nav-toggle__bar"></span>
      </span>
    </button>
  </div>
</header>
