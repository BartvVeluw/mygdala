<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Install\FreshSiteCopyPolicy;
use App\Update\Ownership;
use PHPUnit\Framework\TestCase;

/**
 * The ownership contract (App\Update\Ownership, docs/updates/ARCHITECTURE.md):
 * which files a release may overwrite, and which it must never touch.
 *
 * The expensive mistake here is one-sided — an upload directory the
 * contract forgot would be offered to the updater as Core — so most of these
 * tests hold the contract against the places that actually write files:
 * every uploader, .gitignore's list of runtime directories, and the
 * fresh-site-copy policy's idea of site content.
 */
final class OwnershipTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function installationPaths(): iterable
    {
        yield 'the environment file' => ['.env'];
        yield 'a local environment file' => ['.env.local'];
        yield 'the maintenance flag' => ['.maintenance'];
        yield 'the host PHP settings' => ['.user.ini'];
        yield 'a media upload' => ['assets/media/2026/photo.jpg'];
        yield 'a media thumbnail' => ['assets/media/thumbs/photo.webp'];
        yield 'a section image' => ['assets/images/sections/hero.jpg'];
        yield 'a product image' => ['assets/images/products/mug.jpg'];
        yield 'a personalization preview' => ['assets/images/personalization/p.png'];
        yield 'a branding image' => ['assets/images/branding/logo.svg'];
        yield 'an old site image' => ['assets/images/hero-collage-1.webp'];
        yield 'a section video' => ['assets/videos/sections/intro.mp4'];
        yield 'an engraving font' => ['assets/fonts/personalization/brush.woff2'];
        yield 'private storage' => ['storage/updates/state.json'];
        yield 'a host error log' => ['admin/error_log'];
    }

    /** @dataProvider installationPaths */
    public function testInstallationDataIsNeverReleaseOwned(string $path): void
    {
        $this->assertSame(Ownership::INSTALLATION, Ownership::classify($path));
        $this->assertFalse(Ownership::isShipped($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function releasePaths(): iterable
    {
        yield 'a root template' => ['index.php'];
        yield 'the routing rules' => ['.htaccess'];
        yield 'the version' => ['VERSION'];
        yield 'the documented example' => ['.env.example'];
        yield 'an admin screen' => ['admin/settings.php'];
        yield 'an endpoint' => ['api/admin/update-step.php'];
        yield 'a class' => ['src/Update/Ownership.php'];
        yield 'a migration' => ['db/migrations/20260903120000_create_install_state_table.php'];
        yield 'a stylesheet' => ['assets/css/core.css'];
        yield 'the block preview sample' => ['assets/images/block-preview/sample.svg'];
        yield 'the font MIME rules' => ['assets/fonts/personalization/.htaccess'];
        yield 'a vendor file' => ['vendor/autoload.php'];
        yield 'a vendor licence' => ['vendor/robmorgan/phinx/LICENSE.md'];
        yield 'a production script' => ['scripts/prune-analytics.php'];
    }

    /** @dataProvider releasePaths */
    public function testApplicationCodeIsReleaseOwned(string $path): void
    {
        $this->assertSame(Ownership::RELEASE, Ownership::classify($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function developmentPaths(): iterable
    {
        yield 'a test' => ['tests/Update/OwnershipTest.php'];
        yield 'the agent setup' => ['.claude/skills/shop/SKILL.md'];
        yield 'prose documentation' => ['MODULES.md'];
        yield 'agent notes in src' => ['src/Update/CLAUDE.md'];
        yield 'docker' => ['docker/Dockerfile'];
        yield 'the compose file' => ['docker-compose.yml'];
        yield 'phpunit configuration' => ['phpunit.xml'];
        yield 'the test database script' => ['scripts/test-db.php'];
        yield 'the release command' => ['scripts/release.php'];
        yield 'build output' => ['dist/manifest.json'];
    }

    /** @dataProvider developmentPaths */
    public function testRepositoryToolingIsNeitherShippedNorTouched(string $path): void
    {
        $this->assertSame(Ownership::DEVELOPMENT, Ownership::classify($path));
    }

    /**
     * Every directory the uploaders in src/Service write to. Found by the
     * audit in docs/updates/ARCHITECTURE.md; kept here so a renamed upload
     * folder shows up as a failing test rather than as an update that
     * deletes photos.
     */
    public function testEveryUploaderWritesIntoAnInstallationDirectory(): void
    {
        $sources = [
            'src/Service/Media/MediaUploader.php' => 'assets/media',
            'src/Service/SectionImageUploader.php' => 'assets/images/sections',
            'src/Service/ProductImageUploader.php' => 'assets/images/products',
            'src/Service/Personalization/PersonalizationPreviewImageUploader.php' => 'assets/images/personalization',
            'src/Service/SectionVideoUploader.php' => 'assets/videos/sections',
            'src/Service/Personalization/PersonalizationFontUploader.php' => 'assets/fonts/personalization',
        ];

        foreach ($sources as $file => $directory) {
            $source = (string) file_get_contents(self::root() . '/' . $file);
            $this->assertStringContainsString($directory, $source, "{$file} no longer names {$directory}; update the audit");
            $this->assertTrue(Ownership::isInstallationOwned($directory . '/upload.bin'), $directory);
        }
    }

    /**
     * .gitignore keeps runtime uploads out of git; every such line under
     * assets/ or storage/ must be installation data for the updater too.
     */
    public function testEveryRuntimeDirectoryGitIgnoresIsInstallationOwned(): void
    {
        $lines = file(self::root() . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [];
        $checked = 0;

        foreach ($lines as $line) {
            if (preg_match('#^/((assets|storage)/[^*!]+)\*$#', trim($line), $match) !== 1) {
                continue;
            }

            $checked++;
            $this->assertTrue(Ownership::isInstallationOwned($match[1] . 'example.bin'), $line);
        }

        $this->assertGreaterThanOrEqual(6, $checked);
        $this->assertTrue(Ownership::isInstallationOwned('.env'));
    }

    public function testSiteContentOfTheFreshCopyPolicyIsInstallationOwned(): void
    {
        foreach (FreshSiteCopyPolicy::WRITABLE_DIRECTORIES as $directory) {
            $this->assertTrue(Ownership::isInstallationOwned($directory . '/x.jpg'), $directory);
        }
    }

    public function testTheReleaseManifestIsReleaseOwnedButNeverListsItself(): void
    {
        $this->assertSame(Ownership::RELEASE, Ownership::classify(Ownership::RELEASE_MANIFEST));
    }
}
