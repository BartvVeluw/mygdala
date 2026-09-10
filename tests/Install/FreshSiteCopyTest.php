<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\FreshSiteCopyPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The boundary between THE APPLICATION and THIS SITE, as
 * App\Install\FreshSiteCopyPolicy draws it and
 * `scripts/create_fresh_site_copy.php` acts on it.
 *
 * The repository is both things at once, and it cannot stop being: live pages
 * point at this company's photographs by path, so deleting them to make the
 * tree generic would break a working site for the benefit of one that does
 * not exist yet. The export is the boundary instead — and a boundary nobody
 * checks is a boundary that moves.
 *
 * Two halves. The policy is asked directly, which needs nothing and therefore
 * runs everywhere; and the script is run for real into a throwaway directory,
 * which proves the two agree. Both matter: a correct policy a script ignores
 * is not a correct export.
 *
 * `.gitignore` gets its own test. It already answers "what is this
 * installation's rather than the application's" for git, and an export that
 * disagreed with it would be carrying somebody's uploads.
 */
final class FreshSiteCopyTest extends TestCase
{
    /**
     * The export runs ONCE for the whole class and every "what came out"
     * test reads the same result. It copies hundreds of files; doing that per
     * test method would put seconds into the fast tier for no extra proof.
     */
    private static ?string $shared = null;

    /** @var array{0: int, 1: string}|null */
    private static ?array $sharedResult = null;

