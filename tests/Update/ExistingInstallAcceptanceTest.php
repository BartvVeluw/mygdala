<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Tests\Support\UpdaterSandbox;

/**
 * Acceptance on a COPY of an existing installation, updated from release A
 * to release B only through the Updates screen — never on mygdala-test or
 * any real site.
 *
 * "Existing installation" here is this suite's test database, which is a
 * full copy of the development site's content (pages in two languages, the
 * shop, the blog, the modules, the redirects), plus what a real installation
 * keeps beside the code: uploads in every upload folder, private files in
 * the storage folder next to the site, and its own .env. After the update
 * every one of those must be exactly what it was, and every public URL the
 * sitemap names — in every published language — must still answer the way
 * it did.
 */
final class ExistingInstallAcceptanceTest extends TestCase
{
    private ?UpdaterSandbox $sandbox = null;

    private const VOLATILE = ['phinx_migration_log', 'admin_users', 'page_views', 'analytics_visitor_salts', 'updater_e2e_marker'];

    protected function setUp(): void
    {
        if (!UpdaterSandbox::available()) {
            $this->markTestSkipped('needs the MySQL root account (DB_ROOT_PASSWORD), ext-zip and ext-sodium');
        }
    }

    protected function tearDown(): void
    {
        $this->sandbox?->destroy();
    }

    /** @return array<string, string> installation files outside the release => sha256 */
    private function installationFiles(UpdaterSandbox $site): array
    {
        $files = [];

        // In the site root: everything the ownership contract calls the
        // installation's own (.env, every upload folder).
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site->root(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($site->root()) + 1);
            if ($file->isFile() && \App\Update\Ownership::isInstallationOwned($relative)) {
                $files['site/' . $relative] = (string) hash_file('sha256', $file->getPathname());
            }
        }

        // Beside it: the private storage, apart from the updater's own folder.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($site->storage()), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($site->directory) + 1);
            if ($file->isFile() && !str_starts_with($relative, 'storage/updates/')) {
                $files[$relative] = (string) hash_file('sha256', $file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * What a visitor gets at every URL the sitemap names: the status, the
     * title, the canonical and the language alternates. Asset version query
     * strings are left out — a changed stylesheet is supposed to get a new one.
     *
     * @return array<string, string>
     */
    private function publicSite(UpdaterSandbox $site): array
    {
        $sitemap = $site->visit('/sitemap.xml');
        $this->assertSame(200, $sitemap['status']);
        preg_match_all('#<loc>([^<]+)</loc>#', $sitemap['body'], $locations);
        $this->assertNotEmpty($locations[1], 'the sitemap names pages');

        $answers = [];
        foreach (array_unique($locations[1]) as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            $page = $site->visit($path);
            preg_match('#<title>(.*?)</title>#s', $page['body'], $title);
            preg_match('#<link rel="canonical" href="([^"]+)"#', $page['body'], $canonical);
            preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"#', $page['body'], $alternates, PREG_SET_ORDER);

            $answers[$path] = $page['status'] . ' | ' . trim($title[1] ?? '') . ' | ' . ($canonical[1] ?? '')
                . ' | ' . implode(',', array_map(static fn (array $a): string => $a[1] . '=' . $a[2], $alternates));
            $this->assertStringNotContainsString('Warning:', $page['body'], $path);
            $this->assertStringNotContainsString('Fatal error', $page['body'], $path);
        }
        ksort($answers);

        return $answers;
    }

    public function testACopyOfAnExistingInstallationKeepsEverythingThroughAnUpdate(): void
    {
        $site = $this->sandbox = UpdaterSandbox::install('existing');
        $site->login();

        // What an installation keeps beside the code.
        $uploads = [
            'assets/media/2026/09/team.jpg' => 'jpeg bytes',
            'assets/media/thumbs/team.webp' => 'webp bytes',
            'assets/images/sections/hero.jpg' => 'hero',
            'assets/images/products/mug.jpg' => 'mug',
            'assets/images/branding/logo.svg' => '<svg/>',
            'assets/videos/sections/intro.mp4' => 'mp4',
            'assets/fonts/personalization/brush.woff2' => 'woff2',
        ];
        foreach ($uploads as $path => $content) {
            @mkdir(dirname($site->root() . '/' . $path), 0777, true);
            file_put_contents($site->root() . '/' . $path, $content);
        }
        @mkdir($site->directory . '/storage/contact-attachments', 0777, true);
        file_put_contents($site->directory . '/storage/contact-attachments/request-1.pdf', 'a customer\'s attachment');
        $site->handToWebUser();

        $filesBefore = $this->installationFiles($site);
        $contentBefore = $site->databaseSnapshot(self::VOLATILE);
        $publicBefore = $this->publicSite($site);
        $this->assertArrayHasKey('site/.env', $filesBefore);
        $this->assertArrayHasKey('storage/contact-attachments/request-1.pdf', $filesBefore);
        $this->assertGreaterThan(1, count($publicBefore), 'more than one public URL to compare');

        $site->publish('B');
        $site->check();
        $site->startUpdate();
        $answers = $site->runSteps();
        $this->assertSame('completed', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT) . $site->serverLog());

        // The code is 0.2.0 and the migration ran.
        $this->assertSame("0.2.0\n", file_get_contents($site->root() . '/VERSION'));
        $this->assertTrue($site->hasTable('updater_e2e_marker'));

        // Content, media, .env and private storage: exactly as they were.
        $this->assertSame($contentBefore, $site->databaseSnapshot(self::VOLATILE));
        $this->assertSame($filesBefore, $this->installationFiles($site));
        foreach ($uploads as $path => $content) {
            $this->assertSame($content, file_get_contents($site->root() . '/' . $path), $path);
        }

        // Modules, routing and every published language answer as before.
        $this->assertSame($publicBefore, $this->publicSite($site));

        // The CMS works on the updated code, module screens included.
        foreach (['/admin/index.php', '/admin/pages.php', '/admin/settings.php', '/admin/media.php', '/admin/navigation.php',
            '/admin/products.php', '/admin/orders.php', '/admin/blog.php', '/admin/portfolio.php', '/admin/users.php', '/admin/updates.php'] as $screen) {
            $page = $site->get($screen);
            $this->assertSame(200, $page['status'], $screen);
            $this->assertStringNotContainsString('Warning:', $page['body'], $screen);
            $this->assertStringNotContainsString('Fatal error', $page['body'], $screen);
        }
    }
}
