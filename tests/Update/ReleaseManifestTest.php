<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\ReleaseDescriptor;
use App\Update\ReleaseKeys;
use App\Update\ReleaseManifest;
use App\Update\ReleaseSignature;
use App\Update\UpdateConfig;
use App\Update\UpdateException;
use App\Service\AppEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The two documents a release is described by — the signed feed manifest
 * and the release.json inside the package — and the signature that makes
 * the first one trustworthy. docs/updates/RELEASES.md is the format.
 */
final class ReleaseManifestTest extends TestCase
{
    private const MANIFEST_URL = 'https://updates.example.test/mygdala/manifest.json';

    protected function tearDown(): void
    {
        UpdateConfig::overrideForTests(null);
        AppEnvironment::overrideForTests(null);
    }

    /** @return array<string, mixed> */
    private static function manifest(array $changes = []): array
    {
        return array_replace([
            'manifest_version' => 1,
            'product' => 'mygdala',
            'version' => '0.2.0',
            'build_id' => '0.2.0+abc1234',
            'released_at' => '2026-10-01T12:00:00Z',
            'package_url' => 'mygdala-0.2.0.zip',
            'sha256' => str_repeat('a', 64),
            'size' => 1234567,
            'minimum_php' => '8.2',
            'minimum_mysql' => '5.7',
            'minimum_mariadb' => '10.3',
            'required_extensions' => ['pdo_mysql', 'zip', 'sodium'],
            'minimum_source_version' => '0.1.0',
            'updater_protocol' => 1,
            'migrations' => ['count' => 162, 'latest' => '20261001120000'],
            'notes' => "Nieuw:\n- iets",
        ], $changes);
    }

    private static function parse(array $data): ReleaseManifest
    {
        return ReleaseManifest::fromJson((string) json_encode($data), self::MANIFEST_URL);
    }

    public function testAValidManifestIsReadCompletely(): void
    {
        $manifest = self::parse(self::manifest());

        $this->assertSame('0.2.0', $manifest->version);
        $this->assertSame('https://updates.example.test/mygdala/mygdala-0.2.0.zip', $manifest->packageUrl);
        $this->assertSame(1234567, $manifest->size);
        $this->assertSame('8.2', $manifest->minimumPhp);
        $this->assertSame(['pdo_mysql', 'zip', 'sodium'], $manifest->requiredExtensions);
        $this->assertSame('20261001120000', $manifest->latestMigration);
        $this->assertSame('0.1.0', $manifest->minimumSourceVersion);
    }

