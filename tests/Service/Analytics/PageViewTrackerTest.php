<?php

declare(strict_types=1);

namespace Tests\Service\Analytics;

use App\Service\Analytics\PageViewTracker;
use App\Service\Analytics\VisitorHash;
use PHPUnit\Framework\TestCase;

/**
 * The decisions that happen before a row is written: what is stored as the
 * page's path, which requests count at all, and what survives of a referrer.
 *
 * These are the tests that hold the privacy promises in place. "No query
 * strings except one narrow allowlist" and "referrer host only" are claims
 * the migration and the cookie policy both make about this feature; a change
 * that starts recording an order token or a search phrase should fail here
 * rather than be discovered in the database later.
 */
final class PageViewTrackerTest extends TestCase
{
    public function testTheHomepageIsStoredAsASinglePath(): void
    {
        $this->assertSame('/', PageViewTracker::normalizePath('/'));
        $this->assertSame('/', PageViewTracker::normalizePath('/index.php'));
        $this->assertSame('/', PageViewTracker::normalizePath('/index.php?utm_source=nieuwsbrief'));
    }

    public function testTrailingSlashesAndDoubleSlashesCollapse(): void
    {
        $this->assertSame('/shop.php', PageViewTracker::normalizePath('/shop.php/'));
        $this->assertSame('/collecties/hout', PageViewTracker::normalizePath('//collecties//hout/'));
    }

    /**
     * The one allowlisted parameter: without it every product in the
     * catalogue would collapse into a single "/product.php" row.
     */
    public function testProductIdIsKeptBecauseItIdentifiesThePage(): void
    {
        $this->assertSame('/product.php?id=42', PageViewTracker::normalizePath('/product.php?id=42'));
    }

    public function testEverythingElseInAQueryStringIsDiscarded(): void
    {
        $this->assertSame(
            '/product.php?id=42',
            PageViewTracker::normalizePath('/product.php?id=42&utm_source=facebook&ref=mailing')
        );
        $this->assertSame('/shop.php', PageViewTracker::normalizePath('/shop.php?zoek=trouwkado&filter=hout'));
    }

    /**
     * An order lookup carries an order id in its URL. It is not an
     * identifying parameter for the statistics and must not be stored.
     */
    public function testOrderStatusParametersAreNotStored(): void
    {
        $this->assertSame(
            '/bestelling-status.php',
            PageViewTracker::normalizePath('/bestelling-status.php?order=1737')
        );
    }

    public function testANonNumericProductIdIsDropped(): void
    {
        $this->assertSame('/product.php', PageViewTracker::normalizePath('/product.php?id=<script>'));
        $this->assertSame('/product.php', PageViewTracker::normalizePath('/product.php?id='));
    }

    public function testAVeryLongPathIsTruncatedRatherThanLost(): void
    {
        $path = PageViewTracker::normalizePath('/' . str_repeat('a', 400));

        $this->assertSame(190, strlen($path));
        $this->assertStringStartsWith('/aaa', $path);
    }

    public function testOnlySuccessfulGetRequestsAreCounted(): void
    {
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

        $this->assertTrue(PageViewTracker::shouldTrack('GET', '/shop.php', $browser, 200));
        $this->assertFalse(PageViewTracker::shouldTrack('POST', '/shop.php', $browser, 200));
        $this->assertFalse(PageViewTracker::shouldTrack('HEAD', '/shop.php', $browser, 200));
    }

    /**
     * A 404 is not a page anyone read, and counting them would let a scanner
     * walking invented URLs fill the "most viewed pages" list.
     */
    public function testMissingPagesAreNotCounted(): void
    {
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

        $this->assertFalse(PageViewTracker::shouldTrack('GET', '/bestaat-niet', $browser, 404));
        $this->assertFalse(PageViewTracker::shouldTrack('GET', '/oud', $browser, 301));
    }

    public function testBotsAreNotCounted(): void
    {
        $this->assertFalse(PageViewTracker::shouldTrack(
            'GET',
            '/shop.php',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            200
        ));
    }

    /**
     * The CMS is excluded twice over: no admin template includes the partial
     * that calls the tracker, and the paths are refused here as well.
     */
    public function testCmsAndApiPathsAreNeverCounted(): void
    {
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

        foreach (['/admin', '/admin/index.php', '/admin/products.php', '/api', '/api/product.php'] as $path) {
            $this->assertFalse(PageViewTracker::shouldTrack('GET', $path, $browser, 200), $path);
        }

        // A public page whose name merely starts with the same letters is a
        // normal page and must still be counted.
        $this->assertTrue(PageViewTracker::shouldTrack('GET', '/administratie', $browser, 200));
    }

    public function testOnlyTheReferrerHostIsKept(): void
    {
        $this->assertSame(
            'google.com',
            PageViewTracker::referrerHost('https://www.google.com/search?q=lasergravure+trouwkado', 'vanveluwlaserdesign.nl')
        );
    }

    public function testInternalNavigationHasNoReferrer(): void
    {
        $this->assertNull(PageViewTracker::referrerHost(
            'https://vanveluwlaserdesign.nl/shop.php',
            'vanveluwlaserdesign.nl'
        ));
        $this->assertNull(PageViewTracker::referrerHost(
            'https://www.vanveluwlaserdesign.nl/shop.php',
            'vanveluwlaserdesign.nl'
        ));
        // HTTP_HOST carries the port in local development; a referrer never does.
        $this->assertNull(PageViewTracker::referrerHost('http://localhost/shop.php', 'localhost:8000'));
    }

    public function testADirectVisitHasNoReferrer(): void
    {
        $this->assertNull(PageViewTracker::referrerHost('', 'vanveluwlaserdesign.nl'));
        $this->assertNull(PageViewTracker::referrerHost('not a url', 'vanveluwlaserdesign.nl'));
    }

    /**
     * The visitor hash must actually depend on the day's salt: two people
     * with the same address and browser on two different days have to be
     * uncorrelatable, which is the whole basis of the "no durable identifier"
     * claim.
     */
    public function testTheVisitorHashChangesWithTheSalt(): void
    {
        $ip = '203.0.113.7';
        $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

        $monday = VisitorHash::compute('salt-of-monday', $ip, $agent);
        $tuesday = VisitorHash::compute('salt-of-tuesday', $ip, $agent);

        $this->assertNotSame($monday, $tuesday);
        $this->assertSame($monday, VisitorHash::compute('salt-of-monday', $ip, $agent));
        $this->assertSame(64, strlen($monday));
    }

    /**
     * Without a separator, ("1.2.3", "45...") and ("1.2.3.4", "5...") would
     * hash to the same value and silently merge two visitors into one.
     */
    public function testFieldsCannotRunIntoEachOther(): void
    {
        $this->assertNotSame(
            VisitorHash::compute('salt', '203.0.113.4', '5-browser'),
            VisitorHash::compute('salt', '203.0.113.', '45-browser')
        );
    }

    public function testOnlyRemoteAddrIsUsedAsTheClientAddress(): void
    {
        $ip = VisitorHash::clientIp([
            'REMOTE_ADDR' => '203.0.113.7',
            // Spoofable on a host that does not strip it: trusting this would
            // let one visitor invent a new identity per request.
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ]);

        $this->assertSame('203.0.113.7', $ip);
    }
}
