<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Repository\DatabaseSchemaRepository;
use App\Update\AppVersion;
use App\Update\Preflight;
use App\Update\PreflightCheck;
use App\Update\ReleaseDescriptor;
use App\Update\ReleaseManifest;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * The first preflight pass: what the signed manifest alone can tell about
 * whether this installation may take the release (Preflight::environment()).
 * Scenario E of the upgrade tests (PHP or MySQL too old) is proven here
 * against the real database server, and again end to end in the upgrade
 * tests.
 */
final class PreflightEnvironmentTest extends TestCase
{
    private string $root = '';
    private string $storage = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/mygdala-preflight-' . bin2hex(random_bytes(4));
        $this->root = $base . '/site';
        $this->storage = $base . '/storage/updates';
        mkdir($this->root, 0777, true);

        file_put_contents($this->root . '/VERSION', "0.1.0\n");
        file_put_contents($this->root . '/release.json', $this->descriptor('0.1.0')->toJson());
        AppVersion::clearCache();
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory(dirname($this->root));
        AppVersion::clearCache();
    }

    private function descriptor(string $version): ReleaseDescriptor
    {
        return new ReleaseDescriptor($version, $version . '+t', '2026-09-22T00:00:00Z', '8.1', '5.7', '10.3', [], 1, '20260903120000', 1, [
            'index.php' => str_repeat('a', 64),
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function manifest(array $changes = []): ReleaseManifest
    {
        return ReleaseManifest::fromJson((string) json_encode(array_replace([
            'manifest_version' => 1,
            'product' => 'mygdala',
            'version' => '0.2.0',
            'released_at' => '2026-10-01T12:00:00Z',
            'package_url' => 'https://updates.example.test/p.zip',
            'sha256' => str_repeat('b', 64),
            'size' => 1000,
            'minimum_php' => '8.1',
            'minimum_mysql' => '5.7',
            'minimum_mariadb' => '10.3',
            'required_extensions' => ['pdo_mysql'],
            'minimum_source_version' => '0.1.0',
            'updater_protocol' => 1,
            'migrations' => ['count' => 1, 'latest' => '20260903120000'],
        ], $changes)), 'https://updates.example.test/manifest.json');
    }

    /** @return array<string, PreflightCheck> by check name */
    private function checksFor(ReleaseManifest $manifest): array
    {
        $checks = [];
        foreach ((new Preflight($this->root, $this->storage, new DatabaseSchemaRepository()))->environment($manifest) as $check) {
            $checks[$check->name] = $check;
        }

        return $checks;
    }

    public function testAHealthyInstallationMayTakeANewerRelease(): void
    {
        $checks = $this->checksFor($this->manifest());

        $this->assertTrue(Preflight::passes(array_values($checks)), json_encode(array_map(fn ($c) => $c->toArray(), $checks)));
        $this->assertSame('update.preflight.version_ok', $checks['version']->messageKey);
        $this->assertDirectoryExists($this->storage, 'the updater makes its own directory');
    }

    public function testASameOrOlderReleaseIsNotAnUpdate(): void
    {
        $this->assertSame('update.preflight.version_not_newer', $this->checksFor($this->manifest(['version' => '0.1.0']))['version']->messageKey);
        $this->assertSame('update.preflight.version_not_newer', $this->checksFor($this->manifest(['version' => '0.0.9']))['version']->messageKey);
    }

    public function testAReleaseCanRefuseAVersionTooOldToJumpFrom(): void
    {
        $check = $this->checksFor($this->manifest(['version' => '0.5.0', 'minimum_source_version' => '0.3.0']))['source_version'];

        $this->assertTrue($check->isError());
        $this->assertSame(['current' => '0.1.0', 'minimum' => '0.3.0'], $check->params);
    }

    public function testATooOldPhpIsRefused(): void
    {
        $check = $this->checksFor($this->manifest(['minimum_php' => '99.0']))['php'];

        $this->assertSame('update.preflight.php_too_old', $check->messageKey);
        $this->assertTrue($check->isError());
    }

    public function testATooOldDatabaseServerIsRefused(): void
    {
        $check = $this->checksFor($this->manifest(['minimum_mysql' => '99.0', 'minimum_mariadb' => '99.0']))['database'];

        $this->assertSame('update.preflight.database_too_old', $check->messageKey);
        $this->assertTrue($check->isError());
    }

    public function testAMissingExtensionIsNamed(): void
    {
        $check = $this->checksFor($this->manifest(['required_extensions' => ['pdo_mysql', 'imaginary_ext']]))['extensions'];

        $this->assertTrue($check->isError());
        $this->assertSame('imaginary_ext', $check->params['extensions']);
    }

    public function testAnUnknownUpdaterProtocolIsRefused(): void
    {
        $this->assertTrue($this->checksFor($this->manifest(['updater_protocol' => 2]))['protocol']->isError());
    }

    public function testAGitCheckoutIsNotUpdatedByTheUpdater(): void
    {
        mkdir($this->root . '/.git');

        $this->assertSame('update.preflight.git_checkout', $this->checksFor($this->manifest())['release_install']->messageKey);
    }

    public function testAnInstallationWithoutReleaseJsonIsNotAReleaseInstallation(): void
    {
        unlink($this->root . '/release.json');

        $this->assertSame('update.preflight.not_a_release', $this->checksFor($this->manifest())['release_install']->messageKey);
    }

    public function testAVersionFileThatDisagreesWithReleaseJsonIsAnUnknownSituation(): void
    {
        file_put_contents($this->root . '/VERSION', "0.1.5\n");

        $this->assertSame('update.preflight.version_disagrees', $this->checksFor($this->manifest())['version']->messageKey);
    }

    public function testAnUnwritableStorageDirectoryIsRefused(): void
    {
        // A FILE where the directory should be: unwritable for root and for
        // www-data alike, which chmod alone is not inside this container.
        mkdir(dirname($this->storage), 0777, true);
        file_put_contents($this->storage, 'not a directory');

        $this->assertTrue($this->checksFor($this->manifest())['storage']->isError());
    }
}
