<?php

declare(strict_types=1);

namespace Tests\Update;

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
}
