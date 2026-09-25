<?php

declare(strict_types=1);

/**
 * Shared site-wide header/navigation for public pages.
 *
 * Navigation is fully CMS-managed (App\Service\NavigationService, backed by
 * nav_items — admin/navigation.php); this partial only resolves and renders
 * the current tree, it never hardcodes a menu item. The menu list itself,
 * with its submenus on up to three levels and the link/toggle pair of an
 * item that has one, is partials/main-nav-list.php.
 *
 * Include after <body> has been opened. The including page may set these
 * variables before requiring this file:
 *
 *   $activeNav        (string|null) a App\Service\RouteRegistry key to mark
 *                      with aria-current="page" (e.g. 'shop') — matches a
 *                      nav item whose own link_type is 'route' and points at
 *                      the same key. Defaults to none. A PAGE link is marked
 *                      by its own href instead, compared with the requested
 *                      path (NavigationService::isCurrent()), so a template
 *                      does not have to know which page it is.
 * MODULE SLOT. The action area on the right ends with whatever the ENABLED
 * modules put there (App\Module\ModuleDefinition::headerPartials()). Today
 * that is the Shop's mini-cart and nothing else, and it used to be forty lines
 * of cart markup written out here — which is why every page on the site,
 * webshop or not, loaded the cart's CSS and JS. This partial now contains no
 * shop markup at all: with the Shop switched off the loop below simply has
 * nothing to include.
 *
 * The action area also ends with the HEADER BUTTONS: zero, one or more
 * navigation items presented as a button (App\Service\NavigationPresentation),
 * in their own order, each with a class from a closed list of existing .btn
 * variants. A hidden button, or one whose target cannot currently be reached,
 * renders nothing at all. With no buttons there is no wrapper either, and the
 * header stays coherent on desktop and mobile; the wrapper's layout —
 * wrapping long labels, stacking on a phone — lives with the rest of the
 * header in assets/css/core.css.
 *
 * LABELS arrive as one string per item, already in the language of the
 * request (App\Service\NavigationLocalization), and are printed escaped. The
 * few words this partial owns itself — the skip link, the landmark names,
 * the menu button — are code catalogues read through
 * App\Service\Language\SiteText::escaped().
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Module\ModuleRegistry;
use App\Service\Analytics\PageViewTracker;
use App\Service\Branding;
use App\Service\Language\SiteText;
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

$siteHeader = NavigationService::header();
$navItems = $siteHeader['items'];
$headerButtons = $siteHeader['buttons'];
$activeNav = $activeNav ?? null;
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

$siteName = SiteSettings::get('site_name');
// App\Service\Branding returns one root-relative form (leading "/"), which
// this partial needs because it is also included from portfolio-detail.php,
// served from a nested path (/portfolio/project-slug) where a bare relative
// asset path would 404. It is an empty string when no logo is configured —
// a fresh install, before anybody has uploaded one — and the brand link then
// carries the site name as text rather than a broken image.
$logoPath = Branding::logoPath();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<a class="skip-link" href="#main"><?= SiteText::escaped(['nl' => 'Ga naar inhoud', 'en' => 'Skip to content']) ?></a>

<header class="site-header">
  <div class="container site-header__inner">
    <a href="<?= $h(\App\Service\Routing\LocalizedUrl::path('/')) ?>" class="brand" aria-label="<?= $h($siteName) ?> — home">
<?php if ($logoPath !== ''): ?>
      <img class="brand__logo" src="<?= $h($logoPath) ?>" alt="<?= $h($siteName) ?>"/>
<?php else: ?>
      <span class="brand__name"><?= $h($siteName) ?></span>
<?php endif; ?>
    </a>
    <nav class="main-nav" id="main-nav" aria-label="<?= SiteText::escaped(['nl' => 'Hoofdnavigatie', 'en' => 'Main navigation']) ?>">
      <div class="main-nav__panel">
<?php require __DIR__ . '/main-nav-list.php'; ?>
      <div class="header-actions">
<?php /* The switch exists only on a site that actually publishes more than
         one language. On a single-language site every option would show the
         visitor the same page in the same words, so there is nothing to
         switch between and nothing is rendered — no empty control, no stray
         focus stop.

         SINCE PHASE 6 THESE ARE LINKS, not buttons: each language has its own
         URL, so switching is a navigation and not a text swap in the browser
         (App\Service\Routing\LanguageSwitch). That is also what switches the
         old client-side swap off — assets/js/core.js binds to
         `.lang-switch button`, and there are none any more.

         A language this page has no version of is rendered as a disabled
         span: offering a link that 404s, or one that quietly shows another
         language's words, is worse than saying the version is not there. */ ?>
<?php if (\App\Service\Routing\LanguageSwitch::isAvailable()): ?>
        <div class="lang-switch" role="group" aria-label="<?= SiteText::escaped(['nl' => 'Taal', 'en' => 'Language']) ?>">
<?php foreach (\App\Service\Routing\LanguageSwitch::items() as $switchItem): ?>
<?php if ($switchItem['href'] === null): ?>
          <span class="lang-switch__unavailable" aria-disabled="true" title="<?= $h(\App\Service\Routing\LanguageSwitch::accessibleName($switchItem['code'])) ?>"><?= $h($switchItem['label']) ?></span>
<?php else: ?>
          <a href="<?= $h($switchItem['href']) ?>" hreflang="<?= $h($switchItem['code']) ?>" lang="<?= $h($switchItem['code']) ?>" aria-label="<?= $h(\App\Service\Routing\LanguageSwitch::accessibleName($switchItem['code'])) ?>"<?= $switchItem['is_current'] ? ' aria-current="true"' : '' ?>><?= $h($switchItem['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
        </div>
<?php endif; ?>
<?php foreach (ModuleRegistry::collect('headerPartials') as $headerPartial): ?>
<?php require $headerPartial; ?>
<?php endforeach; ?>
<?php if ($headerButtons !== []): ?>
        <div class="header-buttons">
<?php foreach ($headerButtons as $headerButton): ?>
          <a href="<?= $h($headerButton['href']) ?>" class="<?= $h($headerButton['class']) ?>"<?= $headerButton['open_in_new_tab'] ? ' target="_blank" rel="' . $h((string) $headerButton['rel']) . '"' : '' ?>><?= $h($headerButton['label']) ?></a>
<?php endforeach; ?>
        </div>
<?php endif; ?>
      </div>
      </div>
    </nav>
    <button class="nav-toggle" aria-label="<?= SiteText::escaped(['nl' => 'Menu', 'en' => 'Menu']) ?>" aria-expanded="false" aria-controls="main-nav">
      <span class="nav-toggle__box" aria-hidden="true">
        <span class="nav-toggle__bar"></span>
        <span class="nav-toggle__bar"></span>
        <span class="nav-toggle__bar"></span>
      </span>
    </button>
  </div>
</header>
