<?php

declare(strict_types=1);

namespace Tests\Service\Analytics;

use App\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * End-to-end proof over real HTTP that the tracker is actually wired into the
 * public site and stays out of the CMS — the one thing no unit test can show,
 * because it depends on which templates include partials/header.php.
 *
 * Same live-HTTP technique as tests/Service/PersonalizationProductPageTest.php
 * (the suite has no way to authenticate as an admin, so this only asserts what
 * an anonymous visitor triggers).
 *
 * Rows created here are identified by id: the highest existing id is read
 * before the requests and everything above it is deleted afterwards, so a
 * failing assertion cannot leave test traffic behind in the owner's
 * statistics.
 */
final class AnalyticsTrackingHttpTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private const CRAWLER = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private int $highestIdBefore = 0;

    protected function setUp(): void
    {
        $this->highestIdBefore = $this->highestId();
    }

    protected function tearDown(): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM page_views WHERE id > :id');
        $stmt->execute(['id' => $this->highestIdBefore]);
    }

    private function highestId(): int
    {
        return (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM page_views')->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function newRows(): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM page_views WHERE id > :id ORDER BY id ASC');
        $stmt->execute(['id' => $this->highestIdBefore]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{status: int, body: string, headers: list<string>}|null
     */
    private function get(string $path, string $userAgent, ?string $referrer = null): ?array
    {
        $header = 'User-Agent: ' . $userAgent . "\r\n";
        if ($referrer !== null) {
            $header .= 'Referer: ' . $referrer . "\r\n";
        }

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 15,
            'follow_location' => 0,
            'header' => $header,
        ]]));

        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $headers = $http_response_header ?? [];
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
    }

    private function skipWithoutSite(?array $response): void
    {
        if ($response === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    public function testVisitingAPublicPageRecordsExactlyOnePageview(): void
    {
        $response = $this->get('/shop.php', self::BROWSER, 'https://www.google.com/search?q=lasergravure');
        $this->skipWithoutSite($response);
        $this->assertSame(200, $response['status']);

        $rows = $this->newRows();

        $this->assertCount(1, $rows);
        $this->assertSame('/shop.php', $rows[0]['path']);
        $this->assertSame('desktop', $rows[0]['device_type']);
        // Only the host survives of the referrer — never the search phrase.
        $this->assertSame('google.com', $rows[0]['referrer_host']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['visitor_hash']);
    }

    /**
     * Nothing that could identify the visitor is written: the stored row has
     * no column for an address or a browser string, and the hash must not be
     * the address in disguise.
     */
    public function testTheStoredRowContainsNoAddressOrUserAgent(): void
    {
        $response = $this->get('/', self::BROWSER);
        $this->skipWithoutSite($response);

        $rows = $this->newRows();
        $this->assertCount(1, $rows);

        $stored = strtolower(implode('|', array_map(static fn ($value): string => (string) $value, $rows[0])));

        $this->assertStringNotContainsString('mozilla', $stored);
        $this->assertStringNotContainsString('chrome', $stored);
        // An unsalted sha256 of the address would be trivially reversible;
        // the salt is what makes the hash a count rather than an identifier.
        $this->assertStringNotContainsString(hash('sha256', '127.0.0.1'), $stored);
    }

    public function testTheHomepageIsRecordedUnderASinglePath(): void
    {
        $this->skipWithoutSite($this->get('/', self::BROWSER));
        $this->get('/index.php', self::BROWSER);

        $paths = array_column($this->newRows(), 'path');

        $this->assertSame(['/', '/'], $paths);
    }

    public function testTwoPagesFromTheSameVisitorShareOneHash(): void
    {
        $this->skipWithoutSite($this->get('/', self::BROWSER));
        $this->get('/contact.php', self::BROWSER);

        $rows = $this->newRows();

        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['visitor_hash'], $rows[1]['visitor_hash']);
    }

    public function testCrawlersAreNotRecorded(): void
    {
        $response = $this->get('/shop.php', self::CRAWLER);
        $this->skipWithoutSite($response);
        $this->assertSame(200, $response['status']);

        $this->assertSame([], $this->newRows());
    }

    /**
     * The CMS must never appear in the website statistics. admin/*.php
     * includes admin/_header.php, not partials/header.php, so the tracker is
     * never even reached there.
     */
    public function testCmsPagesAreNotRecorded(): void
    {
        $response = $this->get('/admin/login.php', self::BROWSER);
        $this->skipWithoutSite($response);

        $this->assertSame([], $this->newRows());
    }

    public function testUnknownPagesAreNotRecorded(): void
    {
        $response = $this->get('/deze-pagina-bestaat-echt-niet-' . bin2hex(random_bytes(4)), self::BROWSER);
        $this->skipWithoutSite($response);
        $this->assertSame(404, $response['status']);

        $this->assertSame([], $this->newRows());
    }

    /**
     * The measurement is cookieless — that is what keeps it outside the
     * cookie-consent obligation (see App\Service\CookieConsentConfig, whose
     * 'analytics' category stays deliberately unused).
     */
    public function testTrackingSetsNoCookie(): void
    {
        $response = $this->get('/', self::BROWSER);
        $this->skipWithoutSite($response);

        foreach ($response['headers'] as $line) {
            $this->assertStringStartsNotWith('Set-Cookie:', $line);
        }
    }
}
