<?php

declare(strict_types=1);

/**
 * Everything a public page puts in its <head>: the cookie-consent bootstrap
 * and every stylesheet the page turned out to need.
 *
 * Include this LAST in <head>, after the page has said what it needs:
 *
 *   <?php
 *     SectionRegistry::collectPageAssets('index');   // this page's blocks
 *     PageAssets::requireStyle('assets/css/shop/shop.css');   // this route
 *     require __DIR__ . '/partials/page-assets.php';
 *   ?>
 *
 * Core's own stylesheet is not listed by any caller: App\Service\PageAssets
 * always puts the site shell in front, so a template cannot forget it and
 * cannot get the order wrong.
 *
 * The consent script stays a literal tag rather than going through
 * PageAssets, because PageAssets prints its scripts before </body> and this
 * one has to run in the head, before anything can set a cookie.
 *
 * It also pulls in partials/head-branding.php: the theme-colour meta tag
 * and the favicon link, which fifteen templates used to spell out for
 * themselves with a hardcoded colour and a hardcoded image type. One
 * include point means no template can forget them or drift.
 *
 * partials/page-scripts.php is the other half, just before </body>.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AssetVersion;
use App\Service\CookieConsentConfig;
use App\Service\PageAssets;

require __DIR__ . '/head-branding.php';

?>
<script>window.VVL_CONSENT_CONFIG = <?= json_encode(CookieConsentConfig::jsConfig()) ?>;</script>
<script src="/<?= ltrim(AssetVersion::url('assets/js/cookie-consent.js'), '/') ?>"></script>
<?php PageAssets::renderStyles(); ?>
