<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\AppVersion;
use App\Update\RelativePath;
use App\Update\SemVer;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;

/**
 * The two small value rules everything else in App\Update stands on: what a
 * version is, and what a path inside a release may look like. See
 * docs/updates/ARCHITECTURE.md.
 */
final class VersionAndPathTest extends TestCase
{
    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            @unlink($this->scratch . '/VERSION');
            @rmdir($this->scratch);
        }
        AppVersion::clearCache();
    }

    public function testTheRepositoryCarriesOneCanonicalVersion(): void
    {
        $version = AppVersion::current();

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $this->assertSame(trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION')), $version);
    }

    public function testVersionsCompareNumericallyNotAlphabetically(): void
    {
        $this->assertTrue(SemVer::parse('0.10.0')->isNewerThan(SemVer::parse('0.9.9')));
        $this->assertTrue(SemVer::parse('1.0.0')->isNewerThan(SemVer::parse('0.99.99')));
        $this->assertFalse(SemVer::parse('0.2.0')->isNewerThan(SemVer::parse('0.2.0')));
        $this->assertTrue(SemVer::parse('0.2.0')->equals(SemVer::parse('0.2.0')));
        $this->assertSame(-1, SemVer::parse('0.1.9')->compare(SemVer::parse('0.2.0')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAVersion(): iterable
    {
        yield 'two parts' => ['1.2'];
        yield 'leading v' => ['v1.2.3'];
        yield 'pre-release' => ['1.2.3-beta.1'];
        yield 'build metadata' => ['1.2.3+abc'];
        yield 'leading zero' => ['01.2.3'];
        yield 'whitespace' => [' 1.2.3'];
        yield 'empty' => [''];
    }

    /** @dataProvider notAVersion */
    public function testOnlyStrictMajorMinorPatchIsAVersion(string $candidate): void
    {
        $this->assertNull(SemVer::tryParse($candidate));
    }

    public function testAMissingOrBrokenVersionFileIsAnErrorNotAGuess(): void
    {
        $this->scratch = sys_get_temp_dir() . '/mygdala-version-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);

        try {
            AppVersion::current($this->scratch);
            $this->fail('a root without VERSION must not have a version');
        } catch (\RuntimeException) {
        }

        file_put_contents($this->scratch . '/VERSION', "2.0\n");
        AppVersion::clearCache();

        $this->expectException(\RuntimeException::class);
        AppVersion::current($this->scratch);
    }

    public function testADevelopmentCheckoutHasNoBuildOrReleaseDate(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/release.json');
        $this->assertSame('', AppVersion::build());
        $this->assertSame('', AppVersion::releasedAt());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'parent segment' => ['../index.php'];
        yield 'nested parent' => ['src/../../etc/passwd'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'backslash traversal' => ['..\\index.php'];
        yield 'windows drive' => ['C:/Windows/win.ini'];
        yield 'ntfs stream' => ['index.php:evil'];
        yield 'nul byte' => ["index.php\0.txt"];
        yield 'empty segment' => ['src//Update.php'];
        yield 'dot segment' => ['src/./Update.php'];
        yield 'trailing slash' => ['src/'];
        yield 'trailing dot' => ['evil.php.'];
        yield 'space' => ['my file.php'];
        yield 'unicode' => ['src/Ünicode.php'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a/', 200) . 'b'];
    }

    /** @dataProvider escapingPaths */
    public function testAPathThatCouldEscapeItsDirectoryIsRefused(string $path): void
    {
        $this->assertFalse(RelativePath::isSafe($path));

        $this->expectException(UpdateException::class);
        RelativePath::assertSafe($path);
    }

    public function testEveryPathTheProjectShipsIsSafe(): void
    {
        foreach (['index.php', '.htaccess', 'src/Update/SemVer.php', 'vendor/symfony/console/Application.php',
            'vendor/phpmailer/phpmailer/language/phpmailer.lang-pt_br.php', 'assets/fonts/personalization/.htaccess',
            'vendor/league/container/src/Argument/Literal/ArrayArgument.php', 'db/migrations/20260903120000_x.php',
        ] as $path) {
            $this->assertTrue(RelativePath::isSafe($path), $path);
        }
    }

    public function testParentsAreListedOutermostFirst(): void
    {
        $this->assertSame(['a', 'a/b'], RelativePath::parents('a/b/c.php'));
        $this->assertSame([], RelativePath::parents('index.php'));
    }

    public function testAPrintablePathShowsControlBytesInsteadOfPrintingThem(): void
    {
        $this->assertSame('a\\x00b', RelativePath::printable("a\0b"));
    }
}
