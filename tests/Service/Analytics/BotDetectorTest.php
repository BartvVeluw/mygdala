<?php

declare(strict_types=1);

namespace Tests\Service\Analytics;

use App\Service\Analytics\BotDetector;
use PHPUnit\Framework\TestCase;

/**
 * The bot filter is the difference between "12 people looked at the shop
 * today" and "12 hits, nine of which were Googlebot and this project's own
 * test suite", so the two directions are tested separately: obvious crawlers
 * must be rejected, and ordinary browsers must never be.
 *
 * The false-positive half matters more than it looks. A blocklist that
 * quietly drops real visitors produces numbers that are wrong in a way nobody
 * can see — which is why the Cubot case (a phone brand whose name ends in
 * "bot") has a test of its own.
 */
final class BotDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function botUserAgents(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'applebot' => ['Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)'],
            'yandex' => ['Mozilla/5.0 (compatible; YandexImages/3.0; +http://yandex.com/bots)'],
            'baidu' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)'],
            'facebook link preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'ai crawler' => ['Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'],
            'uptime monitor' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
            'curl' => ['curl/8.4.0'],
            'wget' => ['Wget/1.21.3'],
            'python' => ['python-requests/2.31.0'],
            'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0.0.0 Safari/537.36'],
            'generic crawler' => ['SomeUnknownCrawler/1.0'],
        ];
    }

    /**
     * @dataProvider botUserAgents
     */
    public function testKnownCrawlersAreRejected(string $userAgent): void
    {
        $this->assertTrue(BotDetector::isBot($userAgent), $userAgent . ' should be treated as a bot');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function humanUserAgents(): array
    {
        return [
            'chrome on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'],
            'firefox on mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'],
            'samsung internet' => ['Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36'],
            'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'],
        ];
    }

    /**
     * @dataProvider humanUserAgents
     */
    public function testOrdinaryBrowsersAreCounted(string $userAgent): void
    {
        $this->assertFalse(BotDetector::isBot($userAgent), $userAgent . ' should be treated as a person');
    }

    /**
     * A device whose brand name happens to end in "bot" must not be filtered
     * out — this is why the 'bot' signature is matched with a word boundary
     * instead of as a bare substring.
     */
    public function testAPhoneBrandEndingInBotIsNotABot(): void
    {
        $this->assertFalse(BotDetector::isBot(
            'Mozilla/5.0 (Linux; Android 10; CUBOT_NOTE_7 Build/QP1A.190711.020) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.90 Mobile Safari/537.36'
        ));
    }

    /**
     * PHP's stream wrapper — how this project's own HTTP tests reach public
     * pages — sends no User-Agent header at all. A full suite run therefore
     * must not show up as a spike in the owner's statistics.
     */
    public function testAMissingUserAgentIsTreatedAsABot(): void
    {
        $this->assertTrue(BotDetector::isBot(''));
        $this->assertTrue(BotDetector::isBot('   '));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->assertTrue(BotDetector::isBot('CURL/8.4.0'));
        $this->assertTrue(BotDetector::isBot('MyCrAwLeR/1.0'));
    }
}
