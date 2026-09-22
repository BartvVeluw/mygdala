<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\PackageExtractor;
use App\Update\PackageValidator;
use App\Update\ReleaseDescriptor;
use App\Update\ReleaseManifest;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * Unpacking a verified package into staging and deciding whether what came
 * out is a whole release (PackageExtractor, PackageValidator). The hostile
 * archives here are the ZIP-slip and symlink cases of the upgrade test list,
 * and scenario C (a corrupt ZIP) at the unit level.
 */
final class PackageExtractionTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mygdala-extract-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/staging', 0777, true);
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory($this->base);
    }

    /**
     * @param array<string, string> $entries name => content
     * @param callable(\ZipArchive): void|null $tamper
     */
    private function zip(array $entries, ?callable $tamper = null): string
    {
        $path = $this->base . '/package-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($tamper !== null) {
            $tamper($zip);
        }
        $zip->close();

        return $path;
    }

    private function extract(string $package): void
    {
        $extractor = new PackageExtractor($package, $this->base . '/staging');
        $extractor->inspect();
        $next = 0;
        while (($next = $extractor->extract($next, 60)) !== null) {
            // keep going
        }
    }

    public function testAPlainPackageUnpacksIntoStagingOnly(): void
    {
        $this->extract($this->zip(['index.php' => '<?php // a', 'src/Update/Updater.php' => '<?php // b']));

        $this->assertStringEqualsFile($this->base . '/staging/index.php', '<?php // a');
        $this->assertFileExists($this->base . '/staging/src/Update/Updater.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingNames(): iterable
    {
        yield 'parent directory' => ['../evil.php'];
        yield 'deep parent' => ['src/../../evil.php'];
        yield 'absolute' => ['/tmp/evil.php'];
        yield 'backslash parent' => ['..\\evil.php'];
        yield 'drive letter' => ['C:/evil.php'];
    }

    /** @dataProvider escapingNames */
    public function testAnEntryThatCouldLandOutsideStagingRefusesTheWholePackageFirst(string $name): void
    {
        $package = $this->zip(['index.php' => 'ok', $name => 'evil']);

        try {
            $this->extract($package);
            $this->fail('a package with ' . $name . ' must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.package_unsafe', $e->messageKey);
        }

        $this->assertFileDoesNotExist($this->base . '/evil.php');
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/evil.php');
        $this->assertSame([], array_values(array_diff(scandir($this->base . '/staging') ?: [], ['.', '..'])), 'nothing was written, not even the good entry');
    }

    public function testASymbolicLinkEntryIsRefused(): void
    {
        $package = $this->zip(['index.php' => 'ok', 'link' => '/etc/passwd'], static function (\ZipArchive $zip): void {
            $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });

        try {
            $this->extract($package);
            $this->fail('a symlink entry must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.package_unsafe', $e->messageKey);
        }

        $this->assertFileDoesNotExist($this->base . '/staging/link');
    }

    public function testTwoEntriesThatAreOneFileOnWindowsAreRefused(): void
    {
        try {
            $this->extract($this->zip(['src/Foo.php' => 'a', 'src/foo.php' => 'b']));
            $this->fail('case-insensitive duplicates must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.package_unsafe', $e->messageKey);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptArchives(): iterable
    {
        yield 'not a zip at all' => ['random'];
        yield 'truncated' => ['truncated'];
        yield 'empty file' => ['empty'];
    }

    /** @dataProvider corruptArchives */
    public function testACorruptArchiveIsRefusedBeforeAnythingIsWritten(string $kind): void
    {
        $path = $this->base . '/corrupt.zip';
        $good = (string) file_get_contents($this->zip(['index.php' => str_repeat('x', 5000), 'a.php' => 'y']));
        file_put_contents($path, match ($kind) {
            'random' => random_bytes(2000),
            'truncated' => substr($good, 0, (int) (strlen($good) / 2)),
            'empty' => '',
        });

        try {
            $this->extract($path);
            $this->fail('a corrupt archive must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.package_corrupt', $e->messageKey);
        }

        $this->assertSame(['.', '..'], scandir($this->base . '/staging'));
    }

    public function testExtractionResumesWhereItsTimeBudgetEnded(): void
    {
        $extractor = new PackageExtractor($this->zip(['a.php' => '1', 'b.php' => '2', 'c.php' => '3']), $this->base . '/staging');
        $extractor->inspect();

        $this->assertSame(1, $extractor->extract(0, 0.0), 'at least one entry per call, then stop');
        $this->assertSame(2, $extractor->extract(1, 0.0));
        $this->assertNull($extractor->extract(2, 0.0));
        $this->assertFileExists($this->base . '/staging/c.php');
    }

    // --- PackageValidator ------------------------------------------------

    /** @return array<string, string> the minimal release a validator accepts */
    private static function releaseFiles(string $version): array
    {
        $files = ['VERSION' => $version . "\n"];
        foreach (PackageValidator::REQUIRED_PATHS as $path) {
            $files[$path] ??= '<?php // ' . $path;
        }
        $files['db/migrations/20260903120000_create_install_state_table.php'] = '<?php // migration';

        return $files;
    }

    /**
     * @param array<string, string> $files
     */
    private function stage(array $files, ?array $listed = null, string $version = '0.2.0'): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->base . '/staging/' . $path), 0777, true);
            file_put_contents($this->base . '/staging/' . $path, $content);
        }

        $hashes = [];
        foreach ($listed ?? array_keys($files) as $path) {
            $hashes[$path] = hash('sha256', $files[$path] ?? '');
        }
        ksort($hashes);

        $descriptor = new ReleaseDescriptor($version, $version . '+t', '2026-10-01T12:00:00Z', '8.1', '5.7', '10.3', [], 1, '20260903120000', 1, $hashes);
        file_put_contents($this->base . '/staging/release.json', $descriptor->toJson());
    }

    private function manifest(string $version = '0.2.0', array $changes = []): ReleaseManifest
    {
        return ReleaseManifest::fromJson((string) json_encode(array_replace([
            'manifest_version' => 1, 'product' => 'mygdala', 'version' => $version,
            'released_at' => '2026-10-01T12:00:00Z', 'package_url' => 'https://u.example.test/p.zip',
            'sha256' => str_repeat('c', 64), 'size' => 10, 'minimum_php' => '8.1', 'minimum_mysql' => '5.7',
            'minimum_mariadb' => '10.3', 'updater_protocol' => 1,
            'migrations' => ['count' => 1, 'latest' => '20260903120000'],
        ], $changes)), 'https://u.example.test/manifest.json');
    }

    private function assertInvalid(callable $validate): void
    {
        try {
            $validate();
            $this->fail('the staged release must be refused');
        } catch (UpdateException $e) {
            $this->assertContains($e->messageKey, ['update.error.package_invalid', 'update.error.release_descriptor_invalid'], $e->getMessage());
        }
    }

    public function testACompleteConsistentReleaseValidates(): void
    {
        $this->stage(self::releaseFiles('0.2.0'));

        $descriptor = (new PackageValidator($this->base . '/staging'))->validate($this->manifest());

        $this->assertSame('0.2.0', $descriptor->version);
    }

    public function testAFileTheReleaseListsButDidNotShipIsRefused(): void
    {
        $files = self::releaseFiles('0.2.0');
        $this->stage($files, [...array_keys($files), 'src/Ghost.php']);

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate($this->manifest()));
    }

    public function testAFileTheReleaseShippedButDoesNotListIsRefused(): void
    {
        $files = self::releaseFiles('0.2.0');
        $this->stage($files + ['src/Stowaway.php' => '<?php'], array_keys($files));

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate($this->manifest()));
    }

    public function testAFileWhoseContentDiffersFromItsListedHashIsRefused(): void
    {
        $this->stage(self::releaseFiles('0.2.0'));
        file_put_contents($this->base . '/staging/phinx.php', '<?php // altered');

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate($this->manifest()));
    }

    public function testAPackageThatIsNotTheAnnouncedVersionIsRefused(): void
    {
        $this->stage(self::releaseFiles('0.3.0'), null, '0.3.0');

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate($this->manifest('0.2.0')));
    }

    public function testAPackageThatShipsInstallationDataIsRefusedAsAWhole(): void
    {
        $this->stage(self::releaseFiles('0.2.0') + ['.env' => 'DB_PASSWORD=x']);

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate($this->manifest()));
    }

    public function testAReleaseWithoutTheUpdaterThatMustFinishTheUpdateIsRefused(): void
    {
        $files = self::releaseFiles('0.2.0');
        unset($files['api/admin/update-step.php']);
        $this->stage($files);

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate($this->manifest()));
    }

    public function testAManifestThatMisstatesTheMigrationsIsRefused(): void
    {
        $this->stage(self::releaseFiles('0.2.0'));

        $this->assertInvalid(fn () => (new PackageValidator($this->base . '/staging'))->validate(
            $this->manifest('0.2.0', ['migrations' => ['count' => 2, 'latest' => '20260903120000']])
        ));
    }
}
