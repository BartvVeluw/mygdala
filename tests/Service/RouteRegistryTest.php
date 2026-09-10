<?php

namespace Tests\Service;

use App\Service\RouteRegistry;
use PHPUnit\Framework\TestCase;

class RouteRegistryTest extends TestCase
{
    public function testKnownRouteResolvesToUrl(): void
    {
        $this->assertTrue(RouteRegistry::exists('shop'));
        $this->assertSame('/shop.php', RouteRegistry::url('shop'));
    }

    public function testUnknownRouteIsRejected(): void
    {
        $this->assertFalse(RouteRegistry::exists('does-not-exist'));
        $this->assertNull(RouteRegistry::url('does-not-exist'));
    }

    public function testOrdinaryContentPagesAreNotApplicationRoutes(): void
    {
        // These are CMS content pages that merely happen to be served from
        // their own file. Offering them here as a hardcoded path would make
        // a menu link that survives unpublishing or deleting the page — and
        // that PageService::references() cannot see. They are linked by
        // pages.id instead (link_type = 'page').
        foreach (['diensten', 'portfolio', 'over-mij', 'contact'] as $contentPage) {
            $this->assertFalse(
                RouteRegistry::exists($contentPage),
                "\"{$contentPage}\" is a content page, not an application route"
            );
        }

        // What stays is what the application itself guarantees.
        foreach (['home', 'shop', 'cart', 'checkout'] as $applicationRoute) {
            $this->assertTrue(RouteRegistry::exists($applicationRoute));
        }
    }

    public function testAllReturnsEveryRouteWithLabels(): void
    {
        $all = RouteRegistry::all();

        $this->assertArrayHasKey('home', $all);
        $this->assertArrayHasKey('url', $all['home']);
        $this->assertArrayHasKey('label_nl', $all['home']);
        $this->assertArrayHasKey('label_en', $all['home']);
    }
}
