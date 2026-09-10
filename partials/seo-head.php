<?php

declare(strict_types=1);

/**
 * THE shared <head> SEO block. Every public page in this project renders its
 * title, description, canonical, robots, Open Graph, Twitter card and
 * structured data here and nowhere else.
 *
 * Include inside <head>, having first set exactly one variable:
 *
 *   $seoMetadata (App\Service\SeoMetadata) the already-resolved metadata
 *
 * This partial RESOLVES NOTHING. It does not know what a page, a product or
 * a collection is, it never reads a Site Setting, and it never builds a URL.
 * Which title a page gets, which image represents it and whether it may be
 * indexed are decided by the resolver that owns that content type —
 * App\Service\PageSeo, App\Service\ProductSeo,
 * App\Service\CollectionContent — so the head, the Open Graph tags, the
 * structured data and the sitemap can never disagree.
 *
 * Before this file existed there were three renderers and seven hand-written
 * heads: partials/page-head.php for CMS pages, partials/shop-seo-head.php
 * for the shop, partials/og-meta.php underneath both, and inline <title>/
 * <meta>/<link> markup in cart.php, checkout.php, bestelling-status.php,
 * cookiebeleid.php, herroeping.php, portfolio-detail.php and
 * personaliseren.php. Four title conventions, a canonical URL hardcoded to
 * one domain, and two indexable checkout pages came out of that.
 *
 * BILINGUAL, unchanged from what it replaces: the Dutch value is the real
 * tag content, with data-nl/data-en (data-nl-content/data-en-content on
 * <meta>) so assets/js/core.js's applyLang() swaps them on the language
 * toggle. Open Graph and Twitter carry the Dutch copy — a crawler fetches
 * one document in one language, and this project has no separate localized
 * URLs to offer it (hence no hreflang; see SEO.md).
 *
 * ONLY NON-EMPTY TAGS ARE RENDERED. No description tag when there is no
 * description, no canonical when the page has no canonical URL, no og:image
 * when neither the page nor the site has an image, and no twitter:* block at
 * all without one. An empty tag is worse than an absent tag, and a fresh
 * install that has configured nothing must still produce a valid head.
 *
 * ESCAPING. Every value goes through htmlspecialchars(ENT_QUOTES) on its way
 * into an attribute — all of it is administrator-typed content out of the
 * database. The JSON-LD is encoded with json_encode() from a PHP array,
 * never by string concatenation: JSON_HEX_TAG/AMP/APOS/QUOT turn <, >, &, '
 * and " into \uXXXX, so a name or description containing quotes, HTML or a
 * literal "</script>" cannot terminate the script element, whatever anybody
 * types. JSON_UNESCAPED_UNICODE keeps accents and emoji readable (the
 * response is UTF-8) and JSON_UNESCAPED_SLASHES keeps URLs readable.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\SeoMetadata;

/** @var SeoMetadata $seoMetadata */
$seoHeadH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$seoHeadTwitterCard = $seoMetadata->twitterCard();
?>
<title data-nl="<?= $seoHeadH($seoMetadata->titleNl) ?>" data-en="<?= $seoHeadH($seoMetadata->titleEn) ?>"><?= $seoHeadH($seoMetadata->titleNl) ?></title>
<?php if ($seoMetadata->hasDescription()): ?>
<meta name="description" content="<?= $seoHeadH($seoMetadata->descriptionNl) ?>" data-nl-content="<?= $seoHeadH($seoMetadata->descriptionNl) ?>" data-en-content="<?= $seoHeadH($seoMetadata->descriptionEn) ?>">
<?php endif; ?>
<meta name="robots" content="<?= $seoHeadH($seoMetadata->robots) ?>">
<?php if ($seoMetadata->canonical !== null): ?>
<link rel="canonical" href="<?= $seoHeadH($seoMetadata->canonical) ?>">
<?php endif; ?>
<?php
/**
 * THE SHARE BLOCK — Open Graph and Twitter — is rendered only for a page
 * that HAS a canonical URL, because that is precisely what "a page somebody
 * can share" means. A 404 and the order-status page have no canonical (there
 * is no one URL they are the canonical version of), so they get no share
 * preview; before this, a 404 quietly advertised the site's default sharing
 * image under the title "Pagina niet gevonden".
 *
 * Deliberately NOT tied to indexability instead: the cart, the checkout and
 * the legal pages are `noindex` yet perfectly linkable, and they carried
 * these tags before SEO Foundation V1 — nothing here is in the business of
 * removing metadata that works.
 */
?>
<?php if ($seoMetadata->canonical !== null): ?>
<meta property="og:title" content="<?= $seoHeadH($seoMetadata->titleNl) ?>">
<?php if ($seoMetadata->hasDescription()): ?>
<meta property="og:description" content="<?= $seoHeadH($seoMetadata->descriptionNl) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= $seoHeadH($seoMetadata->canonical) ?>">
<meta property="og:type" content="<?= $seoHeadH($seoMetadata->ogType) ?>">
<?php if ($seoMetadata->ogImageUrl !== null): ?>
<meta property="og:image" content="<?= $seoHeadH($seoMetadata->ogImageUrl) ?>">
<?php endif; ?>
<?php if (\App\Service\SeoDefaults::siteName() !== ''): ?>
<meta property="og:site_name" content="<?= $seoHeadH(\App\Service\SeoDefaults::siteName()) ?>">
<?php endif; ?>
<?php if ($seoHeadTwitterCard !== null): ?>
<meta name="twitter:card" content="<?= $seoHeadH($seoHeadTwitterCard) ?>">
<meta name="twitter:title" content="<?= $seoHeadH($seoMetadata->titleNl) ?>">
<?php if ($seoMetadata->hasDescription()): ?>
<meta name="twitter:description" content="<?= $seoHeadH($seoMetadata->descriptionNl) ?>">
<?php endif; ?>
<meta name="twitter:image" content="<?= $seoHeadH((string) $seoMetadata->ogImageUrl) ?>">
<?php endif; ?>
<?php endif; ?>
<?php if ($seoMetadata->jsonLd !== null): ?>
<script type="application/ld+json"><?= json_encode(
    $seoMetadata->jsonLd,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<?php endif; ?>
