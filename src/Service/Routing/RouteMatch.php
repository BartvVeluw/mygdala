<?php

declare(strict_types=1);

namespace App\Service\Routing;

/**
 * One resolved route: which template answers, with which parameters, in which
 * language — and, when the URL spelled a fixed segment in another language's
 * words, where its canonical form lives.
 *
 * A value object with public readonly fields and no behaviour, like
 * App\Service\Language\SiteLanguage and App\Service\Breadcrumbs\BreadcrumbItem.
 * What is done with it is dispatcher.php's business.
 */
final class RouteMatch
{
    /**
     * @param string                $key           the route's key in App\Service\Routing\RouteTable
     * @param string                $template      a root-level template name, always a literal from the table
     * @param array<string, string> $query         parameters the template reads out of $_GET
     * @param string                $language      the language this route was matched in
     * @param string|null           $canonicalPath the unprefixed path this URL should be redirected to
     *                                             first, or null when the URL is already canonical
     */
    public function __construct(
        public readonly string $key,
        public readonly string $template,
        public readonly array $query,
        public readonly string $language,
        public readonly ?string $canonicalPath = null,
    ) {
    }

    /** Was this URL spelled with another language's words for a fixed segment? */
    public function needsCanonicalRedirect(): bool
    {
        return $this->canonicalPath !== null;
    }
}
