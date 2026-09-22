<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Service\AppEnvironment;
use App\Update\HttpFetcher;
use App\Update\HttpUpdateSource;
use App\Update\PackageDownload;
use App\Update\ReleaseKeys;
use App\Update\ReleaseManifest;
use App\Update\ReleasePackage;
use App\Update\UpdateConfig;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;
use Tests\Support\SandboxServer;

/**
 * Fetching a release: the signed manifest over real HTTP, the package
 * download with its limits, and the size-and-hash gate in front of
 * extraction (docs/updates/ARCHITECTURE.md, "Downloaden en verifiëren").
 *
 * The feed is a directory of fixture files behind PHP's built-in server; a
 * small router adds the misbehaving answers a real host could give.
 */
final class FeedAndPackageTest extends TestCase
{
    private static ?FeedFixture $feed = null;
    private static ?SandboxServer $server = null;

    private string $downloads = '';

    public static function setUpBeforeClass(): void
    {
        self::$feed = new FeedFixture();
        file_put_contents(self::$feed->directory . '/mygdala-0.2.0.zip', str_repeat('release bytes ', 5000));
        file_put_contents(self::$feed->directory . '/router.php', <<<'PHP'
            <?php
            switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
                case '/to-file':
                    header('Location: file:///etc/passwd', true, 302);
                    exit;
                case '/to-manifest':
                    header('Location: /manifest.json', true, 302);
                    exit;
                case '/loop':
                    header('Location: /loop', true, 302);
                    exit;
                case '/huge':
                    echo str_repeat('x', 400000);
                    exit;
            }
            return false;
            PHP);

        self::$server = SandboxServer::start(self::$feed->directory, [], self::$feed->directory . '/router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$feed?->remove();
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('could not start a local web server for the feed fixture');
        }

        AppEnvironment::overrideForTests('testing');
        UpdateConfig::overrideForTests([
            UpdateConfig::MANIFEST_URL_VARIABLE => self::$server->url('/manifest.json'),
            UpdateConfig::PUBLIC_KEY_VARIABLE => self::$feed->publicKey(),
        ]);
        self::$feed->publish('0.2.0', 'mygdala-0.2.0.zip');

