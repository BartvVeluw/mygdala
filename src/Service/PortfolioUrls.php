<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Routing\LocalizedUrl;

/**
 * The Portfolio's fixed public addresses (Portfolio 2.0, MODULES.md
 * "Portfolio"), the counterpart of App\Service\Blog\BlogUrls:
 *
 *   /portfolio          the overview: the module root. Where the CMS page
 *                       with content key "portfolio" exists it is that page
 *                       (its `route_path`, so every link, canonical, sitemap
 *                       entry and breadcrumb that resolves the page says
 *                       /portfolio); where it does not, the module's own
 *                       overview
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

    /** The content key of the CMS page that, when it exists, is the overview. */
    public const OVERVIEW_CONTENT_KEY = 'portfolio';

    /** The overview's name where it has no page to take one from. */
    public const OVERVIEW_LABEL = ['nl' => 'Portfolio', 'en' => 'Portfolio'];

    /**
     * The CMS page that is the overview, published or not, or null when there
     * is none — a new installation, or one that switched Portfolio on later.
     *
     * Found by its content key and nothing else: a page is never adopted as
     * the overview for its title or its slug. Without one, portfolio.php
     * shows the module's own overview (MODULES.md, "Portfolio").
     *
     * @return array<string, mixed>|null
     */
    public static function overviewPage(): ?array
    {
        return PageContent::forContentKey(self::OVERVIEW_CONTENT_KEY);
    }

    /**
     * The overview's address in every active language, for hreflang and the
     * language switch when it has no page to declare them.
     *
     * @return array<string, string> language code => site-relative path
     */
    public static function overviewVersions(): array
    {
        $versions = [];
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $language) {
            $versions[$language] = LocalizedUrl::path(self::OVERVIEW_PATH, $language);
        }

        return $versions;
    }

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
