<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\Build\LineEndings;
use App\Update\Build\ReleaseSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * The line-ending contract (App\Update\Build\LineEndings, .gitattributes,
 * docs/updates/RELEASES.md "Regeleinden"): one list of text and binary file
 * types, held by Git for every checkout and archive and by the release
 * builder for every package.
 *
 * Only paths and small strings, so it stays in the fast tiers. That the
 * builder really ships these bytes, from an LF and from a CRLF copy of this
 * repository alike, is ReleaseBuilderTest's.
 */
final class LineEndingsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * .gitattributes, read as the contract: every line one of the four forms
     * the builder mirrors, nothing Git would read differently.
     *
     * @return array{fallback: int, text_extensions: list<string>, text_names: list<string>, binary_extensions: list<string>}
     */
    private static function gitattributes(): array
    {
        $contract = ['fallback' => 0, 'text_extensions' => [], 'text_names' => [], 'binary_extensions' => []];
        $lines = preg_split('/\r?\n/', (string) file_get_contents(self::root() . '/.gitattributes'));

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line);
            $pattern = $fields[0];
            $attributes = implode(' ', array_slice($fields, 1));

            if ($pattern === '*' && $attributes === 'text=auto eol=lf') {
                $contract['fallback']++;
            } elseif (preg_match('/^\*\.([a-z0-9]+)$/', $pattern, $match) === 1 && $attributes === 'text eol=lf') {
                $contract['text_extensions'][] = $match[1];
            } elseif (preg_match('/^\*\.([a-z0-9]+)$/', $pattern, $match) === 1 && $attributes === 'binary') {
                $contract['binary_extensions'][] = $match[1];
            } elseif (preg_match('#^[A-Za-z0-9._-]+$#', $pattern) === 1 && !str_starts_with($pattern, '*') && $attributes === 'text eol=lf') {
                $contract['text_names'][] = $pattern;
            } else {
                self::fail(sprintf('.gitattributes line %d is not a form the builder mirrors: %s', $number + 1, $line));
            }
        }

        return $contract;
    }

    /** @param list<string> $list */
    private static function sorted(array $list): array
    {
        sort($list);

        return $list;
    }

    public function testGitattributesAndTheBuilderKnowTheSameFileTypes(): void
    {
        $git = self::gitattributes();

        $this->assertSame(1, $git['fallback'], 'one fallback, `* text=auto eol=lf`, so no text file anywhere is checked out with CRLF');
        $this->assertSame(self::sorted(LineEndings::TEXT_EXTENSIONS), self::sorted($git['text_extensions']), 'text by extension');
        $this->assertSame(self::sorted(LineEndings::TEXT_NAMES), self::sorted($git['text_names']), 'text by file name');
        $this->assertSame(self::sorted(LineEndings::BINARY_EXTENSIONS), self::sorted($git['binary_extensions']), 'binary by extension');
        $this->assertSame([], array_intersect(LineEndings::TEXT_EXTENSIONS, LineEndings::BINARY_EXTENSIONS), 'no type is both');
    }

    public function testEveryFileThisRepositoryShipsHasALineEndingRule(): void
    {
        // vendor/ is Composer's and has no rule to need; an empty stand-in
        // keeps the walk to this repository's own files.
        $vendor = sys_get_temp_dir() . '/mygdala-line-endings-' . bin2hex(random_bytes(4));
        mkdir($vendor);

        try {
            $files = ReleaseSource::fromDirectory(self::root(), $vendor)->files();
        } finally {
            FeedFixture::removeDirectory($vendor);
        }

        $this->assertGreaterThan(500, count($files), 'the walk saw the repository');
        $unknown = array_values(array_filter(array_keys($files), static fn (string $path): bool => LineEndings::classify($path) === null));
        $this->assertSame([], $unknown, 'a release would refuse these; list their type in .gitattributes and LineEndings');
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function paths(): iterable
    {
        yield 'PHP' => ['admin/index.php', LineEndings::TEXT];
        yield 'a stylesheet' => ['assets/css/core.css', LineEndings::TEXT];
        yield 'the version file' => ['VERSION', LineEndings::TEXT];
        yield 'Apache rules in a subfolder' => ['assets/fonts/personalization/.htaccess', LineEndings::TEXT];
        yield 'the example environment' => ['.env.example', LineEndings::TEXT];
        yield 'an SVG, which is text' => ['assets/images/block-preview/sample.svg', LineEndings::TEXT];
        yield 'an image' => ['assets/images/block-preview/sample.png', LineEndings::BINARY];
        yield 'a font' => ['assets/fonts/inter.woff2', LineEndings::BINARY];
        yield 'anything in vendor/' => ['vendor/dompdf/dompdf/lib/fonts/Courier.afm', LineEndings::VENDOR];
        yield 'vendor PHP too' => ['vendor/autoload.php', LineEndings::VENDOR];
        yield 'an unknown type' => ['assets/data/table.dat', null];
        yield 'no extension' => ['LICENSE', null];
        yield 'case matters, as in Git on Linux' => ['assets/images/block-preview/LOGO.PNG', null];
    }

    /** @dataProvider paths */
    public function testEachPathHasOneClass(string $path, ?string $expected): void
    {
        $this->assertSame($expected, LineEndings::classify($path));
    }

    public function testTextShipsWithLfOnly(): void
    {
        $this->assertSame("a\nb\n", LineEndings::canonicalText("a\r\nb\r\n"));
        $this->assertSame("a\nb\nc", LineEndings::canonicalText("a\nb\r\nc"), 'a mixed file');
        $this->assertSame("a\nb\n", LineEndings::canonicalText("a\nb\n"), 'LF stays LF');
        $this->assertSame('', LineEndings::canonicalText(''));
        $this->assertNull(LineEndings::canonicalText("a\rb\n"), 'a carriage return that ends no line');
        $this->assertNull(LineEndings::canonicalText("a\r\r\nb\n"), 'one CR too many before a line end');
        $this->assertNull(LineEndings::canonicalText("a\r"), 'an old Mac line end');
    }
}
