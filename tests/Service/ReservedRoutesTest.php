<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ReservedRoutes;
use PHPUnit\Framework\TestCase;

/**
 * Covers the reserved-slug list a CMS page must never be allowed to claim
 * (see App\Service\PageService::validateSlug(), the actual enforcement
 * point). Pure logic, no database.
 */
class ReservedRoutesTest extends TestCase
{
    public function testRejectsRootLevelApplicationPageNames(): void
    {
        $this->assertTrue(ReservedRoutes::isReserved('shop'));
        $this->assertTrue(ReservedRoutes::isReserved('contact'));
        $this->assertTrue(ReservedRoutes::isReserved('portfolio'));
        $this->assertTrue(ReservedRoutes::isReserved('checkout'));
        $this->assertTrue(ReservedRoutes::isReserved('cart'));
        $this->assertTrue(ReservedRoutes::isReserved('collectie'));
    }

    /**
     * /collecties/<slug> is the shop-collection namespace (see .htaccess and
     * collectie.php). The two-segment collection route could not technically
     * collide with a one-segment CMS page, but a page sitting at the root of
     * that URL space would still be confusing, so the bare word is reserved.
     */
    public function testRejectsTheCollectionNamespace(): void
    {
        $this->assertTrue(ReservedRoutes::isReserved('collecties'));
    }

    public function testRejectsTopLevelDirectoryNames(): void
    {
        $this->assertTrue(ReservedRoutes::isReserved('admin'));
        $this->assertTrue(ReservedRoutes::isReserved('api'));
        $this->assertTrue(ReservedRoutes::isReserved('vendor'));
        $this->assertTrue(ReservedRoutes::isReserved('storage'));
    }

    public function testAllowsOrdinarySlugs(): void
    {
        $this->assertFalse(ReservedRoutes::isReserved('garantie'));
        $this->assertFalse(ReservedRoutes::isReserved('verzenden-retourneren'));
        $this->assertFalse(ReservedRoutes::isReserved('algemene-voorwaarden'));
        $this->assertFalse(ReservedRoutes::isReserved('privacyverklaring'));
    }

    public function testIsCaseSensitiveExactMatch(): void
    {
        // Slugs reaching this check are always already lowercased by
        // PageService::sanitizeSlug() — this only documents that the
        // check itself does no separate case-folding.
        $this->assertFalse(ReservedRoutes::isReserved('Shop'));
        $this->assertFalse(ReservedRoutes::isReserved('ADMIN'));
    }
}