    /** Set by the one test that needs a directory of its own. */
    private ?string $ownDestination = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$shared !== null && is_dir(self::$shared)) {
            self::removeDirectoryAt(self::$shared);
        }

        self::$shared = null;
        self::$sharedResult = null;
    }

    protected function tearDown(): void
    {
        if ($this->ownDestination !== null && is_dir($this->ownDestination)) {
            self::removeDirectoryAt($this->ownDestination);
        }

        $this->ownDestination = null;
    }

    /* ------------------------------------------------------------------ */
    /* The policy                                                          */
    /* ------------------------------------------------------------------ */

    public function testTheApplicationItselfIsIncluded(): void
    {
        foreach ([
            'index.php',
            'composer.json',
            '.env.example',
            'phinx.php',
            '.htaccess',
            'src/Service/SiteSettings.php',
            'src/Install/InstallState.php',
            'partials/header.php',
            'admin/index.php',
            'api/checkout.php',
            'assets/css/core.css',
            'assets/js/core.js',
            'db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php',
            'scripts/create_fresh_site_copy.php',
            'PROJECT-MAP.md',
            'SETUP.md',
        ] as $path) {
            $this->assertTrue(FreshSiteCopyPolicy::includes($path), $path . ' is the application and must be copied.');
        }
    }

    public function testSecretsAndDependenciesAreExcluded(): void
    {
        foreach ([
            '.env',
            '.env.local',
            '.git',
            '.git/config',
            'vendor/autoload.php',
            'node_modules/x/index.js',
            '.phpunit.result.cache',
            'docker/mysql-data/ibdata1',
            'storage/contact-attachments/x.pdf',
            'debug.log',
        ] as $path) {
            $this->assertFalse(FreshSiteCopyPolicy::includes($path), $path . ' must never leave this repository.');
        }
    }

    public function testThisSitesPicturesAreExcluded(): void
    {
        foreach ([
            'assets/images/hero-collage-a.webp',
            'assets/images/vanveluwlaserdesignlogo.svg',
            'assets/images/raw/whatever.jpg',
            'assets/images/products/1.webp',
            'assets/images/branding/logo.png',
            'assets/media/thumbs/1.webp',
            'assets/videos/sections/1.mp4',
            'assets/fonts/personalization/some-face.ttf',
        ] as $path) {
            $this->assertFalse(FreshSiteCopyPolicy::includes($path), $path . ' is this site\'s content, not the application.');
        }
    }

    public function testTheTwoApplicationFilesInsideAnExcludedFolderSurvive(): void
    {
        // The MIME-type rules for uploaded engraving fonts have to ship, and
        // the .gitkeep is what keeps the folder in git at all.
        $this->assertTrue(FreshSiteCopyPolicy::includes('assets/fonts/personalization/.htaccess'));
        $this->assertTrue(FreshSiteCopyPolicy::includes('assets/fonts/personalization/.gitkeep'));
    }

    public function testThisSitesProjectHistoryIsExcluded(): void
    {
        // PROJECT-MAP.md itself calls all three history rather than a
        // description of the code.
        $this->assertFalse(FreshSiteCopyPolicy::includes('MAIN.MD'));
        $this->assertFalse(FreshSiteCopyPolicy::includes('docs/CMS_CONTENT_AUDIT.md'));
        $this->assertFalse(FreshSiteCopyPolicy::includes('docs/content-blocks/ROADMAP.md'));
        // Its neighbours are architecture, not history, and stay.
        $this->assertTrue(FreshSiteCopyPolicy::includes('docs/content-blocks/ARCHITECTURE.md'));
    }

    public function testTheWalkNeverDescendsIntoAnExcludedTree(): void
    {
        // Not an optimisation detail: vendor/ is most of the file count and
        // assets/images/ is most of the bytes.
        $this->assertFalse(FreshSiteCopyPolicy::descendsInto('vendor'));
        $this->assertFalse(FreshSiteCopyPolicy::descendsInto('assets/images'));
        $this->assertFalse(FreshSiteCopyPolicy::descendsInto('assets/images/raw'));
        $this->assertFalse(FreshSiteCopyPolicy::descendsInto('.git'));
        $this->assertTrue(FreshSiteCopyPolicy::descendsInto('src'));
        $this->assertTrue(FreshSiteCopyPolicy::descendsInto('assets/css'));
        // It has to walk far enough to reach the two files kept inside it.
        $this->assertTrue(FreshSiteCopyPolicy::descendsInto('assets/fonts'));
        $this->assertTrue(FreshSiteCopyPolicy::descendsInto('assets/fonts/personalization'));
    }

    public function testEveryDirectoryGitIgnoresIsAlsoExcludedFromACopy(): void
    {
        // `.gitignore` already answers "what belongs to this installation
        // rather than to the application". An export that disagreed with it
        // would be carrying somebody's uploads.
        $missing = [];

        foreach ($this->gitIgnoredDirectories() as $directory) {
            if ($this->holdsAnApplicationFile($directory)) {
                // `.gitignore` re-includes a file or two here with `!`, and
                // so does the policy — the folder is walked for those alone.
                continue;
            }

            if (FreshSiteCopyPolicy::descendsInto($directory)) {
                $missing[] = $directory;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "git ignores these, but a fresh copy would carry them:\n" . implode("\n", $missing)
        );
    }

    public function testEveryWritableDirectoryIsOneTheExportExcludes(): void
    {
        // They are recreated empty precisely BECAUSE their contents are
        // excluded. A writable directory that was not excluded would be
        // copied with this site's files still in it.
        foreach (FreshSiteCopyPolicy::WRITABLE_DIRECTORIES as $directory) {
            $this->assertFalse(
                FreshSiteCopyPolicy::includes($directory . '/anything.webp'),
                $directory . ' is recreated empty, so its contents must be excluded.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* The script                                                          */
    /* ------------------------------------------------------------------ */

    public function testTheScriptShips(): void
    {
        $this->assertFileExists($this->root() . '/scripts/create_fresh_site_copy.php');
    }

    public function testItRefusesToRunWithoutADestination(): void
    {
        [$status, $output] = $this->runScript([]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsStringIgnoringCase('usage', $output);
    }

    public function testItRefusesToWriteIntoANonEmptyDirectoryWithoutForce(): void
    {
        $this->ownDestination = sys_get_temp_dir() . '/vvld-fresh-copy-busy-' . bin2hex(random_bytes(6));
        mkdir($this->ownDestination, 0o775, true);
        file_put_contents($this->ownDestination . '/something.txt', 'in the way');

        [$status, $output] = $this->runScript([$this->ownDestination]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsStringIgnoringCase('not empty', $output);
    }

    public function testItCopiesTheApplicationAndSaysSo(): void
    {
        [$status, $output] = $this->export();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Copied', $output);
    }

    public function testTheApplicationItselfIsAllThere(): void
    {
        $this->export();

        // One file from each layer the application is made of. If any is
        // missing, the copy is not a working installation.
        foreach ([
            'index.php',
            'composer.json',
            '.env.example',
            'phinx.php',
            '.htaccess',
            'src/Service/SiteSettings.php',
            'partials/header.php',
            'admin/index.php',
            'assets/css/core.css',
            'assets/js/core.js',
            'db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php',
            'assets/fonts/personalization/.htaccess',
            'PROJECT-MAP.md',
            'SETUP.md',
        ] as $relative) {
            $this->assertFileExists($this->destination() . '/' . $relative, $relative . ' did not survive the export.');
        }
    }

    public function testNoSecretAndNoHistoryCameAlong(): void
    {
        $this->export();

        $this->assertFileDoesNotExist(
            $this->destination() . '/.env',
            'A copy that carries the source site\'s secrets is not a fresh site.'
        );
        $this->assertDirectoryDoesNotExist(
            $this->destination() . '/.git',
            'The new site gets its own history, not this one\'s.'
        );
        $this->assertDirectoryDoesNotExist($this->destination() . '/vendor');
        $this->assertFileDoesNotExist($this->destination() . '/MAIN.MD');
    }

    public function testNotOneOfThisSitesPicturesCameAlong(): void
    {
        $this->export();

        $carried = [];

        foreach (['assets/images', 'assets/media', 'assets/videos'] as $directory) {
            foreach ($this->filesUnder($this->destination() . '/' . $directory) as $file) {
                if (basename($file) !== '.gitkeep') {
                    $carried[] = $file;
                }
            }
        }

        $this->assertSame($carried, [], "The export carried this site's own media:\n" . implode("\n", $carried));
    }

    public function testTheWritableDirectoriesExistEmptyAndWritable(): void
    {
        $this->export();

        foreach (FreshSiteCopyPolicy::WRITABLE_DIRECTORIES as $directory) {
            $path = $this->destination() . '/' . $directory;

            $this->assertDirectoryExists($path, $directory . ' must exist: the application writes into it.');
            $this->assertFileExists($path . '/.gitkeep', $directory . ' needs a .gitkeep to survive a first commit.');
            $this->assertTrue(is_writable($path), $directory . ' must be writable by the runtime.');
        }
    }

    public function testItPointsAtWhatStillNeedsRewritingByHand(): void
    {
        [, $output] = $this->export();

        // The script edits nothing. Whatever still names this site is listed
        // for a human, because rewriting prose automatically is guessing.
        $this->assertStringContainsStringIgnoringCase('Still mentions this site', $output);
        $this->assertStringContainsString('README.md', $output);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: int, 1: string} */
    private function export(): array
    {
        if (self::$sharedResult !== null) {
            return self::$sharedResult;
        }

        self::$shared = sys_get_temp_dir() . '/vvld-fresh-copy-' . bin2hex(random_bytes(6));

        return self::$sharedResult = $this->runScript([self::$shared]);
    }

    private function destination(): string
    {
        $this->export();

        return (string) self::$shared;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function runScript(array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->root() . '/scripts/create_fresh_site_copy.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * The directory patterns in `.gitignore`, as plain repository-relative
     * paths. Only entries that name a directory are read: the file patterns
     * there (`*.log`, `.DS_Store`) are about single files, and the policy
     * recognises those by name rather than by place.
     *
     * @return list<string>
     */
    private function gitIgnoredDirectories(): array
    {
        $directories = [];

        foreach (file($this->root() . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
                continue;
            }

            // `/assets/media/*` and `logs/` both name a directory; `*.log`
            // does not, and neither does a bare `.env`.
            $path = trim($line, '/');
            $path = preg_replace('#/\*$#', '', $path) ?? $path;

            if ($path === '' || str_contains($path, '*')) {
                continue;
            }

            if (is_dir($this->root() . '/' . $path)) {
                $directories[] = $path;
            }
        }

        return array_values(array_unique($directories));
    }

    /** Whether the policy keeps a file inside this otherwise-excluded directory. */
    private function holdsAnApplicationFile(string $directory): bool
    {
        foreach (FreshSiteCopyPolicy::KEPT_INSIDE_EXCLUDED as $kept) {
            if (str_starts_with($kept, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    private static function removeDirectoryAt(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