    public function testAnAbsolutePackageUrlIsKeptAsItIs(): void
    {
        $manifest = self::parse(self::manifest(['package_url' => 'https://cdn.example.test/p/mygdala-0.2.0.zip']));

        $this->assertSame('https://cdn.example.test/p/mygdala-0.2.0.zip', $manifest->packageUrl);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function brokenManifests(): iterable
    {
        yield 'unknown format' => [['manifest_version' => 2], 'update.error.manifest_format'];
        yield 'no format' => [['manifest_version' => null], 'update.error.manifest_invalid'];
        yield 'other product' => [['product' => 'wordpress'], 'update.error.manifest_invalid'];
        yield 'loose version' => [['version' => '0.2'], 'update.error.manifest_invalid'];
        yield 'short hash' => [['sha256' => 'abc'], 'update.error.manifest_invalid'];
        yield 'uppercase hash' => [['sha256' => str_repeat('A', 64)], 'update.error.manifest_invalid'];
        yield 'no size' => [['size' => 0], 'update.error.manifest_invalid'];
        yield 'string size' => [['size' => '100'], 'update.error.manifest_invalid'];
        yield 'huge package' => [['size' => ReleaseManifest::MAX_PACKAGE_BYTES + 1], 'update.error.package_too_large'];
        yield 'traversing package url' => [['package_url' => '../other/evil.zip'], 'update.error.manifest_invalid'];
        yield 'query package url' => [['package_url' => 'p.zip?x=1'], 'update.error.manifest_invalid'];
        yield 'bad requirement' => [['minimum_php' => '8.x'], 'update.error.manifest_invalid'];
        yield 'bad extension' => [['required_extensions' => ['pdo mysql']], 'update.error.manifest_invalid'];
        yield 'bad date' => [['released_at' => 'gisteren'], 'update.error.manifest_invalid'];
        yield 'no protocol' => [['updater_protocol' => 0], 'update.error.manifest_invalid'];
        yield 'bad migration' => [['migrations' => ['count' => 1, 'latest' => '2026']], 'update.error.manifest_invalid'];
    }

    /**
     * @dataProvider brokenManifests
     *
     * @param array<string, mixed> $changes
     */
    public function testABrokenManifestIsRefusedWithAReason(array $changes, string $messageKey): void
    {
        try {
            self::parse(self::manifest($changes));
            $this->fail('this manifest must be refused');
        } catch (UpdateException $e) {
            $this->assertSame($messageKey, $e->messageKey);
        }
    }

    public function testAManifestSurvivesTheRoundTripThroughTheStateFile(): void
    {
        $manifest = self::parse(self::manifest());

        $this->assertEquals($manifest, ReleaseManifest::fromStoredArray($manifest->toArray()));
    }

    public function testASignatureVerifiesOnlyTheExactBytesWithATrustedKey(): void
    {
        $pair = ReleaseSignature::generateKeyPair();
        $trusted = [ReleaseSignature::keyId($pair['public']) => $pair['public']];
        $bytes = (string) json_encode(self::manifest());
        $signature = ReleaseSignature::sign($bytes, $pair['secret']);

        $this->assertSame(ReleaseSignature::keyId($pair['public']), ReleaseSignature::verify($bytes, $signature, $trusted));

        // One byte of whitespace is a different document.
        $this->assertRefused('update.error.signature_invalid', fn () => ReleaseSignature::verify($bytes . ' ', $signature, $trusted));

        // Another key, even a valid one, is not the release key.
        $stranger = ReleaseSignature::generateKeyPair();
        $this->assertRefused(
            'update.error.signature_unknown_key',
            fn () => ReleaseSignature::verify($bytes, ReleaseSignature::sign($bytes, $stranger['secret']), $trusted)
        );

        $this->assertRefused('update.error.no_trusted_key', fn () => ReleaseSignature::verify($bytes, $signature, []));
        $this->assertRefused('update.error.signature_invalid', fn () => ReleaseSignature::verify($bytes, 'not json', $trusted));
    }

    public function testAForgedSignatureUnderTheRightKeyIdIsStillRefused(): void
    {
        $pair = ReleaseSignature::generateKeyPair();
        $keyId = ReleaseSignature::keyId($pair['public']);
        $forged = (string) json_encode(['algorithm' => 'ed25519', 'key_id' => $keyId, 'signature' => base64_encode(random_bytes(64))]);

        $this->assertRefused(
            'update.error.signature_invalid',
            fn () => ReleaseSignature::verify('{}', $forged, [$keyId => $pair['public']])
        );
    }

    public function testTheConfiguredPublicKeyReplacesTheBuiltInTrustRoot(): void
    {
        $pair = ReleaseSignature::generateKeyPair();

        UpdateConfig::overrideForTests([UpdateConfig::PUBLIC_KEY_VARIABLE => base64_encode($pair['public'])]);
        $this->assertSame([ReleaseSignature::keyId($pair['public']) => $pair['public']], ReleaseKeys::trusted());

        UpdateConfig::overrideForTests([UpdateConfig::PUBLIC_KEY_VARIABLE => 'bm90IGEga2V5']);
        $this->assertSame([], ReleaseKeys::trusted(), 'an unusable key trusts nothing rather than something else');
    }

    public function testOnlyHttpsIsAcceptableOnProduction(): void
    {
        AppEnvironment::overrideForTests('production');
        $this->assertTrue(UpdateConfig::isAcceptableUrl('https://updates.example.test/manifest.json'));
        $this->assertFalse(UpdateConfig::isAcceptableUrl('http://updates.example.test/manifest.json'));
        $this->assertFalse(UpdateConfig::isAcceptableUrl('ftp://updates.example.test/manifest.json'));
        $this->assertFalse(UpdateConfig::isAcceptableUrl('file:///etc/passwd'));
        $this->assertFalse(UpdateConfig::isAcceptableUrl('https://user:token@updates.example.test/m.json'));

        AppEnvironment::overrideForTests('');
        $this->assertFalse(UpdateConfig::isAcceptableUrl('http://updates.example.test/manifest.json'), 'no APP_ENV is production');

        AppEnvironment::overrideForTests('testing');
        $this->assertTrue(UpdateConfig::isAcceptableUrl('http://127.0.0.1:8123/manifest.json'));
    }

    public function testADescribedUrlNeverShowsItsQueryString(): void
    {
        $this->assertSame(
            'https://updates.example.test/feed/manifest.json',
            UpdateConfig::describeUrl('https://updates.example.test/feed/manifest.json?token=secret')
        );
    }

    public function testNoFeedIsConfiguredUntilReleaseHostingIsChosen(): void
    {
        UpdateConfig::overrideForTests([]);

        $this->assertSame('', UpdateConfig::manifestUrl());
        $this->assertSame([], ReleaseKeys::trusted());
    }

    public function testTheUpdaterWorksOutsideTheWebRootByDefault(): void
    {
        UpdateConfig::overrideForTests([]);

        $this->assertSame(dirname(UpdateConfig::projectRoot()) . '/storage/updates', UpdateConfig::storagePath());
    }

    public function testAReleaseDescriptorRoundTripsAndRefusesInstallationPaths(): void
    {
        $descriptor = new ReleaseDescriptor(
            '0.2.0', '0.2.0+abc', '2026-10-01T12:00:00Z', '8.2', '5.7', '10.3', ['zip'], 162,
            '20261001120000', 1, ['index.php' => str_repeat('b', 64), 'src/A.php' => str_repeat('c', 64)]
        );

        $this->assertEquals($descriptor, ReleaseDescriptor::fromJson($descriptor->toJson()));

        foreach (['.env', 'assets/media/a.jpg', 'release.json', '../x.php', 'tests/XTest.php'] as $path) {
            $json = str_replace('"src/A.php"', (string) json_encode($path), $descriptor->toJson());
            $this->assertRefused('update.error.release_descriptor_invalid', fn () => ReleaseDescriptor::fromJson($json));
        }
    }

    public function testNoQueryStringSurvivesIntoAnErrorDetail(): void
    {
        $detail = \App\Update\HttpFetcher::withoutQueries('fopen(https://releases.example.test/m.json?token=s3cret&x=1): Failed to open stream');

        $this->assertStringNotContainsString('s3cret', $detail);
        $this->assertStringContainsString('https://releases.example.test/m.json?…', $detail);
    }

    public function testAnOldReleaseJsonIsReadWithoutTodaysOwnershipRulesOnlyWhenAsked(): void
    {
        $json = (new ReleaseDescriptor('0.1.0', '0.1.0', '2026-10-01T00:00:00Z', '8.2', '5.7', '10.3', [], 0, '', 1, [
            'index.php' => str_repeat('a', 64),
        ]))->toJson();
        // A path an older release shipped that today's contract calls development.
        $json = str_replace('"index.php"', '"scripts/release.php"', $json);

        $this->assertRefused('update.error.release_descriptor_invalid', fn () => ReleaseDescriptor::fromJson($json));
        $this->assertArrayHasKey('scripts/release.php', ReleaseDescriptor::fromJson($json, false)->files);

        $unsafe = str_replace('"scripts/release.php"', '"../escape.php"', $json);
        $this->assertRefused('update.error.release_descriptor_invalid', fn () => ReleaseDescriptor::fromJson($unsafe, false));
    }

    public function testOpcacheThatNeverSeesAChangedFileRefusesTheUpdate(): void
    {
        $this->assertTrue(\App\Update\Preflight::opcacheCheck(true, false, 2, false)->isError(), 'no timestamp checks and no invalidation');
        $this->assertFalse(\App\Update\Preflight::opcacheCheck(false, false, 2, false)->isError(), 'OPcache off');
        $this->assertFalse(\App\Update\Preflight::opcacheCheck(true, false, 2, true)->isError(), 'the updater may invalidate');
        $this->assertFalse(\App\Update\Preflight::opcacheCheck(true, true, 2, false)->isError(), 'timestamps are checked');
        $this->assertTrue(\App\Update\Preflight::opcacheCheck(true, true, 600, false)->isError(), 'a ten-minute revalidation is too long to wait');
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