        $this->downloads = sys_get_temp_dir() . '/mygdala-downloads-' . bin2hex(random_bytes(4));
        mkdir($this->downloads);
    }

    protected function tearDown(): void
    {
        UpdateConfig::overrideForTests(null);
        AppEnvironment::overrideForTests(null);
        FeedFixture::removeDirectory($this->downloads);
    }

    public function testTheConfiguredFeedYieldsAVerifiedManifest(): void
    {
        $manifest = HttpUpdateSource::fromConfig()->latest();

        $this->assertSame('0.2.0', $manifest->version);
        $this->assertSame(self::$server->url('/mygdala-0.2.0.zip'), $manifest->packageUrl);
    }

    public function testAManifestChangedAfterSigningIsRefused(): void
    {
        $path = self::$feed->directory . '/manifest.json';
        file_put_contents($path, str_replace('"0.2.0"', '"9.9.9"', (string) file_get_contents($path)));

        $this->assertRefused('update.error.signature_invalid', fn () => HttpUpdateSource::fromConfig()->latest());
    }

    public function testAManifestWithoutItsSignatureIsRefused(): void
    {
        unlink(self::$feed->directory . '/manifest.json.sig');

        $this->assertRefused('update.error.download_status', fn () => HttpUpdateSource::fromConfig()->latest());
    }

    public function testAManifestSignedWithAnotherKeyIsRefused(): void
    {
        $impostor = new FeedFixture(self::$feed->directory);
        $impostor->publish('0.2.0', 'mygdala-0.2.0.zip');

        $this->assertRefused('update.error.signature_unknown_key', fn () => HttpUpdateSource::fromConfig()->latest());
    }

    public function testNoFeedMeansNoUpdateCheckRatherThanAGuess(): void
    {
        UpdateConfig::overrideForTests([]);

        $this->assertRefused('update.error.feed_not_configured', fn () => HttpUpdateSource::fromConfig());
    }

    public function testProductionNeverFetchesOverPlainHttp(): void
    {
        AppEnvironment::overrideForTests('production');

        $this->assertRefused('update.error.url_not_allowed', fn () => HttpUpdateSource::fromConfig()->latest());
    }

    public function testRedirectsAreFollowedButNeverOffHttp(): void
    {
        $http = new HttpFetcher(5);

        $this->assertStringContainsString('"manifest_version"', $http->get(self::$server->url('/to-manifest'), 100000));
        $this->assertRefused('update.error.url_not_allowed', fn () => $http->get(self::$server->url('/to-file'), 100000));
        $this->assertRefused('update.error.download_failed', fn () => $http->get(self::$server->url('/loop'), 100000));
    }

    public function testAResponseLargerThanAllowedIsAbandonedAndLeavesNoFile(): void
    {
        $http = new HttpFetcher(5);
        $target = $this->downloads . '/huge.zip';

        $this->assertRefused('update.error.download_too_large', fn () => $http->get(self::$server->url('/huge'), 1000));
        $this->assertRefused('update.error.download_too_large', fn () => $http->resume(self::$server->url('/huge'), PackageDownload::open($target, 1000, []), microtime(true) + 5));

        $this->assertFileDoesNotExist($target);
        $this->assertLessThanOrEqual(1000, (int) @filesize($target . PackageDownload::SUFFIX), 'never a byte past the limit');
    }

    /** The download step, as many requests as it takes. */
    private static function fetch(HttpUpdateSource $source, ReleaseManifest $manifest, string $target): void
    {
        $cursor = [];
        do {
            $download = PackageDownload::open($target, $manifest->size, $cursor);
            try {
                $source->download($manifest, $download, microtime(true) + 5);
            } finally {
                $cursor = $download->close();
            }
        } while (!$download->isComplete());

        $download->finish();
    }

    public function testADownloadedPackageMatchingItsManifestPassesVerification(): void
    {
        $source = HttpUpdateSource::fromConfig();
        $manifest = $source->latest();
        $target = $this->downloads . '/package.zip';

        self::fetch($source, $manifest, $target);
        (new ReleasePackage($target, $manifest))->verify();

        $this->assertFileEquals(self::$feed->directory . '/mygdala-0.2.0.zip', $target);
    }

    public function testAPackageWhoseHashDiffersFromTheManifestIsRefused(): void
    {
        self::$feed->publish('0.2.0', 'mygdala-0.2.0.zip', ['sha256' => str_repeat('0', 64)]);
        $source = HttpUpdateSource::fromConfig();
        $manifest = $source->latest();
        $target = $this->downloads . '/package.zip';

        self::fetch($source, $manifest, $target);

        $this->assertRefused('update.error.package_hash_mismatch', fn () => (new ReleasePackage($target, $manifest))->verify());
    }

    public function testAPackageLargerThanTheManifestSaysIsNeverWrittenWhole(): void
    {
        $size = (int) filesize(self::$feed->directory . '/mygdala-0.2.0.zip');
        self::$feed->publish('0.2.0', 'mygdala-0.2.0.zip', ['size' => $size - 10]);
        $source = HttpUpdateSource::fromConfig();
        $target = $this->downloads . '/package.zip';

        $this->assertRefused('update.error.download_too_large', fn () => self::fetch($source, $source->latest(), $target));
        $this->assertFileDoesNotExist($target);
    }

    public function testATruncatedPackageFailsOnItsSize(): void
    {
        $source = HttpUpdateSource::fromConfig();
        $manifest = $source->latest();
        $target = $this->downloads . '/package.zip';
        file_put_contents($target, 'too short');

        $this->assertRefused('update.error.package_size_mismatch', fn () => (new ReleasePackage($target, $manifest))->verify());
    }

    public function testTheBuiltInTrustRootIsEmptyUntilAReleaseKeyExists(): void
    {
        UpdateConfig::overrideForTests([UpdateConfig::MANIFEST_URL_VARIABLE => self::$server->url('/manifest.json')]);

        $this->assertSame([], ReleaseKeys::trusted());
        $this->assertRefused('update.error.no_trusted_key', fn () => HttpUpdateSource::fromConfig()->latest());
    }

    private function assertRefused(string $messageKey, callable $action): void
    {
        try {
            $action();
            $this->fail('expected a refusal with ' . $messageKey);
        } catch (UpdateException $e) {
            $this->assertSame($messageKey, $e->messageKey, $e->getMessage());
        }
    }
}
