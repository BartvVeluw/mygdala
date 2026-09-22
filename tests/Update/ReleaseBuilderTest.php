<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\Build\LineEndings;
use App\Update\Build\ReleaseBuilder;
use App\Update\Build\ReleaseSource;
use App\Update\PackageExtractor;
use App\Update\PackageValidator;
use App\Update\ReleaseManifest;
use App\Update\ReleasePackage;
use App\Update\ReleaseSignature;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * The release builder (App\Update\Build\ReleaseBuilder, scripts/release.php):
 * what goes into a package, what is refused, and — the property everything
 * else depends on — that what it builds is exactly what an installation's
 * updater accepts. The upgrade tests build real Mygdala releases with it.
 */
final class ReleaseBuilderTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mygdala-build-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory($this->base);
    }

    /** @param array<string, string> $files */
    private function tree(string $directory, array $files): string
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($directory . '/' . $path), 0777, true);
            file_put_contents($directory . '/' . $path, $content);
        }

        return $directory;
    }

    /** A source tree with everything a release needs, and a lot it must leave out. */
    private function source(string $version = '0.2.0'): ReleaseSource
    {
        $files = ['VERSION' => $version . "\n", 'index.php' => '<?php echo "home";', '.htaccess' => 'RewriteEngine On',
            'db/migrations/20260903120000_first.php' => '<?php', 'db/migrations/20261001120000_second.php' => '<?php',
            'assets/css/core.css' => 'body{}', 'assets/images/block-preview/sample.svg' => '<svg/>',
            'assets/fonts/personalization/.htaccess' => 'AddType font/woff2 .woff2',
            // Never in a release:
            '.env' => 'DB_PASSWORD=secret', 'assets/media/photo.jpg' => 'jpeg', 'assets/images/sections/x.jpg' => 'jpeg',
            'tests/FooTest.php' => '<?php', 'docs/x.md' => '# x', 'README.md' => '# readme', 'src/Update/CLAUDE.md' => '# notes',
            'phpunit.xml' => '<phpunit/>', 'docker-compose.yml' => 'services: {}', 'scripts/test-db.php' => '<?php',
            'scripts/release.php' => '<?php', 'notes.tmp' => 'scratch', 'storage/updates/state.json' => '{}',
            '.claude/settings.json' => '{}', 'release.json' => '{"stale": true}', 'admin/error_log' => 'PHP Warning',
        ];
        foreach (PackageValidator::REQUIRED_PATHS as $required) {
            $files[$required] ??= '<?php // ' . $required;
        }
        unset($files['vendor/autoload.php']);

        $this->tree($this->base . '/src-' . $version, $files);
        $this->tree($this->base . '/vendor-' . $version, [
            'autoload.php' => '<?php require __DIR__ . "/composer/autoload_real.php";',
            'composer/autoload_files.php' => "<?php return ['x' => \$baseDir . '/src/Update/maintenance-guard.php'];",
            'robmorgan/phinx/LICENSE.md' => 'MIT',
        ]);

        return ReleaseSource::fromDirectory($this->base . '/src-' . $version, $this->base . '/vendor-' . $version);
    }

    private function build(ReleaseSource $source, string $out, array $options = []): array
    {
        return (new ReleaseBuilder())->build($source, $options + [
            'version' => '0.2.0',
            'released_at' => '2026-10-01T12:00:00Z',
            'build_id' => '0.2.0+test',
            'notes' => 'Testrelease',
        ], $this->base . '/' . $out);
    }

    public function testAReleaseHoldsTheApplicationAndNothingOfTheInstallationOrTheRepository(): void
    {
        $result = $this->build($this->source(), 'dist');
        $paths = array_keys($result['descriptor']->files);

        foreach (['VERSION', 'index.php', '.htaccess', 'assets/css/core.css', 'assets/images/block-preview/sample.svg',
            'assets/fonts/personalization/.htaccess', 'vendor/autoload.php', 'vendor/robmorgan/phinx/LICENSE.md'] as $expected) {
            $this->assertContains($expected, $paths);
        }

        foreach (['.env', 'assets/media/photo.jpg', 'assets/images/sections/x.jpg', 'tests/FooTest.php', 'docs/x.md', 'README.md',
            'src/Update/CLAUDE.md', 'phpunit.xml', 'docker-compose.yml', 'scripts/test-db.php', 'scripts/release.php', 'notes.tmp',
            'storage/updates/state.json', '.claude/settings.json', 'release.json', 'admin/error_log'] as $excluded) {
            $this->assertNotContains($excluded, $paths, $excluded . ' must not be in a release');
        }

        $this->assertSame(2, $result['descriptor']->migrationCount);
        $this->assertSame('20261001120000', $result['descriptor']->latestMigration);
    }

    public function testTheSameSourceBuildsTheSameBytes(): void
    {
        $source = $this->source();

        $this->assertSame($this->build($source, 'one')['sha256'], $this->build($source, 'two')['sha256']);
    }

    public function testWhatTheBuilderMakesIsWhatAnInstallationAccepts(): void
    {
        $pair = ReleaseSignature::generateKeyPair();
        $result = $this->build($this->source(), 'dist', ['secret_key' => $pair['secret']]);

        // The installation's side, step by step: signature, manifest, package, staging.
        $bytes = (string) file_get_contents($result['manifest']);
        ReleaseSignature::verify($bytes, (string) file_get_contents((string) $result['signature']), [ReleaseSignature::keyId($pair['public']) => $pair['public']]);
        $manifest = ReleaseManifest::fromJson($bytes, 'https://updates.example.test/mygdala/manifest.json');
        $this->assertSame('https://updates.example.test/mygdala/mygdala-0.2.0.zip', $manifest->packageUrl);

        (new ReleasePackage($result['package'], $manifest))->verify();

        $extractor = new PackageExtractor($result['package'], $this->base . '/staging');
        $extractor->inspect();
        $this->assertNull($extractor->extract(0, 60));

        $descriptor = (new PackageValidator($this->base . '/staging'))->validate($manifest);
        $this->assertEquals($result['descriptor'], $descriptor);
    }

    public function testAVersionFileThatDisagreesWithTheRequestedVersionIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->build($this->source('0.2.0'), 'dist', ['version' => '0.3.0']);
    }

    public function testAVendorDirectoryWithoutTheMaintenanceGuardIsRefused(): void
    {
        $source = $this->source();
        file_put_contents($this->base . '/vendor-0.2.0/composer/autoload_files.php', '<?php return [];');

        $this->expectException(UpdateException::class);
        $this->build($source, 'dist');
    }

    public function testASourceWithoutTheUpdaterItsUpdateNeedsIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->build($this->source()->without('api/admin/updates-step.php'), 'dist');
    }

    public function testAnUnsignedBuildSaysSoAndLeavesNoStaleSignature(): void
    {
        $pair = ReleaseSignature::generateKeyPair();
        $this->build($this->source(), 'dist', ['secret_key' => $pair['secret']]);
        $result = $this->build($this->source(), 'dist');

        $this->assertNull($result['signature']);
        $this->assertFileDoesNotExist($this->base . '/dist/manifest.json.sig');
    }

    /**
     * The line-ending contract end to end, on this repository itself: the
     * same commit as an LF tree (a Linux archive) and as a CRLF tree (a
     * Windows working copy under core.autocrlf, which is how v0.1.0 was
     * built) is ONE release, byte for byte, signature included. Binaries and
     * vendor/ hold CR and LF bytes of their own, and keep every one of them.
     */
    public function testThisRepositoryBuildsTheSameReleaseFromAnLfAndACrlfCopy(): void
    {
        $empty = $this->base . '/no-vendor';
        mkdir($empty);
        $repository = ReleaseSource::fromDirectory(dirname(__DIR__, 2), $empty)->files();
        $this->assertGreaterThan(500, count($repository), 'the walk saw the repository');

        // A PNG starts with "\x89PNG\r\n\x1a\n" on purpose: a transfer that
        // converts line endings breaks the signature.
        $binaries = [
            'assets/images/block-preview/line-endings.png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\r\n\n\r",
            'assets/fonts/line-endings.woff2' => "wOF2\x00\x01\r\n\r\r\n\x00",
        ];
        $vendor = [
            'autoload.php' => "<?php\r\nrequire __DIR__ . '/composer/autoload_real.php';\r\n",
            'composer/autoload_files.php' => "<?php return ['x' => \$baseDir . '/src/Update/maintenance-guard.php'];\r\n",
            'dompdf/dompdf/lib/fonts/Courier.afm' => "StartFontMetrics 4.1\r\nFontName Courier\r\n",
            'dompdf/dompdf/lib/fonts/DejaVuSans.ttf' => "\x00\x01\x00\x00\r\n\r\x00",
        ];
        $this->tree($this->base . '/vendor-line-endings', $vendor);

        $lf = $binaries;
        $crlf = $binaries;
        foreach ($repository as $path => $absolute) {
            $bytes = (string) file_get_contents($absolute);
            if (LineEndings::classify($path) === LineEndings::TEXT) {
                $bytes = str_replace("\r\n", "\n", $bytes);
                $crlf[$path] = str_replace("\n", "\r\n", $bytes);
            } else {
                $crlf[$path] = $bytes;
            }
            $lf[$path] = $bytes;
        }
        $this->tree($this->base . '/lf', $lf);
        $this->tree($this->base . '/crlf', $crlf);
        $this->assertStringContainsString("\r\n", $crlf['index.php'], 'the CRLF tree really is CRLF');

        $pair = ReleaseSignature::generateKeyPair();
        $options = ['version' => trim($lf['VERSION']), 'secret_key' => $pair['secret']];
        $fromLf = $this->build(ReleaseSource::fromDirectory($this->base . '/lf', $this->base . '/vendor-line-endings'), 'dist-lf', $options);
        $fromCrlf = $this->build(ReleaseSource::fromDirectory($this->base . '/crlf', $this->base . '/vendor-line-endings'), 'dist-crlf', $options);

        $this->assertSame($fromLf['sha256'], $fromCrlf['sha256'], 'one commit, one package');
        foreach (['manifest.json', 'manifest.json.sig', 'release.json'] as $document) {
            $this->assertSame((string) file_get_contents($this->base . '/dist-lf/' . $document), (string) file_get_contents($this->base . '/dist-crlf/' . $document), $document);
        }

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($fromCrlf['package'], \ZipArchive::RDONLY));
        $withCr = [];
        foreach ($lf as $path => $bytes) {
            $entry = (string) $zip->getFromName($path);
            $this->assertSame($bytes, $entry, $path . ': the LF bytes, or for a binary its own');
            if (LineEndings::classify($path) === LineEndings::TEXT && str_contains($entry, "\r")) {
                $withCr[] = $path;
            }
        }
        foreach ($vendor as $path => $bytes) {
            $this->assertSame($bytes, (string) $zip->getFromName('vendor/' . $path), 'vendor/' . $path . ' as Composer left it');
        }
        $zip->close();
        $this->assertSame([], $withCr, 'no text file ships a carriage return');
        $this->assertSame(hash('sha256', $binaries['assets/images/block-preview/line-endings.png']), $fromCrlf['descriptor']->files['assets/images/block-preview/line-endings.png']);
    }

    public function testATextFileWithACarriageReturnThatEndsNoLineIsRefused(): void
    {
        $stray = $this->tree($this->base . '/stray', ['index.php' => "<?php echo 'a';\r\r\n"]) . '/index.php';

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/carriage return that ends no line in index\.php/');
        $this->build($this->source()->with('index.php', $stray), 'dist');
    }

    public function testAFileTypeWithoutALineEndingRuleIsRefused(): void
    {
        $data = $this->tree($this->base . '/unknown', ['assets/data/table.dat' => "1,2\n"]) . '/assets/data/table.dat';

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('#no line-ending rule for assets/data/table\.dat#');
        $this->build($this->source()->with('assets/data/table.dat', $data), 'dist');
    }
}
