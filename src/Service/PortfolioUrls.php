<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Routing\LocalizedUrl;

/**
 * The Portfolio's fixed public addresses (Portfolio 2.0, MODULES.md
 * "Portfolio"), the counterpart of App\Service\Blog\BlogUrls:
 *
 *   /portfolio          the overview: the module root, and the `route_path`
 *                       of the CMS page with content key "portfolio", so every
 *                       link, canonical, sitemap entry and breadcrumb that
 *                       resolves that page says /portfolio
 *   /portfolio.php      the overview's address before Portfolio 2.0, answered
 *                       with a permanent redirect to /portfolio
 *   /portfolio/<slug>   a project page (PortfolioGalleryContent::publicPath())
 *
 * The routes themselves are App\Module\PortfolioModule::publicRoutes().
 */
final class PortfolioUrls
{
    public const OVERVIEW_PATH = '/portfolio';

    public const LEGACY_OVERVIEW_PATH = '/portfolio.php';

    /**
     * Where a request for the overview's OLD address goes: /portfolio in the
     * language the request named, its query string kept, or null when this
     * request is not for /portfolio.php (or /<lang>/portfolio.php) at all.
     *
     * portfolio.php answers such a request with a PERMANENT redirect before a
     * byte of output: the address has moved for good, and every link the
     * site builds itself already says /portfolio (the page's route_path). A
     * link someone typed into content, a bookmark or a search result still
     * lands on the overview, in one step.
     *
     * Only a retrieval moves. A POST is answered where it was sent, the rule
     * the Redirect Manager follows too (REDIRECTS.md): a form's answer never
     * depends on a redirect, and a Location header would lose its body.
     */
    public static function legacyOverviewRedirectUrl(string $requestUri, string $method): ?string
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        $query = (string) parse_url($requestUri, PHP_URL_QUERY);
        [$bare, $language] = LocalizedUrl::strip($path);

        if ($bare !== self::LEGACY_OVERVIEW_PATH) {
            return null;
        }

        return LocalizedUrl::path(self::OVERVIEW_PATH, $language) . ($query !== '' ? '?' . $query : '');
    }
}
