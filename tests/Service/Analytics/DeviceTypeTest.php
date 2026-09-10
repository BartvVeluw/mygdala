<?php

declare(strict_types=1);

namespace Tests\Service\Analytics;

use App\Service\Analytics\DeviceType;
use PHPUnit\Framework\TestCase;

/**
 * Three buckets, and the two ordering traps that decide whether they are
 * right: an Android tablet must not be read as a phone (its user agent
 * contains "Android" but deliberately omits "Mobile"), and anything
 * unrecognised must land on 'desktop' rather than a fourth category — the
 * dashboard's device split has to add up to the pageview total.
 */
final class DeviceTypeTest extends TestCase
{
    public function testIphoneIsMobile(): void
    {
        $this->assertSame(DeviceType::MOBILE, DeviceType::fromUserAgent(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'
        ));
    }

    public function testAndroidPhoneIsMobile(): void
    {
        $this->assertSame(DeviceType::MOBILE, DeviceType::fromUserAgent(
            'Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'
        ));
    }

    /**
     * The omission of "Mobile" is the only signal an Android tablet gives,
     * which is why tablets are matched before phones.
     */
    public function testAndroidTabletIsTablet(): void
    {
        $this->assertSame(DeviceType::TABLET, DeviceType::fromUserAgent(
            'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ));
    }

    public function testIpadIsTablet(): void
    {
        $this->assertSame(DeviceType::TABLET, DeviceType::fromUserAgent(
            'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'
        ));
    }

    public function testDesktopBrowsersAreDesktop(): void
    {
        $this->assertSame(DeviceType::DESKTOP, DeviceType::fromUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ));
        $this->assertSame(DeviceType::DESKTOP, DeviceType::fromUserAgent(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0'
        ));
    }

    public function testUnknownAndEmptyUserAgentsFallBackToDesktop(): void
    {
        $this->assertSame(DeviceType::DESKTOP, DeviceType::fromUserAgent(''));
        $this->assertSame(DeviceType::DESKTOP, DeviceType::fromUserAgent('Something/1.0'));
    }

    /**
     * Whatever comes back is always one of the three stored values — the
     * `device_type` column has no room for a surprise.
     */
    public function testEveryResultIsOneOfTheThreeKnownValues(): void
    {
        $agents = [
            '', 'Something/1.0', 'Mozilla/5.0 (iPad)', 'Mozilla/5.0 (Linux; Android 13) Mobile',
            'Mozilla/5.0 (Windows NT 10.0)', 'Opera Mini/9', 'BlackBerry9700',
        ];

        foreach ($agents as $agent) {
            $this->assertContains(
                DeviceType::fromUserAgent($agent),
                [DeviceType::DESKTOP, DeviceType::TABLET, DeviceType::MOBILE],
                $agent
            );
        }
    }
}
